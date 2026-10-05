<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\ArchitecturePath;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ExplainFinding;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Path;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class ArchitecturePathTest extends TestCase
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
        $this->write('app/A.php', 'namespace App; class A { public static function run() { B::run(); C::run(); } public function untouched() {} }');
        $this->write('app/B.php', 'namespace App; class B { public static function run() { D::run(); } }');
        $this->write('app/C.php', 'namespace App; class C { public static function run() { D::run(); } }');
        $this->write('app/D.php', 'namespace App; class D { public static function run() {} }');
        $this->write('app/Isolated.php', 'namespace App; class Isolated { public static function run() {} }');
    }

    private function query(string $from = 'A::run', string $to = 'D::run', int $limit = 20, int $depth = 8, array $exclude = []): array
    {
        return (new ArchitecturePath(new Filesystem, $this->tempPath, new AuditScope))->inspect($from, $to, $exclude, $limit, $depth);
    }

    public function test_all_nine_endpoint_combinations_keep_multiple_paths_and_locations(): void
    {
        $this->fixture();
        foreach (['App\\A', 'A::run', 'app/A.php'] as $from) {
            foreach (['App\\D', 'D::run', 'app/D.php'] as $to) {
                $r = $this->query($from, $to);
                $this->assertTrue($r['ok'], json_encode($r));
                foreach (['dependencies', 'execution'] as $channel) {
                    $this->assertCount(2, $r[$channel]['paths'], json_encode($r));
                    $this->assertTrue($r[$channel]['fresh']);
                    foreach ($r[$channel]['paths'] as $path) {
                        $this->assertCount(2, $path['via']);
                        $this->assertCount(3, $path['nodes']);
                        foreach ($path['nodes'] as $node) {
                            $this->assertNotNull($node['location']);
                        }
                        foreach ($path['via'] as $edge) {
                            $this->assertArrayHasKey('line', $edge);
                            $this->assertArrayHasKey('conditions', $edge);
                            $this->assertArrayNotHasKey('cost', $edge);
                        }
                    }
                }
            }
        }
    }

    public function test_dependency_is_not_a_call_and_class_projection_is_explicit(): void
    {
        $this->fixture();
        $this->write('app/A.php', 'namespace App; class A { public function run(D $value) {} }');
        $r = $this->query();
        $this->assertNotEmpty($r['dependencies']['paths']);
        $this->assertSame([], $r['execution']['paths']);
        $this->assertSame('no_path', $r['execution']['status']);
        $this->assertSame(['App\\A'], $r['from']['structural']);
        $this->assertArrayHasKey('strength', $r['dependencies']['paths'][0]['via'][0]);
    }

    public function test_unknown_calls_are_not_no_path_and_limits_are_explicit(): void
    {
        $this->fixture();
        $this->write('app/A.php', 'namespace App; class A { public static function run($receiver) { $receiver->send(); } }');
        $r = $this->query();
        $this->assertSame('incomplete', $r['execution']['status']);
        $this->assertFalse($r['execution']['found']);
        $this->assertNotEmpty($r['execution']['notices']);
        $this->fixture();
        foreach ([$this->query(limit: 1), $this->query(depth: 1), $this->query(limit: 0)] as $r) {
            $this->assertSame('limit', $r['execution']['status']);
            $this->assertTrue($r['execution']['limited']);
        }
        $this->assertSame([], $this->query(limit: 0)['execution']['paths']);
        $this->assertTrue($this->query(limit: 0)['execution']['found']);
    }

    public function test_external_calls_stop_without_reading_vendor(): void
    {
        $this->fixture();
        $this->write('app/A.php', 'namespace App; class A { public static function run(\\Stripe\\Client $client) { $client->request(); } }');
        $this->write('vendor/stripe/Client.php', 'namespace Stripe; class Client { public function request() { \\App\\D::run(); } }');
        $r = $this->query();
        $this->assertFalse($r['execution']['found']);
        $this->assertCount(1, $r['execution']['external_boundaries']);
        $edge = $r['execution']['external_boundaries'][0]['via'][0];
        $this->assertSame('Stripe\\Client::request', $edge['to']);
        $this->assertTrue($edge['external']);
        $this->assertSame('possible', $edge['certainty']);
        $r = $this->query(to: 'Stripe\\Client::request');
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['to']['external']);
        $this->assertCount(1, $r['execution']['paths']);
        $this->assertNull($r['execution']['paths'][0]['nodes'][1]['location']);
        $this->assertFalse($this->query(to: 'vendor/stripe/Client.php')['ok']);
    }

    public function test_ambiguity_errors_inherited_method_and_multiple_classes_per_file(): void
    {
        $this->fixture();
        $this->write('app/Other/A.php', 'namespace App\\Other; class A {}');
        $r = $this->query();
        $this->assertSame('E_PATH_ENDPOINT_AMBIGUOUS', $r['m']);
        $this->assertCount(2, $r['candidates']);
        $this->assertSame('from', $r['endpoint']);
        $this->write('app/Child.php', 'namespace App; class Child extends B {} class Another { public static function other() { D::run(); } }');
        $this->assertSame('App\\B::run', $this->query('Child::run')['from']['execution'][0]);
        $this->assertCount(2, $this->query('app/Child.php')['execution']['paths']);
        $this->assertSame('E_PATH_METHOD_UNRESOLVED', $this->query('Child::absent')['m']);
        $this->assertFalse($this->query('app/Child.php', exclude: ['app/Child.php'])['ok']);
        $this->assertFalse($this->query('', '')['ok']);
        $this->assertFalse($this->query(limit: 501)['ok']);
        $this->assertFalse($this->query(depth: 0)['ok']);
    }

    public function test_http_console_schedule_job_and_callback_file_paths(): void
    {
        $this->fixture();
        $this->write('app/Job.php', 'namespace App; class Job implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Foundation\\Bus\\Dispatchable; public function handle() { D::run(); } }');
        $this->write('app/Console/Commands/Send.php', 'namespace App\\Console\\Commands; class Send extends \\Illuminate\\Console\\Command { protected $signature = "send"; public function handle() { \\App\\Job::dispatch(); } }');
        $this->write('app/A.php', 'namespace App; class A { public static function run() { \\Illuminate\\Support\\Facades\\Artisan::queue("send"); } }');
        $this->write('bootstrap/app.php', 'return \Illuminate\Foundation\Application::configure()->withRouting(web: base_path("routes/web.php"), commands: base_path("routes/console.php"));');
        $this->write('routes/web.php', '\\Illuminate\\Support\\Facades\\Route::get("send", [App\\A::class, "run"]);');
        $this->write('routes/console.php', '\\Illuminate\\Support\\Facades\\Schedule::command("send")->daily()->timezone("UTC"); \\Illuminate\\Support\\Facades\\Artisan::command("closure", function () { App\\D::run(); });');
        $r = $this->query();
        $this->assertNotEmpty($r['execution']['paths'], json_encode($r));
        $kinds = array_column($r['execution']['paths'][0]['via'], 'kind');
        $this->assertContains('artisan-command', $kinds);
        $this->assertContains('job-handler', $kinds);
        $this->assertSame('queue-requested', $r['execution']['paths'][0]['via'][0]['mode']);
        $r = $this->query('routes/web.php');
        $this->assertContains('http-handler', array_column($r['execution']['paths'][0]['via'], 'kind'));
        $r = $this->query('routes/console.php');
        $this->assertNotEmpty($r['execution']['paths']);
        $this->assertContains('schedule-task', array_column(array_merge(...array_column($r['execution']['paths'], 'via')), 'kind'));
        $this->assertTrue($this->query('app/Console/Commands/Send.php')['execution']['found']);
    }

    public function test_event_and_model_paths_preserve_quiet_context_and_deferred_reset(): void
    {
        $this->fixture();
        $this->write('app/Order.php', 'namespace App; #[\Illuminate\Database\Eloquent\Attributes\ObservedBy(Observer::class)] class Order extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Observer.php', 'namespace App; class Observer { public function created(Order $order) { D::run(); } }');
        $this->write('app/Writer.php', 'namespace App; class Writer { public static function persist() { Order::create([]); } }');
        $this->write('app/A.php', 'namespace App; class A { public static function run() { Order::withoutEvents(fn () => Writer::persist()); Writer::persist(); } public static function quiet() { Order::withoutEvents(fn () => Writer::persist()); } }');
        $r = $this->query();
        $this->assertTrue($r['execution']['found']);
        foreach ($r['execution']['paths'] as $path) {
            $this->assertNotContains('without-events', array_column($path['via'], 'kind'));
        }
        $this->assertFalse($this->query('A::quiet')['execution']['found']);
        $this->write('app/Job.php', 'namespace App; class Job implements \Illuminate\Contracts\Queue\ShouldQueue { use \Illuminate\Foundation\Bus\Dispatchable; public function handle() { Writer::persist(); } }');
        $this->write('app/A.php', 'namespace App; class A { public static function run() { Order::withoutEvents(fn () => Job::dispatch()); } }');
        $r = $this->query(depth: 16);
        $this->assertTrue($r['execution']['found'], json_encode($r));
        $this->assertContains('without-events', array_column($r['execution']['paths'][0]['via'], 'kind'));
        $this->assertContains(true, array_column($r['execution']['paths'][0]['via'], 'reset_quiet'));
        $this->write('app/Created.php', 'namespace App; class Created { use \Illuminate\Foundation\Events\Dispatchable; }');
        $this->write('app/Listener.php', 'namespace App; class Listener { public function handle(Created $event) { D::run(); } }');
        $this->write('app/Providers/Events.php', 'namespace App\Providers; class Events extends \Illuminate\Foundation\Support\Providers\EventServiceProvider { protected $listen = [\App\Created::class => [\App\Listener::class]]; }');
        $this->write('app/A.php', 'namespace App; class A { public static function run() { event(new Created); } }');
        $r = $this->query();
        $this->assertTrue($r['execution']['found']);
        $this->assertContains('event-dispatch', array_column($r['execution']['paths'][0]['via'], 'kind'));
        $this->assertContains('event-listener', array_column($r['execution']['paths'][0]['via'], 'kind'));
    }

    public function test_construction_and_uninvoked_callback_do_not_execute_handlers(): void
    {
        $this->fixture();
        $this->write('app/Job.php', 'namespace App; class Job implements \Illuminate\Contracts\Queue\ShouldQueue { public function handle() { D::run(); } }');
        $this->write('app/A.php', 'namespace App; class A { public static function run() { new Job; $callback = fn () => D::run(); } }');
        $this->assertFalse($this->query()['execution']['found']);
        $this->assertTrue($this->query('Job::handle')['execution']['found']);
        $this->assertTrue($this->query('app/A.php')['execution']['found']);
    }

    public function test_cycle_and_identity_are_finite_and_do_not_drop_other_paths(): void
    {
        $this->fixture();
        $this->write('app/B.php', 'namespace App; class B { public static function run() { A::run(); D::run(); } }');
        $r = $this->query();
        $this->assertCount(2, $r['execution']['paths']);
        $this->assertLessThan(20, $r['execution']['edge_visits']);
        $r = $this->query('D::run', 'D::run');
        $this->assertTrue($r['execution']['found']);
        $this->assertSame([], $r['execution']['paths'][0]['via']);
        $this->assertCount(1, $r['execution']['paths'][0]['nodes']);
    }

    public function test_missing_and_unparseable_sources_and_trait_adaptation_are_visible(): void
    {
        $this->fixture();
        $this->write('routes/console.php', 'require base_path("missing.php");');
        $this->write('app/Broken.php', 'class {');
        $r = $this->query('A::run', 'Isolated::run');
        $this->assertSame('incomplete', $r['execution']['status']);
        $this->assertStringContainsString('missing', json_encode($r['execution']['notices']));
        $this->assertStringContainsString('Unparseable', json_encode($r['execution']['notices']));
        $this->write('app/T.php', 'namespace App; trait T { public function run() { D::run(); } }');
        $this->write('app/Adapted.php', 'namespace App; class Adapted { use T { run as other; } }');
        $this->assertSame('E_PATH_METHOD_UNRESOLVED', $this->query('Adapted::other')['m']);
        $this->assertFalse($this->query('Adapted')['execution']['found']);
    }

    public function test_source_edits_during_query_are_stale_and_added_files_change_signature(): void
    {
        $this->fixture();
        $before = $this->query();
        $this->write('routes/extra.php', 'App\D::run();');
        $this->assertNotSame($before['analysis']['source_signature'], $this->query()['analysis']['source_signature']);
        $files = new class($this->tempPath) extends Filesystem
        {
            private bool $changed = false;

            public function __construct(private string $root) {}

            public function get($path, $lock = false)
            {
                $contents = parent::get($path, $lock);
                if (! $this->changed && str_ends_with($path, '/routes/extra.php')) {
                    $this->changed = true;
                    parent::put($this->root.'/app/A.php', '<?php namespace App; class A { public static function run() {} }');
                    clearstatcache();
                }

                return $contents;
            }
        };
        $r = (new ArchitecturePath($files, $this->tempPath))->inspect('A::run', 'D::run');
        $this->assertFalse($r['analysis']['fresh']);
        $this->assertSame('stale', $r['execution']['status']);
        $this->assertSame('stale', $r['dependencies']['status']);
    }

    public function test_layer_explanation_matches_exact_direct_edge_and_keeps_old_shape(): void
    {
        $this->fixture();
        $this->write('app/Http/Controllers/Outer.php', 'namespace App\Http\Controllers; class Outer { public static function send() {} }');
        $this->write('app/Actions/Inner.php', "namespace App\\Actions; class Inner {\n public function run() { \\App\\Http\\Controllers\\Outer::send(); }\n}");
        $this->assertSame(0, Artisan::call('architecture-kit:explain', ['code' => 'E_LAYER_DEPENDENCY', '--path' => 'app/Actions/Inner.php', '--line' => 2, '--agent' => true]));
        $r = json_decode(trim(Artisan::output()), true);
        $context = $r['occurrence']['dependency'];
        $this->assertSame('matched', $context['status']);
        $this->assertCount(1, $context['reported_edges']);
        $this->assertSame('App\\Actions\\Inner', $context['reported_edges'][0]['from']);
        $this->assertSame('App\\Http\\Controllers\\Outer', $context['reported_edges'][0]['to']);
        $this->assertSame(2, $context['reported_edges'][0]['line']);
        $this->assertNotEmpty($context['context_paths']);
        ArchitectureKitServer::tool(ExplainFinding::class, ['code' => 'E_LAYER_DEPENDENCY', 'path' => 'app/Actions/Inner.php', 'line' => 2])->assertSee('reported_edges')->assertSee('matched');
        Artisan::call('architecture-kit:explain', ['code' => 'E_LAYER_DEPENDENCY', '--path' => 'app/Actions/Inner.php', '--line' => 1, '--agent' => true]);
        $this->assertSame('unresolved', json_decode(trim(Artisan::output()), true)['occurrence']['dependency']['status']);
        Artisan::call('architecture-kit:explain', ['code' => 'E_LAYER_DEPENDENCY', '--agent' => true]);
        $this->assertArrayNotHasKey('occurrence', json_decode(trim(Artisan::output()), true));
    }

    public function test_first_class_function_callable_does_not_crash_or_execute_body(): void
    {
        $this->fixture();
        $this->write('app/A.php', 'namespace App; class A { public static function run() { $fn = strlen(...); $call = D::run(...); } }');
        $r = $this->query();
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['execution']['found']);
    }

    public function test_classless_file_has_execution_and_explicit_structural_boundary(): void
    {
        $this->fixture();
        $this->write('routes/script.php', 'App\D::run();');
        $r = $this->query('routes/script.php');
        $this->assertTrue($r['execution']['found']);
        $this->assertSame('incomplete', $r['dependencies']['status']);
        $this->assertNotEmpty($r['dependencies']['notices']);
    }

    public function test_same_line_calls_are_distinct_and_conditional_calls_keep_certainty(): void
    {
        $this->fixture();
        $this->write('app/A.php', 'namespace App; class A { public static function run($flag) { if ($flag) { D::run(); D::run(); } } }');
        $r = $this->query();
        $this->assertCount(2, $r['execution']['paths']);
        $this->assertNotSame($r['execution']['paths'][0]['via'][0]['offset'], $r['execution']['paths'][1]['via'][0]['offset']);
        $this->assertSame('possible', $r['execution']['paths'][0]['certainty']);
        $this->assertNotEmpty($r['execution']['paths'][0]['via'][0]['conditions']);
    }

    public function test_layer_explanation_does_not_guess_between_same_line_violations(): void
    {
        $this->fixture();
        $this->write('app/Http/Controllers/Outer.php', 'namespace App\Http\Controllers; class Outer { public static function send() {} }');
        $this->write('app/Http/Controllers/Other.php', 'namespace App\Http\Controllers; class Other { public static function send() {} }');
        $this->write('app/Actions/Inner.php', 'namespace App\Actions; class Inner { public function run() { \App\Http\Controllers\Outer::send(); \App\Http\Controllers\Other::send(); } }');
        Artisan::call('architecture-kit:explain', ['code' => 'E_LAYER_DEPENDENCY', '--path' => 'app/Actions/Inner.php', '--line' => 1, '--agent' => true]);
        $r = json_decode(trim(Artisan::output()), true);
        $this->assertCount(2, $r['occurrence']['dependency']['reported_edges']);
        Artisan::call('architecture-kit:explain', ['code' => 'E_LAYER_DEPENDENCY', '--path' => 'app/Actions/Inner.php', '--agent' => true]);
        $r = json_decode(trim(Artisan::output()), true);
        $this->assertSame('unresolved', $r['occurrence']['dependency']['status']);
    }

    public function test_vendor_is_unread_even_when_audit_scope_includes_it(): void
    {
        $this->fixture();
        $this->write('vendor/example/Remote.php', 'namespace Remote; class Client { public function send() { \App\D::run(); } }');
        $this->write('app/A.php', 'namespace App; class A { public static function run(\Remote\Client $client) { $client->send(); } }');
        $files = new class extends Filesystem
        {
            public function get($path, $lock = false)
            {
                if (str_contains($path, '/vendor/')) {
                    throw new \RuntimeException('Vendor source must not be read.');
                }

                return parent::get($path, $lock);
            }
        };
        $r = (new ArchitecturePath($files, $this->tempPath, new AuditScope(['app', 'vendor'])))->inspect('A::run', 'D::run');
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['execution']['found']);
        $this->assertNotEmpty($r['execution']['external_boundaries']);
    }

    public function test_structural_notices_have_paths_in_json_and_text_cli(): void
    {
        $this->fixture();
        $this->write('app/Broken.php', 'class {');
        $this->write('app/A.php', 'namespace App; class A { public static function run() { $closure = fn () => D::run(); } }');
        $r = $this->query();
        $this->assertNotEmpty($r['dependencies']['notices']);
        foreach ($r['dependencies']['notices'] as $notice) {
            $this->assertArrayHasKey('path', $notice);
            $this->assertStringStartsWith('app/', $notice['path']);
        }
        $this->assertSame(0, Artisan::call('architecture-kit:path', ['from' => 'A::run', 'to' => 'D::run']));
        $this->assertStringContainsString('app/Broken.php', Artisan::output());
        $this->assertStringNotContainsString('Undefined array key', Artisan::output());
    }

    public function test_unresolved_model_methods_are_visible_but_supported_lifecycle_keeps_semantics(): void
    {
        $this->fixture();
        $this->write('app/Order.php', 'namespace App; class Order extends \Illuminate\Database\Eloquent\Model { public function send() { D::run(); } }');
        $this->write('app/A.php', 'namespace App; class A { public static function run(Order $order, string $method) { $order->$method(); } }');
        $r = $this->query();
        $this->assertFalse($r['execution']['found']);
        $this->assertSame('incomplete', $r['execution']['status']);
        $this->assertStringContainsString('model receiver/method is unresolved', $r['execution']['notices'][0]['reason']);
        $old = (new ArchitectureImpact(new Filesystem, $this->tempPath))->inspect('D::run');
        $this->assertSame([], $old['execution']['flow_analysis']['unresolved']);
        $this->write('app/A.php', 'namespace App; class A { public static function run(Order $order) { $order->customMacro(); } }');
        $this->assertSame('incomplete', $this->query()['execution']['status']);
        $this->write('app/A.php', 'namespace App; class A { public static function run(Order $order) { $order->saveQuietly(); Order::create([]); } }');
        $this->assertSame('no_path', $this->query()['execution']['status']);
        $this->write('app/Order.php', 'namespace App; #[\Illuminate\Database\Eloquent\Attributes\ObservedBy(Observer::class)] class Order extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Observer.php', 'namespace App; class Observer { public function created(Order $order) { D::run(); } }');
        $r = $this->query();
        $this->assertTrue($r['execution']['found']);
        $this->assertContains('model-event', array_column($r['execution']['paths'][0]['via'], 'kind'));
    }

    public function test_cli_mcp_and_schema_agree_and_bad_input_is_rejected(): void
    {
        $this->fixture();
        $this->assertSame(0, Artisan::call('architecture-kit:path', ['from' => 'A::run', 'to' => 'D::run', '--agent' => true]));
        $cli = json_decode(trim(Artisan::output()), true);
        ArchitectureKitServer::tool(Path::class, ['from' => 'A::run', 'to' => 'D::run'])->assertOk()->assertStructuredContent(fn ($json) => $json->where('execution', $cli['execution'])->where('dependencies', $cli['dependencies'])->etc());
        $this->assertTrue($cli['execution']['found']);
        $this->assertSame(0, Artisan::call('architecture-kit:path', ['--schema' => true]));
        $schema = json_decode(trim(Artisan::output()), true);
        $this->assertSame('path', $schema['oneOf'][0]['properties']['cmd']['const']);
        $this->assertSame(1, Artisan::call('architecture-kit:path', ['from' => 'A', 'to' => 'D', '--limit' => '-1', '--agent' => true]));
        ArchitectureKitServer::tool(Path::class, ['from' => [], 'to' => 'D'])->assertSee('E_INVALID_TOOL_INPUT');
    }
}
