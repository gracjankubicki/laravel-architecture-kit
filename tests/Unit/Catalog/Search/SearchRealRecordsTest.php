<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Search;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\Search\SearchState;
use GracjanKubicki\ArchitectureKit\Catalog\Search\SearchStateCodec;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;

final class SearchRealRecordsTest extends SearchFixture
{
    public function test_real_package_source_records_query_restore_and_real_w02_delta(): void
    {
        $root = dirname(__DIR__, 4);
        $files = [];
        $hashes = [];
        foreach (['src/Catalog/CatalogKinds.php', 'src/Catalog/CatalogDiagnostic.php', 'src/Catalog/Incremental/CatalogIdentity.php',
            'src/Catalog/Incremental/CatalogSequence.php', 'src/Context/ArchitectureContextException.php', 'src/Analysis/AnalysisMode.php'] as $path) {
            $bytes = file_get_contents($root.'/'.$path);
            $this->assertIsString($bytes);
            $files[$path] = $bytes;
            $hashes[$path] = hash('sha256', $bytes);
        }
        $generation = $this->prepare($files);
        $index = $generation->index();
        $used = memory_get_usage();
        $started = hrtime(true);
        $state = SearchState::fromGeneration($generation);
        $build = ['ns' => hrtime(true) - $started, 'used_delta' => memory_get_usage() - $used, 'allocated' => memory_get_usage(true)];
        $index->attachSearchState($state);
        $search = new GraphSearch($index);
        $queries = [['CatalogIdentity'], ['catalog'], ['nostic'], ['a'], ['cl'], ['Catalog', 'class', 'src/Catalog'], ['architecture'], ['absent']];
        $timings = [];
        foreach ($queries as $arguments) {
            $started = hrtime(true);
            $answer = $search->find(...$arguments);
            $timings[] = ['arguments' => $arguments, 'ns' => hrtime(true) - $started, 'execution' => $search->execution(), 'count' => $answer['match_count']];
        }
        $this->parity($index, $index, $queries);
        $encoded = SearchStateCodec::encode($state);
        $started = hrtime(true);
        $restored = SearchStateCodec::decode($encoded, $index->elements, $generation->id(), $generation->identity->digest());
        $restoreNs = hrtime(true) - $started;
        $this->assertSame($state->export(), $restored->export());
        // Byte mutation of a copied real source, explicitly synthetic. Never writes that source.
        $path = 'src/Catalog/CatalogDiagnostic.php';
        $files[$path] = str_replace('function toArray(', 'function renamedArray(', $files[$path]);
        $this->assertNotSame($hashes[$path], hash('sha256', $files[$path]));
        $patch = $this->patch($generation, $files, [$path => $files[$path]]);
        $next = $state->apply($patch);
        $actual = $patch->target->index();
        $actual->attachSearchState($next);
        $reference = new CatalogIndex(array_map(fn ($source) => $source->facts, $this->collect($files)));
        $this->parity($actual, $reference, [...$queries, ['renamedArray'], ['toArray']]);
        $this->assertSame(1, (new GraphSearch($actual))->find('renamedArray')['match_count']);
        $rss = [];
        exec('ps -o rss= -p '.getmypid(), $rss);
        $this->evidence(['fixture' => 'six real package source byte copies; toArray rename is synthetic; not CRM/Glenross', 'hashes' => $hashes,
            'source_bytes' => array_sum(array_map('strlen', $files)), 'elements' => count($index->elements), 'generation' => $generation->id(),
            'build' => $build, 'queries' => $timings, 'restore_ns' => $restoreNs, 'encoded_bytes' => strlen($encoded),
            'used' => memory_get_usage(), 'allocated' => memory_get_usage(true), 'rss_kib' => (int) trim(implode('', $rss)), 'search' => $state->measurements(),
            'patch_delta' => $patch->changes['elements'], 'target' => $patch->target->id()]);
    }
}
