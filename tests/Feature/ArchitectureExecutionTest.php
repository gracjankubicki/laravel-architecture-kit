<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\ExecutionSources;
use GracjanKubicki\ArchitectureKit\Impact\ImpactSchema;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Impact;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class ArchitectureExecutionTest extends TestCase
{
    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, '<?php '.$source);
        clearstatcache();
    }

    private function fixture(): void
    {
        $this->write('config/architectures.php', 'return ["enabled" => ["actions"]];');
        $this->write('app/Service.php', 'namespace App; class Service { public static function send() {} public static function other() {} }');
        $this->write('app/Job.php', 'namespace App; class Job implements \Illuminate\Contracts\Queue\ShouldQueue { use \Illuminate\Foundation\Bus\Dispatchable; public function handle() { Service::send(); } }');
        $this->write('app/Created.php', 'namespace App; class Created { use \Illuminate\Foundation\Events\Dispatchable; }');
        $this->write('app/Listener.php', 'namespace App; class Listener { public function handle(Created $event) { Job::dispatch(); } }');
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { event(new Created); } public function other() { new Job; } }');
        $this->write('app/Providers/Events.php', 'namespace App\Providers; class Events extends \Illuminate\Foundation\Support\Providers\EventServiceProvider { protected $listen = [\App\Created::class => [\App\Listener::class]]; }');
        $this->write('bootstrap/app.php', 'return \Illuminate\Foundation\Application::configure()->withRouting(web: base_path("routes/web.php"));');
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::post("orders", [App\Controller::class, "store"]); Route::get("other", [App\Controller::class, "other"]);');
    }

    private function query(string $subject = 'Service::send', int $limit = 100, int $depth = 16): array
    {
        $result = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect($subject, [], $limit, $depth);
        $this->assertTrue($result['ok'], json_encode($result));

        return $result;
    }

    private function httpFlows(array $result): array
    {
        return array_values(array_filter($result['execution']['flows'], fn ($row) => $row['entry']['kind'] === 'http'));
    }

    public function test_subject_outgoing_dispatch_paths_do_not_expand_other_caller_methods(): void
    {
        $this->fixture();
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { Service::send(); Service::other(); } public function other() {} }');
        $this->write('app/Service.php', 'namespace App; class Service { public static function send() { event(new Created); } public static function other() { OtherJob::dispatch(); } }');
        $this->write('app/OtherJob.php', 'namespace App; class OtherJob implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Foundation\\Bus\\Dispatchable; public function handle() {} }');
        // Avoid a callback cycle back to the subject while preserving a downstream call.
        $this->write('app/Job.php', 'namespace App; class Job implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Foundation\\Bus\\Dispatchable; public function handle() { Sink::save(); } }');
        $this->write('app/Sink.php', 'namespace App; class Sink { public static function save() {} }');
        $result = $this->query('Service::send');
        $rows = array_values(array_filter($result['execution']['flows'], fn ($row) => $row['direction'] === 'outgoing'));
        $this->assertNotEmpty($rows);
        $this->assertContains('App\\Sink::save', array_column($rows, 'target'));
        $this->assertNotContains('App\\OtherJob::handle', array_column($rows, 'target'));
        foreach ($rows as $row) {
            $this->assertSame('App\\Service::send', $row['entry']['symbol']);
            $this->assertSame('subject', $row['entry']['kind']);
        }
        $this->assertNotEmpty($result['execution']['routes']);
        $classRows = array_values(array_filter($this->query('Service')['execution']['flows'], fn ($row) => $row['direction'] === 'outgoing'));
        $this->assertContains('App\\OtherJob::handle', array_column($classRows, 'target'));
    }

    public function test_outgoing_paths_preserve_quiet_context_and_dispatch_execution_mode(): void
    {
        $this->fixture();
        $this->write('app/Order.php', 'namespace App; #[\\Illuminate\\Database\\Eloquent\\Attributes\\ObservedBy([Observer::class])] class Order extends \\Illuminate\\Database\\Eloquent\\Model {}');
        $this->write('app/Observer.php', 'namespace App; class Observer { public function created(Order $o) { Service::other(); } }');
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { Order::withoutEvents(function () { Order::create([]); Job::dispatchSync(); }); } public function other() {} }');
        $rows = array_values(array_filter($this->query('Controller::store')['execution']['flows'], fn ($row) => $row['direction'] === 'outgoing'));
        $this->assertContains('App\\Job::handle', array_column($rows, 'target'));
        $this->assertNotContains('App\\Observer::created', array_column($rows, 'target'));
        $job = array_values(array_filter($rows, fn ($row) => $row['target'] === 'App\\Job::handle'))[0];
        $this->assertContains('synchronous', array_column($job['via'], 'mode'));
        $this->assertSame([], $this->query('Controller::other')['execution']['flows']);
    }

    public function test_http_event_listener_job_chain_is_precise_for_method_class_and_file(): void
    {
        $this->fixture();
        foreach (['Service::send', 'Service', 'app/Service.php'] as $subject) {
            $rows = $this->httpFlows($this->query($subject));
            $this->assertNotEmpty($rows);
            $this->assertSame(['/orders'], array_values(array_unique(array_column(array_column(array_column($rows, 'entry'), 'route'), 'uri'))));
            $this->assertContains('event-dispatch', array_column($rows[0]['via'], 'kind'));
            $this->assertContains('event-listener', array_column($rows[0]['via'], 'kind'));
            $this->assertContains('job-handler', array_column($rows[0]['via'], 'kind'));
            $this->assertSame('App\Service::send', $rows[0]['target']);
        }
        $this->assertSame([], $this->query('Service::other')['execution']['flows']);
    }

    public function test_construction_preparation_and_arbitrary_callbacks_do_not_execute_jobs(): void
    {
        $this->fixture();
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { new Job; \Illuminate\Support\Facades\Bus::chain([new Job]); \Illuminate\Support\Facades\Bus::batch([new Job]); $callback = fn () => Job::dispatch(); } public function other() {} }');
        $this->assertSame([], $this->httpFlows($this->query()));
    }

    public function test_chains_batches_nested_callbacks_and_conditions_are_preserved(): void
    {
        $this->fixture();
        $this->write('app/Controller.php', 'namespace App; use Illuminate\Support\Facades\Bus; class Controller { public function store() { Bus::chain([new Job, Bus::batch([[new Job, new Job], new Job])->allowFailures()->then(fn () => Service::send())->catch(fn () => Service::send())->finally(fn () => Service::send())])->catch(fn () => Service::send())->dispatch(); } public function other() {} }');
        $rows = $this->httpFlows($this->query());
        $this->assertNotEmpty($rows);
        $all = json_encode($rows, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Independent batch branch', $all);
        $this->assertStringContainsString('requires success', $all);
        $this->assertStringContainsString('batch-catch', $all);
        $this->assertStringContainsString('first batch failure', $all);
        $this->assertStringContainsString('allow_failures', $all);
        $this->assertStringContainsString('chain-catch', $all);
    }

    public function test_explicit_typed_closure_wildcard_subscriber_and_interface_listeners(): void
    {
        $this->fixture();
        $this->write('app/Contract.php', 'namespace App; interface Contract {}');
        $this->write('app/Created.php', 'namespace App; class Created implements Contract { use \Illuminate\Foundation\Events\Dispatchable; }');
        $this->write('app/Subscriber.php', 'namespace App; class Subscriber { public function subscribe(\Illuminate\Events\Dispatcher $events) { $events->listen(Created::class, [self::class, "run"]); return [Created::class => "run"]; } public function run() { Service::send(); } }');
        $this->write('app/Providers/Events.php', 'namespace App\Providers; use Illuminate\Support\Facades\Event; class Events extends \Illuminate\Support\ServiceProvider { public function boot() { Event::listen(\App\Contract::class, \App\Listener::class); Event::listen(fn (\App\Created $e) => \App\Service::send()); Event::listen("order.*", fn () => \App\Service::send()); Event::subscribe(\App\Subscriber::class); } }');
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { Created::dispatch(); event("order.created"); } public function other() {} }');
        $rows = $this->httpFlows($this->query());
        $all = json_encode($rows, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('subscriber', $all);
        $this->assertStringContainsString('order.created', $all);
        $this->assertStringContainsString('(callback)', $all);
        $this->assertStringContainsString('App\\\\Listener::handle', $all);
    }

    public function test_model_quiet_context_propagates_through_helper_but_keeps_explicit_job(): void
    {
        $this->fixture();
        $this->write('app/Order.php', 'namespace App; #[\Illuminate\Database\Eloquent\Attributes\ObservedBy([Observer::class])] class Order extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Observer.php', 'namespace App; class Observer { public function created(Order $o) { Service::send(); } }');
        $this->write('app/Writer.php', 'namespace App; class Writer { public static function persist() { Order::create([]); } }');
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { Order::withoutEvents(fn () => Writer::persist()); } public function other() { Writer::persist(); } }');
        $rows = $this->httpFlows($this->query());
        $this->assertNotEmpty($rows);
        $this->assertSame(['/other'], array_values(array_unique(array_column(array_column(array_column($rows, 'entry'), 'route'), 'uri'))));
        $this->write('app/Writer.php', 'namespace App; class Writer { public static function persist() { Order::create([]); Job::dispatchSync(); } }');
        $this->assertContains('/orders', array_column(array_column(array_column($this->httpFlows($this->query()), 'entry'), 'route'), 'uri'));
    }

    public function test_quiet_and_mass_builder_writes_do_not_fire_model_events(): void
    {
        $this->fixture();
        $this->write('app/Order.php', 'namespace App; class Order extends \Illuminate\Database\Eloquent\Model { protected $dispatchesEvents = ["created" => Created::class]; }');
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store(Order $order) { $order->saveQuietly(); Order::query()->update([]); Order::where("id", 1)->delete(); } public function other() {} }');
        $this->assertSame([], $this->httpFlows($this->query()));
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { Order::create([]); } public function other() {} }');
        $this->assertNotEmpty($this->httpFlows($this->query()));
    }

    public function test_discovery_disable_and_custom_paths(): void
    {
        $this->fixture();
        $this->write('app/Providers/Events.php', 'namespace App\Providers; class Events {}');
        $this->write('app/Listeners/Auto.php', 'namespace App\Listeners; class Auto { public function handleCreated(\App\Created $event) { \App\Service::send(); } }');
        $this->assertNotEmpty($this->httpFlows($this->query()));
        $this->write('bootstrap/app.php', 'return \Illuminate\Foundation\Application::configure()->withEvents(discover: false)->withRouting(web: base_path("routes/web.php"));');
        $this->assertSame([], $this->httpFlows($this->query()));
        $this->write('extra/listeners/Auto.php', 'namespace Custom; class Auto { public function __invoke(\App\Created $event) { \App\Service::send(); } }');
        $this->write('bootstrap/app.php', 'return \Illuminate\Foundation\Application::configure()->withEvents(discover: [base_path("extra/listeners")])->withRouting(web: base_path("routes/web.php"));');
        $this->assertNotEmpty($this->httpFlows($this->query()));
    }

    public function test_callback_route_dispatch_and_no_http_roots(): void
    {
        $this->fixture();
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::post("closure", fn () => \App\Job::dispatch());');
        $this->assertNotEmpty($this->httpFlows($this->query()));
        unlink($this->tempPath.'/routes/web.php');
        $rows = $this->query()['execution']['flows'];
        $this->assertNotEmpty($rows);
        $this->assertNotContains('http', array_column(array_column($rows, 'entry'), 'kind'));
    }

    public function test_modes_timing_and_static_false_condition(): void
    {
        $this->fixture();
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { Job::dispatchAfterResponse(); Job::dispatchSync(); \Illuminate\Support\Facades\Bus::batch([new Job])->dispatchAfterResponse(); } public function other() { Job::dispatchIf(false); Job::dispatchUnless(true); } }');
        $rows = $this->httpFlows($this->query());
        $this->assertSame(['/orders'], array_values(array_unique(array_column(array_column(array_column($rows, 'entry'), 'route'), 'uri'))));
        $handlers = [];
        foreach ($rows as $row) {
            array_push($handlers, ...array_filter($row['via'], fn ($e) => $e['kind'] === 'job-handler'));
        }
        $afterResponse = array_values(array_filter($handlers, fn ($e) => $e['timing'] === 'after-response'));
        $this->assertContains('synchronous', array_column($afterResponse, 'mode'));
        $this->assertContains('queue-requested', array_column($afterResponse, 'mode'));
    }

    public function test_pending_dispatch_options_with_chain_and_queued_closures(): void
    {
        $this->fixture();
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { Job::dispatch()->onQueue("invoices")->afterCommit()->chain([new Job]); Job::withChain([new Job])->onConnection("redis")->dispatch(); dispatch(fn () => Service::send())->afterResponse(); } public function other() {} }');
        $rows = $this->httpFlows($this->query());
        $all = json_encode($rows, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('after-commit', $all);
        $this->assertStringContainsString('invoices', $all);
        $this->assertStringContainsString('redis', $all);
        $this->assertStringContainsString('after-response', $all);
        $this->assertStringContainsString('Chain item 1', $all);
    }

    public function test_nested_batch_branches_and_same_line_dispatch_sites_keep_identity(): void
    {
        $this->fixture();
        $this->write('app/OtherJob.php', 'namespace App; class OtherJob implements \Illuminate\Contracts\Queue\ShouldQueue { use \Illuminate\Foundation\Bus\Dispatchable; public function handle() { Service::other(); } }');
        $this->write('app/Controller.php', 'namespace App; use Illuminate\Support\Facades\Bus; class Controller { public function store() { Bus::chain([Bus::batch([new Job])->then(fn () => Service::send()), Bus::batch([new OtherJob])->then(fn () => Service::other())])->dispatch(); Job::dispatch(); Job::dispatch(); } public function other() {} }');
        $send = $this->httpFlows($this->query());
        $other = $this->httpFlows($this->query('Service::other'));
        $this->assertNotEmpty($other);
        $this->assertCount(count($send), array_unique(array_column($send, 'id')));
        $batchNodes = [];
        foreach ($send as $row) {
            foreach ($row['via'] as $edge) {
                if ($edge['kind'] === 'batch-dispatch') {
                    $batchNodes[] = $edge['to'];
                }
            }
        }
        $otherNodes = [];
        foreach ($other as $row) {
            foreach ($row['via'] as $edge) {
                if ($edge['kind'] === 'batch-dispatch') {
                    $otherNodes[] = $edge['to'];
                }
            }
        }
        $this->assertSame([], array_intersect($batchNodes, $otherNodes));
    }

    public function test_inherited_observed_by_and_explicit_model_callback_registration(): void
    {
        $this->fixture();
        $this->write('app/BaseOrder.php', 'namespace App; #[\Illuminate\Database\Eloquent\Attributes\ObservedBy(Observer::class)] class BaseOrder extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Order.php', 'namespace App; class Order extends BaseOrder {}');
        $this->write('app/Observer.php', 'namespace App; class Observer { public function created(Order $order) { Service::send(); } }');
        $this->write('app/Providers/Events.php', 'namespace App\Providers; class Events { public function boot() { \App\Order::saving(fn () => \App\Service::other()); } }');
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { Order::create([]); } public function other() {} }');
        $this->assertNotEmpty($this->httpFlows($this->query()));
        $this->assertNotEmpty($this->httpFlows($this->query('Service::other')));
    }

    public function test_discovery_custom_provider_paths_and_contract_calls(): void
    {
        $this->fixture();
        $this->write('app/Providers/Events.php', 'namespace App\Providers; class Events extends \Illuminate\Foundation\Support\Providers\EventServiceProvider { public function shouldDiscoverEvents() { return true; } protected function discoverEventsWithin() { return [base_path("custom/listeners")]; } }');
        $this->write('app/Sender.php', 'namespace App; interface Sender { public function send(); }');
        $this->write('app/Impl.php', 'namespace App; class Impl implements Sender { public function send() { Service::send(); } }');
        $this->write('custom/listeners/Listener.php', 'namespace Custom; class Listener { public function handle(\App\Created $event, \App\Sender $sender) { $sender->send(); } }');
        $this->assertNotEmpty($this->httpFlows($this->query()));
    }

    public function test_unknown_dispatch_and_reachable_receiver_do_not_report_none(): void
    {
        $this->fixture();
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { dispatch($job); $unknown->send(); } public function other() {} }');
        $result = $this->query();
        $this->assertSame([], $this->httpFlows($result));
        $this->assertSame('incomplete', $result['execution']['flow_analysis']['status']);
        $this->assertStringContainsString('unresolved', json_encode($result['execution']['flow_analysis']['unresolved'], JSON_THROW_ON_ERROR));
    }

    public function test_sources_refresh_with_cached_graph_missing_paths_and_no_execution(): void
    {
        $this->fixture();
        $files = new Filesystem;
        $cache = new ProjectGraphCache($files, $this->tempPath);
        $query = fn () => (new ArchitectureImpact($files, $this->tempPath, new AuditScope, $cache))->inspect('Service::send', [], 100, 16);
        $before = $query();
        $warm = $query();
        $this->assertSame($before['execution'], $warm['execution']);
        $this->write('app/Providers/Events.php', 'namespace App\Providers; throw new \RuntimeException("must not execute"); class Events extends \Illuminate\Foundation\Support\Providers\EventServiceProvider { protected $listen = []; }');
        $after = $query();
        $this->assertNotSame($before['snapshot'], $after['snapshot']);
        $this->assertSame([], $this->httpFlows($after));
        $this->write('app/Listeners/Added.php', 'namespace App\Listeners; class Added { public function handle(\App\Created $e) { \App\Service::send(); } }');
        $this->assertNotEmpty($this->httpFlows($query()));
        unlink($this->tempPath.'/app/Listeners/Added.php');
        $this->assertSame([], $this->httpFlows($query()));
        $sources = new ExecutionSources($files, $this->tempPath);
        $sources->discover(new ProjectGraphSnapshot([], []), []);
        $this->assertTrue($sources->fresh());
        $this->write('app/Added.php', 'namespace App; class Added {}');
        $this->assertFalse($sources->fresh());
    }

    public function test_limits_depth_and_bounded_source_failures_are_explicit(): void
    {
        $this->fixture();
        $zero = $this->query(limit: 0);
        $this->assertSame('limit', $zero['execution']['flow_analysis']['status']);
        $this->assertSame([], $zero['execution']['flows']);
        $this->assertGreaterThan(0, $zero['execution']['flow_analysis']['totals']['flows']);
        $this->assertSame('limit', $this->query(depth: 1)['execution']['flow_analysis']['status']);
        $this->write('app/Large.php', str_repeat(' ', 101000).'namespace App; class Large {}');
        $this->assertSame('limit', $this->query()['execution']['flow_analysis']['status']);
    }

    public function test_cli_mcp_schema_and_preflight_sections_share_additive_flows(): void
    {
        $this->fixture();
        Artisan::call('architecture-kit:impact', ['subject' => 'Service::send', '--agent' => true, '--limit' => 100, '--depth' => 16]);
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($cli['ok'], json_encode($cli));
        $this->assertNotEmpty($cli['execution']['flows']);
        ArchitectureKitServer::tool(Impact::class, ['subject' => 'Service::send', 'limit' => 100, 'depth' => 16])->assertOk()->assertStructuredContent(fn ($json) => $json->where('execution', $cli['execution'])->etc());
        $schema = ImpactSchema::get()['oneOf'][0]['properties']['execution']['properties'];
        foreach (array_keys($cli['execution']) as $key) {
            $this->assertArrayHasKey($key, $schema);
        }
        $ordinary = $this->query();
        foreach (['delete', 'signature', 'move'] as $change) {
            $subject = $change === 'move' ? 'Service' : 'Service::send';
            $result = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect($subject, [], 100, 16, $change);
            $this->assertNotEmpty($result['execution']['flows']);
            if ($change !== 'move') {
                $this->assertSame($ordinary['dependents'], $result['dependents']);
            }
            $this->assertFalse($result[$change]['safe_to_change']);
        }
    }

    public function test_global_sync_helper_mapped_handler_and_disabled_queued_listener(): void
    {
        $this->fixture();
        $this->write('app/Handler.php', 'namespace App; class Handler { public function handle(Job $job) { Service::other(); } }');
        $this->write('app/Listener.php', 'namespace App; class Listener implements \Illuminate\Contracts\Queue\ShouldQueue { public function shouldQueue(Created $event) { return false; } public function handle(Created $event) { Service::send(); } }');
        $this->assertSame([], $this->httpFlows($this->query()));
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { \Illuminate\Support\Facades\Bus::map([Job::class => Handler::class]); dispatch_sync(new Job); } public function other() {} }');
        $this->assertSame([], $this->httpFlows($this->query()));
        $rows = $this->httpFlows($this->query('Service::other'));
        $this->assertNotEmpty($rows);
        $handlers = array_values(array_filter($rows[0]['via'], fn ($e) => $e['kind'] === 'job-handler'));
        $this->assertSame('synchronous', $handlers[0]['mode']);
        $this->assertSame('App\Handler::handle', $handlers[0]['to']);
    }

    public function test_queueable_closure_and_contract_after_commit_and_known_sync_suppression(): void
    {
        $this->fixture();
        $this->write('app/Order.php', 'namespace App; #[\Illuminate\Database\Eloquent\Attributes\ObservedBy(Observer::class)] class Order extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Observer.php', 'namespace App; class Observer { public function created(Order $order) { Service::send(); } }');
        $this->write('app/Job.php', 'namespace App; class Job implements \Illuminate\Contracts\Queue\ShouldQueueAfterCommit { use \Illuminate\Foundation\Bus\Dispatchable; public function handle() { Order::create([]); } }');
        $this->write('app/Providers/Events.php', 'namespace App\Providers; use function Illuminate\Events\queueable; class Events { public function boot() { \Illuminate\Support\Facades\Event::listen(queueable(fn (\App\Created $e) => \App\Service::other())->afterCommit()); } }');
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { event(new Created); Job::dispatch(); } public function other() { Order::withoutEvents(fn () => dispatch((new Job)->onConnection("sync")->beforeCommit())); } }');
        $rows = $this->httpFlows($this->query());
        $this->assertNotEmpty($rows);
        $this->assertNotContains('/other', array_column(array_column(array_column($rows, 'entry'), 'route'), 'uri'));
        $this->assertStringContainsString('after-commit', json_encode($rows, JSON_THROW_ON_ERROR));
        $this->assertNotEmpty($this->httpFlows($this->query('Service::other')));
    }

    public function test_mutated_job_and_uncaptured_closure_variable_are_not_proved_dispatches(): void
    {
        $this->fixture();
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { $job = new Job; mutate($job); dispatch($job); dispatch(function () { dispatch($job); }); } public function other() {} }');
        $result = $this->query();
        $this->assertSame([], $this->httpFlows($result));
        $this->assertSame('incomplete', $result['execution']['flow_analysis']['status']);
    }

    public function test_custom_dispatch_wrapper_and_with_chain_collision(): void
    {
        $this->fixture();
        $this->write('app/Gateway.php', 'namespace App; class Gateway { public static function dispatch() { Job::dispatch(); } public static function withChain($jobs) { return new self; } }');
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { Gateway::dispatch(); } public function other() {} }');
        $rows = $this->httpFlows($this->query());
        $this->assertNotEmpty($rows);
        $this->assertContains('App\Gateway::dispatch', array_column($rows[0]['via'], 'to'));
        $this->write('app/Gateway.php', 'namespace App; class Gateway { public static function dispatch() {} public static function withChain($jobs) { return new self; } }');
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { Gateway::withChain([new Job])->dispatch(); } public function other() {} }');
        $this->assertSame([], $this->httpFlows($this->query()));
        $this->write('app/Other.php', 'namespace App; class Other { public function dispatch() {} }');
        $this->write('app/Gateway.php', 'namespace App; class Gateway { public static function withChain($jobs) { return new Other; } public static function dispatch() { Job::dispatch(); } }');
        $result = $this->query();
        $this->assertSame([], $this->httpFlows($result));
        $this->assertSame('incomplete', $result['execution']['flow_analysis']['status']);
        $this->assertStringContainsString('Return type of custom withChain', json_encode($result['execution']['flow_analysis'], JSON_THROW_ON_ERROR));
    }

    public function test_inherited_listener_keeps_concrete_queue_contract_and_handler_declaration(): void
    {
        $this->fixture();
        $this->write('app/BaseListener.php', 'namespace App; class BaseListener { public function handle(Created $event) { Service::send(); } }');
        $this->write('app/Listener.php', 'namespace App; class Listener extends BaseListener implements \Illuminate\Contracts\Queue\ShouldQueue { public function shouldQueue(Created $event) { return false; } }');
        $this->assertSame([], $this->httpFlows($this->query()));
        $this->write('app/Listener.php', 'namespace App; class Listener extends BaseListener implements \Illuminate\Contracts\Queue\ShouldQueueAfterCommit {}');
        $rows = $this->httpFlows($this->query());
        $listeners = array_values(array_filter($rows[0]['via'], fn ($edge) => $edge['kind'] === 'event-listener'));
        $this->assertSame('App\BaseListener::handle', $listeners[0]['to']);
        $this->assertSame('queue-requested', $listeners[0]['mode']);
        $this->assertSame('after-commit', $listeners[0]['timing']);
        $this->write('app/Providers/Events.php', 'namespace App\Providers; class Events { public function boot() { \Illuminate\Support\Facades\Event::listen(\App\Created::class, [\App\Listener::class, "handle"]); } }');
        $this->assertNotEmpty($this->httpFlows($this->query()));
        $this->write('app/Providers/Events.php', 'namespace App\Providers; class Events { public function boot() { \Illuminate\Support\Facades\Event::listen(\App\Created::class, \App\Listener::handle(...)); } }');
        $this->assertNotEmpty($this->httpFlows($this->query()));
    }

    public function test_batch_callbacks_do_not_leak_into_nested_chain_and_keep_all_registrations(): void
    {
        $this->fixture();
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { \Illuminate\Support\Facades\Bus::batch([[new Job, new Job]])->then(fn () => Service::send())->then(fn () => Service::other())->dispatch(); } public function other() {} }');
        foreach (['Service::send', 'Service::other'] as $subject) {
            $rows = $this->httpFlows($this->query($subject));
            $all = json_encode($rows, JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('batch-then', $all);
            $this->assertStringNotContainsString('chain-then', $all);
        }
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { \Illuminate\Support\Facades\Bus::batch([new Job])->then(fn () => Service::other())->then(fn () => Service::other())->dispatch(); } public function other() {} }');
        $rows = $this->httpFlows($this->query('Service::other'));
        $this->assertCount(2, $rows);
        $targets = [];
        foreach ($rows as $row) {
            foreach ($row['via'] as $edge) {
                if ($edge['kind'] === 'batch-then') {
                    $targets[] = $edge['to'];
                }
            }
        }
        $this->assertCount(2, array_unique($targets));
    }

    public function test_after_commit_event_resets_quiet_only_on_deferred_variant(): void
    {
        $this->fixture();
        $this->write('app/Order.php', 'namespace App; #[\Illuminate\Database\Eloquent\Attributes\ObservedBy(Observer::class)] class Order extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Observer.php', 'namespace App; class Observer { public function created(Order $order) { Service::send(); } }');
        $this->write('app/Listener.php', 'namespace App; class Listener { public function handle(Created $event) { Order::create([]); } }');
        $this->write('app/Created.php', 'namespace App; class Created implements \Illuminate\Contracts\Events\ShouldDispatchAfterCommit {}');
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { Order::withoutEvents(fn () => event(new Created)); } public function other() {} }');
        $rows = $this->httpFlows($this->query());
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $dispatch = array_values(array_filter($row['via'], fn ($edge) => $edge['kind'] === 'event-dispatch'));
            $this->assertSame('after-commit', $dispatch[0]['timing']);
            $this->assertStringContainsString('Active transaction', implode(' ', $dispatch[0]['conditions']));
        }
        $this->write('app/Listener.php', 'namespace App; class Listener { public function handle(Created $event) { Service::other(); Order::create([]); } }');
        $immediate = $this->httpFlows($this->query('Service::other'));
        $timings = [];
        foreach ($immediate as $row) {
            foreach ($row['via'] as $edge) {
                if ($edge['kind'] === 'event-dispatch') {
                    $timings[] = $edge['timing'];
                }
            }
        }
        $this->assertContains('immediate', $timings);
        $this->assertContains('after-commit', $timings);
        $this->write('app/Created.php', 'namespace App; class Created {}');
        $this->assertSame([], $this->httpFlows($this->query()));
    }

    public function test_composer_configuration_changed_after_read_is_stale(): void
    {
        $this->fixture();
        file_put_contents($this->tempPath.'/composer.json', json_encode(['autoload' => ['psr-4' => ['Extra\\' => 'extra/']]], JSON_THROW_ON_ERROR));
        $files = new class extends Filesystem
        {
            public function get($path, $lock = false)
            {
                $contents = parent::get($path, $lock);
                if (str_ends_with($path, '/composer.json')) {
                    file_put_contents($path, json_encode(['autoload' => ['psr-4' => ['Changed\\' => 'other-directory/']]], JSON_THROW_ON_ERROR));
                    clearstatcache();
                }

                return $contents;
            }
        };
        $sources = new ExecutionSources($files, $this->tempPath);
        $sources->discover(new ProjectGraphSnapshot([], []), []);
        $this->assertFalse($sources->fresh());
    }

    public function test_reachable_unknown_call_without_execution_registration_is_incomplete(): void
    {
        $this->fixture();
        $this->write('app/Providers/Events.php', 'namespace App\Providers; class Events {}');
        $this->write('bootstrap/app.php', 'return [];');
        $this->write('app/Listener.php', 'namespace App; class Listener {}');
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store($unknown) { $unknown->launchWork(); } public function other() {} }');
        // Explicit route source remains available even without an application builder.
        $result = $this->query();
        $this->assertSame('incomplete', $result['execution']['flow_analysis']['status']);
        $this->assertStringContainsString('launchWork', json_encode($result['execution']['flow_analysis'], JSON_THROW_ON_ERROR));
    }
}
