<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature\Standalone;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphEpoch;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogSettings;
use GracjanKubicki\ArchitectureKit\Context\GraphPage;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use GracjanKubicki\ArchitectureKit\Context\GraphSource;
use GracjanKubicki\ArchitectureKit\Discovery\SearchSchema;
use GracjanKubicki\ArchitectureKit\Impact\ImpactSchema;
use GracjanKubicki\ArchitectureKit\Impact\PathSchema;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\Reach\ReachSchema;
use GracjanKubicki\ArchitectureKit\Standalone\GraphProductSpec;
use GracjanKubicki\ArchitectureKit\Standalone\GraphProtocol;
use GracjanKubicki\ArchitectureKit\Standalone\GraphRuntime;
use GracjanKubicki\ArchitectureKit\Standalone\Host;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use GracjanKubicki\ArchitectureKit\Tests\Support\EngineFixture;
use GracjanKubicki\ArchitectureKit\Tests\Support\GraphRuntimeClockProbe;
use GracjanKubicki\ArchitectureKit\Tests\Unit\AgentOutputSchemaTest;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class GraphRuntimeProcessTest extends TestCase
{
    private string $root;

    private string $home;

    private GraphRuntime $runtime;

    private StateStore $state;

    /** @var list<Process> */
    private array $processes = [];

    protected function setUp(): void
    {
        $artifacts = getenv('ARCHITECTURE_KIT_TEST_ARTIFACT_DIRECTORY') ?: dirname(__DIR__, 3).'/build/graph-performance-implementation/T03';
        $base = $artifacts.'/process-'.bin2hex(random_bytes(6));
        $this->root = $base.'/repo';
        $this->home = realpath(sys_get_temp_dir()).'/kit-t03-'.basename($base).'/home';
        mkdir($base, 0700, true);
        file_put_contents($base.'/home-provenance.json', json_encode(['tool_home' => $this->home, 'fixture_root' => $this->root,
            'backend' => EngineFixture::selected(), 'purpose' => 'T03 private process fixture; no global installation'], JSON_THROW_ON_ERROR));
        mkdir($this->root.'/app/Actions', 0700, true);
        mkdir($this->root.'/app/Services', 0700, true);
        mkdir($this->root.'/.architecture-kit', 0700, true);
        file_put_contents($this->root.'/.git', 'synthetic-root-marker');
        file_put_contents($this->root.'/composer.json', '{"name":"fixture/t03","type":"project","require":{"php":"^8.3"},"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        file_put_contents($this->root.'/.architecture-kit/settings.json', json_encode(['enabled' => ['actions'], 'analysis' => ['engine' => EngineFixture::selected()], 'audit' => ['missing_test' => 'off', 'cache' => true]], JSON_THROW_ON_ERROR));
        file_put_contents($this->root.'/app/Services/Dependency.php', '<?php namespace App\\Services; class Dependency { public function handle(): void {} }');
        file_put_contents($this->root.'/app/Actions/Caller.php', '<?php namespace App\\Actions; class Caller { public function handle(\\App\\Services\\Dependency $dependency): void { $dependency->handle(); } }');
        if (EngineFixture::selected() === 'mago') {
            EngineFixture::install($this->home);
        }
        $this->state = new StateStore($this->home);
        $context = new RepositoryContext($this->root, $this->home);
        RepositoryContext::activate($context);
        AnalysisRuntime::activate(null);
        Host::create($this->home);
        GraphRuntime::instrument(dirname(__DIR__, 2).'/Support/graph-runtime-entrypoint.php', null);
        $this->runtime = new GraphRuntime($context);
    }

    protected function tearDown(): void
    {
        $this->state->update('test-control', static fn () => []);
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach (glob($this->home.'/repositories/*/runtime/*/coordinator.json') ?: [] as $path) {
            $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            try {
                $socket = GraphProtocol::socket(dirname($path), $record['endpoint']);
                GraphProtocol::send($socket, [...$record, 'action' => 'shutdown', 'request_id' => bin2hex(random_bytes(16))]);
                fclose($socket);
            } catch (\Throwable) {
            }
        }
        GraphRuntime::instrument(null, null);
        GraphRuntime::enable(false);
        RepositoryContext::activate(null);
    }

    public static function builtInputProducts(): iterable
    {
        yield 'catalog-query' => [false, false];
        yield 'catalog-metadata' => [false, true];
        yield 'full-context-query' => [true, false];
        yield 'full-context-metadata' => [true, true];
    }

    public static function explicitRuntimePermissions(): iterable
    {
        yield 'read only' => [false, false, false, false];
        yield 'execution granted' => [true, false, false, false];
        yield 'routes granted' => [false, false, true, false];
        yield 'all granted advisory context' => [true, true, true, true];
    }

    #[DataProvider('explicitRuntimePermissions')]
    public function test_graph_children_preserve_explicit_permissions_without_booting_the_application(bool $execute, bool $write, bool $runtimeRoutes, bool $advisoryOnly): void
    {
        $marker = $this->home.'/application-booted';
        mkdir($this->root.'/bootstrap');
        file_put_contents($this->root.'/bootstrap/app.php', '<?php file_put_contents('.var_export($marker, true).', "unexpected");');
        $context = new RepositoryContext($this->root, $this->home, execute: $execute, write: $write, runtimeRoutes: $runtimeRoutes, advisoryOnly: $advisoryOnly);
        Host::refresh($context);
        $runtime = new GraphRuntime($context);
        $ready = $runtime->map('prepare');
        self::assertTrue($ready['ok'], json_encode($ready));
        self::assertSame('ready', $ready['availability']);
        self::assertSame('current', $ready['freshness']);
        $search = $runtime->query('search', ['query' => 'Caller']);
        self::assertTrue($search['ok'], json_encode($search));
        self::assertNotEmpty($search['result']['result']['candidates']);
        self::assertFileDoesNotExist($marker);
    }

    #[DataProvider('builtInputProducts')]
    public function test_built_from_b_cannot_become_current_when_job_inputs_return_to_a(bool $fullContext, bool $collectMetadata): void
    {
        $path = $this->root.'/app/Services/Dependency.php';
        $original = file_get_contents($path);
        $stat = stat($path);
        $this->barrier('build.before');
        $product = $fullContext ? GraphProductSpec::full($this->root, 'context', AuditScope::package(['app']), [], null, []) : null;
        $job = $this->runtime->request(['action' => 'prepare', 'product' => $product]);
        $this->until(fn () => count(array_filter($this->events(), static fn ($event) => $event['stage'] === 'build.before')) === 1);
        $builtBytes = '<?php namespace App\\Services; class Dependency { public function handle(): void {} public function onlyInB(): void {} }';
        file_put_contents($path, $builtBytes);
        $this->barrier(null);
        $built = [];
        $this->until(function () use ($job, &$built): bool {
            $built = $this->runtime->map('status', $job['job_id']);

            return $built['availability'] !== 'building';
        });
        self::assertSame('ready', $built['availability']);
        self::assertSame('stale', $built['last_verified_freshness']);
        file_put_contents($path, $original);
        touch($path, $stat['mtime']);
        // Use separate generations for metadata/query: forwarding either can
        // retire this inactive job, which must not mask the other's freshness.
        $response = $collectMetadata ? $this->runtime->map('result', $job['job_id']) : $this->runtime->request(['action' => 'query', 'job_id' => $job['job_id'],
            'operation' => $fullContext ? 'context' : 'search',
            'arguments' => $fullContext ? ['subject' => 'App\\Actions\\Caller', 'enabled' => ['actions']] : ['query' => 'onlyInB']]);
        $this->state->update('aba-built-input-proof', static fn () => ['job_a' => $job, 'built_b_status' => $built,
            'original_sha256' => hash('sha256', $original), 'original_mtime' => $stat['mtime'],
            'original_bytes' => $original, 'built_bytes' => $builtBytes, 'built_sha256' => hash('sha256', $builtBytes),
            'restored_sha256' => hash_file('sha256', $path), 'restored_bytes' => file_get_contents($path),
            'collect_metadata' => $collectMetadata, 'response_after_restoring_a' => $response]);
        self::assertNotSame('current', $response['freshness'], json_encode($response));
        self::assertArrayNotHasKey('result', $response);
    }

    public function test_worker_and_mcp_preserve_query_limits_without_poisoning_the_generation(): void
    {
        $files = new Filesystem;
        foreach (CatalogSettings::ROOTS as $directory) {
            $files->ensureDirectoryExists($this->root.'/'.$directory);
        }
        file_put_contents($this->root.'/app/Services/Middle.php', '<?php namespace App\\Services; class Middle extends Dependency {}');
        file_put_contents($this->root.'/app/Services/Top.php', '<?php namespace App\\Services; class Top extends Middle {}');
        $ready = $this->runtime->map('prepare');
        $source = (new GraphSource($files, $this->root, mode: AnalysisMode::Basic))->load();
        foreach ([1, 8, 1, 8] as $depth) {
            $arguments = ['subject' => 'App\\Services\\Dependency', 'mode' => 'impact', 'depth' => $depth];
            $result = (new GraphQuery($source['index']))->query($arguments['subject'], $arguments['mode'], depth: $depth);
            $diagnostics = $source['index']->diagnostics;
            if (! $result['traversal_complete']) {
                $diagnostics[] = ['code' => 'traversal_limit', 'message' => 'Traversal reached a depth, node or edge budget. Counts are lower bounds; a missing path is not a no-path result.'];
            }
            $result['analysis'] = [...$source['analysis'], ...$result['analysis'],
                'checks_complete' => $source['analysis']['checks_complete'] && $result['analysis']['checks_complete']];
            $direct = GraphPage::make('architecture-graph', $arguments, $source['snapshot'], $source['scope'], $diagnostics, $result, 'records', 4096);
            $worker = $this->runtime->query('graph', $arguments);
            self::assertSame($direct, $worker['result']);
            $mcp = $this->mcp('architecture-graph', $arguments);
            unset($mcp['runtime']);
            self::assertSame($direct, $mcp);
            self::assertNotEmpty($direct['result']['records']);
            self::assertSame($depth === 8, $direct['analysis']['checks_complete']);
            self::assertSame($depth === 8, $direct['analysis_complete']);
            self::assertSame($depth === 1, isset($direct['diagnostics']['summary']['traversal_limit']));
            if ($depth === 8) {
                self::assertStringContainsString('App\\\\Services\\\\Top', json_encode($direct));
            }
            self::assertSame($ready['diagnostics_digest'], $worker['diagnostics_digest']);
            self::assertSame($ready['diagnostics_by_code'], $worker['diagnostics_by_code']);
        }
        $events = array_values(array_filter($this->events(), static fn ($event) => in_array($event['stage'], ['query.before', 'query.after'], true)));
        self::assertCount(1, array_unique(array_column($events, 'index_digest')));
    }

    public function test_uncollected_stale_generations_release_admission_capacity_and_retire_their_workers(): void
    {
        $jobs = [];
        for ($generation = 0; $generation < GraphProtocol::JOBS + 3; $generation++) {
            $path = $this->root.'/app/Services/Version.php';
            file_put_contents($path, '<?php namespace App\\Services; class Version { const GENERATION = "a-'.$generation.'"; }');
            $this->barrier('build.before');
            $job = $this->runtime->request(['action' => 'prepare']);
            self::assertSame('building', $job['availability'], json_encode($job));
            $this->until(fn () => count(array_filter($this->events(), static fn ($event) => $event['stage'] === 'build.before' && $event['job'] === $job['job_id'])) === 1);
            file_put_contents($path, '<?php namespace App\\Services; class Version { const GENERATION = "b-'.$generation.'"; }');
            $this->barrier(null);
            $status = [];
            $this->until(function () use ($job, &$status): bool {
                $status = $this->runtime->map('status', $job['job_id']);

                return $status['availability'] !== 'building';
            });
            self::assertSame('ready', $status['availability']);
            self::assertSame('stale', $status['last_verified_freshness']);
            $jobs[] = $status;
        }
        // No result/query was sent to any of these completed stale workers.
        foreach (array_slice($jobs, 0, 3) as $old) {
            self::assertSame('unknown_or_expired_job', $this->runtime->map('status', $old['job_id'])['reason']);
            $this->until(static fn () => ! posix_kill($old['pid'], 0));
        }
        $recent = $this->runtime->map('status', $jobs[count($jobs) - 1]['job_id']);
        self::assertSame('ready', $recent['availability']);
        self::assertSame('stale', $recent['last_verified_freshness']);
        GraphRuntime::beginRequest();
        $fresh = $this->runtime->map('prepare');
        self::assertSame('current', $fresh['freshness']);
        self::assertNotContains($fresh['job_id'], array_column($jobs, 'job_id'));
        self::assertNotEmpty($this->runtime->query('search', ['query' => 'Version'])['result']['result']['candidates']);
    }

    public function test_terminal_job_history_releases_capacity_after_more_than_64_completed_generations(): void
    {
        $jobs = [];
        for ($generation = 0; $generation < GraphProtocol::JOBS + 3; $generation++) {
            file_put_contents($this->root.'/app/Services/Version.php', '<?php namespace App\\Services; class Version { const GENERATION = '.$generation.'; }');
            GraphRuntime::beginRequest();
            $ready = $this->runtime->map('prepare');
            self::assertSame('ready', $ready['availability'], json_encode($ready));
            self::assertSame('current', $ready['freshness']);
            self::assertNotContains($ready['job_id'], array_column($jobs, 'job_id'));
            $jobs[] = $ready;
        }
        $recent = $jobs[count($jobs) - 2];
        $this->runtime->request(['action' => 'deadline', 'job_id' => $recent['job_id']]);
        $known = 0;
        foreach ($jobs as $ordinal => $job) {
            $status = $this->runtime->map('status', $job['job_id']);
            if ($ordinal < 3) {
                self::assertSame('unknown_or_expired_job', $status['reason']);
                self::assertSame('unavailable', $status['availability']);
            } else {
                $known++;
                self::assertSame($job['job_id'], $status['job_id']);
            }
        }
        self::assertSame(GraphProtocol::JOBS, $known);
        $history = $this->runtime->map('result', $recent['job_id']);
        self::assertSame('generation_retired', $history['reason']);
        self::assertTrue($history['deadline_exceeded']);
        self::assertSame($recent['instance_token'], $history['instance_token']);
        self::assertSame('unknown_or_expired_job', $this->runtime->map('result', $jobs[0]['job_id'])['reason']);
        GraphRuntime::beginRequest();
        $query = $this->runtime->query('search', ['query' => 'Version']);
        self::assertSame($ready['job_id'], $query['job_id']);
        self::assertSame('current', $query['freshness']);
        self::assertNotEmpty($query['result']['result']['candidates']);
    }

    public function test_query_budget_is_finalized_after_the_response_for_catalog_fallback_and_full_products(): void
    {
        $product = GraphProductSpec::full($this->root, 'context', AuditScope::package(['app']), [], null, []);
        foreach ([['search', ['query' => 'Dependency'], null], ['context', ['subject' => 'App\\Actions\\Caller', 'enabled' => ['actions']], $product]] as [$operation, $arguments, $spec]) {
            GraphRuntimeClockProbe::$active = true;
            GraphRuntimeClockProbe::$offset = 0;
            GraphRuntimeClockProbe::$responses = 0;
            GraphRuntime::beginRequest();
            try {
                $response = $this->runtime->query($operation, $arguments, $spec);
                self::assertSame(1, GraphRuntimeClockProbe::$responses, 'The request clock advances only after a real query response, never during prepare.');
                self::assertArrayHasKey('result', $response);
                self::assertSame('current', $response['freshness']);
                self::assertTrue($response['deadline_exceeded']);
                self::assertTrue($this->runtime->map('status', $response['job_id'])['deadline_exceeded']);
            } finally {
                GraphRuntimeClockProbe::$active = false;
                GraphRuntimeClockProbe::$offset = 0;
                GraphRuntime::beginRequest();
            }
        }
    }

    public function test_catalog_queries_preserve_exported_index_and_diagnostic_metadata(): void
    {
        file_put_contents($this->root.'/app/Services/Dynamic.php', '<?php namespace App\\Services; function dynamic($receiver) { $receiver->unknown(); }');
        $ready = $this->runtime->map('prepare');
        self::assertNotEmpty($ready['diagnostics_by_code']);
        $queries = [
            ['search', ['query' => 'Dependency']],
            ['graph', ['subject' => 'App\\Services\\Dependency', 'mode' => 'context']],
            ['discovery', ['query' => 'Dependency']],
            ['path', ['from' => 'App\\Actions\\Caller', 'to' => 'App\\Services\\Dependency', 'audit_scope' => ['app']]],
        ];
        foreach ($queries as [$operation, $arguments]) {
            $response = $this->runtime->request(['action' => 'query', 'job_id' => $ready['job_id'], 'operation' => $operation, 'arguments' => $arguments]);
            self::assertArrayHasKey('result', $response, json_encode($response));
            self::assertSame($ready['diagnostics_digest'], $response['diagnostics_digest']);
            self::assertSame($ready['diagnostics_by_code'], $response['diagnostics_by_code']);
            $fast = $this->runtime->query($operation, $arguments);
            self::assertSame($response['result'], $fast['result']);
            self::assertSame($response['diagnostics_digest'], $fast['diagnostics_digest']);
            self::assertSame($response['diagnostics_by_code'], $fast['diagnostics_by_code']);
        }
        $events = array_values(array_filter($this->events(), static fn ($e) => in_array($e['stage'], ['query.before', 'query.after'], true)));
        self::assertNotEmpty($events);
        self::assertCount(1, array_unique(array_column($events, 'index_digest')));
        foreach ($events as $event) {
            self::assertSame($ready['diagnostics_digest'], $event['diagnostics_digest']);
            self::assertSame($ready['diagnostics_by_code'], $event['diagnostics_by_code']);
        }
    }

    public function test_ready_query_reuses_catalog_with_one_pre_post_pair_and_falls_back_to_new_job(): void
    {
        $ready = $this->runtime->map('prepare');
        $offset = count($this->events());
        $query = $this->runtime->query('search', ['query' => 'Dependency']);
        self::assertSame($ready['job_id'], $query['job_id']);
        self::assertNotEmpty($query['result']['result']['candidates']);
        $events = array_slice($this->events(), $offset);
        foreach (['query.route', 'query.freshness.before', 'query.recheck.before'] as $stage) {
            self::assertCount(1, array_filter($events, static fn ($e) => $e['stage'] === $stage));
        }
        self::assertCount(0, array_filter($events, static fn ($e) => $e['stage'] === 'build.before'));
        $file = $this->root.'/app/Services/Dependency.php';
        $stat = stat($file);
        $original = file_get_contents($file);
        file_put_contents($file, str_replace('Dependency', 'DifferentX', $original));
        touch($file, $stat['mtime']);
        GraphRuntime::beginRequest();
        $next = $this->runtime->query('search', ['query' => 'DifferentX']);
        self::assertSame('current', $next['freshness']);
        self::assertNotSame($ready['job_id'], $next['job_id']);
        self::assertNotSame($ready['pid'], $next['pid']);
        self::assertSame(3, $next['budget_seconds']);
        self::assertNotEmpty($next['result']['result']['candidates']);
        $offset = count($this->events());
        $this->runtime->map('result', $next['job_id']);
        $events = array_slice($this->events(), $offset);
        self::assertCount(1, array_filter($events, static fn ($e) => $e['stage'] === 'query.freshness.before'));
        self::assertCount(0, array_filter($events, static fn ($e) => $e['stage'] === 'query.recheck.before'));
        file_put_contents($file, $original);
        touch($file, $stat['mtime']);
        GraphRuntime::beginRequest();
        $restored = $this->runtime->query('search', ['query' => 'Dependency']);
        self::assertSame($ready['snapshot_id'], $restored['snapshot_id']);
        self::assertNotSame($ready['job_id'], $restored['job_id']);
        self::assertNotSame($next['job_id'], $restored['job_id']);
    }

    public function test_ready_query_rechecks_sources_changed_during_routing(): void
    {
        $ready = $this->runtime->map('prepare');
        $this->barrier('query.route', $ready['job_id']);
        $query = new Process([PHP_BINARY, '-d', 'memory_limit=1G', dirname(__DIR__, 2).'/Support/graph-runtime-client.php', $this->root, $this->home,
            json_encode(['action' => 'ready-query', 'operation' => 'search', 'arguments' => ['query' => 'Dependency']], JSON_THROW_ON_ERROR)]);
        $this->processes[] = $query;
        $query->start();
        $this->until(fn () => count(array_filter($this->events(), static fn ($e) => $e['stage'] === 'query.route')) === 1);
        file_put_contents($this->root.'/app/Services/Dependency.php', '<?php class ChangedDuringRouting {}');
        $this->barrier(null);
        self::assertSame(0, $query->wait(), $query->getErrorOutput());
        $response = json_decode($query->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('stale', $response['freshness']);
        self::assertArrayNotHasKey('result', $response);
        $next = $this->runtime->query('search', ['query' => 'ChangedDuringRouting']);
        self::assertSame('current', $next['freshness']);
        self::assertNotSame($ready['job_id'], $next['job_id']);
    }

    public function test_ready_query_keeps_the_original_request_clock_and_records_exceeded_deadline(): void
    {
        $ready = $this->runtime->map('prepare');
        // Deterministic expired request clock, without a 20 second CI sleep.
        $clock = new \ReflectionProperty(GraphRuntime::class, 'requestStarted');
        $clock->setValue(null, hrtime(true) - 21000000000);
        try {
            $response = $this->runtime->query('search', ['query' => 'Dependency']);
            self::assertSame($ready['job_id'], $response['job_id']);
            self::assertArrayHasKey('result', $response);
            self::assertTrue($response['deadline_exceeded']);
            self::assertTrue($this->runtime->map('status', $ready['job_id'])['deadline_exceeded']);
        } finally {
            GraphRuntime::beginRequest();
        }
    }

    public function test_ready_query_never_substitutes_catalog_for_an_explicit_product(): void
    {
        $this->runtime->map('prepare');
        foreach (['context', 'impact', 'occurrence'] as $kind) {
            $response = $this->runtime->request(['action' => 'ready-query', 'product' => ['kind' => $kind, 'scope' => ['app']],
                'operation' => 'search', 'arguments' => ['query' => 'Dependency']]);
            self::assertFalse($response['ok']);
            self::assertSame('runtime_product', $response['reason']);
            self::assertArrayNotHasKey('result', $response);
        }
    }

    public function test_initialized_live_mcp_reports_request_and_dispatch_separately(): void
    {
        $ready = $this->runtime->map('prepare');
        $process = $this->process(['mcp']);
        $input = new InputStream;
        $process->setInput($input);
        $process->start();
        $buffer = '';
        $responses = [];
        $receive = function (int $id) use ($process, &$buffer, &$responses): array {
            $this->until(function () use ($id, $process, &$buffer, &$responses): bool {
                $buffer .= $process->getIncrementalOutput();
                while (($end = strpos($buffer, "\n")) !== false) {
                    $message = json_decode(substr($buffer, 0, $end), true, flags: JSON_THROW_ON_ERROR);
                    $buffer = substr($buffer, $end + 1);
                    if (isset($message['id'])) {
                        $responses[$message['id']] = $message;
                    }
                }

                return isset($responses[$id]);
            });

            return $responses[$id];
        };
        try {
            $input->write(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26',
                'capabilities' => new \stdClass, 'clientInfo' => ['name' => 't04-ready-fixture', 'version' => '1']]], JSON_THROW_ON_ERROR)."\n");
            self::assertArrayHasKey('result', $receive(1));
            $input->write("{\"jsonrpc\":\"2.0\",\"method\":\"notifications/initialized\"}\n");
            $offset = count($this->events());
            $start = hrtime(true);
            $input->write(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'architecture-search',
                'arguments' => ['query' => 'Dependency']]], JSON_THROW_ON_ERROR)."\n");
            $response = $receive(2);
            $seconds = (hrtime(true) - $start) / 1e9;
            $result = json_decode($response['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($ready['job_id'], $result['runtime']['job_id']);
            self::assertSame('current', $result['runtime']['freshness']);
            self::assertNotEmpty($result['result']['candidates']);
            $events = array_slice($this->events(), $offset);
            $before = array_values(array_filter($events, static fn ($e) => $e['stage'] === 'query.before'));
            $after = array_values(array_filter($events, static fn ($e) => $e['stage'] === 'query.after'));
            self::assertCount(1, $before);
            self::assertCount(1, $after);
            $this->state->update('live-mcp-metrics', static fn () => ['initialized_request_response_s' => $seconds,
                'worker_dispatch_s' => $after[0]['time'] - $before[0]['time'], 'backend' => EngineFixture::selected(),
                'scope' => 'Small ready fixture; instrumentation and 10ms receive polling included; no timing threshold assertion.']);
        } finally {
            $input->close();
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            $this->recordProcess('initialized-live-mcp', [], $process);
        }
    }

    public function test_checkout_launcher_works_without_a_composer_proxy_or_instrumented_entrypoint(): void
    {
        $binDirectory = $GLOBALS['_composer_bin_dir'] ?? null;
        unset($GLOBALS['_composer_bin_dir']);
        GraphRuntime::instrument(null, null);
        try {
            $ready = $this->runtime->map('prepare');
            self::assertTrue($ready['ok']);
            self::assertSame('ready', $ready['availability']);
            self::assertSame('current', $ready['freshness']);
            $search = $this->runtime->query('search', ['query' => 'Dependency']);
            self::assertNotEmpty($search['result']['result']['candidates']);
        } finally {
            if ($binDirectory !== null) {
                $GLOBALS['_composer_bin_dir'] = $binDirectory;
            }
        }
    }

    public function test_real_cli_mcp_share_generation_and_preserve_nonempty_queries(): void
    {
        $cli = $this->cli(['map', 'prepare']);
        self::assertSame('ready', $cli['availability']);
        self::assertSame('current', $cli['freshness']);
        self::assertArrayNotHasKey('index', $cli);
        self::assertSame('unverified', $this->cli(['map', 'status', $cli['job_id']])['freshness']);
        $socket = GraphProtocol::socket($this->runtime->directory(), $cli['endpoint']);
        GraphProtocol::send($socket, [...$cli, 'operation' => 'ping']);
        $identity = GraphProtocol::receive($socket);
        fclose($socket);
        self::assertSame($cli['instance_token'], $identity['instance_token']);
        self::assertSame($cli['snapshot_id'], $identity['snapshot_id']);
        $mcp = $this->mcp('architecture-map', ['action' => 'prepare']);
        self::assertSame($cli['job_id'], $mcp['job_id']);
        self::assertSame($cli['snapshot_id'], $mcp['snapshot_id']);
        $search = $this->mcp('architecture-search', ['query' => 'Dependency']);
        self::assertNotEmpty($search['result']['candidates']);
        self::assertSame($cli['snapshot_id'], $search['snapshot']);
        $graph = $this->mcp('architecture-graph', ['subject' => 'App\\Services\\Dependency', 'mode' => 'context']);
        self::assertNotEmpty($graph['result']['records']);
        $context = $this->cli(['context', 'App\\Actions\\Caller', '--agent']);
        self::assertNotEmpty($context['dependencies']);
        self::assertArrayHasKey('violations', $context);
        self::assertSame(20, $context['analysis']['runtime']['budget_seconds']);
        $impact = $this->cli(['impact', 'App\\Services\\Dependency', '--agent']);
        self::assertNotEmpty($impact['dependents']);
        $this->assertSchema($impact, ImpactSchema::get());
        $path = $this->cli(['path', 'App\\Actions\\Caller::handle', 'App\\Services\\Dependency::handle', '--agent']);
        self::assertNotEmpty($path['source_path']['records']);
        $this->assertSchema($path, PathSchema::get());
        $this->assertSchema($context, (new AgentOutput)->schema('architecture-context'));
        $discovery = $this->cli(['search', 'Dependency', '--agent']);
        $this->assertSchema($discovery, SearchSchema::get());
        $reach = $this->cli(['reach', 'App\\Services\\Dependency', '--agent']);
        $this->assertSchema($reach, ReachSchema::get());
        $result = $this->cli(['map', 'result', $cli['job_id']]);
        self::assertSame($cli['snapshot_id'], $result['snapshot_id']);
        self::assertArrayNotHasKey('result', $result);
        self::assertCount(3, array_filter($this->events(), static fn ($e) => $e['stage'] === 'build.before'));
    }

    public function test_status_is_available_during_build_and_deadline_remains_recorded_after_collection(): void
    {
        $this->barrier('build.before');
        $client = $this->process(['map', 'prepare']);
        $client->start();
        $this->until(fn () => $this->events() !== []);
        $pending = $this->runtime->request(['action' => 'prepare']);
        self::assertSame('building', $pending['availability']);
        $job = $pending['job_id'];
        $status = $this->mcp('architecture-map', ['action' => 'status', 'job_id' => $job]);
        self::assertSame('building', $status['availability']);
        self::assertFalse($status['deadline_exceeded']);
        self::assertSame($job, $this->runtime->request(['action' => 'prepare'])['job_id']);
        self::assertSame(0, $client->wait(), $client->getErrorOutput());
        $pending = json_decode($client->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('building', $pending['availability']);
        self::assertTrue($pending['deadline_exceeded']);
        self::assertGreaterThanOrEqual(20, $pending['request_elapsed_seconds']);
        $this->barrier(null);
        $ready = $this->ready($job);
        self::assertTrue($ready['deadline_exceeded']);
        self::assertSame('current', $ready['freshness']);
        self::assertCount(1, array_filter($this->events(), static fn ($e) => $e['stage'] === 'build.before'));
    }

    public function test_detached_build_survives_real_client_termination_and_mcp_disconnect(): void
    {
        $this->barrier('build.before');
        $client = $this->process(['map', 'prepare']);
        $client->start();
        $this->until(fn () => $this->events() !== []);
        $pending = $this->runtime->request(['action' => 'prepare']);
        $workerPid = $this->events()[0]['pid'];
        self::assertNotSame($client->getPid(), $workerPid);
        $client->stop(1, SIGTERM);
        self::assertFalse($client->isRunning());
        self::assertTrue(posix_kill($workerPid, 0));
        $mcp = $this->mcpProcess('architecture-map', ['action' => 'prepare']);
        $mcp->start();
        usleep(200000);
        $mcp->stop(1, SIGTERM);
        self::assertFalse($mcp->isRunning());
        self::assertTrue(posix_kill($workerPid, 0));
        $this->barrier(null);
        $ready = $this->ready($pending['job_id']);
        self::assertSame($workerPid, $ready['pid']);
        self::assertSame('ready', $ready['availability']);
    }

    public function test_same_stat_changes_create_fresh_worker_and_pinned_query_drains_old_generation(): void
    {
        $old = $this->runtime->map('prepare');
        $this->barrier('query.before', $old['job_id']);
        $query = new Process([PHP_BINARY, '-d', 'memory_limit=1G', dirname(__DIR__, 2).'/Support/graph-runtime-client.php', $this->root, $this->home,
            json_encode(['action' => 'ready-query', 'operation' => 'search', 'arguments' => ['query' => 'Dependency']], JSON_THROW_ON_ERROR)]);
        $this->processes[] = $query;
        $query->start();
        $this->until(fn () => count(array_filter($this->events(), static fn ($e) => $e['stage'] === 'query.before' && $e['job'] === $old['job_id'])) >= 1);
        $file = $this->root.'/app/Services/Dependency.php';
        $stat = stat($file);
        file_put_contents($file, str_replace('Dependency', 'DifferentX', file_get_contents($file)));
        touch($file, $stat['mtime']);
        self::assertSame($stat['size'], filesize($file));
        $new = $this->runtime->map('prepare');
        self::assertNotSame($old['job_id'], $new['job_id']);
        self::assertNotSame($old['pid'], $new['pid']);
        self::assertTrue(posix_kill($old['pid'], 0));
        $coordinator = (new StateStore($this->runtime->directory()))->read('coordinator')['pid'];
        $rss = new Process(['ps', '-o', 'pid=,rss=,command=', '-p', implode(',', [$coordinator, $old['pid'], $new['pid']])]);
        self::assertSame(0, $rss->run());
        $this->recordProcess('simultaneous-rss-coordinator-old-new', [], $rss);
        $this->barrier(null);
        self::assertSame(0, $query->wait(), $query->getErrorOutput());
        $response = json_decode($query->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('stale', $response['freshness']);
        self::assertNotEmpty($response['result']['result']['candidates']);
        $this->until(fn () => ! posix_kill($old['pid'], 0));
        $found = $this->runtime->query('search', ['query' => 'DifferentX']);
        self::assertNotEmpty($found['result']['result']['candidates']);
    }

    public function test_clear_invalidates_ram_and_preclear_writer_without_deleting_new_epoch(): void
    {
        $ready = $this->runtime->map('prepare');
        $epoch = new GraphEpoch(RepositoryContext::current());
        (new ProjectGraphCache(new Filesystem, $this->root))->clear();
        self::assertFalse($epoch->unchanged());
        $next = $this->runtime->map('prepare');
        self::assertNotSame($ready['job_id'], $next['job_id']);
        self::assertNotSame($ready['pid'], $next['pid']);
        self::assertSame('current', $next['freshness']);
    }

    public function test_protocol_rejects_foreign_tokens_repository_and_unknown_jobs(): void
    {
        $ready = $this->runtime->map('prepare');
        $directory = $this->runtime->directory();
        $record = (new StateStore($directory))->read('coordinator');
        foreach (['instance_token', 'repository_id', 'analysis_fingerprint', 'protocol'] as $field) {
            $socket = GraphProtocol::socket($directory, $record['endpoint']);
            $request = $this->runtime->envelope(['action' => 'ping'], $record['instance_token']);
            $request[$field] = 'wrong';
            GraphProtocol::send($socket, $request);
            $response = GraphProtocol::receive($socket);
            fclose($socket);
            self::assertFalse($response['ok']);
            self::assertNull($response['instance_token']);
            self::assertNull($response['coordinator_token']);
            self::assertStringContainsString($field, $response['reason']);
        }
        self::assertFalse($this->runtime->map('result', str_repeat('a', 32))['ok']);
        self::assertSame($ready['job_id'], $this->runtime->map('prepare')['job_id']);
    }

    public function test_unconfigured_or_other_repository_cannot_inherit_runtime_enable(): void
    {
        GraphRuntime::enable();
        self::assertNotNull(GraphRuntime::context($this->root));
        self::assertNull(GraphRuntime::context($this->root.'/other'));
        RepositoryContext::activate(null);
        self::assertNull(GraphRuntime::context($this->root));
        GraphRuntime::enable(false);
        self::assertNull(GraphRuntime::context($this->root));
        self::assertDirectoryDoesNotExist($this->home.'/other');
    }

    public function test_global_builder_serializes_two_repositories_and_rejects_foreign_job(): void
    {
        $this->barrier('build.before');
        $one = $this->runtime->request(['action' => 'prepare']);
        $this->until(fn () => $this->events() !== []);
        $firstContext = RepositoryContext::current();
        $secondRoot = dirname($this->root).'/second';
        (new Filesystem)->copyDirectory($this->root, $secondRoot);
        $secondContext = new RepositoryContext($secondRoot, $this->home);
        RepositoryContext::activate($secondContext);
        $second = new GraphRuntime($secondContext);
        $two = $second->request(['action' => 'prepare']);
        self::assertNotSame($one['job_id'], $two['job_id']);
        usleep(200000);
        self::assertCount(1, array_filter($this->events(), static fn ($e) => $e['stage'] === 'build.before'));
        self::assertSame('building', $second->map('status', $two['job_id'])['availability']);
        self::assertSame('unavailable', $second->map('result', $one['job_id'])['availability']);
        $this->barrier(null);
        $this->until(fn () => $second->map('result', $two['job_id'])['availability'] === 'ready');
        RepositoryContext::activate($firstContext);
        self::assertSame('ready', $this->ready($one['job_id'])['availability']);
        self::assertCount(2, array_filter($this->events(), static fn ($e) => $e['stage'] === 'build.before'));
    }

    public function test_bad_generation_ready_and_oversized_messages_do_not_publish(): void
    {
        $this->barrier('build.before');
        $pending = $this->runtime->request(['action' => 'prepare']);
        $this->until(fn () => $this->events() !== []);
        $directory = $this->runtime->directory();
        $state = new StateStore($directory);
        foreach (glob($directory.'/launch-*.json') ?: [] as $path) {
            $spec = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (($spec['job_id'] ?? null) !== $pending['job_id']) {
                continue;
            }
            foreach (['instance_token', 'repository_id', 'input_manifest_digest', 'job_id'] as $field) {
                $wrong = [...$spec, 'protocol' => GraphProtocol::VERSION, 'availability' => 'ready', 'freshness' => 'current'];
                $wrong[$field] = 'wrong';
                $state->update('job-'.$pending['job_id'], static fn () => $wrong);
                usleep(30000);
                self::assertSame('building', $this->runtime->map('status', $pending['job_id'])['availability']);
            }
        }
        $record = $state->read('coordinator');
        $socket = GraphProtocol::socket($directory, $record['endpoint']);
        fwrite($socket, str_repeat('x', GraphProtocol::REQUEST_BYTES + 1)."\n");
        $bad = GraphProtocol::receive($socket);
        fclose($socket);
        self::assertSame('runtime_message_limit', $bad['reason']);
        $state->update('job-'.$pending['job_id'], static fn () => []);
        $this->barrier(null);
        self::assertSame('current', $this->ready($pending['job_id'])['freshness']);
    }

    public function test_crashed_builder_is_failed_and_retry_uses_a_new_process(): void
    {
        $old = $this->runtime->map('prepare');
        file_put_contents($this->root.'/app/NewFile.php', '<?php namespace App; class NewFile {}');
        $this->barrier('build.before');
        $pending = $this->runtime->request(['action' => 'prepare']);
        $this->until(fn () => count(array_filter($this->events(), static fn ($e) => $e['stage'] === 'build.before')) === 2);
        $events = array_values(array_filter($this->events(), static fn ($e) => $e['stage'] === 'build.before' && $e['job'] === $pending['job_id']));
        self::assertTrue(posix_kill($events[0]['pid'], SIGKILL));
        $failed = [];
        $this->until(function () use ($pending, &$failed): bool {
            $failed = $this->runtime->map('result', $pending['job_id']);

            return $failed['availability'] !== 'building';
        });
        self::assertSame('failed', $failed['availability']);
        self::assertTrue(posix_kill($old['pid'], 0));
        $this->barrier(null);
        $retry = $this->runtime->map('prepare');
        self::assertSame('ready', $retry['availability']);
        self::assertNotSame($pending['job_id'], $retry['job_id']);
        self::assertNotSame($events[0]['pid'], $retry['pid']);
    }

    public function test_preclear_builder_cannot_republish_after_clear(): void
    {
        $this->barrier('build.before');
        $pending = $this->runtime->request(['action' => 'prepare']);
        $this->until(fn () => $this->events() !== []);
        $directory = $this->runtime->directory();
        $record = (new StateStore($directory))->read('coordinator');
        (new ProjectGraphCache(new Filesystem, $this->root))->clear();
        $this->barrier(null);
        $old = [];
        $this->until(function () use ($directory, $record, $pending, &$old): bool {
            $socket = GraphProtocol::socket($directory, $record['endpoint']);
            GraphProtocol::send($socket, [...$record, 'request_id' => bin2hex(random_bytes(16)), 'action' => 'result', 'job_id' => $pending['job_id']]);
            $old = GraphProtocol::receive($socket);
            fclose($socket);

            return $old['availability'] !== 'building';
        });
        self::assertSame('stale', $old['freshness']);
        self::assertStringContainsString('cache_cleared', $old['cache']['ready_write']['reason']);
        self::assertSame([], glob(RepositoryContext::current()->storage.'/graph/*/catalog-ready-v1/active-*.json'));
        self::assertSame('current', $this->runtime->map('prepare')['freshness']);
    }

    public function test_actual_idle_and_long_build_lifecycle(): void
    {
        if (getenv('ARCHITECTURE_KIT_TEST_LONG_RUNTIME') !== '1') {
            self::markTestSkipped('Explicit real 5 minute lifecycle run.');
        }
        $start = microtime(true);
        $one = $this->runtime->map('prepare');
        $firstDirectory = $this->runtime->directory();
        $firstCoordinator = (new StateStore($firstDirectory))->read('coordinator')['pid'];
        $firstContext = RepositoryContext::current();
        usleep(200000);
        $this->runtime->query('search', ['query' => 'Dependency']);
        $secondRoot = dirname($this->root).'/long-build';
        (new Filesystem)->copyDirectory($this->root, $secondRoot);
        $secondContext = new RepositoryContext($secondRoot, $this->home);
        RepositoryContext::activate($secondContext);
        $second = new GraphRuntime($secondContext);
        $this->barrier('build.before');
        $pending = $second->request(['action' => 'prepare']);
        $secondDirectory = $second->directory();
        $secondCoordinator = (new StateStore($secondDirectory))->read('coordinator')['pid'];
        $this->until(fn () => count(array_filter($this->events(), static fn ($e) => $e['stage'] === 'build.before')) === 2);
        $events = array_values(array_filter($this->events(), static fn ($e) => $e['stage'] === 'build.before' && $e['job'] === $pending['job_id']));
        $secondWorker = $events[0]['pid'];
        $barrierStart = $events[0]['time'];
        while (microtime(true) - $barrierStart < 302) {
            usleep(200000);
        }
        self::assertFalse(posix_kill($firstCoordinator, 0));
        self::assertFalse(posix_kill($one['pid'], 0));
        self::assertTrue(posix_kill($secondCoordinator, 0));
        self::assertTrue(posix_kill($secondWorker, 0));
        $this->state->update('timeline', static fn () => ['start' => $start, 'barrier_start' => $barrierStart,
            'after_302_seconds' => microtime(true), 'idle_coordinator_exited' => true, 'active_build_alive' => true]);
        $this->barrier(null);
        $this->until(fn () => $second->map('result', $pending['job_id'])['availability'] === 'ready');
        $lastRequest = microtime(true);
        RepositoryContext::activate($firstContext);
        $parses = count(array_filter($this->events(), static fn ($e) => $e['stage'] === 'source.parse'));
        $restored = $this->runtime->map('prepare');
        self::assertSame('hit', $restored['cache']['read']['status']);
        self::assertSame('current', $restored['freshness']);
        self::assertNotSame($one['pid'], $restored['pid']);
        self::assertSame($parses, count(array_filter($this->events(), static fn ($e) => $e['stage'] === 'source.parse')));
        while (microtime(true) - $lastRequest < 302) {
            usleep(200000);
        }
        self::assertFalse(posix_kill($secondCoordinator, 0));
        self::assertFalse(posix_kill($secondWorker, 0));
        $this->state->update('timeline', static fn (array $timeline) => [...$timeline, 'build_completed_at' => $lastRequest,
            'after_final_idle' => microtime(true), 'completed_build_coordinator_exited' => true, 'completed_worker_exited' => true]);
    }

    public function test_start_race_ignores_stale_pid_and_preserves_unrelated_helper(): void
    {
        $helper = new Process([PHP_BINARY, '-r', 'sleep(30);']);
        $helper->start();
        $this->processes[] = $helper;
        $directory = $this->runtime->directory();
        (new StateStore($directory))->update('coordinator', fn () => $this->runtime->envelope(['endpoint' => 'stale.sock', 'pid' => $helper->getPid()], 'stale-token'));
        $one = $this->process(['map', 'prepare']);
        $two = $this->process(['map', 'prepare']);
        $one->start();
        $two->start();
        self::assertSame(0, $one->wait(), $one->getErrorOutput());
        self::assertSame(0, $two->wait(), $two->getErrorOutput());
        $a = json_decode($one->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $b = json_decode($two->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($a['job_id'], $b['job_id']);
        self::assertTrue($helper->isRunning());
        self::assertCount(1, array_filter($this->events(), static fn ($e) => $e['stage'] === 'build.before'));
        $this->recordProcess('start-race-one', [], $one);
        $this->recordProcess('start-race-two', [], $two);
    }

    public function test_decoded_corruption_restarts_fresh_and_skips_only_rejected_generation(): void
    {
        $old = $this->runtime->map('prepare');
        $coordinator = (new StateStore($this->runtime->directory()))->read('coordinator')['pid'];
        $this->runtime->request(['action' => 'shutdown']);
        $this->until(fn () => ! posix_kill($coordinator, 0));
        $cache = new ProjectGraphCache(new Filesystem, $this->root);
        $pointers = glob($cache->canonicalDirectory().'/catalog-ready-v1/active-*.json');
        self::assertCount(1, $pointers);
        $pointer = json_decode(file_get_contents($pointers[0]), true, flags: JSON_THROW_ON_ERROR);
        $generation = dirname($pointers[0]).'/'.$pointer['generation'];
        $manifest = json_decode(file_get_contents($generation.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $segment = $manifest['fields']['relations']['segments'][0]['file'];
        file_put_contents($generation.'/'.$segment, 'corrupt after elements were decoded');
        $ready = $this->runtime->map('prepare');
        self::assertSame('ready', $ready['availability']);
        self::assertSame('current', $ready['freshness']);
        self::assertNotSame($old['pid'], $ready['pid']);
        self::assertDirectoryExists($generation);
        $newBuilds = array_filter($this->events(), static fn ($e) => $e['stage'] === 'build.before' && $e['job'] === $ready['job_id']);
        self::assertCount(2, $newBuilds);
        self::assertCount(2, array_unique(array_column($newBuilds, 'pid')));
    }

    public function test_pending_does_not_refresh_task_beginning_or_invent_a_baseline(): void
    {
        $begin = $this->cli(['task', 'begin', 'before-map']);
        self::assertArrayHasKey('begin_sources', $begin);
        $this->barrier('build.before');
        $pending = $this->runtime->request(['action' => 'prepare']);
        $status = $this->cli(['task', 'status', 'before-map']);
        self::assertSame($begin['begin_sources'], $status['begin_sources']);
        $missing = $this->cli(['task', 'check', 'missing']);
        self::assertFalse($missing['ok']);
        self::assertFalse($missing['control']['blocking']);
        $this->barrier(null);
        $this->ready($pending['job_id']);
        $again = $this->cli(['task', 'begin', 'before-map']);
        foreach (['begin_sources', 'begin_errors', 'created_at', 'fingerprint'] as $field) {
            self::assertSame($begin[$field], $again[$field]);
        }
        self::assertFileDoesNotExist(RepositoryContext::current()->storage.'/tasks/'.hash('sha256', 'missing').'.json');
    }

    public function test_ready_incomplete_disabled_cache_and_real_write_boundary_failure_are_independent(): void
    {
        $settings = json_decode(file_get_contents($this->root.'/.architecture-kit/settings.json'), true, flags: JSON_THROW_ON_ERROR);
        $settings['audit']['cache'] = false;
        file_put_contents($this->root.'/.architecture-kit/settings.json', json_encode($settings, JSON_THROW_ON_ERROR));
        file_put_contents($this->root.'/app/Broken.php', '<?php class Broken {');
        $context = new RepositoryContext($this->root, $this->home);
        RepositoryContext::activate($context);
        $runtime = new GraphRuntime($context);
        $partial = $runtime->map('prepare');
        self::assertSame('current', $partial['freshness']);
        self::assertSame('ready', $partial['availability']);
        self::assertFalse($partial['checks_complete']);
        self::assertSame(['status' => 'skip', 'reason' => 'disabled'], $partial['cache']['write']);
        $settings['audit']['cache'] = true;
        file_put_contents($this->root.'/.architecture-kit/settings.json', json_encode($settings, JSON_THROW_ON_ERROR));
        unlink($this->root.'/app/Broken.php');
        $context = new RepositoryContext($this->root, $this->home);
        RepositoryContext::activate($context);
        $cache = new ProjectGraphCache(new Filesystem, $this->root);
        mkdir($cache->canonicalDirectory(), 0700, true);
        mkdir($this->home.'/boundary-target');
        symlink($this->home.'/boundary-target', $cache->canonicalDirectory().'/catalog-ready-v1');
        $ready = (new GraphRuntime($context))->map('prepare');
        self::assertSame('current', $ready['freshness']);
        self::assertSame('ready', $ready['availability']);
        self::assertSame('error', $ready['cache']['write']['status']);
        self::assertSame('unsafe_store', $ready['cache']['write']['reason']);
    }

    public function test_queue_limit_is_explicit_and_does_not_cancel_active_builder(): void
    {
        $this->barrier('build.before');
        $pending = $this->runtime->request(['action' => 'prepare']);
        $this->until(fn () => $this->events() !== []);
        $worker = $this->events()[0]['pid'];
        for ($i = 1; $i < GraphProtocol::JOBS; $i++) {
            file_put_contents($this->root.'/app/QueueVersion.php', '<?php // generation '.$i);
            $queued = $this->runtime->request(['action' => 'prepare']);
            self::assertSame('building', $queued['availability']);
        }
        file_put_contents($this->root.'/app/QueueVersion.php', '<?php // rejected next generation');
        $rejected = $this->runtime->request(['action' => 'prepare']);
        self::assertSame('unavailable', $rejected['availability']);
        self::assertSame('runtime_job_limit', $rejected['reason']);
        self::assertFalse($rejected['ok']);
        self::assertTrue(posix_kill($worker, 0));
        self::assertSame('building', $this->runtime->map('status', $pending['job_id'])['availability']);
    }

    public function test_request_clock_includes_work_before_map_is_called(): void
    {
        $this->runtime->map('prepare');
        GraphRuntime::beginRequest();
        usleep(50000);
        $ready = $this->runtime->map('prepare');
        self::assertGreaterThanOrEqual(0.05, $ready['request_elapsed_seconds']);
        self::assertStringContainsString('including_context', $ready['timing_scope']);
    }

    public function test_rules_changed_while_worker_builds_cannot_publish_current_under_the_new_selection(): void
    {
        $this->barrier('build.before');
        $old = $this->runtime->request(['action' => 'prepare']);
        $this->until(fn () => count(array_filter($this->events(), static fn ($event) => $event['stage'] === 'build.before')) === 1);
        $path = $this->root.'/.architecture-kit/settings.json';
        $settings = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $settings['enabled'] = ['services'];
        file_put_contents($path, json_encode($settings, JSON_THROW_ON_ERROR));
        $this->barrier(null);
        $oldContext = RepositoryContext::current();
        $directory = $oldContext->storage;
        $job = [];
        $this->until(function () use ($directory, $old, &$job): bool {
            foreach (glob($directory.'/runtime/*/job-'.$old['job_id'].'.json') ?: [] as $candidate) {
                $job = json_decode(file_get_contents($candidate), true, flags: JSON_THROW_ON_ERROR);
                if (isset($job['availability']) && $job['availability'] !== 'building') {
                    return true;
                }
            }

            return false;
        });
        self::assertNotSame('current', $job['freshness']);
        self::assertFalse($job['ok'] ?? false);
        $fresh = new RepositoryContext($this->root, $this->home);
        Host::refresh($fresh);
        $runtime = new GraphRuntime($fresh);
        self::assertNotSame($oldContext->configPath, $fresh->configPath);
        $new = $runtime->request(['action' => 'prepare']);
        self::assertNotSame($old['job_id'], $new['job_id']);
        $this->runtime = $runtime;
        self::assertSame('current', $this->ready($new['job_id'])['freshness']);
    }

    public function test_mcp_first_starts_shared_generation_and_changes_during_build_stay_stale(): void
    {
        $first = $this->mcp('architecture-map', ['action' => 'prepare']);
        self::assertSame($first['job_id'], $this->cli(['map', 'prepare'])['job_id']);
        file_put_contents($this->root.'/app/Version.php', '<?php namespace App; class BeforeBuild {}');
        $this->barrier('build.before');
        $old = $this->runtime->request(['action' => 'prepare']);
        $this->until(fn () => count(array_filter($this->events(), static fn ($e) => $e['stage'] === 'build.before')) === 2);
        file_put_contents($this->root.'/app/Version.php', '<?php namespace App; class AfterBuild {}');
        $new = $this->runtime->request(['action' => 'prepare']);
        self::assertNotSame($old['job_id'], $new['job_id']);
        $this->barrier(null);
        self::assertSame('stale', $this->ready($old['job_id'])['freshness']);
        self::assertSame('current', $this->ready($new['job_id'])['freshness']);
        self::assertNotEmpty($this->runtime->query('search', ['query' => 'AfterBuild'])['result']['result']['candidates']);
    }

    public function test_context_violations_and_real_tree_audit_survive_ram_and_restore(): void
    {
        $settings = json_decode(file_get_contents($this->root.'/.architecture-kit/settings.json'), true, flags: JSON_THROW_ON_ERROR);
        $settings['enabled'] = ['actions', 'ports-and-adapters'];
        file_put_contents($this->root.'/.architecture-kit/settings.json', json_encode($settings, JSON_THROW_ON_ERROR));
        mkdir($this->root.'/app/Infrastructure');
        file_put_contents($this->root.'/app/Infrastructure/Concrete.php', '<?php namespace App\\Infrastructure; class Concrete {}');
        file_put_contents($this->root.'/app/Actions/Caller.php', '<?php namespace App\\Actions; class Caller { public function handle(\\App\\Infrastructure\\Concrete $service): void {} }');
        file_put_contents($this->root.'/app/Actions/TreeAction.php', '<?php namespace App\\Actions; use Illuminate\\Http\\Request; class TreeAction { public function handle(Request $request): void {} }');
        $context = new RepositoryContext($this->root, $this->home);
        RepositoryContext::activate($context);
        $this->runtime = new GraphRuntime($context);
        $this->runtime->map('prepare');
        $cold = $this->cli(['context', 'App\\Actions\\Caller', '--agent']);
        self::assertContains('E_LAYER_DEPENDENCY', array_column($cold['violations'], 'code'));
        $ram = $this->mcp('architecture-context', ['subject' => 'App\\Actions\\Caller']);
        self::assertSame($cold['violations'], $ram['violations']);
        $audit = $this->cli(['audit', '--agent', '--full']);
        self::assertNotEmpty(array_filter($audit['find'], static fn ($finding) => $finding['r'] === 'actions' && $finding['p'] === 'app/Actions/TreeAction.php'));
        $explain = $this->cli(['explain', 'E_LAYER_DEPENDENCY', '--path=app/Actions/Caller.php', '--line=1', '--agent']);
        self::assertSame('App\\Actions\\Caller', $explain['occurrence']['symbol']);
        self::assertNotEmpty($explain['occurrence']['dependency']['reported_edges']);
        $pid = (new StateStore($this->runtime->directory()))->read('coordinator')['pid'];
        $this->runtime->request(['action' => 'shutdown']);
        $this->until(fn () => ! posix_kill($pid, 0));
        $restored = $this->cli(['context', 'App\\Actions\\Caller', '--agent']);
        self::assertSame($cold['violations'], $restored['violations']);
        self::assertSame($cold['dependencies'], $restored['dependencies']);
    }

    public function test_deadline_classes_survive_restart_and_single_move(): void
    {
        $cold = $this->runtime->map('prepare');
        self::assertSame(20, $cold['budget_seconds']);
        $stop = function (): void {
            $pid = (new StateStore($this->runtime->directory()))->read('coordinator')['pid'];
            $this->runtime->request(['action' => 'shutdown']);
            $this->until(fn () => ! posix_kill($pid, 0));
        };
        $stop();
        self::assertSame(2, $this->runtime->map('prepare')['budget_seconds']);
        $stop();
        file_put_contents($this->root.'/app/Services/Dependency.php', '<?php namespace App\\Services; class Dependency { public function handle(): int { return 1; } }');
        self::assertSame(3, $this->runtime->map('prepare')['budget_seconds']);
        $stop();
        rename($this->root.'/app/Actions/Caller.php', $this->root.'/app/Actions/MovedCaller.php');
        self::assertSame(3, $this->runtime->map('prepare')['budget_seconds']);
        $stop();
        file_put_contents($this->root.'/app/One.php', '<?php namespace App; class One {}');
        file_put_contents($this->root.'/app/Two.php', '<?php namespace App; class Two {}');
        self::assertSame(20, $this->runtime->map('prepare')['budget_seconds']);
        $stop();
        $settings = json_decode(file_get_contents($this->root.'/.architecture-kit/settings.json'), true, flags: JSON_THROW_ON_ERROR);
        $settings['enabled'][] = 'ports-and-adapters';
        file_put_contents($this->root.'/.architecture-kit/settings.json', json_encode($settings, JSON_THROW_ON_ERROR));
        self::assertSame(20, $this->cli(['map', 'prepare'])['budget_seconds']);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function customPathScopeLayouts(): array
    {
        return ['project' => ['project', ['app', 'Modules']], 'package' => ['library', ['Modules']]];
    }

    /** @param list<string> $expectedScope */
    #[DataProvider('customPathScopeLayouts')]
    public function test_path_preserves_custom_audit_scope_in_cli_and_mcp_across_cold_ram_restore(string $projectType, array $expectedScope): void
    {
        $settings = json_decode(file_get_contents($this->root.'/.architecture-kit/settings.json'), true, flags: JSON_THROW_ON_ERROR);
        $settings['audit']['paths'] = ['Modules'];
        file_put_contents($this->root.'/.architecture-kit/settings.json', json_encode($settings, JSON_THROW_ON_ERROR));
        $composer = json_decode(file_get_contents($this->root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $composer['type'] = $projectType;
        $composer['autoload']['psr-4']['Modules\\'] = 'Modules/';
        file_put_contents($this->root.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        mkdir($this->root.'/Modules/Start', 0700, true);
        mkdir($this->root.'/Modules/End', 0700, true);
        file_put_contents($this->root.'/Modules/Start/Top.php', '<?php namespace Modules\\Start; class Top { public static function run(): void { \\Modules\\End\\Target::run(); } }');
        file_put_contents($this->root.'/Modules/End/Target.php', '<?php namespace Modules\\End; class Target { public static function run(): void {} }');
        $context = new RepositoryContext($this->root, $this->home);
        RepositoryContext::activate($context);
        $this->runtime = new GraphRuntime($context);
        $baseline = null;
        $firstWorker = null;
        foreach (['cold', 'ram', 'restore'] as $phase) {
            if ($phase === 'restore') {
                $coordinator = (new StateStore($this->runtime->directory()))->read('coordinator')['pid'];
                $this->runtime->request(['action' => 'shutdown']);
                $this->until(fn () => ! posix_kill($coordinator, 0));
            }
            $cli = $this->cli(['path', 'Modules\\Start\\Top::run', 'Modules\\End\\Target::run', '--agent']);
            $mcp = $this->mcp('path', ['from' => 'Modules\\Start\\Top::run', 'to' => 'Modules\\End\\Target::run']);
            foreach (['cli' => $cli, 'mcp' => $mcp] as $transport => $result) {
                self::assertTrue($result['ok'], $phase.' '.$transport);
                self::assertSame($expectedScope, $result['scope']['audit_paths'], $phase.' '.$transport);
                self::assertSame('found', $result['source_path']['status']);
                self::assertNotEmpty($result['source_path']['records']);
                self::assertSame('basic', $result['analysis']['mode']);
                self::assertFalse($result['analysis']['deep_run']);
                self::assertSame($cli['snapshot'], $result['snapshot']);
                if ($baseline !== null) {
                    self::assertSame($baseline['scope'], $result['scope']);
                    self::assertSame($baseline['source_path'], $result['source_path']);
                }
            }
            if ($phase === 'cold') {
                $baseline = $cli;
                $firstWorker = $cli['runtime']['pid'];
            } elseif ($phase === 'ram') {
                self::assertSame($firstWorker, $cli['runtime']['pid']);
            } else {
                self::assertNotSame($firstWorker, $cli['runtime']['pid']);
                self::assertSame('hit', $cli['runtime']['cache']['read']['status']);
            }
            self::assertSame($cli['runtime']['job_id'], $mcp['runtime']['job_id']);
        }
        self::assertCount(2, array_filter($this->events(), static fn ($event) => $event['stage'] === 'build.before'));
        self::assertCount(0, array_filter($this->events(), static fn ($event) => $event['product'] !== 'catalog'));
    }

    public function test_runtime_path_rejects_missing_or_unsafe_audit_scope_without_replacing_catalog(): void
    {
        symlink($this->root.'/app', $this->root.'/ScopeLink');
        $ready = $this->runtime->map('prepare');
        $arguments = ['from' => 'App\\Actions\\Caller::handle', 'to' => 'App\\Services\\Dependency::handle'];
        foreach ([null, 'app', [7], ['../outside'], ['/absolute'], ['vendor'], ['ScopeLink']] as $scope) {
            $response = $this->runtime->request(['action' => 'query', 'job_id' => $ready['job_id'], 'operation' => 'path',
                'arguments' => [...$arguments, ...($scope === null ? [] : ['audit_scope' => $scope])]]);
            self::assertFalse($response['ok']);
            self::assertSame('runtime_path_scope', $response['reason']);
            self::assertArrayNotHasKey('result', $response);
        }
        $valid = $this->runtime->request(['action' => 'query', 'job_id' => $ready['job_id'], 'operation' => 'path',
            'arguments' => [...$arguments, 'audit_scope' => ['app']]]);
        self::assertTrue($valid['ok']);
        self::assertNotEmpty($valid['result']['source_path']['records']);
        self::assertSame(['app'], $valid['result']['scope']['audit_paths']);
        self::assertSame($ready['pid'], $valid['pid']);
    }

    /** Reuse the repository's small schema validator without adding a dependency.
     * @param array<string, mixed> $value
     * @param array<string, mixed> $schema */
    private function assertSchema(array $value, array $schema): void
    {
        $validator = new AgentOutputSchemaTest('test_it_exposes_a_stable_audit_agent_output_schema');
        $method = new \ReflectionMethod($validator, 'matchesSchema');
        self::assertTrue($method->invoke($validator, $value, $schema), json_encode($value));
    }

    /** @return array<string, mixed> */
    private function ready(string $job): array
    {
        $result = [];
        $this->until(function () use ($job, &$result): bool {
            $result = $this->runtime->map('result', $job);

            return $result['availability'] !== 'building';
        });
        self::assertSame('ready', $result['availability'], json_encode($result));

        return $result;
    }

    private function barrier(?string $stage, ?string $job = null): void
    {
        $this->state->update('test-control', static fn () => ['barrier' => $stage, ...($job === null ? [] : ['job' => $job])]);
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        return $this->state->read('test-events');
    }

    private function until(callable $condition): void
    {
        $end = microtime(true) + 12;
        do {
            if ($condition()) {
                return;
            } usleep(10000);
        } while (microtime(true) < $end);
        self::fail('Process condition did not become true; artifacts retained in '.$this->home);
    }

    /** @param array<mixed> $arguments */
    private function recordProcess(string $channel, array $arguments, Process $process): void
    {
        $this->state->update('requests', static function (array $records) use ($channel, $arguments, $process): array {
            $records[] = ['utc' => gmdate('c'), 'channel' => $channel, 'arguments' => $arguments,
                'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput(), 'exit' => $process->getExitCode()];

            return $records;
        });
    }

    /** @param list<string> $arguments */
    private function process(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', dirname(__DIR__, 2).'/Support/graph-runtime-entrypoint.php',
            '--project', $this->root, '--tool-home', $this->home, ...$arguments], timeout: 40);
        $this->processes[] = $process;

        return $process;
    }

    /** @param list<string> $arguments
     * @return array<string, mixed> */
    private function cli(array $arguments): array
    {
        $process = $this->process($arguments);
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $this->recordProcess('cli', $arguments, $process);

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $arguments */
    private function mcpProcess(string $tool, array $arguments): Process
    {
        $process = $this->process(['mcp']);
        $process->setInput(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], JSON_THROW_ON_ERROR)."\n");

        return $process;
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed> */
    private function mcp(string $tool, array $arguments): array
    {
        $process = $this->mcpProcess($tool, $arguments);
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $this->recordProcess($tool, $arguments, $process);
        $response = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

        return json_decode($response['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
    }
}

namespace GracjanKubicki\ArchitectureKit\Tests\Support;

/** Advance a request's clock only when its real worker response has been read. */
final class GraphRuntimeClockProbe
{
    public static bool $active = false;

    public static int $offset = 0;

    public static int $responses = 0;
}

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Tests\Support\GraphRuntimeClockProbe;

function hrtime(bool $as_number = false): array|int|float
{
    $now = \hrtime($as_number);

    return $as_number && GraphRuntimeClockProbe::$active ? $now + GraphRuntimeClockProbe::$offset : $now;
}

function fgets(mixed $stream, ?int $length = null): string|false
{
    $line = $length === null ? \fgets($stream) : \fgets($stream, $length);
    if (GraphRuntimeClockProbe::$active && $line !== false) {
        $response = json_decode($line, true);
        if (is_array($response) && isset($response['job_id'], $response['result'])) {
            GraphRuntimeClockProbe::$responses++;
            GraphRuntimeClockProbe::$offset = 21000000000;
        }
    }

    return $line;
}
