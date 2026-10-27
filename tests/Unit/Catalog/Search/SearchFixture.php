<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Search;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogContribution;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogGeneration;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogIdentity;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogPatch;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogSequence;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogUpdater;
use GracjanKubicki\ArchitectureKit\Context\GraphPage;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;
use GracjanKubicki\ArchitectureKit\Tests\Unit\Context\LegacyGraphPage;
use PHPUnit\Framework\TestCase;

abstract class SearchFixture extends TestCase
{
    protected function index(): CatalogIndex
    {
        $files = [];
        foreach ([
            ['id-only-marker', 'name-only-marker', 'class', 'app/Actions/One.php', ['action'], ['name' => 'meta-only-marker', 'uri' => '/uri-only-marker', 'table' => 'table-only-marker', 'route_name' => 'route-only-marker']],
            ['second', 'ABACUS', 'class', 'app/Actions/Sub/Two.php', ['controller'], []],
            ['third', 'xababa*?.[x]%_\\ĄÉ', 'class', 'app/ActionsExtra/Three.php', [], []],
            ['fourth', 'ab', 'function', 'tests/Four.php', [], ['name' => ['ignored-non-string']]],
            ['fifth', '12', 'class', 'Modules/Five.php', [], []],
            ['sixth', 'xab-ą-é', 'class', 'app/Six.php', [], []],
        ] as [$id, $name, $kind, $path, $roles, $metadata]) {
            $files[] = new CatalogFacts($path, [new CatalogElement($id, $name, $kind, 1, 2, 0, roles: $roles, metadata: $metadata)]);
        }

        return new CatalogIndex($files);
    }

    /** @param array<string, string> $files
     * @return list<CatalogContribution>
     */
    protected function collect(array $files): array
    {
        ksort($files);
        $builder = new ProjectGraphBuilder(catalog: true);
        $sources = [];
        foreach ($files as $path => $bytes) {
            $sources[] = CatalogContribution::collect(new FileContext($path, $bytes), $builder);
        }

        return $sources;
    }

    protected function identity(): CatalogIdentity
    {
        return new CatalogIdentity(['repository' => '/w03-fixture', 'configuration' => 'default', 'scope' => 'app,tests,Modules,composer',
            'composer' => 'none', 'classification' => 'default', 'backend' => getenv('ARCHITECTURE_KIT_SUITE_ENGINE') ?: 'php',
            'php' => PHP_VERSION, 'tool' => 'w03', 'product' => 'basic-v1']);
    }

    /** @param array<string, string> $files */
    protected function prepare(array $files): CatalogGeneration
    {
        return (new CatalogUpdater)->prepare($this->collect($files), $this->identity(), 'w03-session', contents: fn ($path) => $files[$path] ?? null);
    }

    /** All raw fields and ordering are compared; oracle is the original independent scan.
     * @param  list<array{0: string, 1?: ?string, 2?: ?string}>  $queries
     */
    protected function parity(CatalogIndex $actual, CatalogIndex $reference, array $queries): void
    {
        $search = new GraphSearch($actual);
        $oracle = new LegacyGraphSearch($reference);
        foreach ($queries as $arguments) {
            $expected = $oracle->find(...$arguments);
            $result = $search->find(...$arguments);
            $this->evidence(['query' => $arguments, 'expected' => $expected, 'actual' => $result, 'execution' => $search->execution()]);
            $this->assertSame($expected, $result, json_encode($arguments));
        }
    }

    /** @param list<array{0: string, 1?: ?string, 2?: ?string}> $queries */
    protected function pageParity(CatalogIndex $actual, CatalogIndex $reference, array $queries): void
    {
        foreach ($queries as $query) {
            $result = (new GraphSearch($actual))->find(...$query);
            $expected = (new LegacyGraphSearch($reference))->find(...$query);
            $arguments = ['query' => $query[0], 'kind' => $query[1] ?? null, 'path' => $query[2] ?? null, 'limit' => 7];
            $all = [];
            do {
                $page = GraphPage::make('search', $arguments, 'same-generation', [], $actual->diagnostics, $result, 'candidates');
                $oracle = LegacyGraphPage::make('search', $arguments, 'same-generation', [], $reference->diagnostics, $expected, 'candidates');
                $this->assertSame($oracle, $page);
                array_push($all, ...$page['result']['candidates']);
                $this->evidence(['expected_page' => $oracle, 'actual_page' => $page]);
                $arguments = $page['next']['arguments'] ?? null;
            } while ($arguments !== null);
            $this->assertSame($expected['candidates'], $all);
        }
    }

    /** @param array<string, mixed> $data */
    protected function evidence(array $data): void
    {
        $directory = getenv('W03_EVIDENCE_DIR');
        if ($directory === false || $directory === '') {
            return;
        }
        $directory .= '/'.substr(strrchr(static::class, '\\'), 1).'/'.$this->name();
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        static $ordinal = 0;
        file_put_contents($directory.'/'.(++$ordinal).'.json', json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** @param array<string, string> $files
     * @param  array<string, string>  $changed
     * @param  list<string>  $deleted
     */
    protected function patch(CatalogGeneration $base, array $files, array $changed, array $deleted = []): CatalogPatch
    {
        $sequence = CatalogSequence::next($base->sequence);

        return (new CatalogUpdater)->patch($base, $this->collect($changed), $deleted, $this->identity(), 'w03-session', $sequence, $sequence, fn ($path) => $files[$path] ?? null);
    }
}
