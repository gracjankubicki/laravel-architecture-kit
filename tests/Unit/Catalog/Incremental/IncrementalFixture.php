<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Incremental;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogContribution;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogDependencyState;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogGeneration;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogIdentity;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogSequence;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogUpdater;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;
use PHPUnit\Framework\TestCase;

abstract class IncrementalFixture extends TestCase
{
    protected function identity(): CatalogIdentity
    {
        return new CatalogIdentity(['repository' => '/fixture', 'configuration' => 'default', 'scope' => 'app,tests,Modules,composer',
            'composer' => 'none', 'classification' => 'default', 'backend' => getenv('ARCHITECTURE_KIT_SUITE_ENGINE') ?: 'php',
            'php' => '8.5', 'tool' => 'test', 'product' => 'basic-v1']);
    }

    /** @param array<string, string> $files
     * @return list<CatalogContribution>
     */
    protected function collect(array $files): array
    {
        ksort($files);
        $builder = new ProjectGraphBuilder(catalog: true);

        return array_map(fn (string $path, string $contents) => CatalogContribution::collect(new FileContext($path, $contents), $builder), array_keys($files), array_values($files));
    }

    /** @param array<string, string> $files */
    protected function prepare(array $files): CatalogGeneration
    {
        return (new CatalogUpdater)->prepare($this->collect($files), $this->identity(), 'session', contents: fn ($path) => $files[$path] ?? null);
    }

    /** @param array<string, string> $files
     * @param  array<string, string>  $changed
     * @param  list<string>  $deleted
     */
    protected function update(CatalogGeneration $base, array $files, array $changed, array $deleted = []): CatalogGeneration
    {
        $reader = fn ($path) => $files[$path] ?? null;
        $patch = (new CatalogUpdater)->patch($base, $this->collect($changed), $deleted, $this->identity(), 'session', CatalogSequence::next($base->sequence), CatalogSequence::next($base->sequence), $reader);

        return $patch->apply($base, 'session', $reader);
    }

    /** @param array<string, string> $files
     * @param  list<string>  $selectors
     */
    protected function assertOracle(CatalogGeneration $generation, array $files, array $selectors): void
    {
        $oracle = new CatalogIndex(array_map(fn (CatalogContribution $source) => $source->facts, $this->collect($files)));
        $this->assertSame($oracle->exportState(), $generation->exportState());
        $evidence = getenv('W02_EVIDENCE_DIR');
        if ($evidence !== false && $evidence !== '') {
            static $ordinal = 0;
            $directory = $evidence.'/'.substr(strrchr(static::class, '\\'), 1).'/'.$this->name().'/'.(++$ordinal);
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/state.json', json_encode(['files' => $files, 'hashes' => $generation->hashes(),
                'generation' => $generation->id(), 'measurements' => $generation->measurements,
                'actual' => $generation->exportState(), 'oracle' => $oracle->exportState()], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
        $index = $generation->index();
        $responses = [];
        foreach ($selectors as $selector) {
            $responses[$selector] = ['actual_name' => $index->namedTypes($selector), 'oracle_name' => $oracle->namedTypes($selector)];
            $this->assertSame($oracle->namedTypes($selector), $index->namedTypes($selector));
            foreach (['context', 'impact'] as $operation) {
                $responses[$selector][$operation] = ['oracle' => (new GraphQuery($oracle))->query($selector, $operation), 'actual' => (new GraphQuery($index))->query($selector, $operation)];
                $this->assertSame($responses[$selector][$operation]['oracle'], $responses[$selector][$operation]['actual']);
            }
            $responses[$selector]['search'] = ['oracle' => (new GraphSearch($oracle))->find($selector), 'actual' => (new GraphSearch($index))->find($selector)];
            $this->assertSame($responses[$selector]['search']['oracle'], $responses[$selector]['search']['actual']);
        }
        if ($evidence !== false && $evidence !== '') {
            file_put_contents($directory.'/queries.json', json_encode($responses, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            file_put_contents($directory.'/dependency.json', (new CatalogDependencyState)->encode($generation));
        }
    }
}
