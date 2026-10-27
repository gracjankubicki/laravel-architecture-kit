<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\Capture;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisSession;
use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsOptions;
use GracjanKubicki\ArchitectureKit\Analysis\BasicSource;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\PhpAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Analysis\StreamingBasicCollector;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntryCodec;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureRecord;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureSourcePlan;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureStore;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use PHPUnit\Framework\TestCase;

final class NativeCaptureTest extends TestCase
{
    private string $base;

    private string $root;

    private AnalysisSession $session;

    private BasicFactsOptions $options;

    private array $plan;

    private array $identity;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__, 4).'/build/graph-performance-rust/R01/fixtures/'.bin2hex(random_bytes(6));
        $this->root = $this->base.'/repo';
        mkdir($this->root, 0700, true);
        $binary = getenv('ARCHITECTURE_KIT_TEST_ENGINE');
        $backend = getenv('ARCHITECTURE_KIT_SUITE_ENGINE') === 'mago' ? new MagoAnalysisEngine($binary ?: '') : new PhpAnalysisEngine;
        $this->session = new AnalysisSession($backend, '8.3');
        AnalysisRuntime::activate($this->session);
        $this->options = new BasicFactsOptions(true, true, true);
        $files = [
            'app/Service.php' => "<?php\nnamespace App;\nclass Service { public function run(): string { return 'ok'; } }\n",
            'app/Caller.php' => "<?php\nnamespace App;\nclass Caller { public function call(Service \$service): string { return \$service->run(); } }\n",
            'Modules/Billing/Job.php' => "<?php\nnamespace Modules\\Billing; class Job extends \\App\\Service {}\n",
            'resources/views/page.blade.php' => "<div>\n{{ app(\\App\\Service::class)->run() }}\n</div>\n",
            'tests/ServiceTest.php' => "<?php\nuse App\\Service; it('runs', function () { \$this->get('/capture-fixture'); expect((new Service)->run())->toBe('ok'); });\n",
            'app/Empty.php' => "<?php\n",
            'app/Broken.php' => "<?php\nclass Broken {\n",
            'composer.json' => '{"name":"fixture/capture","type":"project","require":{"php":"^8.3"},"autoload":{"psr-4":{"App\\\\":"app/","Modules\\\\":"Modules/"}}}',
        ];
        foreach ($files as $path => $bytes) {
            if (! is_dir(dirname($this->root.'/'.$path))) {
                mkdir(dirname($this->root.'/'.$path), 0700, true);
            }
            file_put_contents($this->root.'/'.$path, $bytes);
        }
        $this->plan = CaptureSourcePlan::create($this->root);
        $this->identity = ['fixture' => TypedValue::digest($this->plan)];
    }

    private function store(?\Closure $admit = null): CaptureStore
    {
        return new CaptureStore($this->base.'/dataset', $this->plan, $this->identity, $this->options->toArray(), $admit ?? static function (int $bytes): void {});
    }

    private function capture(): array
    {
        $store = $this->store();
        $entries = [];
        (new StreamingBasicCollector($this->session, $this->options, $this->root))->collect($this->plan,
            function (array $record) use ($store, &$entries): void {
                $entries[$record['owner']] = CaptureRecord::decode($record, $this->options->toArray());
                $store->append($record);
            }, static function (): void {});
        CaptureSourcePlan::assertCurrent($this->plan);
        $store->commit();

        return $entries;
    }

    public function test_full_entries_witnesses_blade_and_replay_survive_source_removal(): void
    {
        $entries = $this->capture();
        $this->assertCount(8, $entries);
        $this->assertNotNull($entries['app/Broken.php']->syntaxError);
        $this->assertSame('file', $entries['app/Empty.php']->catalog->elements[0]->kind);
        $this->assertNotEmpty($entries['app/Caller.php']->edges);
        $this->assertNotEmpty($entries['tests/ServiceTest.php']->testInvocations);
        $this->assertSame(0700, fileperms($this->base.'/dataset') & 0777);
        rename($this->root, $this->root.'-unavailable');
        $replayed = [];
        foreach (CaptureStore::replay($this->base.'/dataset', $this->identity) as $item) {
            $path = $item['record']['owner'];
            $replayed[$path] = $item['entry'];
            $this->assertSame(FileGraphEntryCodec::encode($entries[$path]), FileGraphEntryCodec::encode($item['entry']));
            if ($path === 'resources/views/page.blade.php') {
                $this->assertSame('blade-php-v1', $item['record']['witness']['mapping_version']);
                $this->assertNotEmpty($item['record']['witness']['segments']);
            }
        }
        $this->assertSame(array_keys($entries), array_keys($replayed));
        foreach (glob($this->base.'/dataset/*') as $path) {
            $this->assertSame(0600, fileperms($path) & 0777);
        }
    }

    public function test_small_full_php_oracle_has_eight_sets_queries_and_negative_comparator_controls(): void
    {
        $entries = $this->capture();
        $direct = (new ProjectGraphBuilder(impact: true, preserveOccurrences: true, catalog: true, basePath: $this->root))->collectBasicMany(
            array_map(fn (string $path) => new FileContext($path, file_get_contents($this->root.'/'.$path), analysis: $this->session), array_keys($this->plan['files'])));
        $reference = new CatalogIndex(array_map(static fn ($entry) => $entry->catalog, $direct));
        $replayed = [];
        foreach (CaptureStore::replay($this->base.'/dataset', $this->identity) as $item) {
            $replayed[] = $item['entry']->catalog;
        }
        $actual = new CatalogIndex($replayed);
        $make = static function (CatalogIndex $index): array {
            $query = new GraphQuery($index);

            return ['state' => $index->exportState(), 'answers' => [
                'search' => (new GraphSearch($index))->find('Service'), 'context' => $query->query('App\\Service'),
                'impact' => $query->query('App\\Service', 'impact'), 'path' => $query->query('App\\Caller', 'path', 'App\\Service')]];
        };
        $oracle = $make($reference);
        $this->assertCount(8, $oracle['state']);
        $this->assertCount(1, $reference->namedTypes('App\\Service'));
        $this->assertSame(3, $reference->elements[$reference->namedTypes('App\\Service')[0]]['line']);
        $this->assertNotEmpty($oracle['state']['relations']);
        $this->assertNotEmpty($oracle['state']['diagnostics']);
        $this->assertGreaterThan(0, $oracle['answers']['search']['match_count']);
        $this->assertSame('found', $oracle['answers']['context']['status']);
        $this->assertSame($oracle, $make($actual));
        $encoded = TypedValue::encode($oracle);
        CaptureStore::writePrivate($this->base.'/oracle-'.$this->session->engine->engine.'.json', TypedValue::json(['identity' => $this->identity, 'oracle' => $encoded]));
        $mutated = $oracle;
        array_pop($mutated['state']['relations']);
        $this->assertNotSame(TypedValue::digest($encoded), TypedValue::digest(TypedValue::encode($mutated)));
        $mutated = $oracle;
        $first = array_key_first($mutated['state']['elements']);
        $mutated['state']['elements'][$first]['line']++;
        $this->assertNotSame($encoded, TypedValue::encode($mutated));
    }

    public function test_typed_values_keep_key_types_order_float_null_and_empty(): void
    {
        $data = ['001' => 1.0, 3 => null, 'bool' => false, 'empty' => [], 'nested' => ['b' => 2, 'a' => 1]];
        $json = TypedValue::json(TypedValue::encode($data));
        $this->assertSame($data, TypedValue::decode(json_decode($json, true, 512, JSON_THROW_ON_ERROR)));
        $plain = BasicSource::plain(new SourceInput('empty.php', '', '8.3'));
        $record = CaptureRecord::create(0, 'php', $plain, new BasicFactsOptions, new FileGraphEntry([], []));
        $this->assertNull(CaptureRecord::decode($record, (new BasicFactsOptions)->toArray())->catalog);
        $this->assertNull(CaptureRecord::decode($record, (new BasicFactsOptions)->toArray())->syntaxError);
    }

    public function test_each_segment_corruption_and_missing_complete_are_rejected_before_yield(): void
    {
        $this->capture();
        $segment = $this->base.'/dataset/segment-000000.ndjson';
        $original = file_get_contents($segment);
        $lines = explode("\n", trim($original));
        foreach (['delete' => implode("\n", array_slice($lines, 1))."\n", 'duplicate' => $original.$lines[0]."\n",
            'reorder' => implode("\n", array_reverse($lines))."\n", 'truncate' => substr($original, 0, -1),
            'modify' => str_replace('Service', 'SERVice', $original)] as $case => $bytes) {
            file_put_contents($segment, $bytes);
            $yielded = false;
            try {
                foreach (CaptureStore::replay($this->base.'/dataset', $this->identity) as $item) {
                    $yielded = true;
                }
                $this->fail($case.' corruption accepted');
            } catch (\RuntimeException $error) {
                $this->assertFalse($yielded, $case);
            }
        }
        file_put_contents($segment, $original);
        unlink($this->base.'/dataset/complete');
        $this->expectException(\RuntimeException::class);
        iterator_to_array(CaptureStore::replay($this->base.'/dataset', $this->identity));
    }

    public function test_witness_and_preparation_corruption_is_rejected_without_source_reader(): void
    {
        $this->capture();
        foreach (CaptureStore::replay($this->base.'/dataset', $this->identity) as $item) {
            if ($item['record']['kind'] !== 'blade') {
                continue;
            }
            $record = $item['record'];
            foreach (['length', 'newline', 'mapping', 'identity'] as $case) {
                $broken = $record;
                match ($case) {
                    'length' => $broken['witness']['original']['length'] = -1,
                    'newline' => $broken['witness']['original']['newlines'][] = PHP_INT_MAX,
                    'mapping' => $broken['witness']['segments'][0]['length']++,
                    'identity' => $broken['witness']['mapping_fingerprint'] = str_repeat('0', 64),
                };
                try {
                    CaptureRecord::decode($broken, $this->options->toArray());
                    $this->fail($case.' witness accepted');
                } catch (\InvalidArgumentException $error) {
                    $this->assertStringStartsWith('capture_', $error->getMessage());
                }
            }
        }
    }

    public function test_edit_add_delete_and_configuration_drift_are_not_completed(): void
    {
        foreach (['edit', 'add', 'delete', 'config'] as $change) {
            $before = CaptureSourcePlan::create($this->root);
            match ($change) {
                'edit' => file_put_contents($this->root.'/app/Service.php', '<?php class Changed {}'),
                'add' => file_put_contents($this->root.'/app/Added.php', '<?php class Added {}'),
                'delete' => unlink($this->root.'/app/Caller.php'),
                'config' => file_put_contents($this->root.'/composer.json', '{"require":{"php":"^8.4"}}'),
            };
            try {
                CaptureSourcePlan::assertCurrent($before);
                $this->fail($change.' drift accepted');
            } catch (\RuntimeException $error) {
                $this->assertSame('capture_source_drift', $error->getMessage());
            }
        }
        $store = $this->store();
        $store->abort('capture_source_drift');
        $this->assertFileDoesNotExist($this->base.'/dataset/complete');
    }

    public function test_producer_interruption_disk_limit_and_io_failure_cannot_publish_complete(): void
    {
        $store = $this->store(static function (int $bytes): void {
            throw new \RuntimeException('capture_disk_limit');
        });
        try {
            (new StreamingBasicCollector($this->session, $this->options, $this->root))->collect($this->plan, $store->append(...), static function (): void {});
            $this->fail('disk limit accepted');
        } catch (\RuntimeException $error) {
            $this->assertSame('capture_disk_limit', $error->getMessage());
        }
        $store->abort('capture_disk_limit');
        $this->assertFileDoesNotExist($this->base.'/dataset/complete');
        $this->expectException(\RuntimeException::class);
        $store->commit();
    }

    public function test_records_are_bounded_and_no_global_composition_runs(): void
    {
        $stages = [];
        $session = $this->session->withStageObserver(static function (string $stage) use (&$stages): void {
            $stages[] = $stage;
        });
        AnalysisRuntime::activate($session);
        $count = 0;
        (new StreamingBasicCollector($session, $this->options, $this->root))->collect($this->plan,
            static function (array $record) use (&$count): void {
                $count++;
            }, static function (): void {});
        $this->assertSame(count($this->plan['files']), $count);
        foreach ($stages as $stage) {
            $this->assertStringNotContainsString('compose', $stage);
            $this->assertStringNotContainsString('resolver', $stage);
        }
    }
}
