<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature\Standalone;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CatalogProductSet;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CatalogState;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphSegmentStore;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Context\ArchitectureContext;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;
use GracjanKubicki\ArchitectureKit\Discovery\ArchitectureDiscovery;
use GracjanKubicki\ArchitectureKit\Discovery\SearchSchema;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\ArchitecturePath;
use GracjanKubicki\ArchitectureKit\Impact\ImpactSchema;
use GracjanKubicki\ArchitectureKit\Impact\PathSchema;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\Reach\ArchitectureReach;
use GracjanKubicki\ArchitectureKit\Reach\ReachSchema;
use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimePlatform;
use GracjanKubicki\ArchitectureKit\Standalone\GraphProcessMemory;
use GracjanKubicki\ArchitectureKit\Standalone\GraphProtocol;
use GracjanKubicki\ArchitectureKit\Standalone\GraphResources;
use GracjanKubicki\ArchitectureKit\Standalone\GraphRuntime;
use GracjanKubicki\ArchitectureKit\Standalone\GraphRuntimeSchema;
use GracjanKubicki\ArchitectureKit\Standalone\Host;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use GracjanKubicki\ArchitectureKit\Tests\Support\EngineFixture;
use GracjanKubicki\ArchitectureKit\Tests\Support\GraphRuntimeCleanup;
use GracjanKubicki\ArchitectureKit\Tests\Support\WorktreeMcpClient;
use GracjanKubicki\ArchitectureKit\Tests\Unit\AgentOutputSchemaTest;
use GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\Watch\RuntimeFixture;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ObservedGraphRuntimeTest extends TestCase
{
    private string $root;

    private string $home;

    private GraphRuntime $runtime;

    private ?Process $queryProcess = null;

    protected function setUp(): void
    {
        GraphRuntime::startProcess();
        $id = bin2hex(random_bytes(8));
        $this->root = dirname(__DIR__, 3).'/build/graph-performance-implementation/W04/fixtures/'.$id;
        $this->home = realpath(sys_get_temp_dir()).'/kit-w04-'.$id;
        mkdir($this->root.'/app', 0700, true);
        mkdir($this->root.'/.architecture-kit', 0700, true);
        file_put_contents($this->root.'/.git', 'synthetic-test-root');
        file_put_contents($this->root.'/composer.json', '{"name":"test/w04","require":{"php":"^8.3"},"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        file_put_contents($this->root.'/.architecture-kit/settings.json', json_encode(['analysis' => ['engine' => EngineFixture::selected()], 'audit' => ['cache' => true]], JSON_THROW_ON_ERROR));
        file_put_contents($this->root.'/app/A.php', '<?php namespace App; class A { function alpha() {} }');
        file_put_contents($this->root.'/app/B.php', '<?php namespace App; class B { function gamma() { $this->gamma(); } }');
        $binary = RuntimeFixture::native();
        $platform = RuntimePlatform::current();
        $store = new StateStore($this->home.'/runtimes/0.1.0/'.$platform);
        $body = file_get_contents($binary);
        copy($binary, $store->directory.'/architecture-kit-runtime');
        chmod($store->directory.'/architecture-kit-runtime', 0700);
        $store->update('installation', static fn () => ['platform' => $platform, 'manifest' => RuntimeFixture::manifest($body)]);
        if (EngineFixture::selected() === 'mago') {
            EngineFixture::install($this->home);
        }
        $context = new RepositoryContext($this->root, $this->home);
        RepositoryContext::activate($context);
        AnalysisRuntime::activate(null);
        Host::create($this->home);
        GraphRuntime::instrument(dirname(__DIR__, 2).'/Support/graph-runtime-entrypoint.php', null);
        $this->runtime = new GraphRuntime($context);
    }

    protected function tearDown(): void
    {
        if (isset($this->home)) {
            (new StateStore($this->home))->update('test-control', static fn () => []);
            GraphRuntimeCleanup::stop($this->home);
        }
        if ($this->queryProcess?->isRunning()) {
            $this->queryProcess->stop(1);
        }
        if (isset($this->runtime)) {
            try {
                $directory = $this->runtime->directory();
                $record = (new StateStore($directory))->read('coordinator');
                if (isset($record['endpoint'])) {
                    $socket = GraphProtocol::socket($directory, $record['endpoint']);
                    GraphProtocol::send($socket, [...$record, 'action' => 'shutdown', 'routing_identity' => null, 'request_id' => bin2hex(random_bytes(16))]);
                    fclose($socket);
                }
            } catch (\Throwable) {
            }
        }
        RepositoryContext::activate(null);
        GraphRuntime::enable(false);
        GraphRuntime::instrument(null, null);
    }

    public function test_bounded_collection_batches_preserve_all_sources_metadata_blade_diagnostics_and_public_answers(): void
    {
        unlink($this->root.'/app/A.php');
        unlink($this->root.'/app/B.php');
        for ($i = 0; $i < 70; $i++) {
            $name = sprintf('C%03d', $i);
            $method = $i === 31 ? 'function run(C032 $next) { $next->run(); }' : 'function run() {}';
            file_put_contents($this->root.'/app/'.$name.'.php', '<?php namespace App; class '.$name.' { '.$method.' }');
        }
        file_put_contents($this->root.'/app/ZBroken.php', '<?php namespace App; class Broken {');
        mkdir($this->root.'/resources/views', 0700, true);
        file_put_contents($this->root.'/resources/views/batch.blade.php', '<div>{{ \App\C032::class }}</div>');
        file_put_contents($this->root.'/composer.json', '{"name":"test/batch-fixture","require":{"php":"^8.3","acme/batch-library":"^1.0"},"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        file_put_contents($this->home.'/performance-provenance.json', json_encode(['fixture' => 'bounded-batch-full-parity'], JSON_THROW_ON_ERROR));
        GraphRuntime::instrument(dirname(__DIR__, 2).'/Smoke/graph-performance-entrypoint.php', null);
        $prepared = $this->runtime->map('prepare');
        $this->assertSame('ready', $prepared['availability'], json_encode($prepared));
        $trace = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->home.'/performance-events.jsonl', FILE_IGNORE_NEW_LINES));
        $build = array_values(array_filter($trace, static fn ($event) => $event['stage'] === 'build.after' && $event['job'] === $prepared['job_id']))[0];
        $batches = array_values(array_filter($trace, static fn ($event) => $event['stage'] === 'collection.batch' && $event['job'] === $prepared['job_id']));
        $this->assertNotEmpty($batches);
        $this->assertLessThanOrEqual(32, max(array_column($batches, 'path_count')));
        $this->assertLessThanOrEqual(4 * 1024 * 1024, max(array_column($batches, 'source_bytes')));
        $this->assertSame(73, $build['parsed_sources']);
        if (EngineFixture::selected() === 'mago') {
            $this->assertSame(72, $build['analysis_stages']['basic.native.extract']['count']);
            $this->assertSame(4, $build['analysis_stages']['basic.native.batch']['count']);
        } else {
            $this->assertArrayNotHasKey('basic.native.batch', $build['analysis_stages']);
        }
        $store = new GraphSegmentStore(new Filesystem, $this->runtime->directory().'/product-'.$prepared['confirmation']['product_key']);
        $actual = $store->read($prepared['confirmation']['product_key'], CatalogState::FIELDS,
            manifestFields: [...CatalogState::FIELDS, ...CatalogProductSet::DERIVED]);
        $this->assertSame('hit', $actual['status']);
        array_push($actual['state']['diagnostics'], ...$actual['metadata']['settings_diagnostics']);
        $oraclePath = $this->root.'/batch-full-oracle.json';
        $oracleProcess = new Process([PHP_BINARY, '-d', 'memory_limit=128M', dirname(__DIR__, 2).'/Support/graph-runtime-oracle.php', $this->root, $this->home, $oraclePath]);
        $oracleProcess->run();
        $this->assertSame(0, $oracleProcess->getExitCode(), $oracleProcess->getErrorOutput());
        $oracle = json_decode(file_get_contents($oraclePath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($oracle, $actual['state']);
        $index = CatalogIndex::fromState($oracle);
        $this->assertSame($index->checksComplete(), $prepared['checks_complete']);
        $this->assertNotEmpty($index->diagnostics, 'The invalid source and scope diagnostics must not disappear through batching.');
        $answers = [];
        foreach (['C032', 'batch.blade.php', 'acme/batch-library'] as $query) {
            $body = $this->runtime->query('search', ['query' => $query, 'limit' => 100]);
            $rows = $body['result']['result'];
            unset($rows['execution']);
            $expected = (new GraphSearch($index))->find($query);
            $this->assertSame($expected, $rows, $query);
            $answers[] = ['query' => $query, 'body' => $body, 'expected' => $expected];
        }
        $body = $this->runtime->query('graph', ['subject' => 'App\\C032::run', 'mode' => 'impact', 'depth' => 3, 'limit' => 100]);
        $rows = $body['result']['result'];
        unset($rows['analysis']);
        $expected = (new GraphQuery($index))->query('App\\C032::run', 'impact', null, 3);
        unset($expected['analysis']);
        $this->assertSame($expected, $rows);
        $this->assertStringContainsString('C031', json_encode($rows, JSON_THROW_ON_ERROR), 'The caller across source32 must remain visible.');
        $this->record('bounded-batch-full-parity', ['prepared' => $prepared, 'batch_trace' => $batches, 'build_trace' => $build,
            'actual_state' => $actual['state'], 'full_oracle_state' => $oracle, 'search_answers' => $answers, 'graph_answer' => $body,
            'oracle_command' => $oracleProcess->getCommandLine(), 'oracle_exit' => $oracleProcess->getExitCode(),
            'oracle_stdout' => $oracleProcess->getOutput(), 'oracle_stderr' => $oracleProcess->getErrorOutput()]);
    }

    public function test_public_context_source_mismatch_retains_confirmation_and_schema_rejects_its_absence(): void
    {
        file_put_contents($this->root.'/.architecture-kit/settings.json', json_encode(['enabled' => ['services'],
            'analysis' => ['engine' => EngineFixture::selected()], 'audit' => ['cache' => true]], JSON_THROW_ON_ERROR));
        $source = '<?php namespace App; class A {';
        for ($i = 0; $i < 8; $i++) {
            $source .= ' public function alpha'.$i.'() { return 42; }';
        }
        $source .= '}';
        file_put_contents($this->root.'/app/A.php', $source);
        file_put_contents($this->root.'/app/B.php', '<?php namespace App; class B { function beta(A $a) { return $a->alpha0(); } }');
        mkdir($this->root.'/tests', 0700);
        file_put_contents($this->root.'/tests/ATest.php', '<?php namespace Tests; class ATest { function testA(\\App\\A $a) { $a->alpha0(); } }');
        $binary = dirname(__DIR__, 2).'/Support/graph-runtime-entrypoint.php';
        $client = new WorktreeMcpClient(['--project', $this->root, '--tool-home', $this->home, 'mcp'], binary: $binary);
        try {
            $client->initialize();
            $warm = $client->tool('architecture-context', ['subject' => 'App\\A', 'limit' => 20])['result']['structuredContent'];
            $this->assertSame('last_confirmed', $warm['freshness'], json_encode($warm));
        } finally {
            $client->close();
        }
        $control = new StateStore($this->home);
        $before = count(array_filter($control->read('test-events'), static fn ($event) => $event['stage'] === 'query.before'));
        $control->update('test-control', static fn () => ['barrier' => 'query.before']);
        $frames = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'w04-context-race', 'version' => '1']]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'architecture-context', 'arguments' => ['subject' => 'App\\A', 'limit' => 20]]],
        ];
        $this->queryProcess = new Process([PHP_BINARY, '-d', 'memory_limit='.ini_get('memory_limit'), $binary, '--project', $this->root, '--tool-home', $this->home, 'mcp'], timeout: 25);
        $this->queryProcess->setInput(implode("\n", array_map(json_encode(...), $frames))."\n");
        $this->queryProcess->start();
        $until = microtime(true) + 5;
        do {
            usleep(10000);
            $pinned = count(array_filter($control->read('test-events'), static fn ($event) => $event['stage'] === 'query.before')) > $before;
        } while (! $pinned && microtime(true) < $until);
        $this->assertTrue($pinned);
        file_put_contents($this->root.'/app/A.php', str_replace('return 42;', 'return 43;', $source));
        $control->update('test-control', static fn () => []);
        $this->queryProcess->wait();
        $this->assertSame(0, $this->queryProcess->getExitCode(), $this->queryProcess->getErrorOutput());
        $messages = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim($this->queryProcess->getOutput())));
        $body = $messages[count($messages) - 1]['result']['structuredContent'];
        $this->record('context-mismatch-confirmation', ['before' => $warm, 'response' => $body]);
        $this->assertFalse($body['ok']);
        $this->assertSame('stale', $body['freshness']);
        $this->assertSame('changed_inputs:app/A.php', $body['reason']);
        $this->assertSame('stale', $body['confirmation']['freshness']);
        $this->assertTrue($body['confirmation']['pending_changes']);
        $this->assertSame($body['snapshot_id'], $body['confirmation']['snapshot_id']);
        $this->assertSame($warm['analysis']['checks_complete'], $body['checks_complete']);
        $this->assertRuntimeSchema($body);
        $missing = $body;
        unset($missing['confirmation']);
        $this->assertRuntimeSchema($missing, false);
    }

    public function test_public_failed_admission_preserves_gc_and_both_budget_conditions(): void
    {
        GraphResources::setGlobal($this->home, 1, 1);
        $client = new WorktreeMcpClient(['--project', $this->root, '--tool-home', $this->home, 'mcp']);
        try {
            $client->initialize();
            $body = $client->tool('architecture-map', ['action' => 'prepare'])['result']['structuredContent'];
            $until = microtime(true) + 8;
            while ($body['availability'] === 'building' && microtime(true) < $until) {
                usleep(10000);
                $body = $client->tool('architecture-map', ['action' => 'result', 'job_id' => $body['job_id']])['result']['structuredContent'];
            }
            $this->record('public-admission-refusal', ['body' => $body, 'transcript' => $client->transcript]);
            $this->assertSame('failed', $body['availability']);
            $this->assertSame('unverified', $body['freshness']);
            $this->assertSame('preparation_required:resident_budget', $body['reason']);
            $report = $body['measurements']['admission'];
            $this->assertFalse($report['admitted']);
            $this->assertSame(3 * $report['facts_bytes'] + 3 * 1024 * 1024, $report['candidate_bytes']);
            $this->assertSame(1, $report['global_condition']['limit_bytes']);
            $this->assertTrue($report['global_condition']['exceeded']);
            $this->assertFalse($report['php_condition']['exceeded']);
            $this->assertSame(['global_budget'], $report['failed_conditions']);
            $this->assertSame($report['php_allocated'] + $report['candidate_bytes'], $report['php_condition']['counted_bytes']);
            $this->assertRuntimeSchema($body);
            $invalid = $body;
            $invalid['measurements']['admission']['candidate_bytes'] = 'not-a-counter';
            $this->assertRuntimeSchema($invalid, false);
        } finally {
            $client->close();
        }
    }

    public function test_public_admission_reports_unknown_rss_with_explicit_allocator_fallback(): void
    {
        $bin = $this->root.'/ps-fault-bin';
        mkdir($bin, 0700);
        file_put_contents($bin.'/ps', "#!/bin/sh\nexit 1\n");
        chmod($bin.'/ps', 0700);
        $process = new Process([PHP_BINARY, '-d', 'memory_limit='.ini_get('memory_limit'), dirname(__DIR__, 2).'/Support/graph-runtime-entrypoint.php',
            '--project', $this->root, '--tool-home', $this->home, 'map', 'prepare'], env: ['PATH' => $bin.PATH_SEPARATOR.getenv('PATH')], timeout: 25);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $body = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->record('public-admission-rss-fallback', ['command' => $process->getCommandLine(), 'body' => $body]);
        $this->assertSame('ready', $body['availability']);
        $report = $body['measurements']['build']['admission'];
        $this->assertTrue($report['admitted']);
        $this->assertNull($report['measured_rss_bytes']);
        $this->assertSame('php_allocated_fallback', $report['process_bytes_basis']);
        $this->assertSame('process_rss_unavailable', $report['rss_reason']);
        $this->assertNull($report['gc']['before']['rss_bytes']);
        $this->assertNull($report['gc']['after']['rss_bytes']);
        $this->assertSame($report['gc']['after']['php_allocated'], $report['process_bytes']);
        $this->assertRuntimeSchema($body);
    }

    /** @param array<string, mixed> $body */
    private function assertRuntimeSchema(array $body, bool $expected = true): void
    {
        $validator = new \ReflectionMethod(AgentOutputSchemaTest::class, 'matchesSchema');
        $instance = new AgentOutputSchemaTest('test_it_exposes_a_stable_audit_agent_output_schema');
        $this->assertSame($expected, $validator->invoke($instance, $body, GraphRuntimeSchema::state()), json_encode($body));
    }

    public function test_loaded_mcp_without_a_prior_map_query_rejects_a_new_implementation_and_fresh_cli_recovers(): void
    {
        $tool = $this->root.'/isolated-tool';
        $files = new Filesystem;
        $files->copyDirectory(dirname(__DIR__, 3).'/src', $tool.'/src');
        mkdir($tool.'/bin', 0700, true);
        $autoload = var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true);
        $entrypoint = '<?php require '.$autoload.'; spl_autoload_register(static function (string $class): void {'
            .' $prefix = "GracjanKubicki\\\\ArchitectureKit\\\\"; if (str_starts_with($class, $prefix)) {'
            .' $path = dirname(__DIR__)."/src/".str_replace("\\\\", "/", substr($class, strlen($prefix))).".php";'
            .' if (is_file($path)) { require $path; } } }, true, true);'
            .' exit(GracjanKubicki\\ArchitectureKit\\Standalone\\Runner::run($argv));';
        file_put_contents($tool.'/bin/architecture-kit', $entrypoint);
        $client = new WorktreeMcpClient(['--project', $this->root, '--tool-home', $this->home, 'mcp'], $this->root, $tool.'/bin/architecture-kit');
        try {
            $this->assertArrayHasKey('result', $client->initialize());
            // The first map request happens AFTER the disk implementation changes.
            file_put_contents($tool.'/src/Standalone/GraphRetention.php', "\n// Isolated test upgrade.\n", FILE_APPEND);
            $fresh = new Process([PHP_BINARY, '-d', 'memory_limit='.ini_get('memory_limit'), $tool.'/bin/architecture-kit',
                '--project', $this->root, '--tool-home', $this->home, 'map', 'prepare']);
            $fresh->run();
            $this->assertSame(0, $fresh->getExitCode(), $fresh->getErrorOutput());
            $prepared = json_decode($fresh->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('ready', $prepared['availability'], $fresh->getOutput());
            $stale = $client->tool('architecture-search', ['query' => 'alpha']);
            $body = $stale['result']['structuredContent'];
            $this->record('old-mcp-after-upgrade', $stale);
            $this->assertSame('restart_required', $body['reason']);
            $this->assertSame('unverified', $body['freshness']);
            $this->assertArrayNotHasKey('result', $body);
            $fresh = new Process([PHP_BINARY, '-d', 'memory_limit=128M', $tool.'/bin/architecture-kit',
                '--project', $this->root, '--tool-home', $this->home, 'search', 'alpha', '--agent']);
            $fresh->run();
            $this->assertSame(0, $fresh->getExitCode(), $fresh->getErrorOutput());
            $body = json_decode($fresh->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->record('fresh-cli-after-upgrade', $body);
            $this->assertSame('last_confirmed', $body['freshness']);
            $this->assertNull($body['fresh']);
            $this->assertNotEmpty($body['candidates']);
        } finally {
            $this->record('old-mcp-transcript', ['transcript' => $client->transcript]);
            $client->close();
        }
    }

    public function test_native_bootstrap_query_and_real_selective_update(): void
    {
        $prepared = $this->runtime->map('prepare');
        $this->record('prepare', $prepared);
        $this->assertSame('ready', $prepared['availability'], json_encode($prepared));
        $this->assertSame('graph-runtime-v2', $prepared['protocol']);
        $this->assertTrue($prepared['measurements']['build']['admission']['admitted']);
        $this->assertSame([], $prepared['measurements']['build']['admission']['failed_conditions']);
        $this->assertRuntimeSchema($prepared);
        $a = $this->runtime->query('search', ['query' => 'alpha']);
        $this->record('query-a', $a);
        $this->assertSame('last_confirmed', $a['freshness'], json_encode($a));
        $this->assertSame(1, $a['result']['result']['match_count']);
        $this->assertSame(2, $a['result']['runtime_semantics']);
        $this->assertSame($a['snapshot_id'], $a['result']['confirmation']['snapshot_id']);
        $at = microtime(true);
        file_put_contents($this->root.'/app/A.php', '<?php namespace App; class A { function beta() {} }');
        do {
            usleep(30000);
            $b = $this->runtime->query('search', ['query' => 'beta']);
            $this->record('query-b', $b);
        } while (($b['result']['result']['match_count'] ?? 0) !== 1 && microtime(true) - $at < 8);
        $this->assertSame(1, $b['result']['result']['match_count'] ?? null, json_encode($b));
        $this->assertSame('selective_patch', $b['execution_mode']);
        $this->assertGreaterThanOrEqual(1, $b['measurements']['build']['reused_calls']);
        $this->assertNotSame($a['snapshot_id'], $b['snapshot_id']);
        $this->assertSame('last_confirmed', $b['freshness']);
        $this->assertSame($b['confirmation']['observed_sequence'], $b['confirmation']['applied_sequence']);
        $store = new GraphSegmentStore(new Filesystem, $this->runtime->directory().'/product-'.$b['confirmation']['product_key']);
        $restored = $store->read($b['confirmation']['product_key'], CatalogState::FIELDS,
            manifestFields: [...CatalogState::FIELDS, ...CatalogProductSet::DERIVED]);
        $this->assertSame('hit', $restored['status']);
        $actual = $restored['state'];
        array_push($actual['diagnostics'], ...$restored['metadata']['settings_diagnostics']);
        $oraclePath = $this->root.'/fresh-oracle.json';
        $oracleProcess = new Process([PHP_BINARY, '-d', 'memory_limit=128M', dirname(__DIR__, 2).'/Support/graph-runtime-oracle.php',
            $this->root, $this->home, $oraclePath]);
        $oracleProcess->run();
        $this->assertSame(0, $oracleProcess->getExitCode(), $oracleProcess->getErrorOutput());
        $oracle = json_decode(file_get_contents($oraclePath), true, flags: JSON_THROW_ON_ERROR);
        $this->record('fresh-oracle-process', ['argv' => $oracleProcess->getCommandLine(), 'exit' => $oracleProcess->getExitCode(),
            'stdout' => $oracleProcess->getOutput(), 'stderr' => $oracleProcess->getErrorOutput()]);
        $this->record('full-oracle', ['actual' => $actual, 'oracle' => $oracle]);
        $this->assertSame($oracle, $actual);
    }

    public function test_restart_restores_the_complete_set_without_parser_or_resolver_and_uses_a_new_watch_session(): void
    {
        $a = $this->runtime->map('prepare');
        $this->assertSame('ready', $a['availability'], json_encode($a));
        $events = new StateStore($this->home);
        $before = count(array_filter($events->read('test-events'), static fn ($event) => $event['stage'] === 'source.parse'));
        $directory = $this->runtime->directory();
        $coordinator = (new StateStore($directory))->read('coordinator');
        $this->runtime->request(['action' => 'shutdown']);
        $until = microtime(true) + 3;
        do {
            usleep(10000);
            clearstatcache();
        } while (file_exists($directory.'/'.$coordinator['endpoint']) && microtime(true) < $until);
        $b = $this->runtime->map('prepare');
        $this->record('restored-session', $b);
        $this->assertSame('ready', $b['availability'], json_encode($b));
        $this->assertSame('restore', $b['execution_mode']);
        $this->assertSame('hit', $b['cache']['read']['status']);
        $this->assertNotSame($a['watch_session'], $b['watch_session']);
        $this->assertSame('last_confirmed', $b['freshness']);
        $this->assertSame($before, count(array_filter($events->read('test-events'), static fn ($event) => $event['stage'] === 'source.parse')));
        $this->assertSame(0, $b['measurements']['build']['executed_calls']);
    }

    public function test_real_native_loss_and_helper_restart_are_unverified_until_full_reconcile(): void
    {
        $a = $this->runtime->map('prepare');
        $control = new StateStore($this->home);
        foreach (['watch_loss', 'watch_close'] as $fault) {
            $oldSession = $a['watch_session'];
            $control->update('test-control', static fn () => [$fault => true, 'barrier' => 'build.before']);
            $until = microtime(true) + 5;
            do {
                usleep(10000);
                $stale = $this->runtime->map('status', $a['job_id']);
            } while ($stale['freshness'] !== 'unverified' && microtime(true) < $until);
            $this->record($fault.'-unverified', $stale);
            $this->assertSame('unverified', $stale['freshness'], json_encode($stale));
            $this->assertNotNull($stale['confirmation']['reason']);
            $control->update('test-control', static fn () => []);
            $until = microtime(true) + 7;
            do {
                usleep(30000);
                $a = $this->runtime->map('prepare');
            } while ($a['freshness'] !== 'last_confirmed' && microtime(true) < $until);
            $this->record($fault.'-recovered', $a);
            $this->assertSame('last_confirmed', $a['freshness'], json_encode($a));
            $this->assertSame('full_content_pre_post', $a['basis']);
            if ($fault === 'watch_close') {
                $this->assertNotSame($oldSession, $a['watch_session']);
            }
        }
    }

    public function test_every_public_consumer_preserves_v2_and_matches_its_schema(): void
    {
        GraphRuntime::enable();
        $files = new Filesystem;
        $payloads = [
            'discovery' => [(new ArchitectureDiscovery($files, $this->root))->search('alpha'), SearchSchema::get()],
            'path' => [(new ArchitecturePath($files, $this->root))->inspect('App\\A', 'App\\B'), PathSchema::get()],
            'impact' => [(new ArchitectureImpact($files, $this->root))->inspect('App\\A'), ImpactSchema::get()],
            'reach' => [(new ArchitectureReach($files, $this->root))->inspect('App\\A'), ReachSchema::get()],
            'context' => [(new AgentOutput)->architectureContext((new ArchitectureContext($files, $this->root))->inspect('App\\A', [])),
                (new AgentOutput)->schema('architecture-context')],
        ];
        $validator = new \ReflectionMethod(AgentOutputSchemaTest::class, 'matchesSchema');
        $instance = new AgentOutputSchemaTest('test_it_exposes_a_stable_audit_agent_output_schema');
        foreach ($payloads as $operation => [$body, $schema]) {
            $this->record('consumer-'.$operation, $body);
            $this->assertTrue($body['ok'], json_encode($body));
            $this->assertSame(2, $body['runtime_semantics']);
            $this->assertSame('last_confirmed', $body['freshness'], json_encode($body));
            $this->assertTrue($validator->invoke($instance, $body, $schema), $operation.' '.json_encode($body));
        }
        $client = new WorktreeMcpClient(['--project', $this->root, '--tool-home', $this->home, 'mcp']);
        try {
            $client->initialize();
            foreach (['architecture-search' => ['query' => 'alpha'], 'architecture-graph' => ['subject' => 'App\\A']] as $tool => $arguments) {
                $response = $client->tool($tool, $arguments);
                $this->record('mcp-'.$tool, $response);
                $body = $response['result']['structuredContent'];
                $this->assertTrue($body['ok'], json_encode($body));
                $this->assertSame(2, $body['runtime_semantics']);
                $this->assertSame('last_confirmed', $body['freshness']);
                $this->assertSame($body['confirmation'], $body['runtime']['confirmation']);
                $this->assertLessThanOrEqual(65536, strlen(json_encode($body, JSON_THROW_ON_ERROR)));
            }
        } finally {
            $client->close();
        }
    }

    public function test_periodic_content_polling_detects_a_same_stat_change_without_an_event(): void
    {
        if (getenv('ARCHITECTURE_KIT_W04_PERIODIC') !== '1') {
            self::markTestSkipped('Opt in once with ARCHITECTURE_KIT_W04_PERIODIC=1 for the real >=30s content-polling scenario.');
        }
        // This case intentionally has no installed native observer. Other tests prove native events.
        unlink($this->home.'/runtimes/0.1.0/'.RuntimePlatform::current().'/architecture-kit-runtime');
        $a = $this->runtime->map('prepare');
        $this->assertSame('ready', $a['availability'], json_encode($a));
        $this->assertSame('polling', $a['confirmation']['watch_health']);
        $path = $this->root.'/app/A.php';
        $stat = stat($path);
        $at = microtime(true);
        file_put_contents($path, str_replace('alpha', 'delta', file_get_contents($path)));
        touch($path, $stat['mtime']);
        $this->assertSame($stat['size'], filesize($path));
        $initial = $this->runtime->query('search', ['query' => 'delta']);
        $this->assertSame(0, $initial['result']['result']['match_count']);
        $this->assertSame('last_confirmed', $initial['freshness']);
        $this->assertSame($a['confirmed_at'], $initial['confirmed_at']);
        $until = microtime(true) + 36;
        do {
            usleep(200000);
            $b = $a;
            foreach (glob($this->runtime->directory().'/job-*.json') ?: [] as $file) {
                $candidate = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                if (($candidate['job_id'] ?? null) !== $a['job_id'] && ($candidate['availability'] ?? '') === 'ready') {
                    $b = $this->runtime->map('status', $candidate['job_id']);
                }
            }
        } while (($b['snapshot_id'] ?? null) === $a['snapshot_id'] && microtime(true) < $until);
        $result = $this->runtime->query('search', ['query' => 'delta']);
        $this->record('same-stat-without-event', ['elapsed_seconds' => microtime(true) - $at, 'before' => $a, 'after' => $result,
            'mtime_preserved' => filemtime($path) === $stat['mtime'], 'size_preserved' => filesize($path) === $stat['size'], 'three_second_claim' => false]);
        $this->assertSame(1, $result['result']['result']['match_count']);
        $this->assertNotSame($a['last_full_scan_at'], $result['last_full_scan_at']);
        $this->assertGreaterThanOrEqual(30, microtime(true) - $at);
    }

    public function test_active_query_survives_a_refused_transition_and_fresh_recomposition(): void
    {
        $path = $this->root.'/.architecture-kit/settings.json';
        $settings = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $settings['graph_runtime']['transition_budget_bytes'] = 1;
        file_put_contents($path, json_encode($settings, JSON_THROW_ON_ERROR));
        $context = new RepositoryContext($this->root, $this->home);
        RepositoryContext::activate($context);
        Host::refresh($context);
        $this->runtime = new GraphRuntime($context);
        $a = $this->runtime->map('prepare');
        $this->assertSame('ready', $a['availability'], json_encode($a));
        $control = new StateStore($this->home);
        $control->update('test-control', static fn () => ['barrier' => 'query.after', 'job' => $a['job_id']]);
        $this->queryProcess = new Process([PHP_BINARY, '-d', 'memory_limit=128M', dirname(__DIR__, 2).'/Support/graph-runtime-client.php',
            $this->root, $this->home, json_encode(['action' => 'ready-query', 'operation' => 'search', 'arguments' => ['query' => 'alpha']], JSON_THROW_ON_ERROR)]);
        $this->queryProcess->start();
        $until = microtime(true) + 5;
        do {
            usleep(10000);
            $held = array_filter($control->read('test-events'), static fn ($event) => $event['stage'] === 'query.after' && $event['job'] === $a['job_id']);
        } while ($held === [] && microtime(true) < $until);
        $this->assertNotEmpty($held);
        file_put_contents($this->root.'/app/A.php', '<?php namespace App; class A { function beta() {} }');
        $until = microtime(true) + 8;
        do {
            usleep(30000);
            $b = $a;
            foreach (glob($this->runtime->directory().'/job-*.json') ?: [] as $file) {
                $candidate = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                if (($candidate['job_id'] ?? null) !== $a['job_id'] && ($candidate['availability'] ?? '') === 'ready') {
                    $b = $this->runtime->map('status', $candidate['job_id']);
                }
            }
        } while (($b['snapshot_id'] ?? null) === $a['snapshot_id'] && microtime(true) < $until);
        $this->record('admission-b', $b);
        $this->assertNotSame($a['snapshot_id'], $b['snapshot_id'] ?? null, json_encode($b));
        $this->assertSame('full_recompose', $b['execution_mode']);
        $this->assertSame('transition_budget', $b['execution_reason']);
        $this->assertTrue($this->queryProcess->isRunning());
        $control->update('test-control', static fn () => []);
        $this->queryProcess->wait();
        $this->assertSame(0, $this->queryProcess->getExitCode(), $this->queryProcess->getErrorOutput());
        $old = json_decode($this->queryProcess->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->record('admission-old-lease', $old);
        $this->assertSame($a['snapshot_id'], $old['snapshot_id']);
        $this->assertSame(1, $old['result']['result']['match_count']);
        $this->assertSame('stale', $old['freshness']);
        $new = $this->runtime->query('search', ['query' => 'beta']);
        $this->assertSame(1, $new['result']['result']['match_count']);
    }

    public function test_two_real_repository_processes_share_lru_and_drain_the_old_lease(): void
    {
        $settingsPath = $this->root.'/.architecture-kit/settings.json';
        $settings = json_decode(file_get_contents($settingsPath), true, flags: JSON_THROW_ON_ERROR);
        $settings['graph_runtime'] = ['resident_budget_bytes' => 512 * 1024 * 1024, 'transition_budget_bytes' => 512 * 1024 * 1024];
        file_put_contents($settingsPath, json_encode($settings, JSON_THROW_ON_ERROR));
        $firstContext = new RepositoryContext($this->root, $this->home);
        RepositoryContext::activate($firstContext);
        Host::refresh($firstContext);
        $this->runtime = new GraphRuntime($firstContext);
        $a = $this->runtime->map('prepare');
        $this->assertSame('ready', $a['availability'], json_encode($a));
        $control = new StateStore($this->home);
        $control->update('test-control', static fn () => ['barrier' => 'query.after', 'job' => $a['job_id']]);
        $this->queryProcess = new Process([PHP_BINARY, '-d', 'memory_limit=128M', dirname(__DIR__, 2).'/Support/graph-runtime-client.php',
            $this->root, $this->home, json_encode(['action' => 'ready-query', 'operation' => 'search', 'arguments' => ['query' => 'alpha']], JSON_THROW_ON_ERROR)]);
        $this->queryProcess->start();
        $until = microtime(true) + 4;
        do {
            usleep(10000);
            $held = array_filter($control->read('test-events'), static fn ($event) => $event['stage'] === 'query.after' && $event['job'] === $a['job_id']);
        } while ($held === [] && microtime(true) < $until);
        $this->assertNotEmpty($held);
        $secondRoot = dirname($this->root).'/'.basename($this->root).'-second';
        mkdir($secondRoot.'/app', 0700, true);
        mkdir($secondRoot.'/.architecture-kit', 0700, true);
        copy($this->root.'/composer.json', $secondRoot.'/composer.json');
        copy($settingsPath, $secondRoot.'/.architecture-kit/settings.json');
        file_put_contents($secondRoot.'/.git', 'synthetic-second-root');
        file_put_contents($secondRoot.'/app/A.php', '<?php namespace App; class A { function secondRepositoryOnly() {} }');
        $secondContext = new RepositoryContext($secondRoot, $this->home);
        RepositoryContext::activate($secondContext);
        Host::refresh($secondContext);
        $second = new GraphRuntime($secondContext);
        try {
            $b = $second->map('prepare');
            $this->assertSame('ready', $b['availability'], json_encode($b));
            $until = microtime(true) + 4;
            do {
                usleep(50000);
                $registry = $control->read('graph-resources-v2');
            } while (count($registry['residents'] ?? []) < 2 && microtime(true) < $until);
            $this->assertCount(2, $registry['residents']);
            $measured = array_sum(array_column($registry['residents'], 'bytes')) + array_sum(array_column($registry['controllers'], 'bytes'));
            $pressureLimit = $measured - intdiv($registry['residents'][$a['instance_token']]['bytes'], 2);
            $admin = new Process([PHP_BINARY, '-d', 'memory_limit=128M', dirname(__DIR__, 3).'/bin/architecture-kit',
                '--tool-home', $this->home, 'runtime', 'global-limits', 'set', (string) $pressureLimit, (string) (512 * 1024 * 1024)]);
            $admin->run();
            $this->assertSame(0, $admin->getExitCode(), $admin->getErrorOutput());
            $administration = json_decode($admin->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('global_user_tool_home', $administration['scope']);
            $this->assertSame($pressureLimit, $administration['limits']['resident_bytes']);
            $this->record('global-explicit-administration', ['argv' => $admin->getCommandLine(), 'exit' => $admin->getExitCode(),
                'before' => $registry['limits'], 'after' => $administration, 'measured_bytes' => $measured]);
            $until = microtime(true) + 5;
            do {
                usleep(50000);
                $registry = $control->read('graph-resources-v2');
                $old = $registry['residents'][$a['instance_token']] ?? [];
            } while (! ($old['retiring'] ?? false) && microtime(true) < $until);
            $this->record('global-lru', ['registry' => $registry, 'first' => $a, 'second' => $b]);
            $this->assertSame('resident_budget_lru', $old['reason'] ?? null, json_encode($registry));
            $this->assertTrue($this->queryProcess->isRunning());
            $this->assertTrue(posix_kill($a['pid'], 0));
            RepositoryContext::activate($firstContext);
            Host::refresh($firstContext);
            $denied = $this->runtime->request(['action' => 'query', 'job_id' => $a['job_id'], 'operation' => 'search', 'arguments' => ['query' => 'alpha']]);
            $this->assertSame('unavailable', $denied['availability']);
            $this->assertArrayNotHasKey('result', $denied);
            $control->update('test-control', static fn () => []);
            $this->queryProcess->wait();
            $leased = json_decode($this->queryProcess->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->record('global-lru-drained', $leased);
            $this->assertSame($a['snapshot_id'], $leased['snapshot_id']);
            $this->assertSame(1, $leased['result']['result']['match_count']);
            $until = microtime(true) + 3;
            do {
                usleep(10000);
            } while (posix_kill($a['pid'], 0) && microtime(true) < $until);
            $this->assertFalse(posix_kill($a['pid'], 0));
            $restored = $this->runtime->map('prepare');
            $this->assertSame('hit', $restored['cache']['read']['status']);
            $this->assertSame('resident_budget_lru', $restored['restore_reason']);
        } finally {
            $control->update('test-control', static fn () => []);
            RepositoryContext::activate($secondContext);
            Host::refresh($secondContext);
            $second->request(['action' => 'shutdown']);
            RepositoryContext::activate($firstContext);
            Host::refresh($firstContext);
        }
    }

    public function test_a_finite_burst_and_continuous_writer_keep_building_and_publish_the_final_bytes(): void
    {
        $a = $this->runtime->map('prepare');
        $path = $this->root.'/app/A.php';
        $started = microtime(true);
        $writer = new Process([PHP_BINARY, '-r', '$path=$argv[1]; for($i=0;$i<35;$i++){file_put_contents($path,"<?php namespace App; class BurstMarker".$i." {}");usleep(30000);}', $path]);
        $writer->start();
        $writer->wait();
        $this->assertSame(0, $writer->getExitCode(), $writer->getErrorOutput());
        $finished = microtime(true);
        $until = microtime(true) + 8;
        do {
            usleep(30000);
            $last = $this->runtime->query('search', ['query' => 'BurstMarker34']);
        } while ((($last['result']['result']['match_count'] ?? 0) !== 1 || ($last['freshness'] ?? '') !== 'last_confirmed'
            || ($last['confirmation']['observed_sequence'] ?? null) !== ($last['confirmation']['applied_sequence'] ?? null)) && microtime(true) < $until);
        $this->assertSame(1, $last['result']['result']['match_count'] ?? null, json_encode($last));
        $events = (new StateStore($this->home))->read('test-events');
        $during = array_values(array_filter($events, static fn ($event) => $event['stage'] === 'build.before'
            && $event['job'] !== $a['job_id'] && $event['time'] >= $started && $event['time'] < $finished));
        $this->record('continuous-burst', ['started' => $started, 'finished' => $finished, 'builds_during_writer' => $during, 'final' => $last]);
        $this->assertNotEmpty($during, 'The maximum coalescing window starts work during the writer, rather than waiting forever.');
        $observed = array_values(array_filter($events, static fn ($event) => $event['stage'] === 'watch.event'
            && ($event['event_kind'] ?? null) === 'changes' && $event['time'] >= $started));
        $this->assertNotEmpty($observed);
        // Coalescing schedules a builder. Its separate PHP startup is not part
        // of this window, but the assertion above still requires actual work during writes.
        $scheduled = [];
        foreach (glob($this->runtime->directory().'/launch-*.json') ?: [] as $path) {
            $launch = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (($launch['job_id'] ?? null) === $during[0]['job'] && is_float($launch['started_at'] ?? null)) {
                $scheduled[] = $launch['started_at'];
            }
        }
        // Immutable launch records retain the first attempt even if this job retried.
        $this->assertNotEmpty($scheduled);
        $firstScheduled = min($scheduled);
        $this->record('continuous-burst-window', ['observed_at' => $observed[0]['time'],
            'scheduled_at' => $firstScheduled, 'worker_started_at' => $during[0]['time']]);
        $this->assertLessThan(0.5, $firstScheduled - $observed[0]['time']);
        $this->assertSame($last['confirmation']['observed_sequence'], $last['confirmation']['applied_sequence']);
        $this->assertSame('last_confirmed', $last['freshness']);
    }

    /** @param array<string, mixed> $value */
    private function record(string $stage, array $value): void
    {
        file_put_contents($this->root.'/responses.ndjson', json_encode(['stage' => $stage, 'at' => microtime(true), 'response' => $value], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    }

    public function test_composer_lock_refresh_and_installed_metadata_allow_a_new_map(): void
    {
        foreach (['composer.lock', 'vendor/composer/installed.json'] as $path) {
            $absolute = $this->root.'/'.$path;
            (new Filesystem)->ensureDirectoryExists(dirname($absolute));
            file_put_contents($absolute, json_encode(['packages' => [], 'packages-dev' => [], 'content-hash' => 'before']));
            $context = new RepositoryContext($this->root, $this->home);
            RepositoryContext::activate($context);
            Host::refresh($context);
            $runtime = new GraphRuntime($context);
            $before = $runtime->map('prepare');
            $this->assertSame('ready', $before['availability'], json_encode($before));
            $oldController = (new StateStore($runtime->directory()))->read('coordinator');
            $oldEndpoint = $runtime->directory().'/'.$oldController['endpoint'];
            $routing = $context->routingIdentity();
            file_put_contents($absolute, json_encode(['packages' => [], 'packages-dev' => [], 'content-hash' => 'after']));
            $fresh = new RepositoryContext($this->root, $this->home);
            $this->assertNotSame($routing, $fresh->routingIdentity());
            RepositoryContext::activate($fresh);
            Host::refresh($fresh);
            $runtime = new GraphRuntime($fresh);
            $after = $runtime->map('prepare');
            $this->assertSame('ready', $after['availability'], json_encode($after));
            $this->assertSame('last_confirmed', $after['freshness']);
            $this->assertNotSame($before['job_id'], $after['job_id']);
            $this->assertNotSame($before['analysis_fingerprint'], $after['analysis_fingerprint']);
            $until = microtime(true) + 5;
            do {
                usleep(10000);
                clearstatcache(true, $oldEndpoint);
            } while (file_exists($oldEndpoint) && microtime(true) < $until);
            $this->assertFileDoesNotExist($oldEndpoint);
            $this->assertNotEmpty($runtime->query('search', ['query' => 'alpha'])['result']['result']['candidates']);
        }
    }

    public function test_restore_admission_refuses_before_hydration_after_global_limit_is_lowered(): void
    {
        $ready = $this->runtime->map('prepare');
        $this->assertSame('success', $ready['cache']['write']['status']);
        GraphRuntimeCleanup::stop($this->home);
        GraphResources::setGlobal($this->home, 1, 1);
        $offset = count((new StateStore($this->home))->read('test-events'));
        $refused = $this->runtime->map('prepare');
        $this->assertSame('failed', $refused['availability'], json_encode($refused));
        $this->assertSame('preparation_required:resident_budget', $refused['reason']);
        $this->assertTrue($refused['measurements']['admission']['global_condition']['exceeded']);
        $this->assertSame(0, $refused['measurements']['admission']['facts_bytes']);
        $this->assertGreaterThan(0, $refused['measurements']['admission']['candidate_bytes']);
        $this->assertNotContains('source.parse', array_column(array_slice((new StateStore($this->home))->read('test-events'), $offset), 'stage'));
    }

    public function test_unchanged_reconcile_cache_keeps_the_same_segments_and_pointer(): void
    {
        $first = $this->runtime->map('prepare');
        $directory = $this->runtime->directory();
        $pointers = glob($directory.'/product-*/active-*.json');
        $this->assertCount(1, $pointers);
        $pointer = file_get_contents($pointers[0]);
        $manifests = glob($directory.'/product-*/*/manifest.json');
        $this->assertCount(1, $manifests);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            GraphRuntimeCleanup::stop($this->home);
            $restored = $this->runtime->map('prepare');
            $this->assertSame('ready', $restored['availability'], json_encode($restored));
            $this->assertSame('hit', $restored['cache']['read']['status']);
            $this->assertSame('restore', $restored['execution_mode']);
            $this->assertSame('skip', $restored['cache']['write']['status']);
            $this->assertSame('unchanged_product_set', $restored['cache']['write']['reason']);
            $this->assertSame($restored['snapshot_id'], $restored['confirmation']['generation']);
            $built = array_values(array_filter((new StateStore($this->home))->read('test-events'), static fn ($event) => $event['stage'] === 'build.after'));
            $this->assertSame($built[0]['index_digest'], $built[count($built) - 1]['index_digest']);
            $this->assertSame($built[0]['diagnostics_digest'], $built[count($built) - 1]['diagnostics_digest']);
            $this->assertSame($pointer, file_get_contents($pointers[0]));
            $this->assertSame($manifests, glob($directory.'/product-*/*/manifest.json'));
        }
    }

    public function test_restore_then_selective_patch_reuses_independent_operations_and_matches_full_oracle(): void
    {
        $first = $this->runtime->map('prepare');
        $this->assertSame('ready', $first['availability']);
        GraphRuntimeCleanup::stop($this->home);
        $restored = $this->runtime->map('prepare');
        $this->assertSame('restore', $restored['execution_mode']);
        $this->assertSame('unchanged_product_set', $restored['cache']['write']['reason']);
        // Reuse the complete existing mutation/oracle scenario after a real restart.
        // It requires selective_patch, reused_calls, full eight-field and query parity.
        $this->test_native_bootstrap_query_and_real_selective_update();
    }

    public function test_prehydrate_transition_admission_uses_transition_ceiling_while_old_query_is_pinned(): void
    {
        $source = '<?php namespace App; class Bulk {';
        for ($i = 0; $i < 300; $i++) {
            $source .= ' function m'.$i.'_'.str_repeat('a', 512).'() {}';
        }
        file_put_contents($this->root.'/app/Bulk.php', $source.'}');
        $a = $this->runtime->map('prepare');
        $this->assertSame('ready', $a['availability'], json_encode($a));
        $control = new StateStore($this->home);
        $control->update('test-control', static fn () => ['barriers' => ['query.after' => $a['job_id'], 'restore.before' => null]]);
        $this->queryProcess = new Process([PHP_BINARY, '-d', 'memory_limit=128M', dirname(__DIR__, 2).'/Support/graph-runtime-client.php',
            $this->root, $this->home, json_encode(['action' => 'ready-query', 'operation' => 'search', 'arguments' => ['query' => 'alpha']], JSON_THROW_ON_ERROR)]);
        $this->queryProcess->start();
        $until = microtime(true) + 10;
        do {
            usleep(10000);
            $held = array_filter($control->read('test-events'), static fn ($event) => $event['stage'] === 'query.after' && $event['job'] === $a['job_id']);
        } while ($held === [] && microtime(true) < $until);
        $this->assertNotEmpty($held);
        file_put_contents($this->root.'/app/A.php', '<?php namespace App; class A { function beta() {} }');
        $until = microtime(true) + 10;
        do {
            usleep(10000);
            $waiting = array_values(array_filter($control->read('test-events'), static fn ($event) => $event['stage'] === 'restore.before'));
        } while ($waiting === [] && microtime(true) < $until);
        $this->assertNotEmpty($waiting);
        $builder = $waiting[count($waiting) - 1];
        $pointers = glob($this->runtime->directory().'/product-*/active-*.json');
        $pointer = json_decode(file_get_contents($pointers[0]), true, flags: JSON_THROW_ON_ERROR);
        $manifest = json_decode(file_get_contents(dirname($pointers[0]).'/'.$pointer['generation'].'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $reservation = 3 * 1024 * 1024;
        foreach ($manifest['fields'] as $field) {
            foreach ($field['segments'] as $segment) {
                $reservation += 14 * $segment['decoded_bytes'] + $segment['bytes'];
            }
        }
        $until = microtime(true) + 10;
        do {
            usleep(10000);
            $registry = $control->read('graph-resources-v2');
        } while (! isset($registry['residents'][$a['instance_token']]) && microtime(true) < $until);
        $this->assertArrayHasKey($a['instance_token'], $registry['residents']);
        $retained = array_sum(array_column($registry['residents'], 'bytes')) + array_sum(array_column($registry['controllers'] ?? [], 'bytes'));
        $rss = GraphProcessMemory::tree($builder['pid'])['rss_bytes'];
        $this->assertNotNull($rss);
        $resident = $retained + $rss + intdiv($reservation * 9, 10);
        $transition = max(512 * 1024 * 1024, $retained + $rss + 2 * $reservation);
        GraphResources::setGlobal($this->home, $resident, $transition);
        $control->update('test-control', static fn () => ['barriers' => ['query.after' => $a['job_id']]]);
        $b = $this->runtime->map('prepare');
        $this->assertSame('ready', $b['availability'], json_encode($b));
        $this->assertSame('selective_patch', $b['execution_mode']);
        $this->assertFalse($b['transition_refused'] ?? false);
        $reports = array_values(array_filter($control->read('test-events'), static fn ($event) => $event['stage'] === 'resource.admission'
            && $event['job'] === $b['job_id'] && ($event['admission']['facts_bytes'] ?? null) === 0));
        $this->assertNotEmpty($reports);
        $report = $reports[count($reports) - 1]['admission'];
        $this->assertTrue($report['transition']);
        $this->assertTrue($report['admitted']);
        $this->assertGreaterThan($resident, $report['global_condition']['counted_bytes']);
        $this->assertSame($transition, $report['global_condition']['limit_bytes']);
        $this->assertTrue($this->queryProcess->isRunning());
        $control->update('test-control', static fn () => []);
        $this->assertSame(0, $this->queryProcess->wait());
        $old = json_decode($this->queryProcess->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($a['snapshot_id'], $old['snapshot_id']);
        $this->assertSame(1, $old['result']['result']['match_count']);
        $this->record('prehydrate-transition-admission', ['old' => $a, 'new' => $b, 'report' => $report,
            'resident' => $resident, 'transition' => $transition, 'reservation' => $reservation]);
    }
}
