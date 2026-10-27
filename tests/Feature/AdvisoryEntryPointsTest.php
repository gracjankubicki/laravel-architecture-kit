<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Tests\Support\EngineFixture;
use GracjanKubicki\ArchitectureKit\Tests\Support\GraphRuntimeCleanup;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AdvisoryEntryPointsTest extends TestCase
{
    private string $directory;

    private string $root;

    private string $home;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/kit-daily-'.bin2hex(random_bytes(8));
        $this->root = $this->directory.'/repo with space';
        $this->home = $this->directory.'/private-home';
        mkdir($this->root.'/app/Actions', 0700, true);
        file_put_contents($this->root.'/composer.json', '{"type":"project","require":{"php":"^8.3"}}');
        if (EngineFixture::selected() === 'mago') {
            EngineFixture::install($this->home);
        }
        $registration = $this->cli(['init', 'actions']);
        file_put_contents($registration['config'], json_encode(['enabled' => ['actions'], 'analysis' => ['engine' => EngineFixture::selected()], 'audit' => ['incomplete_analysis' => 'block']], JSON_THROW_ON_ERROR));
        file_put_contents($this->root.'/app/Actions/Bad.php', '<?php namespace App\Actions; class Bad { public function one() {} public function two() {} }');
    }

    protected function tearDown(): void
    {
        GraphRuntimeCleanup::stop($this->home);
        (new Filesystem)->deleteDirectory($this->directory);
    }

    public function test_public_basic_search_stays_fresh_with_unchanged_materialized_settings(): void
    {
        foreach (['routes', 'bootstrap', 'config', 'database', 'resources/views', 'tests', 'Modules'] as $path) {
            (new Filesystem)->ensureDirectoryExists($this->root.'/'.$path);
        }
        $settings = ['enabled' => ['actions'], 'analysis' => ['engine' => EngineFixture::selected()], 'graph' => ['paths' => ['Modules']]];
        // Formatting differs from the tool's external var_export materialization.
        file_put_contents($this->root.'/config/architectures.php', '<?php return '.str_replace('\n', '', var_export($settings, true)).'; // project formatting');
        file_put_contents($this->root.'/Modules/Example.php', '<?php namespace Modules; class Example {}');
        foreach ([1, 2] as $attempt) {
            $search = $this->cli(['search', 'Modules\\Example', '--agent']);
            self::assertSame('found', $search['status']);
            self::assertNull($search['fresh']);
            self::assertSame('last_confirmed', $search['freshness']);
            self::assertTrue($search['analysis']['checks_complete']);
            self::assertNotEmpty($search['candidates']);
        }
        $frames = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'freshness-lab', 'version' => '1']]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'architecture-search', 'arguments' => ['query' => 'Modules\\Example']]],
        ];
        $process = $this->process(['mcp']);
        $process->setInput(implode("\n", array_map(json_encode(...), $frames))."\n");
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $responses = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim($process->getOutput())));
        self::assertSame([1, 2], array_column($responses, 'id'));
        $search = $responses[1]['result']['structuredContent'];
        self::assertSame('found', $search['status']);
        self::assertTrue($search['analysis']['checks_complete']);
        self::assertTrue($search['analysis_complete']);
        self::assertArrayNotHasKey('changed_inputs', $search['diagnostics']['summary']);
        $shutdown = GraphRuntimeCleanup::stop($this->home);
        self::assertNotEmpty($shutdown);
        self::assertTrue($shutdown[0]['response']['ok']);
        self::assertSame($shutdown[0]['request']['instance_token'], $shutdown[0]['response']['instance_token']);
        $proofs = dirname(__DIR__, 2).'/build/graph-performance-implementation/W04/cleanup-proofs';
        (new Filesystem)->ensureDirectoryExists($proofs, 0700);
        file_put_contents($proofs.'/'.hash('sha256', $this->home).'.json', json_encode($shutdown, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function test_public_target_and_revision_assessments_require_consent_before_source_capture(): void
    {
        mkdir($this->root.'/.architecture-kit', 0700, true);
        file_put_contents($this->root.'/.architecture-kit/target.json', '{"id":"daily","version":"1","paths":["app"]}');
        foreach (['target', 'revision-diff'] as $command) {
            foreach ([[], ['--mode=deep', '--task=target']] as $options) {
                $denied = $this->cli([$command, '--agent', ...$options], 1);
                self::assertFalse($denied['ok']);
                self::assertSame('not_run', $denied['analysis']['status']);
                self::assertStringContainsString('consent', $denied['msg']);
            }
        }
        self::assertFalse($this->cli(['guard', '--target', '--agent'])['ok']);
        $calls = [
            ['name' => 'revision-diff', 'arguments' => ['from' => 'HEAD']],
            ['name' => 'architecture-target', 'arguments' => ['analysis_mode' => 'deep', 'task' => 'mcp-target']],
            ['name' => 'guard', 'arguments' => ['target' => true]],
            ['name' => 'analysis-consent', 'arguments' => ['task' => 'mcp-target', 'confirm' => true]],
            ['name' => 'architecture-target', 'arguments' => ['analysis_mode' => 'deep', 'task' => 'mcp-target']],
        ];
        $frames = [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'target-lab', 'version' => '1']]]];
        foreach ($calls as $i => $call) {
            $frames[] = ['jsonrpc' => '2.0', 'id' => $i + 2, 'method' => 'tools/call', 'params' => $call];
        }
        $process = $this->process(['mcp']);
        $process->setInput(implode("\n", array_map(json_encode(...), $frames))."\n");
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $responses = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim($process->getOutput())));
        self::assertSame([1, 2, 3, 4, 5, 6], array_column($responses, 'id'));
        foreach (array_slice($responses, 1, 3) as $response) {
            self::assertFalse($response['result']['structuredContent']['ok']);
            self::assertSame('not_run', $response['result']['structuredContent']['analysis']['status']);
        }
        self::assertTrue($responses[4]['result']['structuredContent']['ok']);
        self::assertTrue($responses[5]['result']['structuredContent']['analysis']['deep_run']);
        self::assertTrue($this->cli(['analysis-consent', 'grant-deep', 'target', '--confirm'])['ok']);
        $deep = $this->cli(['target', '--mode=deep', '--task=target', '--agent']);
        self::assertTrue($deep['ok']);
        self::assertSame('deep', $deep['analysis']['mode']);
        self::assertTrue($deep['analysis']['deep_run']);
        self::assertSame('basic', $this->cli(['search', 'Bad', '--agent'])['analysis']['mode']);
    }

    public function test_public_source_demo_maps_modules_candidates_signatures_and_advisory_failure(): void
    {
        mkdir($this->root.'/Modules/Billing', 0700, true);
        mkdir($this->root.'/tests/Unit', 0700, true);
        file_put_contents($this->root.'/app/DiscountService.php', '<?php namespace App; class DiscountService { public function apply() {} }');
        file_put_contents($this->root.'/app/OrderService.php', '<?php namespace App; class OrderService { public function send(DiscountService $d) { $d->apply(); } public function unknown($handler) { $handler->run(); } }');
        file_put_contents($this->root.'/Modules/Billing/Invoice.php', '<?php namespace Modules\Billing; class Invoice { public function send(\App\OrderService $order, \Vendor\RateProvider $boundary) { $order->send(new \App\DiscountService); } }');
        file_put_contents($this->root.'/tests/Unit/DiscountServiceTest.php', '<?php namespace Tests; use App\DiscountService; class DiscountServiceTest { public function test_discount() { $service = new DiscountService; $service->apply(); } }');
        $search = $this->cli(['search', 'Modules\Billing\Invoice', '--agent']);
        self::assertNotEmpty($search['candidates']);
        self::assertSame('basic', $search['analysis']['mode']);
        $context = $this->cli(['context', 'App\DiscountService', '--agent']);
        self::assertContains('tests/Unit/DiscountServiceTest.php', array_column($context['tests'], 'path'));
        $impact = $this->cli(['impact', 'App\DiscountService::apply', '--agent']);
        self::assertNotEmpty($impact['dependents']);
        self::assertSame('not_run', $impact['execution']['status']);
        $path = $this->cli(['path', 'App\OrderService::send', 'App\DiscountService::apply', '--agent']);
        self::assertNotEmpty($path['source_path']['records']);
        self::assertFalse($path['analysis']['deep_run']);
        $reach = $this->cli(['reach', 'App\DiscountService', '--agent']);
        self::assertSame('basic', $reach['analysis']['mode']);
        $begin = $this->cli(['task', 'begin', 'signature-demo']);
        file_put_contents($this->root.'/app/DiscountService.php', '<?php namespace App; class DiscountService { public function apply($required) {} }');
        $failed = $this->cli(['task', 'check', 'signature-demo']);
        self::assertFalse($failed['ok']);
        self::assertGreaterThan(0, $failed['new_errors']);
        self::assertFalse($failed['control']['blocking']);
        self::assertSame($begin['begin_errors'], $failed['begin_errors']);
        file_put_contents($this->root.'/app/Broken.php', '<?php class Broken { invalid');
        $unverified = $this->cli(['task', 'check', 'signature-demo']);
        self::assertFalse($unverified['analysis']['checks_complete']);
        self::assertFalse($unverified['control']['blocking']);
    }

    public function test_real_cli_separates_analysis_truth_from_advisory_strict_full_and_enforce(): void
    {
        foreach ([[], ['--strict'], ['--full'], ['--strict', '--full']] as $options) {
            $audit = $this->cli(['audit', '--agent', ...$options]);
            self::assertFalse($audit['ok']);
            self::assertSame('basic', $audit['analysis']['mode']);
            self::assertFalse($audit['analysis']['deep_run']);
            self::assertFalse($audit['control']['blocking']);
        }
        self::assertTrue($this->cli(['audit', '--agent', '--enforce'], 1)['control']['blocking']);
        self::assertFalse($this->cli(['guard', '--agent', '--strict'])['control']['blocking']);
        self::assertTrue($this->cli(['guard', '--agent', '--enforce'], 1)['control']['blocking']);
        self::assertFalse($this->cli(['task', 'check', 'missing'])['ok']);
        self::assertSame('started', $this->cli(['task', 'begin', 'missing'])['status']);
        self::assertFalse($this->cli(['task', 'skip', 'missing', '--confirm'])['ok']);
    }

    public function test_human_audit_enforce_rejects_incomplete_checks_without_recognized_errors(): void
    {
        unlink($this->root.'/app/Actions/Bad.php');
        file_put_contents($this->root.'/app/Large.php', '<?php namespace App; /*'.str_repeat('x', 100001).'*/ class Large {}');
        $advisory = $this->cli(['audit', '--agent']);
        self::assertFalse($advisory['analysis']['checks_complete']);
        self::assertFalse($advisory['ok']);
        self::assertSame(0, $advisory['err']);
        $process = $this->process(['audit', '--enforce']);
        self::assertSame(1, $process->run(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('Incomplete analysis', $process->getOutput());
    }

    public function test_real_cli_and_mcp_require_scoped_consent_before_deep_but_keep_basic_available(): void
    {
        $denied = $this->cli(['audit', '--agent', '--mode=deep', '--task=A']);
        self::assertFalse($denied['ok']);
        self::assertStringContainsString('consent', $denied['msg']);
        self::assertTrue($this->cli(['search', 'Bad', '--agent'])['ok']);
        self::assertTrue($this->cli(['analysis-consent', 'grant-deep', 'A', '--confirm'])['ok']);
        self::assertSame('deep', $this->cli(['audit', '--agent', '--mode=deep', '--task=A'])['analysis']['mode']);
        self::assertFalse($this->cli(['audit', '--agent', '--mode=deep', '--task=B'])['ok']);

        $calls = [['name' => 'audit-changed', 'arguments' => ['analysis_mode' => 'deep', 'task' => 'M', 'changed' => false]],
            ['name' => 'analysis-consent', 'arguments' => ['task' => 'M', 'confirm' => true]],
            ['name' => 'audit-changed', 'arguments' => ['analysis_mode' => 'deep', 'task' => 'M', 'changed' => false]],
            ['name' => 'audit-changed', 'arguments' => ['changed' => false, 'full' => true]],
            ['name' => 'guard', 'arguments' => ['changed' => false, 'enforce' => true]]];
        $frames = [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'daily-lab', 'version' => '1']]]];
        foreach ($calls as $i => $call) {
            $frames[] = ['jsonrpc' => '2.0', 'id' => $i + 2, 'method' => 'tools/call', 'params' => $call];
        }
        $process = $this->process(['mcp']);
        $process->setInput(implode("\n", array_map(json_encode(...), $frames))."\n");
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $responses = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim($process->getOutput())));
        self::assertCount(6, $responses);
        $payload = array_map(static fn ($row) => $row['result']['structuredContent'], array_slice($responses, 1));
        self::assertFalse($payload[0]['ok']);
        self::assertTrue($payload[1]['ok']);
        self::assertSame('deep', $payload[2]['analysis']['mode']);
        self::assertSame('basic', $payload[3]['analysis']['mode']);
        self::assertFalse($payload[3]['control']['blocking']);
        self::assertTrue($payload[4]['control']['blocking']);
        self::assertSame([1, 2, 3, 4, 5, 6], array_column($responses, 'id'));
    }

    public function test_exported_hook_commands_report_errors_and_corrupt_stdin_without_blocking(): void
    {
        foreach (['codex', 'claude'] as $client) {
            $export = $this->cli(['integration', 'export', $client]);
            $hooks = json_decode(file_get_contents($export['directory'].'/hooks.json'), true, flags: JSON_THROW_ON_ERROR);
            $command = $hooks['hooks']['Stop'][0]['hooks'][0]['command'];
            foreach ([json_encode(['hook_event_name' => 'Stop', 'cwd' => $this->root, 'session_id' => 'unbound']), '{broken-json'] as $input) {
                $process = Process::fromShellCommandline($command, $this->directory);
                $process->setInput($input);
                self::assertSame(0, $process->run(), $process->getErrorOutput());
                $response = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                self::assertArrayNotHasKey('decision', $response);
                self::assertStringContainsString('unverified', strtolower(json_encode($response)));
                self::assertStringNotContainsString('Do not edit until', json_encode($response));
            }
        }
    }

    private function process(array $arguments): Process
    {
        return new Process([PHP_BINARY, dirname(__DIR__, 2).'/bin/architecture-kit', '--project', $this->root, '--tool-home', $this->home, ...$arguments], $this->directory, timeout: 30);
    }

    private function cli(array $arguments, int $exit = 0): array
    {
        $process = $this->process($arguments);
        self::assertSame($exit, $process->run(), $process->getErrorOutput().$process->getOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
