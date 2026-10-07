<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\ExecutionSources;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Impact;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class ArchitectureConsoleScheduleTest extends TestCase
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
        $this->write('app/Service.php', 'namespace App; class Service { public static function send() {} public static function filter() {} public static function after() {} public static function other() {} }');
        $this->write('app/Job.php', 'namespace App; class Job implements \Illuminate\Contracts\Queue\ShouldQueue { use \Illuminate\Foundation\Bus\Dispatchable; public function handle() { Service::send(); } }');
        $this->write('app/Console/Commands/Send.php', 'namespace App\Console\Commands; class Send extends \Illuminate\Console\Command { protected $signature = "invoices:send {id?}"; protected $aliases = ["send:alias"]; public function handle() { \App\Job::dispatch(); } }');
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { \Illuminate\Support\Facades\Artisan::queue("invoices:send"); } public function other() { new Job; } }');
        $this->write('bootstrap/app.php', 'return \Illuminate\Foundation\Application::configure()->withRouting(web: base_path("routes/web.php"), commands: base_path("routes/console.php"));');
        $this->write('routes/web.php', '\Illuminate\Support\Facades\Route::get("send", [App\Controller::class, "store"]); \Illuminate\Support\Facades\Route::get("other", [App\Controller::class, "other"]);');
        $this->write('routes/console.php', '\Illuminate\Support\Facades\Schedule::command("invoices:send --id=4")->daily()->timezone("Europe/Warsaw")->withoutOverlapping()->onOneServer();');
    }

    private function inspectImpact(string $subject = 'Service::send', int $limit = 100, int $depth = 16): array
    {
        $result = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect($subject, [], $limit, $depth);
        $this->assertTrue($result['ok'], json_encode($result));

        return $result;
    }

    private function flows(string $kind, string $subject = 'Service::send'): array
    {
        return array_values(array_filter($this->inspectImpact($subject)['execution']['flows'], fn ($row) => $row['entry']['kind'] === $kind));
    }

    public function test_console_http_and_scheduler_reach_method_class_and_file(): void
    {
        $this->fixture();
        foreach (['Service::send', 'Service', 'app/Service.php'] as $subject) {
            $this->assertNotEmpty($this->flows('console', $subject));
            $rows = $this->flows('schedule', $subject);
            $this->assertNotEmpty($rows);
            $this->assertContains('artisan-command', array_column($rows[0]['via'], 'kind'));
            $this->assertContains('job-handler', array_column($rows[0]['via'], 'kind'));
            $this->assertSame(['Europe/Warsaw'], $rows[0]['entry']['schedule']['timezone']);
            $this->assertTrue($rows[0]['entry']['schedule']['daily']);
            $http = $this->flows('http', $subject);
            $this->assertNotEmpty($http);
            $this->assertSame('/send', $http[0]['entry']['route']['uri']);
            $edge = array_values(array_filter($http[0]['via'], fn ($e) => $e['kind'] === 'artisan-command'))[0];
            $this->assertSame('queue-requested', $edge['mode']);
        }
        $this->assertSame([], $this->inspectImpact('Service::other')['execution']['flows']);
    }

    public function test_closure_command_binding_alias_and_silent_command_calls(): void
    {
        $this->fixture();
        $this->write('routes/console.php', '\Illuminate\Support\Facades\Artisan::command("wrap {id?}", function () { $this->callSilent("send:alias"); $this->callSilently(App\Console\Commands\Send::class); })->aliases(["wrapper"]); \Illuminate\Support\Facades\Schedule::command("wrapper")->hourly();');
        $rows = $this->flows('schedule');
        $this->assertNotEmpty($rows);
        $this->assertSame(2, count($rows));
        $this->assertContains('wrapper', array_column(array_merge(...array_column($rows, 'via')), 'command'));
        $this->assertSame(2, count(array_filter($rows[0]['via'], fn ($e) => $e['kind'] === 'artisan-command')));
    }

    public function test_inherited_handle_and_invoke_explicit_registrations_and_custom_directory(): void
    {
        $this->fixture();
        $this->write('app/Base.php', 'namespace App; abstract class Base extends \Illuminate\Console\Command { public function __invoke() { Service::send(); } }');
        $this->write('custom/Tasks/Invoke.php', 'namespace Custom; class Invoke extends \App\Base { protected $name = "invoke"; }');
        $this->write('app/Unregistered.php', 'namespace App; class Unregistered extends Base { protected $name = "unregistered"; }');
        $this->write('bootstrap/app.php', 'return \Illuminate\Foundation\Application::configure()->withCommands([base_path("custom/Tasks")])->withSchedule(function ($schedule) { $schedule->command("invoke")->everyMinute(); });');
        $rows = $this->flows('schedule');
        $this->assertNotEmpty($rows);
        $this->assertContains('App\Base::__invoke', array_column($rows[0]['via'], 'to'));
        $this->assertNotContains('unregistered', array_column(array_column($this->flows('console'), 'entry'), 'command'));
    }

    public function test_scheduler_groups_filters_and_callbacks_are_siblings_of_worker(): void
    {
        $this->fixture();
        $this->write('routes/console.php', '\Illuminate\Support\Facades\Schedule::daily()->timezone("UTC")->group(function () { \Illuminate\Support\Facades\Schedule::job(new App\Job, "billing", "redis")->when(fn () => App\Service::filter())->skip(fn () => App\Service::filter())->before(fn () => App\Service::after())->onSuccess(fn () => App\Service::after())->onFailure(fn () => App\Service::after())->after(fn () => App\Service::after())->then(fn () => App\Service::after()); });');
        $rows = $this->flows('schedule');
        $this->assertNotEmpty($rows);
        $this->assertSame(['UTC'], $rows[0]['entry']['schedule']['timezone']);
        $job = array_values(array_filter($rows[0]['via'], fn ($e) => $e['kind'] === 'job-handler'))[0];
        $this->assertSame('billing', $job['queue']);
        $this->assertSame('redis', $job['connection']);
        $callbacks = $this->flows('schedule', 'Service::after');
        $this->assertCount(5, $callbacks);
        foreach ($callbacks as $row) {
            $this->assertNotContains('job-handler', array_column($row['via'], 'kind'));
        }
        $all = json_encode($callbacks, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('not Job::handle success', $all);
        $filters = $this->flows('schedule', 'Service::filter');
        $this->assertCount(2, $filters);
        $this->assertStringContainsString('preceding when', json_encode($filters));
    }

    public function test_false_when_and_true_skip_short_circuit_filters_and_task(): void
    {
        $this->fixture();
        foreach (['when(false)->when(fn () => App\Service::filter())', 'skip(true)->skip(fn () => App\Service::filter())'] as $filters) {
            $this->write('routes/console.php', '\Illuminate\Support\Facades\Schedule::job(new App\Job)->'.$filters.'->onSuccess(fn () => App\Service::after());');
            $this->assertSame([], $this->flows('schedule'));
            $this->assertSame([], $this->flows('schedule', 'Service::filter'));
            $this->assertSame([], $this->flows('schedule', 'Service::after'));
        }
        $this->write('routes/console.php', '\Illuminate\Support\Facades\Schedule::job(new App\Job)->when(fn () => App\Service::filter())->skip(true);');
        $this->assertCount(1, $this->flows('schedule', 'Service::filter'));
        $this->assertSame([], $this->flows('schedule'));
    }

    public function test_lookalikes_construction_and_arbitrary_callbacks_do_not_invoke_commands(): void
    {
        $this->fixture();
        $this->write('routes/console.php', '');
        $this->write('app/Gateway.php', 'namespace App; class Gateway { public static function call($name) {} public static function queue($name) {} public static function command($name) {} }');
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { Gateway::call("invoices:send"); Gateway::queue("invoices:send"); Gateway::command("invoices:send"); new \App\Console\Commands\Send; $callback = fn () => \Illuminate\Support\Facades\Artisan::call("invoices:send"); } public function other() { \Illuminate\Support\Facades\Artisan::callSilent("invoices:send"); } }');
        $this->assertSame([], $this->flows('http'));
    }

    public function test_dynamic_conflicts_missing_and_invalid_background_are_visible_without_paths(): void
    {
        $this->fixture();
        $this->write('routes/console.php', '\Illuminate\Support\Facades\Schedule::call(fn () => App\Service::send())->runInBackground(); \Illuminate\Support\Facades\Schedule::command($unknown); \Illuminate\Support\Facades\Artisan::command("conflict", fn () => App\Service::send()); \Illuminate\Support\Facades\Artisan::command("conflict", fn () => App\Service::other());');
        $this->write('bootstrap/app.php', 'return \Illuminate\Foundation\Application::configure()->withCommands([base_path("missing/commands.php")]);');
        $this->assertSame([], $this->flows('schedule'));
        $analysis = $this->inspectImpact('Service::after')['execution']['flow_analysis'];
        $this->assertSame('incomplete', $analysis['status']);
        $notices = json_encode($analysis, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('runInBackground is invalid', $notices);
        $this->assertStringContainsString('Dynamic Artisan', $notices);
        $this->assertStringContainsString('registration conflict', $notices);
        $this->assertStringContainsString('input is missing', $notices);
    }

    public function test_legacy_kernel_load_command_closures_and_explicit_provider_registration(): void
    {
        $this->fixture();
        $this->write('bootstrap/app.php', '');
        $this->write('app/Explicit.php', 'namespace App; class Explicit extends \Illuminate\Console\Command { protected $name = "explicit"; public function handle() { Service::send(); } public function __invoke() { Service::other(); } }');
        $this->write('legacy/commands/Loaded.php', 'namespace Legacy; class Loaded extends \Illuminate\Console\Command { protected $name = "loaded"; public function handle() { \App\Service::send(); } }');
        $this->write('app/Console/Kernel.php', 'namespace App\Console; class Kernel extends \Illuminate\Foundation\Console\Kernel { protected $commands = [\App\Explicit::class]; protected function commands() { $this->load(base_path("legacy/commands")); require base_path("legacy/register.php"); $this->command("kernel:wrap", function () { $this->call("loaded"); }); } protected function schedule(\Illuminate\Console\Scheduling\Schedule $schedule) { $schedule->command("kernel:wrap")->weekly(); $schedule->command("explicit"); } }');
        $this->write('legacy/register.php', '\Illuminate\Support\Facades\Artisan::command("custom:file", fn () => App\Service::send());');
        $this->write('app/Providers/ConsoleProvider.php', 'namespace App\Providers; class ConsoleProvider extends \Illuminate\Support\ServiceProvider { public function boot() { $this->commands([\App\Explicit::class]); \Illuminate\Support\Facades\Artisan::addCommands([\App\Explicit::class]); } }');
        $this->write('routes/console.php', '');
        $this->assertCount(2, $this->flows('schedule'));
        $this->assertContains('custom:file', array_column(array_column($this->flows('console'), 'entry'), 'command'));
        $this->assertSame([], $this->flows('schedule', 'Service::other'));
    }

    public function test_all_artisan_invocation_forms_and_overridden_calls_commands(): void
    {
        $this->fixture();
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { \Illuminate\Support\Facades\Artisan::call(\App\Console\Commands\Send::class); \Illuminate\Support\Facades\Artisan::call(new \App\Console\Commands\Send); } public function other() {} }');
        $this->assertCount(2, $this->flows('http'));
        foreach ($this->flows('http') as $row) {
            $this->assertContains('synchronous', array_column($row['via'], 'mode'));
        }
        $this->write('app/Console/Commands/Wrap.php', 'namespace App\Console\Commands; class Wrap extends \Illuminate\Console\Command { protected $signature = "wrap"; public function handle() { $this->call("invoices:send"); $this->callSilent("invoices:send"); $this->callSilently("invoices:send"); } }');
        $this->write('routes/console.php', '\Illuminate\Support\Facades\Schedule::command("wrap");');
        $this->assertCount(3, $this->flows('schedule'));
        $this->write('app/Console/Commands/Wrap.php', 'namespace App\Console\Commands; class Wrap extends \Illuminate\Console\Command { protected $signature = "wrap"; public function handle() { $this->call("invoices:send"); } public function call($command, array $arguments = []) { \App\Service::other(); } }');
        $this->assertSame([], $this->flows('schedule'));
        $this->assertCount(1, $this->flows('schedule', 'Service::other'));
    }

    public function test_schedule_call_array_reference_invokable_and_background_command(): void
    {
        $this->fixture();
        $this->write('app/CallableTask.php', 'namespace App; class CallableTask { public function __invoke() { Service::send(); } public static function run() { Service::send(); } public function handle() { Service::other(); } }');
        $this->write('routes/console.php', 'use Illuminate\Support\Facades\Schedule; Schedule::call([App\CallableTask::class, "run"]); Schedule::call(App\CallableTask::run(...)); Schedule::call(new App\CallableTask); Schedule::call(App\CallableTask::class); Schedule::command("invoices:send")->runInBackground()->onSuccess(fn () => App\Service::after());');
        $rows = $this->flows('schedule');
        $this->assertCount(5, $rows);
        $this->assertSame([], $this->flows('schedule', 'Service::other'));
        $after = $this->flows('schedule', 'Service::after');
        $this->assertCount(1, $after);
        $this->assertContains('background-finish', array_column($after[0]['via'], 'mode'));
        $this->assertNotContains('job-handler', array_column($after[0]['via'], 'kind'));
    }

    public function test_nested_groups_injected_parameter_pending_attributes_and_separate_mutations(): void
    {
        $this->fixture();
        $this->write('routes/console.php', 'use Illuminate\Support\Facades\Schedule; Schedule::daily()->timezone("UTC")->group(function ($schedule) { $schedule->hourly()->group(function ($schedule) { $schedule->job(new App\Job); }); }); Schedule::timezone("Europe/Warsaw"); $event = Schedule::job(new App\Job); $event->before(fn () => App\Service::after()); $event->after(fn () => App\Service::after()); Schedule::job(new App\Job);');
        $rows = $this->flows('schedule');
        $this->assertCount(3, $rows);
        $options = array_column(array_column($rows, 'entry'), 'schedule');
        $this->assertSame(['UTC'], $options[0]['timezone']);
        $this->assertTrue($options[0]['hourly']);
        $this->assertSame(['Europe/Warsaw'], $options[1]['timezone']);
        $this->assertArrayNotHasKey('timezone', $options[2]);
        $this->assertCount(2, $this->flows('schedule', 'Service::after'));
    }

    public function test_unactivated_scheduling_callback_does_not_create_execution_path(): void
    {
        $this->fixture();
        $this->write('routes/console.php', '$unused = fn () => \Illuminate\Support\Facades\Schedule::job(new App\Job); $unusedCommand = fn () => \Illuminate\Support\Facades\Artisan::command("unused", fn () => App\Service::send());');
        $this->assertSame([], $this->flows('schedule'));
        $this->assertNotContains('unused', array_column(array_column($this->flows('console'), 'entry'), 'command'));
    }

    public function test_sources_freshness_missing_custom_files_additions_and_no_application_execution(): void
    {
        $this->fixture();
        $this->write('bootstrap/app.php', 'throw new \RuntimeException("never run application"); return \Illuminate\Foundation\Application::configure()->withCommands([base_path("custom/routes.php"), base_path("custom/tasks")]);');
        $first = $this->inspectImpact();
        $this->write('custom/routes.php', 'throw new \RuntimeException("never run console file"); \Illuminate\Support\Facades\Artisan::command("file:send", fn () => App\Service::send());');
        $this->write('custom/tasks/Task.php', 'namespace Custom; class Task extends \Illuminate\Console\Command { protected $name = "custom:task"; public function handle() { \App\Service::send(); } }');
        $next = $this->inspectImpact();
        $this->assertNotSame($first['execution']['flow_analysis']['source_signature'], $next['execution']['flow_analysis']['source_signature']);
        $this->assertContains('file:send', array_column(array_column($this->flows('console'), 'entry'), 'command'));
        $this->assertContains('custom:task', array_column(array_column($this->flows('console'), 'entry'), 'command'));
        $sources = new ExecutionSources(new Filesystem, $this->tempPath);
        $sources->discover(new ProjectGraphSnapshot([], []), []);
        $this->assertTrue($sources->fresh());
        $this->write('custom/tasks/Added.php', 'namespace Custom; class Added {}');
        $this->assertFalse($sources->fresh());
        unlink($this->tempPath.'/custom/routes.php');
        $this->assertNotContains('file:send', array_column(array_column($this->flows('console'), 'entry'), 'command'));
    }

    public function test_cli_mcp_preflights_and_limits_share_console_and_schedule_flows(): void
    {
        $this->fixture();
        Artisan::call('architecture-kit:impact', ['subject' => 'Service::send', '--agent' => true, '--limit' => 100, '--depth' => 16]);
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $mcp = ArchitectureKitServer::tool(Impact::class, ['subject' => 'Service::send', 'limit' => 100, 'depth' => 16]);
        $mcp->assertOk()->assertSee('artisan-command')->assertSee('schedule-task');
        $this->assertSame($this->inspectImpact()['execution'], $cli['execution']);
        foreach (['delete', 'signature', 'move'] as $change) {
            $result = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect($change === 'move' ? 'Service' : 'Service::send', [], 100, 16, $change);
            $this->assertNotEmpty($result['execution']['flows']);
            $this->assertFalse($result[$change]['safe_to_change']);
        }
        $limited = $this->inspectImpact(limit: 0)['execution'];
        $this->assertSame([], $limited['flows']);
        $this->assertSame('limit', $limited['flow_analysis']['status']);
        $this->assertGreaterThan(0, $limited['flow_analysis']['totals']['flows']);
        $this->assertSame('limit', $this->inspectImpact(depth: 1)['execution']['flow_analysis']['status']);
    }

    public function test_signature_attributes_named_aliases_and_handle_precedence(): void
    {
        $this->fixture();
        $this->write('app/Console/Commands/AttributeCommand.php', 'namespace App\Console\Commands; #[\Illuminate\Console\Attributes\Signature(signature: "attribute:send", aliases: ["attr:alias"])] class AttributeCommand extends \Illuminate\Console\Command { protected $signature = "old:name"; public function handle() { \App\Service::send(); } public function __invoke() { \App\Service::other(); } }');
        $this->write('routes/console.php', '\Illuminate\Support\Facades\Schedule::command("attr:alias");');
        $this->assertCount(1, $this->flows('schedule'));
        $this->assertSame([], $this->flows('schedule', 'Service::other'));
        $this->assertStringContainsString('require Laravel 13', json_encode($this->flows('console'), JSON_THROW_ON_ERROR));
        $this->assertNotContains('old:name', array_column(array_column($this->flows('console'), 'entry'), 'command'));
    }

    public function test_job_contract_and_options_are_distinct_from_schedule_success(): void
    {
        $this->fixture();
        $this->write('app/SyncJob.php', 'namespace App; class SyncJob { public function handle() { Service::send(); } }');
        $this->write('routes/console.php', 'use Illuminate\Support\Facades\Schedule; Schedule::job(new App\SyncJob)->everyFiveMinutes()->onSuccess(fn () => App\Service::after()); Schedule::job((new App\Job)->onQueue("low")->onConnection("sync"))->cron("0 * * * *")->environments(["production"])->withoutOverlapping(10)->onOneServer();');
        $rows = $this->flows('schedule');
        $this->assertCount(2, $rows);
        $handlers = [];
        foreach ($rows as $row) {
            $handlers[] = array_values(array_filter($row['via'], fn ($edge) => $edge['kind'] === 'job-handler'))[0];
        }
        $this->assertSame('synchronous', $handlers[0]['mode']);
        $this->assertSame('queue-requested', $handlers[1]['mode']);
        $this->assertSame('low', $handlers[1]['queue']);
        $this->assertSame('sync', $handlers[1]['connection']);
        $after = $this->flows('schedule', 'Service::after');
        $this->assertCount(1, $after);
        $this->assertNotContains('job-handler', array_column($after[0]['via'], 'kind'));
    }

    public function test_source_stat_edits_and_missing_input_creation_invalidate_freshness(): void
    {
        $this->fixture();
        $this->write('bootstrap/app.php', 'return \Illuminate\Foundation\Application::configure()->withCommands([base_path("custom/console.php")]);');
        $source = fn () => new ExecutionSources(new Filesystem, $this->tempPath);
        $graph = new ProjectGraphSnapshot([], []);
        $sources = $source();
        $sources->discover($graph, []);
        $this->assertTrue($sources->fresh());
        $this->write('custom/console.php', '\Illuminate\Support\Facades\Artisan::command("new", fn () => App\Service::send());');
        $this->assertFalse($sources->fresh());
        $sources = $source();
        $sources->discover($graph, []);
        $this->assertTrue($sources->fresh());
        $this->write('custom/console.php', '\Illuminate\Support\Facades\Artisan::command("changed:name", fn () => App\Service::after());');
        $this->assertFalse($sources->fresh());
    }

    public function test_named_schedule_callback_arguments_preserve_filters_and_callbacks(): void
    {
        $this->fixture();
        foreach (['when(callback: false)', 'skip(callback: true)'] as $filter) {
            $this->write('routes/console.php', '\Illuminate\Support\Facades\Schedule::job(new App\Job)->'.$filter.'->onSuccess(callback: fn () => App\Service::after());');
            $this->assertSame([], $this->flows('schedule'));
            $this->assertSame([], $this->flows('schedule', 'Service::after'));
        }
        $this->write('routes/console.php', '\Illuminate\Support\Facades\Schedule::job(job: new App\Job)->when(callback: fn () => App\Service::filter())->before(callback: fn () => App\Service::after())->after(callback: fn () => App\Service::after())->onSuccess(callback: fn () => App\Service::after())->onFailure(callback: fn () => App\Service::after());');
        $this->assertCount(1, $this->flows('schedule', 'Service::filter'));
        $this->assertCount(4, $this->flows('schedule', 'Service::after'));
    }

    public function test_positional_with_routing_custom_console_file_and_missing_input_freshness(): void
    {
        $this->fixture();
        $this->write('routes/console.php', '');
        $this->write('bootstrap/app.php', 'return \Illuminate\Foundation\Application::configure()->withRouting(null, null, null, base_path("custom/positional.php"));');
        $sources = new ExecutionSources(new Filesystem, $this->tempPath);
        $facts = $sources->discover(new ProjectGraphSnapshot([], []), []);
        $this->assertArrayHasKey('custom/positional.php', $facts['inputs']);
        $this->assertNull($facts['inputs']['custom/positional.php']);
        $this->assertTrue($sources->fresh());
        $this->write('custom/positional.php', '\Illuminate\Support\Facades\Artisan::command("positional", fn () => App\Service::send()); \Illuminate\Support\Facades\Schedule::command("positional");');
        $this->assertFalse($sources->fresh());
        $this->assertCount(1, $this->flows('schedule'));
        $this->assertContains('positional', array_column(array_column($this->flows('console'), 'entry'), 'command'));
    }

    public function test_success_and_failure_require_before_and_preceding_after_completion(): void
    {
        $this->fixture();
        $this->write('routes/console.php', '\Illuminate\Support\Facades\Schedule::job(new App\Job)->before(fn () => throw new \RuntimeException)->after(fn () => throw new \RuntimeException)->onSuccess(fn () => App\Service::after())->onFailure(fn () => App\Service::after());');
        $rows = $this->flows('schedule', 'Service::after');
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $callbackEdge = $row['via'][count($row['via']) - 2];
            $conditions = implode(' ', $callbackEdge['conditions']);
            $this->assertStringContainsString('Before callbacks must complete without throwing', $conditions);
            $this->assertStringContainsString('preceding after callbacks must complete without throwing', $conditions);
            $this->assertStringContainsString('Task completion is required', $conditions);
            $this->assertNotContains('job-handler', array_column($row['via'], 'kind'));
        }
    }

    public function test_callback_mutex_requires_a_prior_name_and_accepts_named_callback_events(): void
    {
        $this->fixture();
        foreach (['withoutOverlapping()', 'onOneServer()', 'withoutOverlapping()->name("late")', 'onOneServer()->name("late")'] as $modifier) {
            $this->write('routes/console.php', '\Illuminate\Support\Facades\Schedule::call(fn () => App\Service::send())->'.$modifier.';');
            $this->assertSame([], $this->flows('schedule'));
            $this->assertStringContainsString('mutex requires a name', json_encode($this->inspectImpact()['execution']['flow_analysis']['unresolved'], JSON_THROW_ON_ERROR));
        }
        $this->write('routes/console.php', '\Illuminate\Support\Facades\Schedule::call(fn () => App\Service::send())->name("named")->withoutOverlapping()->onOneServer();');
        $this->assertCount(1, $this->flows('schedule'));
        $this->write('routes/console.php', '$event = \Illuminate\Support\Facades\Schedule::call(fn () => App\Service::send()); $event->name("named"); $event->withoutOverlapping(); $event->onOneServer();');
        $this->assertCount(1, $this->flows('schedule'));
        $this->write('routes/console.php', '\Illuminate\Support\Facades\Schedule::name("grouped")->withoutOverlapping()->group(events: function () { \Illuminate\Support\Facades\Schedule::call(fn () => App\Service::send()); });');
        $this->assertCount(1, $this->flows('schedule'));
    }
}
