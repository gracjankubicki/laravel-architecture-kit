<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature\Standalone;

use GracjanKubicki\ArchitectureKit\Standalone\GraphProtocol;
use GracjanKubicki\ArchitectureKit\Tests\Support\EngineFixture;
use GracjanKubicki\ArchitectureKit\Tests\Support\GraphRuntimeCleanup;
use PHPUnit\Framework\Attributes\RequiresEnvironmentVariable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[RequiresEnvironmentVariable('ARCHITECTURE_KIT_TEST_RUNTIME')]
final class GraphPerformanceSmokeTest extends TestCase
{
    private string $base;

    private string $root;

    private string $home;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__, 3).'/build/graph-performance-implementation/W04/legacy-smoke/smoke-'.bin2hex(random_bytes(6));
        $this->root = $this->base.'/repo';
        mkdir($this->root.'/app/Sub', 0700, true);
        mkdir($this->root.'/.architecture-kit', 0700, true);
        file_put_contents($this->root.'/.git', 'synthetic root marker');
        file_put_contents($this->root.'/composer.json', '{"name":"fixture/t04","type":"project","require":{"php":"^8.3"},"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        file_put_contents($this->root.'/.architecture-kit/settings.json', '{"enabled":["actions"],"analysis":{"engine":"php"},"audit":{"cache":true,"missing_test":"off"}}');
        file_put_contents($this->root.'/app/Service.php', '<?php namespace App; class Service { public function run(): void {} }');
        file_put_contents($this->root.'/app/Caller.php', '<?php namespace App; class Caller { public function call(Service $service): void { $service->run(); } }');
        $this->home = realpath(sys_get_temp_dir()).'/kit-t04-'.basename($this->base);
        mkdir($this->home, 0700);
        file_put_contents($this->base.'/home-provenance.json', json_encode(['tool_home' => $this->home, 'fixture' => $this->root, 'backend' => EngineFixture::selected()], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        GraphRuntimeCleanup::stop($this->home);
        foreach (glob($this->home.'/repositories/*/runtime/*/coordinator.json') ?: [] as $path) {
            $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            try {
                $socket = GraphProtocol::socket(dirname($path), $record['endpoint']);
                GraphProtocol::send($socket, [...$record, 'action' => 'shutdown', 'request_id' => bin2hex(random_bytes(16))]);
                fclose($socket);
            } catch (\Throwable) {
                // Only our private socket is contacted; no PID-based signal or global cleanup.
            }
        }
    }

    public function test_actual_cli_mcp_restore_mutations_and_full_oracle_remain_distinct_from_timing(): void
    {
        $cold = $this->measure('cold', ['transport' => 'cli']);
        $this->assertTrue($cold['full_state_and_query_equal']);
        $this->assertTrue($cold['negative_comparator_controls']);
        $warm = $this->measure('warm', ['transport' => 'mcp', 'oracle_file' => $this->base.'/cold/oracle.json']);
        $this->assertSame('new_process_after_controlled_shutdown', $warm['warm_start']);
        $this->assertNotSame($cold['ready']['pid'], $warm['ready']['pid']);
        $this->assertSame('hit', $warm['ready']['cache']['read']['status']);
        $this->assertNotSame($cold['ready']['snapshot_id'], $warm['ready']['snapshot_id']);
        $this->assertSame(json_decode(file_get_contents($this->base.'/cold/actual.stdout'), true, flags: JSON_THROW_ON_ERROR)['full_record_digests'],
            json_decode(file_get_contents($this->base.'/warm/actual.stdout'), true, flags: JSON_THROW_ON_ERROR)['full_record_digests']);
        $changed = $this->measure('edit', ['transport' => 'cli', 'changes' => [['path' => 'app/Service.php',
            'before_sha256' => hash_file('sha256', $this->root.'/app/Service.php'),
            'contents' => '<?php namespace App; class Service { public function run(): void {} public function added(): void {} }']]]);
        $this->assertNotSame($warm['ready']['snapshot_id'], $changed['ready']['snapshot_id']);
        $added = $this->measure('add', ['transport' => 'mcp', 'changes' => [['path' => 'app/Added.php', 'contents' => '<?php namespace App; class Added {}']]]);
        $moved = $this->measure('move', ['transport' => 'cli', 'changes' => [['path' => 'app/Added.php', 'to' => 'app/Sub/Added.php',
            'before_sha256' => hash_file('sha256', $this->root.'/app/Added.php')]]]);
        $this->assertNotSame($added['ready']['snapshot_id'], $moved['ready']['snapshot_id']);
        $deleted = $this->measure('delete', ['transport' => 'mcp', 'changes' => [['path' => 'app/Sub/Added.php',
            'before_sha256' => hash_file('sha256', $this->root.'/app/Sub/Added.php')]]]);
        $this->assertNotSame($changed['ready']['snapshot_id'], $deleted['ready']['snapshot_id']);
        $this->assertSame(json_decode(file_get_contents($this->base.'/edit/actual.stdout'), true, flags: JSON_THROW_ON_ERROR)['full_record_digests'],
            json_decode(file_get_contents($this->base.'/delete/actual.stdout'), true, flags: JSON_THROW_ON_ERROR)['full_record_digests']);
        $this->assertNotSame($changed['ready']['job_id'], $deleted['ready']['job_id']);
        $this->assertNotSame($changed['ready']['pid'], $deleted['ready']['pid']);
        // Late files belong to the retired job, never to the new worker for the same content.
        $jobPath = (glob($this->home.'/repositories/*/runtime/*/job-'.$changed['ready']['job_id'].'.json') ?: [])[0];
        $oldReady = json_decode(file_get_contents($jobPath), true, flags: JSON_THROW_ON_ERROR);
        $oldReady['verified_at'] = gmdate('c');
        file_put_contents($jobPath, json_encode($oldReady, JSON_THROW_ON_ERROR));
        file_put_contents(dirname($jobPath).'/running-'.$changed['ready']['job_id'].'.json', json_encode([
            'pid' => $changed['ready']['pid'], 'instance_token' => $changed['ready']['instance_token'],
        ], JSON_THROW_ON_ERROR));
        foreach (['status', 'result'] as $action) {
            $history = $this->runtimeCall(['map', $action, $changed['ready']['job_id']]);
            $this->assertSame('unavailable', $history['availability']);
            $this->assertSame('unverified', $history['freshness']);
            $this->assertSame('generation_retired', $history['reason']);
            $this->assertSame($changed['ready']['instance_token'], $history['instance_token']);
            $this->assertSame($changed['ready']['deadline_exceeded'], $history['deadline_exceeded']);
            $this->assertNotSame($deleted['ready']['instance_token'], $history['instance_token']);
        }
        $current = $this->runtimeCall(['map', 'prepare']);
        $this->assertSame($deleted['ready']['job_id'], $current['job_id']);
        $this->assertSame($deleted['ready']['instance_token'], $current['instance_token']);
        $major = $this->measure('major', ['transport' => 'cli', 'changes' => [
            ['path' => 'app/Service.php', 'before_sha256' => hash_file('sha256', $this->root.'/app/Service.php'),
                'contents' => '<?php namespace App; class Service { public function run(): void {} public function major(): void {} }'],
            ['path' => 'app/Caller.php', 'before_sha256' => hash_file('sha256', $this->root.'/app/Caller.php'),
                'contents' => '<?php namespace App; class Caller { public function call(Service $service): void { $service->major(); } }'],
        ]]);
        $this->assertNotSame($deleted['ready']['snapshot_id'], $major['ready']['snapshot_id']);
        $query = $this->measure('query', ['transport' => 'mcp', 'oracle_file' => $this->base.'/major/oracle.json'], null);
        $this->assertSame($query['resident_request_response_s'] <= 0.1, $query['resident_query_within_threshold']);
        $this->assertSame($query['resident_query_within_threshold'], $query['checks_satisfied']);
        $this->assertGreaterThanOrEqual($query['worker_dispatch_s'], $query['resident_request_response_s']);
        $this->assertStringContainsString('including IPC, freshness', $query['resident_timing_scope']);
        $this->assertFileExists($this->base.'/query/resident-session.stdout');
        $events = json_decode(file_get_contents($this->base.'/query/query-markers.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['query.route', 'query.before', 'query.after'] as $stage) {
            $this->assertContains($stage, array_column($events, 'stage'));
        }
        $this->assertNotContains('query.freshness.before', array_column($events, 'stage'));
        $this->assertNotContains('query.recheck.before', array_column($events, 'stage'));
        $this->assertSame(2, $query['public_query']['runtime_semantics']);
        $this->assertSame('last_confirmed', $query['public_query']['freshness']);
        $this->assertGreaterThan(0, $query['max_simultaneous_rss_kib']);
    }

    public function test_unconfigured_static_copy_uses_only_explicit_external_settings(): void
    {
        unlink($this->root.'/.architecture-kit/settings.json');
        $result = $this->measure('cold', ['transport' => 'mcp', 'settings' => []]);
        $this->assertSame('explicit scenario settings for an unconfigured static copy', $result['configuration_origin']);
        $this->assertFileDoesNotExist($this->root.'/config/architectures.php');
        $this->assertFileDoesNotExist($this->root.'/.architecture-kit/settings.json');
        $this->assertGreaterThan(0, filesize($this->base.'/cold/actual-diagnostics.jsonl'));
        $this->assertGreaterThan(0, filesize($this->base.'/cold/oracle-diagnostics.jsonl'));
    }

    public function test_recovery_does_not_claim_execution_without_an_admitted_fault(): void
    {
        $result = $this->measure('recovery', ['transport' => 'cli'], 2);
        $this->assertStringContainsString('separately admitted', $result['error']);
        $this->assertSame('OPEN', $result['status']);
        $this->assertArrayNotHasKey('checks_satisfied', $result);
    }

    private function runtimeCall(array $arguments): array
    {
        $command = [PHP_BINARY, '-d', 'memory_limit=1G', dirname(__DIR__, 2).'/Smoke/graph-performance-entrypoint.php',
            '--project', $this->root, '--tool-home', $this->home, '--profile', 'performance-'.EngineFixture::selected(), ...$arguments];
        $process = new Process($command, dirname(__DIR__, 3), timeout: 30);
        $this->assertSame(0, $process->run(), $process->getErrorOutput());
        file_put_contents($this->base.'/history-calls.jsonl', json_encode(['command' => $command, 'exit' => $process->getExitCode(),
            'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function measure(string $case, array $scenario, ?int $expectedExit = 0): array
    {
        $output = $this->base.'/'.$case;
        mkdir($output, 0700);
        $scenario += ['query' => 'Service', 'memory_limit' => '1G'];
        if (EngineFixture::selected() === 'mago') {
            $scenario['engine_binary'] = getenv('ARCHITECTURE_KIT_TEST_ENGINE');
        }
        file_put_contents($output.'/scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, '-d', 'memory_limit=1G', dirname(__DIR__, 2).'/Smoke/graph-performance.php',
            '--project', $this->root, '--tool-home', $this->home, '--output', $output, '--backend', EngineFixture::selected(), '--case', $case];
        $process = new Process($command, dirname(__DIR__, 3), timeout: 60);
        $exit = $process->run();
        file_put_contents($output.'/harness.stdout', $process->getOutput());
        file_put_contents($output.'/harness.stderr', $process->getErrorOutput());
        file_put_contents($output.'/harness-command.json', json_encode(['command' => $command, 'exit' => $exit], JSON_THROW_ON_ERROR));
        if ($expectedExit === null) {
            // The integration test checks honest benchmark classification, not this machine's speed.
            $this->assertContains($exit, [0, 1], $process->getOutput().$process->getErrorOutput());
        } else {
            $this->assertSame($expectedExit, $exit, $process->getOutput().$process->getErrorOutput());
        }
        $result = json_decode(file_get_contents($output.'/summary.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($expectedExit === 0 || $expectedExit === null) {
            foreach (['full_state_and_query_equal', 'negative_comparator_controls', 'source_version_stable', 'inputs_stable_during_measurement', ...($expectedExit === 0 ? ['checks_satisfied'] : [])] as $key) {
                $this->assertTrue($result[$key], $key.' '.$case);
            }
        }

        return $result;
    }
}
