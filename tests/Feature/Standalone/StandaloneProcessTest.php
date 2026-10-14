<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature\Standalone;

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
        file_put_contents($this->root.'/composer.json', '{"name":"fixture/project"}');
        $this->action(false);
        $this->cli(['init', 'actions']);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->temp);
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

    public function test_incomplete_required_test_relationship_never_passes_task_or_guard(): void
    {
        $settings = $this->cli(['init', 'actions']);
        file_put_contents($settings['config'], '{"enabled":["actions"],"audit":{"missing_test":"error"}}');
        mkdir($this->root.'/tests/Feature', 0700, true);
        file_put_contents($this->root.'/tests/Feature/UnknownTest.php', "<?php it('runs unknown command', function () { \$this->artisan(\$unknown); });");
        $audit = $this->cli(['audit', '--agent'], exit: 1);
        self::assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($audit['find'], 'm'));
        self::assertSame('blocked', $this->cli(['task', 'begin', 'incomplete'], exit: 1)['status']);
        self::assertSame('blocked', $this->cli(['task', 'check', 'incomplete'], exit: 1)['status']);
        self::assertFalse($this->cli(['guard', '--agent'], exit: 1)['ok']);

        // Also cover a previously valid beginning becoming incomplete during edits.
        unlink($this->root.'/tests/Feature/UnknownTest.php');
        self::assertSame('started', $this->cli(['task', 'begin', 'becomes-incomplete'])['status']);
        foreach (['codex', 'claude'] as $client) {
            $this->cli(['task', 'begin', 'incomplete-'.$client]);
        }
        file_put_contents($this->root.'/tests/Feature/UnknownTest.php', "<?php it('runs unknown command', function () { \$this->artisan(\$unknown); });");
        self::assertSame('blocked', $this->cli(['task', 'check', 'becomes-incomplete'], exit: 1)['status']);
        foreach (['codex', 'claude'] as $client) {
            $this->cli(['task', 'bind', 'incomplete-'.$client, '--client', $client, '--session', 'incomplete-'.$client]);
            $stop = $this->hook($client, ['hook_event_name' => 'Stop', 'cwd' => $this->root, 'session_id' => 'incomplete-'.$client]);
            self::assertSame('block', $stop['decision']);
        }
    }

    public function test_ordinary_missing_test_warnings_do_not_block_task_verification(): void
    {
        $settings = $this->cli(['init', 'actions']);
        file_put_contents($settings['config'], '{"enabled":["actions"],"audit":{"missing_test":"warn"}}');
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
        self::assertSame('stale', $this->cli(['task', 'status', 'one'], exit: 1)['status']);
        self::assertSame('pass', $this->cli(['task', 'check', 'one'])['status']);
        self::assertSame($begin['begin_sources'], $this->cli(['task', 'begin', 'one'])['begin_sources']);
        file_put_contents($this->root.'/app/Actions/Other.php', '<?php namespace App\\Actions; use Illuminate\\Http\\Request; class Other {}');
        $failed = $this->cli(['task', 'check', 'one'], exit: 1);
        self::assertSame('fail', $failed['status']);
        self::assertGreaterThan(0, $failed['new_errors']);
        unlink($this->root.'/app/Actions/Other.php');
        self::assertSame('pass', $this->cli(['task', 'check', 'one'])['status']);
        $shared = $this->root.'/.architecture-kit';
        mkdir($shared);
        file_put_contents($shared.'/settings.json', '{"enabled":["services"]}');
        self::assertSame('blocked', $this->cli(['task', 'check', 'one'], exit: 1)['status']);
        self::assertFalse($this->cli(['task', 'skip', 'one', '--confirm'])['ok']);
        self::assertSame('blocked', $this->cli(['task', 'check', 'missing'], exit: 1)['status']);
    }

    public function test_profiles_are_explicit_and_mutations_require_opt_in(): void
    {
        $this->cli(['init', 'services', '--profile', 'personal']);
        $this->action(true);
        self::assertGreaterThan(0, $this->cli(['audit', '--agent'], exit: 1)['err']);
        self::assertSame(0, $this->cli(['audit', '--agent', '--profile', 'personal'])['err']);
        $blocked = $this->process(['make', 'actions', 'Other']);
        $blocked->run();
        self::assertNotSame(0, $blocked->getExitCode());
        self::assertFileDoesNotExist($this->root.'/app/Actions/Other.php');
        $write = $this->process(['make', 'actions', 'Other', '--allow-write']);
        $write->run();
        self::assertSame(0, $write->getExitCode(), $write->getOutput().$write->getErrorOutput());
        self::assertFileExists($this->root.'/app/Actions/Other.php');
        $inside = $this->process(['init', '--tool-home', $this->root.'/hidden']);
        $inside->run();
        self::assertSame(2, $inside->getExitCode());
        self::assertDirectoryDoesNotExist($this->root.'/hidden');
    }

    public function test_dynamic_php_config_is_rejected_without_execution(): void
    {
        mkdir($this->root.'/config');
        file_put_contents($this->root.'/config/architectures.php', '<?php file_put_contents(__DIR__."/executed", "1"); return ["enabled" => []];');
        $process = $this->process(['audit', '--agent']);
        $process->run();
        self::assertSame(2, $process->getExitCode());
        self::assertFileDoesNotExist($this->root.'/config/executed');
    }

    public function test_both_hook_protocols_begin_once_block_errors_and_preserve_resume(): void
    {
        foreach (['codex', 'claude'] as $client) {
            $input = ['hook_event_name' => 'SessionStart', 'source' => 'startup', 'session_id' => $client, 'cwd' => $this->root];
            $start = $this->hook($client, $input);
            self::assertStringContainsString('Status started', $start['hookSpecificOutput']['additionalContext']);
            $this->action(true);
            $stop = $this->hook($client, [...$input, 'hook_event_name' => 'Stop']);
            self::assertSame('block', $stop['decision']);
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
