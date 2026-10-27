<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisDocument;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisSession;
use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsEngine;
use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsOptions;
use GracjanKubicki\ArchitectureKit\Analysis\BasicSource;
use GracjanKubicki\ArchitectureKit\Analysis\EngineDescriptor;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\PhpAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Analysis\StreamingBasicCollector;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogDiagnostic;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogRelation;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureRecord;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureSourcePlan;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureStore;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeGraphClient;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeReplay;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class NativeGraphStoreTest extends TestCase
{
    private string $base;

    private string $root;

    private array $captureIdentity;

    private array $identity;

    private NativeGraphClient $client;

    private array $measurements = [];

    private NativeReplayParserGuard $parserGuard;

    private Process $sampler;

    protected function setUp(): void
    {
        $binary = getenv('ARCHITECTURE_KIT_TEST_GRAPH_RUNTIME');
        if (! $binary) {
            $this->markTestSkipped('Explicit private graph runtime required; no download.');
        }
        $this->base = realpath(sys_get_temp_dir()).'/ak-r02-'.bin2hex(random_bytes(8));
        mkdir($this->base, 0700);
        $this->root = $this->base.'/repo';
        mkdir($this->root, 0700);
        $sources = [
            'app/Service.php' => "<?php\nnamespace App;\nclass Service { public function run(): string { return 'ok'; } }\n",
            'app/Caller.php' => "<?php\nnamespace App;\nclass Caller { public function call(Service \$service): string { return \$service->run(); } }\n",
            'Modules/Billing/Job.php' => "<?php\nnamespace Modules\\Billing; class Job extends \\App\\Service {}\n",
            'resources/views/page.blade.php' => "<div>\n{{ app(\\App\\Service::class)->run() }}\n</div>\n",
            'tests/ServiceTest.php' => "<?php\nuse App\\Service; it('runs', function () { expect((new Service)->run())->toBe('ok'); });\n",
            'app/Empty.php' => "<?php\n",
            'app/Broken.php' => "<?php\nclass Broken {\n",
            'composer.json' => '{"name":"fixture/replay","autoload":{"psr-4":{"App\\\\":"app/","Modules\\\\":"Modules/"}}}',
        ];
        foreach ($sources as $path => $bytes) {
            if (! is_dir(dirname($this->root.'/'.$path))) {
                mkdir(dirname($this->root.'/'.$path), 0700, true);
            }
            file_put_contents($this->root.'/'.$path, $bytes);
            chmod($this->root.'/'.$path, 0600);
        }
        $privateEngine = $this->base.'/private-engine';
        if (getenv('ARCHITECTURE_KIT_SUITE_ENGINE') === 'mago') {
            copy(getenv('ARCHITECTURE_KIT_TEST_ENGINE'), $privateEngine);
            chmod($privateEngine, 0700);
            $engine = new MagoAnalysisEngine($privateEngine);
        } else {
            $engine = new PhpAnalysisEngine;
        }
        $session = new AnalysisSession($engine, '8.3');
        AnalysisRuntime::activate($session);
        $plan = CaptureSourcePlan::create($this->root);
        $this->captureIdentity = ['fixture' => TypedValue::digest($plan)];
        $this->identity = ['repository' => hash('sha256', $this->root), 'profile' => 'fixture', 'product' => 'basic-local',
            'parser' => $session->engine->engine, 'tool' => 'r02-fixture', 'configuration' => TypedValue::digest($plan['identity']), 'capture' => $this->captureIdentity['fixture']];
        $options = new BasicFactsOptions(true, true, true);
        $store = new CaptureStore($this->base.'/dataset', $plan, $this->captureIdentity, $options->toArray(), static function (): void {});
        (new StreamingBasicCollector($session, $options, $this->root))->collect($plan, $store->append(...), static function (): void {});
        $store->commit();
        // Remove every original path, and deactivate the parser before any native work.
        rename($this->root, $this->root.'-unavailable');
        AnalysisRuntime::activate(null);
        unset($session, $engine); // Closes the owned persistent Mago process.
        if (is_file($privateEngine)) {
            unlink($privateEngine); // The original private parser executable is gone.
        }
        $this->parserGuard = new NativeReplayParserGuard;
        AnalysisRuntime::activate(new AnalysisSession($this->parserGuard, '8.3'));
        $this->sampler = new Process([PHP_BINARY, dirname(__DIR__, 3).'/Support/native-graph-sampler.php', (string) getmypid(), $this->base.'/rss.ndjson', $this->base.'/observer.stop'], timeout: 35);
        $this->sampler->start();
        $this->client = new NativeGraphClient($binary, 268435456, 536870912);
    }

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            $status = $this->client->request(['op' => 'status']);
            $directory = getenv('ARCHITECTURE_KIT_NATIVE_TEST_ARTIFACTS') ?: dirname(__DIR__, 4).'/build/graph-performance-rust/R02';
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            $parser = $this->identity['parser'];
            file_put_contents($directory.'/measurements-'.$parser.'.ndjson', TypedValue::json([
                'test' => $this->name(), 'parser' => $parser, 'scope' => 'synthetic_private_fixture', 'runtime_descriptor' => $this->client->descriptor,
                'dataset_manifest_sha256' => is_file($this->base.'/dataset/manifest.json') ? hash_file('sha256', $this->base.'/dataset/manifest.json') : null,
                'runtime_status' => $status, 'phases' => $this->measurements, 'raw_rss_samples' => $this->base.'/rss.ndjson',
                'client_last_request' => $this->client->lastMeasurement, 'sampled_rss' => ['status' => 'sampled_50ms', 'reason' => 'Short phases may fall between samples; OS lifetime peak is separate'],
            ])."\n", FILE_APPEND);
            $this->client->close();
            CaptureStore::writePrivate($this->base.'/observer.stop', 'stop');
            $this->sampler->wait();
            $this->assertSame(0, $this->sampler->getExitCode(), $this->sampler->getErrorOutput());
            $this->assertSame(0, $this->parserGuard->calls);
            AnalysisRuntime::activate(null);
        }
    }

    private function replay(): NativeReplay
    {
        return new NativeReplay($this->client);
    }

    private function compare(string $generation): void
    {
        $native = $this->replay()->records($generation, $this->identity);
        $count = 0;
        foreach (CaptureStore::replay($this->base.'/dataset', $this->captureIdentity) as $item) {
            $this->assertTrue($native->valid());
            $this->assertSame($item['record'], $native->current());
            $native->next();
            $count++;
        }
        $this->assertFalse($native->valid());
        $this->assertSame(8, $count);
    }

    public function test_native_replay_full_entries_after_source_and_parser_removal(): void
    {
        $started = hrtime(true);
        $generation = $this->replay()->load($this->base.'/dataset', $this->captureIdentity, $this->identity);
        $this->measurements[] = ['phase' => 'replay', 'wall_ns' => hrtime(true) - $started, 'php_used_bytes' => memory_get_usage(), 'php_allocated_bytes' => memory_get_usage(true)];
        $this->compare($generation);
        $status = $this->client->request(['op' => 'status']);
        $this->assertGreaterThan(0, $status['owned_capacity_bytes']);
        $this->assertSame('not_attempted', $status['cache_status']);
        $this->assertSame($generation, $status['generation']);
        $this->expectExceptionMessage('native_graph_unsupported');
        $this->client->request(['op' => 'query_page']);
    }

    public function test_native_snapshot_restore_whole_generation_without_sources(): void
    {
        $started = hrtime(true);
        $generation = $this->replay()->load($this->base.'/dataset', $this->captureIdentity, $this->identity);
        $this->measurements[] = ['phase' => 'replay', 'wall_ns' => hrtime(true) - $started, 'php_used_bytes' => memory_get_usage(), 'php_allocated_bytes' => memory_get_usage(true)];
        mkdir($this->base.'/cache', 0700);
        $lease = $this->client->request(['op' => 'pin', 'generation' => $generation, 'identity' => $this->identity])['lease'];
        $result = $this->client->request(['op' => 'snapshot', 'generation' => $generation, 'identity' => $this->identity, 'lease' => $lease, 'directory' => $this->base.'/cache']);
        $this->assertSame('write', $result['cache_status']);
        $pointer = json_decode(file_get_contents($this->base.'/cache/CURRENT'), true, 512, JSON_THROW_ON_ERROR);
        $manifestPath = $this->base.'/cache/'.$pointer['generation'].'/manifest.json';
        $this->assertSame(hash_file('sha256', $manifestPath), $pointer['manifest_sha256']);
        $snapshot = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        foreach ($snapshot['segments'] as $segment) {
            $path = dirname($manifestPath).'/'.$segment['file'];
            $this->assertSame(hash_file('sha256', $path), $segment['sha256']);
            $this->assertSame(filesize($path), $segment['bytes']);
        }
        $this->client->close();
        $this->client = new NativeGraphClient(getenv('ARCHITECTURE_KIT_TEST_GRAPH_RUNTIME'), 268435456, 536870912);
        $started = hrtime(true);
        $result = $this->client->request(['op' => 'restore', 'identity' => $this->identity, 'directory' => $this->base.'/cache']);
        $this->measurements[] = ['phase' => 'restore', 'wall_ns' => hrtime(true) - $started, 'php_used_bytes' => memory_get_usage(), 'php_allocated_bytes' => memory_get_usage(true)];
        $this->assertSame($generation, $result['generation']);
        $this->assertSame('read', $result['cache_status']);
        $this->compare($generation);
        $pointer = json_decode(file_get_contents($this->base.'/cache/CURRENT'), true, 512, JSON_THROW_ON_ERROR);
        $file = $this->base.'/cache/'.$pointer['generation'].'/record-00000000.json';
        file_put_contents($file, 'corrupt');
        $this->expectExceptionMessage('native_graph_cache_segment_hash');
        $this->client->request(['op' => 'restore', 'identity' => $this->identity, 'directory' => $this->base.'/cache']);
    }

    public function test_native_replay_leases_keep_old_generation_and_refuse_foreign_identity(): void
    {
        $one = $this->replay()->load($this->base.'/dataset', $this->captureIdentity, $this->identity);
        $lease = $this->client->request(['op' => 'pin', 'generation' => $one, 'identity' => $this->identity])['lease'];
        $two = $this->replay()->load($this->base.'/dataset', $this->captureIdentity, $this->identity);
        $this->assertNotSame($one, $two);
        $status = $this->client->request(['op' => 'status']);
        $pid = $status['process_usage']['pid'];
        $ps = new Process(['ps', '-p', (string) $pid, '-o', 'pid=,rss=,lstart='], timeout: 1);
        $anchor = hrtime(true);
        $ps->run();
        $this->assertTrue($ps->isSuccessful());
        $this->measurements[] = ['phase' => 'old_lease_plus_new', 'monotonic_begin_ns' => $anchor, 'monotonic_end_ns' => hrtime(true),
            'runtime_status' => $status, 'endpoint_rss_ps' => trim($ps->getOutput()), 'rss_unit' => 'KiB', 'peak' => 'endpoint only, not phase peak'];

        $page = $this->client->request(['op' => 'read_batch', 'generation' => $one, 'identity' => $this->identity, 'lease' => $lease, 'start' => 0, 'limit' => 1]);
        $this->assertCount(1, $page['records']);
        $this->assertSame(8, $page['total']);
        $before = $this->client->request(['op' => 'status'])['owned_capacity_bytes'];
        $this->client->request(['op' => 'release', 'lease' => $lease]);
        $this->assertLessThan($before, $this->client->request(['op' => 'status'])['owned_capacity_bytes']);
        $this->expectExceptionMessage('native_graph_stale_snapshot');
        $this->client->request(['op' => 'pin', 'generation' => $two, 'identity' => [...$this->identity, 'profile' => 'foreign']]);
    }

    public function test_native_replay_rejects_incomplete_capture_before_runtime_ingest(): void
    {
        unlink($this->base.'/dataset/complete');
        $this->expectExceptionMessage('capture_file_boundary');
        $this->replay()->load($this->base.'/dataset', $this->captureIdentity, $this->identity);
    }

    public function test_native_graph_wire_accepts_maximum_record_plus_envelope_and_reports_no_truncation(): void
    {
        // Explicit private admission for the worst-case decode estimate. Actual
        // allocation is measured; this does not change any global resource limit.
        $this->client->close();
        $this->client = new NativeGraphClient(getenv('ARCHITECTURE_KIT_TEST_GRAPH_RUNTIME'), 268435456, 2147483648);
        $options = (new BasicFactsOptions)->toArray();
        $record = CaptureRecord::create(0, 'php',
            BasicSource::plain(new SourceInput('empty.php', '', '8.3')),
            new BasicFactsOptions, new FileGraphEntry([], [], syntaxError: 'x'));
        $record['entry'][1][5][2][1] = str_repeat('x', NativeGraphClient::RECORD_BYTES - 1 - strlen(TypedValue::json($record)) + 1);
        $this->assertSame(NativeGraphClient::RECORD_BYTES - 1, strlen(TypedValue::json($record)));
        $ingest = $this->client->request(['op' => 'begin', 'identity' => $this->identity, 'options' => $options,
            'scope' => [['owner' => 'empty.php', 'sha256' => $record['sha256'], 'kind' => 'php', 'php_version' => '8.3']]])['ingest'];
        $this->client->request(['op' => 'append', 'ingest' => $ingest, 'record' => $record]);
        $g = $this->client->request(['op' => 'commit', 'ingest' => $ingest])['generation'];
        foreach ($this->replay()->records($g, $this->identity) as $got) {
            $this->assertSame($record, $got);
        }
        // The same record must be refused at 1 GiB by the conservative estimate,
        // before allocating its payload in the native receiver.
        $bounded = new NativeGraphClient(getenv('ARCHITECTURE_KIT_TEST_GRAPH_RUNTIME'), 268435456, 1073741824);
        try {
            $id = $bounded->request(['op' => 'begin', 'identity' => $this->identity, 'options' => $options,
                'scope' => [['owner' => 'empty.php', 'sha256' => $record['sha256'], 'kind' => 'php', 'php_version' => '8.3']]])['ingest'];
            $bounded->request(['op' => 'append', 'ingest' => $id, 'record' => $record]);
            $this->fail('1 GiB admission incorrectly accepted boundary record');
        } catch (\RuntimeException $error) {
            $this->assertStringStartsWith('native_graph_', $error->getMessage());
            $this->measurements[] = ['phase' => 'maximum_record_1gib_admission', 'status' => 'refused', 'transition_bytes' => 1073741824, 'reason' => $error->getMessage(), 'default_capacity' => 'OPEN'];
        } finally {
            $bounded->close();
        }
    }

    public function test_native_graph_failed_append_preserves_committed_generation_and_releases_ingest(): void
    {
        $g = $this->replay()->load($this->base.'/dataset', $this->captureIdentity, $this->identity);
        $manifest = CaptureStore::manifest($this->base.'/dataset', $this->captureIdentity);
        $first = CaptureStore::replay($this->base.'/dataset', $this->captureIdentity)->current()['record'];
        $ingest = $this->client->request(['op' => 'begin', 'identity' => $this->identity, 'options' => $manifest['options'],
            'scope' => [['owner' => $first['owner'], 'sha256' => $first['sha256'], 'kind' => $first['kind'], 'php_version' => $first['php_version']]]])['ingest'];
        $first['ordinal'] = 1;
        try {
            $this->client->request(['op' => 'append', 'ingest' => $ingest, 'record' => $first]);
            $this->fail('Corrupt ordinal accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('native_graph_coverage_mismatch', $e->getMessage());
        }
        $status = $this->client->request(['op' => 'status']);
        $this->assertNull($status['ingest']);
        $this->assertSame($g, $status['generation']);
        $this->compare($g);
    }

    public function test_native_graph_numeric_ids_forward_parents_and_typed_extensible_metadata(): void
    {
        $options = new BasicFactsOptions(catalog: true);
        $records = [];
        foreach (['a.php', 'b.php'] as $ordinal => $owner) {
            $catalog = new CatalogFacts($owner, [
                new CatalogElement('01', 'Zażółć', 'file', 1, 1, 0, '0', ['source', 'source'],
                    ['nested' => ['001' => 0.0, 3 => null, 'false' => false, 'empty' => [], 'str' => '', 'int' => 0], 'extra' => ['b' => 2, 'a' => 1]]),
                new CatalogElement('0', '0', 'file', 1, 1, 0),
            ], [new CatalogRelation('0', '01', 'uses', 1, 1),
                new CatalogRelation('0', 'external:Missing', 'uses', 1, 1)],
                [new CatalogDiagnostic('catalog_limit', 'fixture', 1, '0', 'structure')]);
            $records[] = CaptureRecord::create($ordinal, 'php',
                BasicSource::plain(new SourceInput($owner, '', '8.3')),
                $options, new FileGraphEntry([], [], catalog: $catalog));
        }
        $scope = array_map(static fn (array $r): array => ['owner' => $r['owner'], 'sha256' => $r['sha256'], 'kind' => $r['kind'], 'php_version' => $r['php_version']], $records);
        $ingest = $this->client->request(['op' => 'begin', 'identity' => $this->identity, 'options' => $options->toArray(), 'scope' => $scope])['ingest'];
        foreach ($records as $r) {
            $this->client->request(['op' => 'append', 'ingest' => $ingest, 'record' => $r]);
        }
        $g = $this->client->request(['op' => 'commit', 'ingest' => $ingest])['generation'];
        $this->assertSame($records, iterator_to_array($this->replay()->records($g, $this->identity)));
        // Change one parent without changing counts. No partial generation is published.
        $ingest = $this->client->request(['op' => 'begin', 'identity' => $this->identity, 'options' => $options->toArray(), 'scope' => $scope])['ingest'];
        $catalog = TypedValue::decode($records[0]['entry']);
        $catalog['catalog']['elements'][0]['parent'] = 'element:missing';
        $records[0]['entry'] = TypedValue::encode($catalog);
        try {
            $this->client->request(['op' => 'append', 'ingest' => $ingest, 'record' => $records[0]]);
            $this->fail('Dangling local parent accepted');
        } catch (\RuntimeException $error) {
            $this->assertSame('native_graph_parent_ref', $error->getMessage());
        }
        $this->assertSame($g, $this->client->request(['op' => 'status'])['generation']);
    }
}

/** Any attempt to parse after capture fails instead of using a fallback. */
final class NativeReplayParserGuard implements BasicFactsEngine
{
    public int $calls = 0;

    public function descriptor(): EngineDescriptor
    {
        return new EngineDescriptor('php', 'disabled-replay-parser', ['syntax-tree-v1', 'basic-file-facts-v1']);
    }

    public function analyze(SourceInput $source): AnalysisDocument
    {
        $this->calls++;
        throw new \RuntimeException('Replay invoked disabled parser');
    }

    public function analyzeBasic(array $sources, BasicFactsOptions $options): array
    {
        $this->calls++;
        throw new \RuntimeException('Replay invoked disabled Basic parser');
    }
}
