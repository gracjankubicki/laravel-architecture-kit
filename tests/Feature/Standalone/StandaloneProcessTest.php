<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature\Standalone;

use GracjanKubicki\ArchitectureKit\Tests\Support\EngineFixture;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class StandaloneProcessTest extends TestCase
{
    private string $root;

    private string $home;

    private string $temp;

    protected function setUp(): void
    {
        $this->temp = sys_get_temp_dir().'/kit-process-'.bin2hex(random_bytes(8));
        $this->root = $this->temp.'/project';
        $this->home = $this->temp.'/tool';
        mkdir($this->root.'/app/Actions', 0700, true);
        file_put_contents($this->root.'/composer.json', '{"name":"fixture/project","type":"project","require":{"php":"^8.3"}}');
        $this->action(false);
        if (EngineFixture::selected() === 'mago') {
            EngineFixture::install($this->home);
        }
        $settings = $this->cli(['init', 'actions']);
        $config = json_decode(file_get_contents($settings['config']), true, flags: JSON_THROW_ON_ERROR);
        $config['analysis'] = ['engine' => EngineFixture::selected()];
        file_put_contents($settings['config'], json_encode($config, JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->temp);
    }

    public function test_engine_diagnostics_show_shared_precedence_and_an_explicit_profile_override(): void
    {
        if (! getenv('ARCHITECTURE_KIT_TEST_ENGINE')) {
            self::markTestSkipped('Set ARCHITECTURE_KIT_TEST_ENGINE.');
        }
        EngineFixture::install($this->home);
        mkdir($this->root.'/.architecture-kit');
        file_put_contents($this->root.'/.architecture-kit/settings.json', '{"enabled":["actions"]}');
        $shared = $this->cli(['engine', 'describe']);
        self::assertSame('mago', $shared['engine']);
        self::assertSame('default', $shared['origin']);
        self::assertSame('8.3.0', $shared['php_version']);
        self::assertNull($shared['profile']);
        $profile = $this->cli(['init', 'actions', '--profile', 'personal']);
        $config = json_decode(file_get_contents($profile['config']), true, flags: JSON_THROW_ON_ERROR);
        $config['analysis'] = ['engine' => 'php'];
        file_put_contents($profile['config'], json_encode($config, JSON_THROW_ON_ERROR));
        $local = $this->cli(['engine', 'describe', '--profile', 'personal']);
        self::assertSame('php', $local['engine']);
        self::assertSame('mago', $local['shared_engine']);
        self::assertTrue($local['overrides_shared_engine']);
        self::assertSame($profile['config'], $local['origin']);
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => ['uri' => 'architecture-kit://analysis-engine']],
        ];
        $process = $this->process(['mcp', '--profile', 'personal']);
        $process->setInput(implode("\n", array_map(json_encode(...), $messages))."\n");
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $responses = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim($process->getOutput())));
        $mcp = json_decode($responses[1]['result']['contents'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        unset($local['ok']); // CLI status envelope is not part of the MCP resource.
        self::assertSame($local, $mcp);
    }

    public function test_engine_switch_blocks_comparison_without_refreshing_the_task_beginning(): void
    {
        if (! getenv('ARCHITECTURE_KIT_TEST_ENGINE')) {
            self::markTestSkipped('Set ARCHITECTURE_KIT_TEST_ENGINE.');
        }
        EngineFixture::install($this->home);
        $registration = $this->cli(['init', 'actions']);
        $begin = $this->cli(['task', 'begin', 'engine-switch']);
        self::assertSame('started', $begin['status']);
        $settings = json_decode(file_get_contents($registration['config']), true, flags: JSON_THROW_ON_ERROR);
        $settings['analysis']['engine'] = $settings['analysis']['engine'] === 'php' ? 'mago' : 'php';
        file_put_contents($registration['config'], json_encode($settings, JSON_THROW_ON_ERROR));
        $checked = $this->cli(['task', 'check', 'engine-switch'], exit: 0);
        self::assertSame('blocked', $checked['status']);
        self::assertFalse($checked['ok']);
        $again = $this->cli(['task', 'begin', 'engine-switch'], exit: 0);
        foreach (['begin_sources', 'begin_errors', 'fingerprint', 'created_at'] as $key) {
            self::assertSame($begin[$key], $again[$key]);
        }
    }

    public function test_source_only_cli_and_mcp_use_target_not_host_and_write_nothing_in_repo(): void
    {
        mkdir($this->root.'/vendor/composer', 0700, true);
        mkdir($this->root.'/bootstrap', 0700, true);
        $trap = '<?php file_put_contents('.var_export($this->root.'/executed', true).', "executed");';
        foreach (['vendor/autoload.php', 'vendor/composer/installed.php', 'bootstrap/app.php'] as $path) {
            file_put_contents($this->root.'/'.$path, $trap);
        }
        $before = $this->files();
        $audit = $this->cli(['audit', '--agent']);
        self::assertTrue($audit['ok']);
        $search = $this->cli(['search', 'SendInvoice', '--agent'], cwd: $this->root.'/app');
        self::assertSame('App\\Actions\\SendInvoice', $search['candidates'][0]['name']);
        self::assertTrue($search['fresh']);
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'search', 'arguments' => ['query' => 'SendInvoice']]],
            ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'resources/read', 'params' => ['uri' => 'architecture-kit://guideline']],
            ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'task-control', 'arguments' => ['action' => 'begin', 'task' => 'mcp-task']]],
        ];
        $process = $this->process(['mcp']);
        $process->setInput(implode("\n", array_map(fn ($message) => json_encode($message), $messages))."\n");
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $responses = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim($process->getOutput())));
        self::assertCount(5, $responses, $process->getOutput());
        self::assertContains('task-control', array_column($responses[1]['result']['tools'], 'name'));
        self::assertStringContainsString('SendInvoice', json_encode($responses[2]));
        self::assertStringContainsString('started', json_encode($responses[4]));
        self::assertStringContainsString('Actions', json_encode($responses[3]));
        self::assertSame($before, $this->files());
    }

    public function test_cache_off_cold_and_warm_preserve_full_reports_and_control_decisions(): void
    {
        $registered = $this->cli(['init', 'actions']);
        mkdir($this->root.'/app/Http/Controllers', 0700, true);
        mkdir($this->root.'/app/Support', 0700, true);
        mkdir($this->root.'/routes');
        file_put_contents($this->root.'/routes/web.php', '<?php use Illuminate\\Support\\Facades\\Route; Route::get("probe", [App\\Http\\Controllers\\Probe::class, "index"]);');
        for ($i = 0; $i < 400; $i++) {
            file_put_contents($this->root.'/app/Support/Value'.$i.'.php', '<?php namespace App\\Support; class Value'.$i.' {}');
        }
        foreach (['complete' => 'return 1;', 'incomplete' => 'return \\Vendor\\Unknown::handle();'] as $status => $body) {
            file_put_contents($this->root.'/app/Http/Controllers/Probe.php', '<?php namespace App\\Http\\Controllers; class Probe { public function index() { '.$body.' } }');
            $reports = $decisions = [];
            foreach (['off' => false, 'cold' => true, 'warm' => true] as $mode => $enabled) {
                file_put_contents($registered['config'], json_encode(['analysis' => ['engine' => 'php'], 'enabled' => ['actions'], 'audit' => ['cache' => $enabled, 'missing_test' => 'off']], JSON_THROW_ON_ERROR));
                $reports[$mode] = $this->deepAudit(['--full', '--limit=50000']);
                self::assertSame($status, $reports[$mode]['analysis']['status']);
                self::assertFalse($reports[$mode]['analysis']['truncated']);
                $begin = $this->cli(['task', 'begin', $status.'-'.$mode], exit: 0);
                $check = $this->cli(['task', 'check', $status.'-'.$mode], exit: 0);
                $guard = $this->cli(['guard', '--agent'], exit: 0);
                $decisions[$mode] = [$begin['status'], $check['status'], $check['ok'], $guard['ok']];
            }
            self::assertSame($reports['off'], $reports['cold']);
            self::assertSame($reports['off'], $reports['warm']);
            self::assertSame($decisions['off'], $decisions['cold']);
            self::assertSame($decisions['off'], $decisions['warm']);
        }
    }

    public function test_full_mcp_reports_keep_sdk_text_and_structured_content_on_repeated_requests(): void
    {
        mkdir($this->root.'/app/Http/Controllers', 0700, true);
        mkdir($this->root.'/routes');
        file_put_contents($this->root.'/routes/web.php', '<?php use Illuminate\\Support\\Facades\\Route; Route::get("pełny/raport", [App\\Http\\Controllers\\Probe::class, "index"]);');
        file_put_contents($this->root.'/app/Http/Controllers/Probe.php', '<?php namespace App\\Http\\Controllers; class Probe { public function index() { '.str_repeat('\\Vendor\\Unknown::handle();'."\n", 100).' } }');
        $this->cli(['analysis-consent', 'grant-deep', 'full-report', '--confirm']);
        $messages = [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'full-report-test', 'version' => '1']]]];
        foreach (['audit-changed', 'audit-changed', 'guard', 'audit-changed', 'audit-changed'] as $index => $name) {
            $messages[] = ['jsonrpc' => '2.0', 'id' => $index + 2, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => ['changed' => false, 'full' => true, 'limit' => 50000, ...($name === 'audit-changed' ? ['analysis_mode' => 'deep', 'task' => 'full-report'] : [])]]];
        }
        $process = $this->process(['mcp']);
        $process->setInput(implode("\n", array_map(json_encode(...), $messages))."\n");
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $responses = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim($process->getOutput())));
        self::assertCount(6, $responses);
        foreach (array_slice($responses, 1) as $response) {
            self::assertArrayNotHasKey('error', $response);
            $result = $response['result'];
            self::assertSame($result['structuredContent'], json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR));
            self::assertFalse($result['structuredContent']['analysis']['truncated']);
            if ($response['id'] === 4) {
                self::assertSame('basic', $result['structuredContent']['analysis']['mode']);
                self::assertSame(0, $result['structuredContent']['analysis']['total']);
            } else {
                self::assertSame('deep', $result['structuredContent']['analysis']['mode']);
                self::assertGreaterThan(20, $result['structuredContent']['analysis']['total']);
            }
        }
        self::assertSame($responses[1]['result'], $responses[2]['result']);
        self::assertSame($responses[1]['result'], $responses[4]['result']);
        self::assertSame($responses[1]['result'], $responses[5]['result']);
    }

    public function test_basic_missing_test_errors_remain_advisory_without_automatic_reachability(): void
    {
        $settings = $this->cli(['init', 'actions']);
        file_put_contents($settings['config'], '{"analysis":{"engine":"php"},"enabled":["actions"],"audit":{"missing_test":"error","incomplete_analysis":"block"}}');
        mkdir($this->root.'/tests/Feature', 0700, true);
        file_put_contents($this->root.'/tests/Feature/UnknownTest.php', '<?php it("runs unknown command", function () { $this->artisan($unknown); });');
        $audit = $this->cli(['audit', '--agent']);
        self::assertSame('basic', $audit['analysis']['mode']);
        self::assertFalse($audit['analysis']['deep_run']);
        self::assertTrue($audit['analysis']['checks_complete']);
        self::assertNotContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($audit['find'], 'm'));
        self::assertGreaterThan(0, $audit['err']);
        self::assertFalse($audit['control']['blocking']);
        self::assertSame('started', $this->cli(['task', 'begin', 'incomplete'])['status']);
        self::assertSame('pass', $this->cli(['task', 'check', 'incomplete'])['status']);
        self::assertFalse($this->cli(['guard', '--agent'])['ok']);
        $deep = $this->deepAudit();
        self::assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($deep['find'], 'm'));
    }

    public function test_ordinary_missing_test_warnings_do_not_block_task_verification(): void
    {
        $settings = $this->cli(['init', 'actions']);
        file_put_contents($settings['config'], '{"analysis":{"engine":"php"},"enabled":["actions"],"audit":{"missing_test":"warn"}}');
        $audit = $this->cli(['audit', '--agent']);
        self::assertGreaterThan(0, $audit['warn']);
        self::assertNotContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($audit['find'], 'm'));
        $this->cli(['task', 'begin', 'warnings']);
        self::assertSame('pass', $this->cli(['task', 'check', 'warnings'])['status']);
    }

    public function test_cli_discovers_the_git_project_from_a_subdirectory_without_project_option(): void
    {
        mkdir($this->root.'/.git');
        file_put_contents($this->root.'/app/composer.json', '{"name":"fixture/nested-package"}');
        $binary = getenv('ARCHITECTURE_KIT_TEST_BIN') ?: dirname(__DIR__, 3).'/bin/architecture-kit';
        $process = new Process([PHP_BINARY, $binary, '--tool-home', $this->home, 'search', 'SendInvoice', '--agent'], $this->root.'/app', timeout: 30);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('App\\Actions\\SendInvoice', $result['candidates'][0]['name']);
        self::assertTrue($result['fresh']);
    }

    public function test_saved_reach_pages_and_cache_stay_outside_the_repository(): void
    {
        $before = $this->files();
        $report = $this->cli(['reach', 'App\\Actions\\SendInvoice', '--agent']);
        self::assertTrue($report['reach']['fresh']);
        $id = $report['reach']['pagination']['report_id'];
        self::assertNotEmpty($id);
        $page = $this->cli(['reach', '--report', $id, '--agent']);
        self::assertSame($id, $page['reach']['pagination']['report_id']);
        self::assertNotEmpty(glob($this->home.'/repositories/*/reports/'.$id.'.json'));
        self::assertSame($before, $this->files());
    }

    public function test_task_keeps_dirty_beginning_counts_new_errors_and_detects_staleness(): void
    {
        $this->action(true);
        $begin = $this->cli(['task', 'begin', 'one']);
        self::assertNotEmpty($begin['begin_errors']);
        self::assertSame('pass', $this->cli(['task', 'check', 'one'])['status']);
        file_put_contents($this->root.'/app/Actions/SendInvoice.php', "<?php\n\n\nnamespace App\\Actions; use Illuminate\\Http\\Request; class SendInvoice { public function handle(): void {} }");
        self::assertSame('stale', $this->cli(['task', 'status', 'one'], exit: 0)['status']);
        self::assertSame('pass', $this->cli(['task', 'check', 'one'])['status']);
        self::assertSame($begin['begin_sources'], $this->cli(['task', 'begin', 'one'])['begin_sources']);
        file_put_contents($this->root.'/app/Actions/Other.php', '<?php namespace App\\Actions; use Illuminate\\Http\\Request; class Other {}');
        $failed = $this->cli(['task', 'check', 'one'], exit: 0);
        self::assertSame('fail', $failed['status']);
        self::assertGreaterThan(0, $failed['new_errors']);
        unlink($this->root.'/app/Actions/Other.php');
        self::assertSame('pass', $this->cli(['task', 'check', 'one'])['status']);
        $shared = $this->root.'/.architecture-kit';
        mkdir($shared);
        file_put_contents($shared.'/settings.json', '{"analysis":{"engine":"php"},"enabled":["services"]}');
        self::assertSame('blocked', $this->cli(['task', 'check', 'one'], exit: 0)['status']);
        self::assertFalse($this->cli(['task', 'skip', 'one', '--confirm'])['ok']);
        self::assertSame('blocked', $this->cli(['task', 'check', 'missing'], exit: 0)['status']);
    }

    public function test_profiles_are_explicit_and_mutations_require_opt_in(): void
    {
        $profile = $this->cli(['init', 'services', '--profile', 'personal']);
        $config = json_decode(file_get_contents($profile['config']), true, flags: JSON_THROW_ON_ERROR);
        $config['analysis'] = ['engine' => 'php'];
        file_put_contents($profile['config'], json_encode($config, JSON_THROW_ON_ERROR));
        $this->action(true);
        self::assertGreaterThan(0, $this->cli(['audit', '--agent'], exit: 0)['err']);
        self::assertSame(0, $this->cli(['audit', '--agent', '--profile', 'personal'])['err']);
        $blocked = $this->process(['make', 'actions', 'Other']);
        $blocked->run();
        self::assertSame(0, $blocked->getExitCode());
        self::assertStringContainsString('allow-write', $blocked->getOutput().$blocked->getErrorOutput());
        self::assertFileDoesNotExist($this->root.'/app/Actions/Other.php');
        $write = $this->process(['make', 'actions', 'Other', '--allow-write']);
        $write->run();
        self::assertSame(0, $write->getExitCode(), $write->getOutput().$write->getErrorOutput());
        self::assertFileExists($this->root.'/app/Actions/Other.php');
        $inside = $this->process(['init', '--tool-home', $this->root.'/hidden']);
        $inside->run();
        self::assertSame(0, $inside->getExitCode());
        self::assertStringContainsString('outside', $inside->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->root.'/hidden');
    }

    public function test_dynamic_php_config_is_rejected_without_execution(): void
    {
        mkdir($this->root.'/config');
        file_put_contents($this->root.'/config/architectures.php', '<?php file_put_contents(__DIR__."/executed", "1"); return ["enabled" => []];');
        $process = $this->process(['audit', '--agent']);
        $process->run();
        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('false', $process->getErrorOutput());
        self::assertFileDoesNotExist($this->root.'/config/executed');
    }

    public function test_both_hook_protocols_begin_once_report_advisory_errors_and_preserve_resume(): void
    {
        foreach (['codex', 'claude'] as $client) {
            $input = ['hook_event_name' => 'SessionStart', 'source' => 'startup', 'session_id' => $client, 'cwd' => $this->root];
            $start = $this->hook($client, $input);
            self::assertStringContainsString('Status started', $start['hookSpecificOutput']['additionalContext']);
            $this->action(true);
            $stop = $this->hook($client, [...$input, 'hook_event_name' => 'Stop']);
            self::assertArrayNotHasKey('decision', $stop);
            self::assertStringContainsString('unverified', $stop['systemMessage']);
            $retry = $this->hook($client, [...$input, 'hook_event_name' => 'Stop', 'stop_hook_active' => true]);
            self::assertArrayNotHasKey('decision', $retry);
            self::assertStringContainsString('fail', $retry['systemMessage']);
            $resume = $this->hook($client, [...$input, 'source' => 'resume']);
            self::assertStringContainsString('Status fail', $resume['hookSpecificOutput']['additionalContext']);
            $missing = $this->hook($client, [...$input, 'source' => 'resume', 'session_id' => 'missing-'.$client]);
            self::assertStringContainsString('no beginning', $missing['hookSpecificOutput']['additionalContext']);
            $prompt = $this->hook($client, [...$input, 'hook_event_name' => 'UserPromptSubmit', 'session_id' => 'late-'.$client]);
            self::assertStringContainsString('no beginning', $prompt['hookSpecificOutput']['additionalContext']);
            $this->action(false);
            self::assertSame('exported', $this->cli(['integration', 'export', $client])['state']);
            $status = $this->cli(['integration', 'status', $client]);
            self::assertSame('observed', $status['state']);
            self::assertFalse($status['active']);
        }
    }

    public function test_export_keeps_the_composer_proxy_that_supplies_the_autoloader(): void
    {
        $proxy = $this->temp.'/composer-proxy.php';
        $autoload = dirname(__DIR__, 3).'/vendor/autoload.php';
        $binary = dirname(__DIR__, 3).'/bin/architecture-kit';
        file_put_contents($proxy, '<?php $_composer_autoload_path = '.var_export($autoload, true).'; require '.var_export($binary, true).';');
        $process = new Process([PHP_BINARY, $proxy, '--tool-home', $this->home, '--project', $this->root, 'integration', 'export', 'codex']);
        $process->mustRun();
        $export = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(realpath($proxy), $export['mcp']['args'][0]);
        $hooks = json_decode(file_get_contents($export['directory'].'/hooks.json'), true, flags: JSON_THROW_ON_ERROR);
        $hook = new Process(['sh', '-c', $hooks['hooks']['SessionStart'][0]['hooks'][0]['command']]);
        $hook->setInput(json_encode(['hook_event_name' => 'SessionStart', 'source' => 'startup', 'cwd' => $this->root, 'session_id' => 'exported-session']));
        $hook->mustRun();
        self::assertStringContainsString('Status started', $hook->getOutput());
    }

    public function test_source_routes_and_declared_directories_allow_a_complete_task_without_execution(): void
    {
        $settings = $this->cli(['init', 'thin-controllers']);
        file_put_contents($settings['config'], '{"analysis":{"engine":"php"},"enabled":["thin-controllers"],"audit":{"classification":{"roles":[{"path":"app/Dto","kind":"data"}]}}}');
        mkdir($this->root.'/app/Http/Controllers', 0700, true);
        mkdir($this->root.'/routes', 0700, true);
        mkdir($this->root.'/bootstrap', 0700, true);
        mkdir($this->root.'/vendor', 0700, true);
        $trap = '<?php file_put_contents('.var_export($this->root.'/executed', true).', "executed");';
        file_put_contents($this->root.'/bootstrap/app.php', $trap);
        file_put_contents($this->root.'/vendor/autoload.php', $trap);
        file_put_contents($this->root.'/app/Http/Controllers/InvoiceController.php', '<?php namespace App\\Http\\Controllers; class InvoiceController { public function index() { return "ok"; } }');
        file_put_contents($this->root.'/routes/web.php', $trap.' use Illuminate\\Support\\Facades\\Route; Route::get("/invoices", [App\\Http\\Controllers\\InvoiceController::class, "index"]);');
        $before = $this->files();
        $audit = $this->cli(['audit', '--agent']);
        self::assertSame('complete', $audit['analysis']['status']);
        self::assertSame('started', $this->cli(['task', 'begin', 'source-http'])['status']);
        self::assertSame('pass', $this->cli(['task', 'check', 'source-http'])['status']);
        self::assertSame($before, $this->files());
        self::assertFileDoesNotExist($this->root.'/executed');
    }

    public function test_unresolved_source_routes_preserve_known_endpoints_and_block_task_verification(): void
    {
        $settings = $this->cli(['init', 'thin-controllers']);
        file_put_contents($settings['config'], '{"analysis":{"engine":"php"},"enabled":["thin-controllers"],"audit":{"incomplete_analysis":"block"}}');
        mkdir($this->root.'/app/Http/Controllers', 0700, true);
        mkdir($this->root.'/routes', 0700, true);
        file_put_contents($this->root.'/app/Http/Controllers/InvoiceController.php', '<?php namespace App\\Http\\Controllers; class InvoiceController { public function index() { return "ok"; } }');
        file_put_contents($this->root.'/routes/web.php', '<?php use Illuminate\\Support\\Facades\\Route; Route::get("/invoices", [App\\Http\\Controllers\\InvoiceController::class, "index"]); Route::get("/dynamic", $handler);');
        $audit = $this->deepAudit();
        self::assertSame('incomplete', $audit['analysis']['status']);
        self::assertContains('A_ROUTE_SOURCE_UNRESOLVED', array_column($audit['analysis']['notices'], 'code'));
        self::assertSame('started', $this->cli(['task', 'begin', 'dynamic-http'], exit: 0)['status']);
        self::assertTrue($this->cli(['guard', '--agent'])['ok']);
    }

    public function test_missing_static_route_handler_blocks_task_without_executing_vendor(): void
    {
        $settings = $this->cli(['init', 'thin-controllers']);
        file_put_contents($settings['config'], '{"analysis":{"engine":"php"},"enabled":["thin-controllers"],"audit":{"incomplete_analysis":"block"}}');
        mkdir($this->root.'/app/Http/Controllers', 0700, true);
        mkdir($this->root.'/routes', 0700, true);
        mkdir($this->root.'/vendor', 0700, true);
        file_put_contents($this->root.'/vendor/autoload.php', '<?php file_put_contents(__DIR__."/executed", "yes");');
        file_put_contents($this->root.'/app/Http/Controllers/InvoiceController.php', '<?php namespace App\\Http\\Controllers; class InvoiceController { public function index() { return "ok"; } }');
        file_put_contents($this->root.'/routes/web.php', '<?php use Illuminate\\Support\\Facades\\Route; Route::get("/invoices", [App\\Http\\Controllers\\InvoiceController::class, "index"]); Route::get("/missing", [App\\Http\\Controllers\\MissingController::class, "index"]); Route::get("/external", [Vendor\\ExternalController::class, "index"]);');
        $audit = $this->deepAudit();
        self::assertSame('incomplete', $audit['analysis']['status']);
        $notices = array_values(array_filter($audit['analysis']['notices'], fn ($notice) => $notice['code'] === 'A_SOURCE_UNAVAILABLE'));
        self::assertCount(2, $notices);
        self::assertSame(['missing', 'external'], array_column(array_column($notices, 'route'), 'uri'));
        self::assertSame('routes/web.php', $notices[0]['route']['source']['path']);
        self::assertSame('started', $this->cli(['task', 'begin', 'missing-http'], exit: 0)['status']);
        self::assertSame('pass', $this->cli(['task', 'check', 'missing-http'], exit: 0)['status']);
        self::assertTrue($this->cli(['guard', '--agent'])['ok']);
        self::assertFileDoesNotExist($this->root.'/vendor/executed');
    }

    public function test_runtime_routes_are_explicit_fresh_and_propagated_to_exported_hooks(): void
    {
        $settings = $this->cli(['init', 'thin-controllers']);
        file_put_contents($settings['config'], '{"analysis":{"engine":"php"},"enabled":["thin-controllers"],"audit":{"classification":{"roles":[{"path":"app/Dto","kind":"data"}]}}}');
        mkdir($this->root.'/app/Http/Controllers', 0700, true);
        mkdir($this->root.'/bootstrap', 0700, true);
        mkdir($this->root.'/vendor', 0700, true);
        file_put_contents($this->root.'/app/Http/Controllers/InvoiceController.php', '<?php namespace App\\Http\\Controllers; class InvoiceController { public function __construct() { throw new \\LogicException("Do not instantiate controllers"); } public function index() { return "ok"; } }');
        $autoload = dirname(__DIR__, 3).'/vendor/autoload.php';
        file_put_contents($this->root.'/vendor/autoload.php', '<?php require_once '.var_export($autoload, true).';');
        $counter = $this->temp.'/runtime-boots';
        file_put_contents($this->root.'/bootstrap/app.php', '<?php file_put_contents('.var_export($counter, true).', "boot\\n", FILE_APPEND); return \\Illuminate\\Foundation\\Application::configure(basePath: dirname(__DIR__))->withRouting(using: function () { app("router")->get("dynamic-".random_int(1, 10000), [\\App\\Http\\Controllers\\InvoiceController::class, "index"]); })->create();');
        file_put_contents($this->root.'/bootstrap/providers.php', '<?php return [];');
        $before = $this->files();
        self::assertSame('incomplete', $this->deepAudit()['analysis']['status']);
        self::assertFileDoesNotExist($counter);
        self::assertSame('complete', $this->deepAudit(['--runtime-routes'])['analysis']['status']);
        self::assertSame('started', $this->cli(['--runtime-routes', 'task', 'begin', 'runtime'])['status']);
        self::assertSame('pass', $this->cli(['--runtime-routes', 'task', 'check', 'runtime'])['status']);
        self::assertSame('stale', $this->cli(['task', 'status', 'runtime'], exit: 0)['status']);
        self::assertCount(1, file($counter));
        $export = $this->cli(['--runtime-routes', 'integration', 'export', 'codex']);
        self::assertContains('--runtime-routes', $export['mcp']['args']);
        $hooks = json_decode(file_get_contents($export['directory'].'/hooks.json'), true, flags: JSON_THROW_ON_ERROR);
        $hook = new Process(['sh', '-c', $hooks['hooks']['SessionStart'][0]['hooks'][0]['command']]);
        $hook->setInput(json_encode(['hook_event_name' => 'SessionStart', 'source' => 'startup', 'cwd' => $this->root, 'session_id' => 'runtime-hook']));
        $hook->mustRun();
        self::assertStringContainsString('Status started', $hook->getOutput());
        self::assertSame($before, $this->files());
    }

    public function test_runtime_bootstrap_failure_and_invalid_settings_never_pass_task_verification(): void
    {
        self::assertSame('started', $this->cli(['--runtime-routes', 'task', 'begin', 'runtime-failed'], exit: 0)['status']);
        self::assertTrue($this->cli(['--runtime-routes', 'guard', '--agent'])['ok']);
        self::assertFalse($this->deepAudit(['--runtime-routes'])['analysis']['checks_complete']);
        $settings = $this->cli(['init', 'actions']);
        file_put_contents($settings['config'], '{"analysis":{"engine":"php"},"enabled":["actions"],"runtime":{"routes":{"driver":"docker-compose","service":"invalid;touch /tmp/unwanted"}}}');
        self::assertSame('started', $this->cli(['--runtime-routes', 'task', 'begin', 'invalid-runtime'], exit: 0)['status']);
        file_put_contents($settings['config'], '{"analysis":{"engine":"php"},"enabled":["actions"],"rules":["Fixture\\\\CustomRule"]}');
        $custom = $this->process(['--runtime-routes', 'audit', '--agent']);
        $custom->run();
        self::assertSame(0, $custom->getExitCode());
        self::assertStringContainsString('--allow-execution', $custom->getOutput().$custom->getErrorOutput());
        $false = $this->process(['--runtime-routes=false', 'audit', '--agent']);
        $false->run();
        self::assertSame(0, $false->getExitCode());
        self::assertStringContainsString('bare --runtime-routes', $false->getErrorOutput());
    }

    public function test_partial_analysis_policy_is_consistent_in_cli_mcp_and_both_hooks(): void
    {
        $registration = $this->cli(['init', 'actions']);
        $settings = json_decode(file_get_contents($registration['config']), true, flags: JSON_THROW_ON_ERROR);
        mkdir($this->root.'/app/Http/Controllers', 0700, true);
        file_put_contents($this->root.'/app/Http/Controllers/Probe.php', '<?php namespace App\\Http\\Controllers; class Probe { public function index() { return 1; } }');
        mkdir($this->root.'/routes');
        file_put_contents($this->root.'/routes/web.php', '<?php use Illuminate\\Support\\Facades\\Route; Route::get("dynamic", $handler);');
        foreach (['warn', 'block'] as $policy) {
            if ($policy === 'block') {
                $settings['audit']['incomplete_analysis'] = 'block';
            } // The first run deliberately uses an existing profile without the new setting.
            file_put_contents($registration['config'], json_encode($settings, JSON_THROW_ON_ERROR));
            $blocked = false; // Old completeness settings do not enable Deep or Enforce.
            $begin = $this->cli(['task', 'begin', $policy], exit: $blocked ? 1 : 0);
            self::assertSame($blocked ? 'blocked' : 'started', $begin['status']);
            $checked = $this->cli(['task', 'check', $policy], exit: $blocked ? 1 : 0);
            self::assertSame($blocked ? 'blocked' : 'pass', $checked['status']);
            $guard = $this->cli(['guard', '--agent', '--strict'], exit: $blocked ? 1 : 0);
            self::assertSame(! $blocked, $guard['ok']);
            self::assertSame('complete', $guard['analysis']['status']);
            self::assertSame([], $guard['analysis']['notices']);
            if (! $blocked) {
                self::assertSame('complete', $begin['begin_analysis']['status']);
                self::assertSame($policy, $checked['analysis']['policy']);
                self::assertSame(0, $checked['analysis']['notice_total']);
                self::assertFalse($checked['analysis']['deep_run']);
            }
            $mcp = $this->mcp([
                ['name' => 'task-control', 'arguments' => ['action' => 'begin', 'task' => 'mcp-'.$policy]],
                ['name' => 'task-control', 'arguments' => ['action' => 'check', 'task' => 'mcp-'.$policy]],
                ['name' => 'guard', 'arguments' => ['changed' => false, 'strict' => true]],
            ]);
            self::assertSame($blocked ? 'blocked' : 'started', $mcp[0]['status']);
            self::assertSame($blocked ? 'blocked' : 'pass', $mcp[1]['status']);
            self::assertSame(! $blocked, $mcp[2]['ok']);
            self::assertSame('complete', $mcp[2]['analysis']['status']);
            foreach (['codex', 'claude'] as $client) {
                $input = ['hook_event_name' => 'SessionStart', 'source' => 'startup', 'cwd' => $this->root, 'session_id' => $policy.'-'.$client];
                $start = $this->hook($client, $input);
                self::assertStringContainsString('Status '.($blocked ? 'blocked' : 'started'), $start['hookSpecificOutput']['additionalContext']);
                $stop = $this->hook($client, [...$input, 'hook_event_name' => 'Stop']);
                if ($blocked) {
                    self::assertArrayNotHasKey('decision', $stop);
                    self::assertStringContainsString('unverified', $stop['systemMessage']);
                } else {
                    self::assertArrayNotHasKey('decision', $stop);
                    self::assertStringContainsString('Basic', $start['hookSpecificOutput']['additionalContext']);
                    self::assertStringContainsString('pass', $stop['systemMessage']);
                }
            }
        }
    }

    public function test_new_partial_analysis_warns_but_new_errors_and_policy_drift_still_block(): void
    {
        mkdir($this->root.'/app/Http/Controllers', 0700, true);
        file_put_contents($this->root.'/app/Http/Controllers/Probe.php', '<?php namespace App\\Http\\Controllers; class Probe { public function index() { return 1; } }');
        mkdir($this->root.'/routes');
        file_put_contents($this->root.'/routes/web.php', '<?php use Illuminate\\Support\\Facades\\Route; Route::get("probe", [App\\Http\\Controllers\\Probe::class, "index"]);');
        $begin = $this->cli(['task', 'begin', 'partial-after-edit']);
        self::assertSame('complete', $begin['begin_analysis']['status']);
        file_put_contents($this->root.'/routes/web.php', '<?php use Illuminate\\Support\\Facades\\Route; Route::get("dynamic", $handler);');
        $checked = $this->cli(['task', 'check', 'partial-after-edit']);
        self::assertSame('pass', $checked['status']);
        self::assertSame('complete', $checked['analysis']['status']);
        $this->action(true);
        $failed = $this->cli(['task', 'check', 'partial-after-edit'], exit: 0);
        self::assertSame('fail', $failed['status']);
        self::assertGreaterThan(0, $failed['new_errors']);
        self::assertSame('complete', $failed['analysis']['status']);
        $registration = $this->cli(['init', 'actions']);
        $settings = json_decode(file_get_contents($registration['config']), true, flags: JSON_THROW_ON_ERROR);
        $settings['audit']['incomplete_analysis'] = 'block';
        file_put_contents($registration['config'], json_encode($settings, JSON_THROW_ON_ERROR));
        self::assertSame('blocked', $this->cli(['task', 'check', 'partial-after-edit'], exit: 0)['status']);
        $again = $this->cli(['task', 'begin', 'partial-after-edit'], exit: 0);
        self::assertSame($begin['begin_errors'], $again['begin_errors']);
        self::assertSame($begin['begin_sources'], $again['begin_sources']);
        self::assertSame($begin['begin_analysis'], $again['begin_analysis']);
    }

    public function test_invalid_completeness_policy_is_rejected_by_control_entrypoints(): void
    {
        $registration = $this->cli(['init', 'actions']);
        $settings = json_decode(file_get_contents($registration['config']), true, flags: JSON_THROW_ON_ERROR);
        $settings['audit']['incomplete_analysis'] = 'ignore';
        file_put_contents($registration['config'], json_encode($settings, JSON_THROW_ON_ERROR));
        $begin = $this->cli(['task', 'begin', 'invalid-policy'], exit: 0);
        self::assertSame('blocked', $begin['status']);
        self::assertStringContainsString('warn, block', $begin['reason']);
        $guard = $this->cli(['guard', '--agent'], exit: 0);
        self::assertFalse($guard['ok']);
        self::assertFalse($guard['analysis']['checks_complete']);
        self::assertStringContainsString('warn, block', json_encode($guard['analysis']['notices']));
    }

    private function deepAudit(array $options = []): array
    {
        $this->cli(['analysis-consent', 'grant-deep', 'semantic', '--confirm']);

        return $this->cli(['audit', '--agent', '--mode=deep', '--task=semantic', ...$options]);
    }

    private function mcp(array $calls): array
    {
        $messages = [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'policy-test', 'version' => '1']]]];
        foreach ($calls as $index => $call) {
            $messages[] = ['jsonrpc' => '2.0', 'id' => $index + 2, 'method' => 'tools/call', 'params' => $call];
        }
        $process = $this->process(['mcp']);
        $process->setInput(implode("\n", array_map(json_encode(...), $messages))."\n");
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $responses = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim($process->getOutput())));
        self::assertCount(count($messages), $responses, $process->getOutput());

        return array_map(static fn ($response) => $response['result']['structuredContent'], array_slice($responses, 1));
    }

    private function hook(string $client, array $input): array
    {
        $process = $this->process(['hook', $client]);
        $process->setInput(json_encode($input));
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function action(bool $error): void
    {
        file_put_contents($this->root.'/app/Actions/SendInvoice.php', '<?php namespace App\\Actions; '.($error ? 'use Illuminate\\Http\\Request; ' : '').'class SendInvoice { public function handle(): void {} }');
    }

    private function process(array $args, ?string $cwd = null): Process
    {
        return new Process([PHP_BINARY, getenv('ARCHITECTURE_KIT_TEST_BIN') ?: dirname(__DIR__, 3).'/bin/architecture-kit', '--tool-home', $this->home, '--project', $this->root, ...$args], $cwd ?? dirname(__DIR__, 3), timeout: 30);
    }

    private function cli(array $args, int $exit = 0, ?string $cwd = null): array
    {
        $process = $this->process($args, $cwd);
        $process->run();
        self::assertSame($exit, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function files(): array
    {
        $result = [];
        foreach ((new Filesystem)->allFiles($this->root, true) as $file) {
            $result[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }
        ksort($result);

        return $result;
    }
}
