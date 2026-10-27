<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsOptions;
use GracjanKubicki\ArchitectureKit\Analysis\BasicSource;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\BladePhpSource;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureRecord;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeBasic;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeGraphClient;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeReplay;
use PHPUnit\Framework\TestCase;

abstract class NativeBasicFixture extends TestCase
{
    protected NativeGraphClient $client;

    protected NativeBasic $basic;

    protected array $identity;

    protected function setUp(): void
    {
        $binary = getenv('ARCHITECTURE_KIT_TEST_GRAPH_RUNTIME');
        if (! $binary) {
            $this->markTestSkipped('Explicit private native runtime required.');
        }
        $this->client = new NativeGraphClient($binary, 268435456, 536870912);
        $this->basic = new NativeBasic($this->client);
        $this->identity = ['repository' => 'r03-fixture', 'profile' => 'basic', 'product' => 'basic-v1', 'parser' => getenv('ARCHITECTURE_KIT_SUITE_ENGINE') ?: 'php', 'tool' => 'r03', 'configuration' => 'default', 'capture' => 'fixture'];
    }

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            $this->client->close();
        }
    }

    protected function collect(array $files): array
    {
        ksort($files);
        $builder = new ProjectGraphBuilder(impact: true, preserveOccurrences: true, catalog: true, mode: AnalysisMode::Basic);
        $options = new BasicFactsOptions(true, true, true);
        $entries = $builder->collectBasicMany(array_map(fn ($path, $bytes) => new FileContext($path, $bytes), array_keys($files), array_values($files)));
        $records = [];
        foreach (array_keys($files) as $i => $path) {
            $kind = in_array($path, ['composer.json', 'composer.lock', 'vendor/composer/installed.json'], true) ? 'composer' : (str_ends_with($path, '.blade.php') ? 'blade' : 'php');
            $source = $kind === 'blade' ? (new BladePhpSource)->input(new FileContext($path, $files[$path])) : BasicSource::plain(new SourceInput($path, $files[$path], '8.5'));
            $records[] = CaptureRecord::create($i, $kind, $source, $options, $entries[$i]);
        }

        return [$records, new CatalogIndex(array_map(fn ($e) => $e->catalog, $entries), mode: AnalysisMode::Basic), $options];
    }

    protected function load(array $files): array
    {
        [$records, $oracle, $options] = $this->collect($files);

        return $this->loadRecords($records, $oracle, $options);
    }

    protected function loadRecords(array $records, CatalogIndex $oracle, BasicFactsOptions $options): array
    {
        $this->identity['capture'] = TypedValue::digest(array_map(fn ($r) => [$r['owner'], $r['sha256']], $records));
        $scope = array_map(fn ($r) => array_intersect_key($r, array_flip(['owner', 'sha256', 'kind', 'php_version'])), $records);
        $ingest = $this->client->request(['op' => 'begin', 'identity' => $this->identity, 'options' => $options->toArray(), 'scope' => $scope])['ingest'];
        foreach ($records as $record) {
            $this->client->request(['op' => 'append', 'ingest' => $ingest, 'record' => $record]);
            $this->basic->enrich($ingest, $record);
            $accounting = $this->client->request(['op' => 'accounting_check', 'ingest' => $ingest]);
            $this->assertSame($accounting['full'], $accounting['cached'], 'Pending ledger drift');
        }
        $generation = $this->client->request(['op' => 'commit', 'ingest' => $ingest])['generation'];
        $generation = $this->basic->compose($generation, $this->identity);
        $exported = iterator_to_array((new NativeReplay($this->client))->records($generation, $this->identity));
        $this->assertSame($records, $exported);
        $actual = $this->basic->state($generation, $this->identity);
        $this->assertSame($oracle->exportState(), $actual);

        $this->assertAccounting($generation);
        $scans = $this->client->request(['op' => 'status'])['accounting_full_scans'];
        $this->assertQueries($generation, $oracle);
        $this->assertSame($scans, $this->client->request(['op' => 'status'])['accounting_full_scans'], 'Ready queries scanned the model for accounting');

        return [$generation, $oracle];
    }

    protected function assertQueries(string $generation, CatalogIndex $oracle): void
    {
        $query = new GraphQuery($oracle);
        foreach ($oracle->elements as $element) {
            $id = $element['id'];
            foreach (['context', 'impact'] as $mode) {
                $this->assertSame($query->query($id, $mode), $this->basic->query($generation, $this->identity, ['type' => $mode, 'subject' => $id]));
            }
            $impact = $query->query($id, 'impact');
            $impact['records'] = array_values(array_filter($impact['records'], fn ($row) => $row['test_candidate']));
            $impact['status'] = $impact['records'] === [] ? 'empty' : 'found';
            $this->assertSame($impact, $this->basic->query($generation, $this->identity, ['type' => 'test_candidates', 'subject' => $id]));
            $this->assertSame($query->query($id, 'path', $id), $this->basic->query($generation, $this->identity, ['type' => 'path', 'subject' => $id, 'target' => $id]));
            $this->assertSame((new GraphSearch($oracle))->find($element['name']), $this->basic->query($generation, $this->identity, ['type' => 'search', 'query' => $element['name']]));
        }
        // Exercise actual forward and reverse paths after every full/patch, not only self paths.
        $pairs = [];
        foreach ($oracle->relations as $relation) {
            if (! isset($oracle->elements[$relation['from']], $oracle->elements[$relation['to']])) {
                continue;
            }
            foreach ([[$relation['from'], $relation['to']], [$relation['to'], $relation['from']]] as [$from, $to]) {
                $key = json_encode([$from, $to], JSON_THROW_ON_ERROR);
                if (isset($pairs[$key])) {
                    continue;
                }
                $pairs[$key] = true;
                $this->assertSame($query->query($from, 'path', $to), $this->basic->query($generation, $this->identity, ['type' => 'path', 'subject' => $from, 'target' => $to]));
            }
        }

    }

    protected function patch(string $generation, array $files, array $changed): array
    {
        [$records, $oracle] = $this->collect($files);
        $target = $this->identity;
        $target['capture'] = TypedValue::digest(array_map(fn ($r) => [$r['owner'], $r['sha256']], $records));
        $scope = array_map(fn ($r) => array_intersect_key($r, array_flip(['owner', 'sha256', 'kind', 'php_version'])), $records);
        $next = $this->basic->patch($generation, $this->identity, $target, $scope, array_filter($records, fn ($r) => in_array($r['owner'], $changed, true)));
        $this->identity = $target;
        $this->assertSame($oracle->exportState(), $this->basic->state($next, $this->identity));
        $this->assertAccounting($next);
        $this->assertQueries($next, $oracle);

        return [$next, $oracle];
    }

    protected function assertAccounting(string $generation): void
    {
        $lease = $this->client->request(['op' => 'pin', 'generation' => $generation, 'identity' => $this->identity])['lease'];
        try {
            $accounting = $this->client->request(['op' => 'accounting_check', 'generation' => $generation, 'identity' => $this->identity, 'lease' => $lease]);
            $this->assertSame($accounting['full'], $accounting['cached'], 'Immutable ledger drift');
        } finally {
            $this->client->request(['op' => 'release', 'lease' => $lease]);
        }
    }
}
