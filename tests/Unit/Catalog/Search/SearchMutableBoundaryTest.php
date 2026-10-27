<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Search;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\Search\SearchReferences;
use GracjanKubicki\ArchitectureKit\Catalog\Search\SearchState;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;
use PHPUnit\Framework\Attributes\DataProvider;

final class SearchMutableBoundaryTest extends SearchFixture
{
    /** @return iterable<string, array{string, bool, bool}> */
    public static function references(): iterable
    {
        foreach (['name', 'whole-record', 'source-path', 'sources', 'metadata-uri', 'roles', 'path', 'kind', 'id', 'whole-elements'] as $field) {
            foreach ([false, true] as $attach) {
                foreach ([false, true] as $afterCache) {
                    yield $field.'-'.($attach ? 'attach' : 'build').'-'.($afterCache ? 'after' : 'before') => [$field, $attach, $afterCache];
                }
            }
        }
    }

    #[DataProvider('references')]
    public function test_references_before_or_after_cache_preserve_complete_legacy_answers(string $field, bool $attach, bool $afterCache): void
    {
        $index = new CatalogIndex([new CatalogFacts('app/Before.php', [new CatalogElement('ref', 'before', 'queue', 1, 1, 0,
            roles: ['controller'], metadata: ['uri' => '/before-uri'])])]);
        $search = new GraphSearch($index);
        if ($afterCache) {
            if ($attach) {
                $index->attachSearchState(SearchState::build($index->elements, 'attached', 'identity'));
            }
            $search->find('before');
        }
        switch ($field) {
            case 'whole-record': $reference = &$index->elements['ref'];
                break;
            case 'source-path': $reference = &$index->elements['ref']['sources'][0]['path'];
                break;
            case 'sources': $reference = &$index->elements['ref']['sources'];
                break;
            case 'metadata-uri': $reference = &$index->elements['ref']['metadata']['uri'];
                break;
            case 'roles': $reference = &$index->elements['ref']['roles'];
                break;
            case 'whole-elements': $reference = &$index->elements;
                break;
            default: $reference = &$index->elements['ref'][$field];
        }
        if (! $afterCache) {
            if ($attach) {
                $index->attachSearchState(SearchState::build($index->elements, 'attached', 'identity'));
            }
            $search->find('before');
        }
        $old = $index->searchState();
        match ($field) {
            'whole-record' => $reference['name'] = 'after',
            'whole-elements' => $reference['ref']['name'] = 'after',
            'source-path', 'path' => $reference = 'Modules/After.php',
            'sources' => $reference = [['path' => 'Modules/After.php', 'line' => 3, 'end_line' => 3]],
            'metadata-uri' => $reference = '/after-uri',
            'roles' => $reference = ['listener'],
            'kind' => $reference = 'class',
            default => $reference = 'after',
        };
        $queries = [['before'], ['after'], ['app/Before.php'], ['Modules/After.php'], ['before-uri'], ['after-uri'], ['listener'], ['controller'], ['queue'], ['class']];
        $this->parity($index, $index, $queries);
        $this->assertNotSame($old, $index->searchState());
        $this->assertTrue($index->searchState()->matches($index->elements));
        // Same count with changed contents must still invalidate the guard.
        $this->assertCount(1, $index->elements);
        $this->assertSame(1, $search->find(match ($field) {
            'source-path', 'sources', 'path' => 'Modules/After.php', 'metadata-uri' => 'after-uri',
            'roles' => 'listener', 'kind' => 'class', default => 'after',
        })['match_count']);
    }

    public function test_plain_copy_after_reference_returns_to_the_fast_guard(): void
    {
        $index = $this->index();
        $alias = &$index->elements['second']['name'];
        $search = new GraphSearch($index);
        $search->find('ABACUS');
        $this->assertTrue(SearchReferences::contains($index->elements));
        $index->elements = json_decode(json_encode($index->elements, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse(SearchReferences::contains($index->elements));
        $state = $index->searchState();
        // Exercise the detached-equal copy before changing the old external alias.
        $this->assertSame($state, $index->searchState());
        $alias = 'external-only-change';
        $this->parity($index, $index, [['ABACUS'], ['external-only-change']]);
        $this->assertSame($state, $index->searchState());
        $index->elements['second']['name'] = 'plain-change';
        $this->parity($index, $index, [['ABACUS'], ['plain-change']]);
        $this->assertNotSame($state, $index->searchState());
        $this->assertFalse(SearchReferences::contains($index->elements));
    }

    public function test_reference_and_normal_admission_and_query_cost_are_reported_separately(): void
    {
        $files = [];
        for ($i = 0; $i < 256; $i++) {
            $files[] = new CatalogFacts('app/Entry'.$i.'.php', [new CatalogElement('entry:'.$i, 'Needle'.$i, 'queue', 1, 1, 0,
                metadata: ['padding' => str_repeat('x', 1024)])]);
        }
        $normal = new CatalogIndex($files);
        $reference = new CatalogIndex($files);
        $alias = &$reference->elements['entry:255']['name'];
        $this->assertFalse(SearchReferences::contains($normal->elements));
        $this->assertTrue(SearchReferences::contains($reference->elements));
        $measurements = [];
        foreach (['normal' => $normal, 'reference' => $reference] as $mode => $index) {
            $started = hrtime(true);
            $measurements[$mode]['has_references'] = SearchReferences::contains($index->elements);
            $measurements[$mode]['reference_detection_ns'] = hrtime(true) - $started;
            $started = hrtime(true);
            $state = SearchState::build($index->elements);
            $measurements[$mode]['build_ns'] = hrtime(true) - $started;
            $started = hrtime(true);
            $index->attachSearchState($state);
            $measurements[$mode]['attach_ns'] = hrtime(true) - $started;
            $search = new GraphSearch($index);
            $started = hrtime(true);
            for ($query = 0; $query < 30; $query++) {
                $result = $search->find('Needle255');
            }
            $measurements[$mode]['queries_30_ns'] = hrtime(true) - $started;
            $measurements[$mode]['query_average_ns'] = $measurements[$mode]['queries_30_ns'] / 30;
            $this->assertSame($state, $index->searchState());
            $this->assertSame((new LegacyGraphSearch($index))->find('Needle255'), $result);
            $measurements[$mode]['result'] = $result;
        }
        $alias = 'after';
        $this->parity($reference, $reference, [['after'], ['Needle255']]);
        $this->evidence(['fixture' => '256 synthetic CatalogFacts records, 1KiB padding each; reference path includes full digest check on each query',
            'measurements' => $measurements, 'used' => memory_get_usage(), 'allocated' => memory_get_usage(true)]);
    }

    /** @param array<string, mixed> $data */
    protected function evidence(array $data): void
    {
        $directory = getenv('W03_DELTA_EVIDENCE');
        if ($directory !== false && $directory !== '') {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/reference.ndjson', json_encode(['test' => $this->nameWithDataSet(), ...$data], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
        }
    }
}
