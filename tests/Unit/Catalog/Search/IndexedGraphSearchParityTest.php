<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Search;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogKinds;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;

final class IndexedGraphSearchParityTest extends SearchFixture
{
    public function test_all_fields_exact_prefix_substring_and_best_rank(): void
    {
        $index = $this->index();
        $queries = [];
        foreach (['id-only-marker', 'name-only-marker', 'class', 'app/Actions/One.php', 'action', 'meta-only-marker', '/uri-only-marker', 'table-only-marker', 'route-only-marker'] as $field) {
            $queries[] = [$field];
            $queries[] = [substr($field, 0, -2)];
            $queries[] = [substr($field, 2, -2)];
            $this->assertContains('id-only-marker', array_column((new GraphSearch($index))->find($field)['candidates'], 'id'));
        }
        $this->parity($index, $index, $queries);
        $this->assertSame(['id-only-marker'], array_column((new GraphSearch($index))->find('meta-only-marker')['candidates'], 'id'));
        $this->assertSame('exact', (new GraphSearch($index))->find('id-only-marker')['candidates'][0]['match']);
        $this->assertSame(0, (new GraphSearch($index))->find('ignored-non-string')['match_count']);
    }

    public function test_short_bytes_literal_utf8_and_all_filters(): void
    {
        $index = $this->index();
        $queries = [];
        foreach (['a', 'ab', '12', 'zz', ' AB ', 'Ą', 'ą', 'É', 'é', '*', '?', '.', '[x]', '%', '_', '\\', 'a*?', 'abacus', 'ABACUS'] as $needle) {
            foreach ([null, ...CatalogKinds::all()] as $kind) {
                foreach ([null, 'app/Actions', 'app/Actions/', 'app/Actions/One.php', 'app/Actions/Sub', 'app/ActionsExtra'] as $path) {
                    $queries[] = [$needle, $kind, $path];
                }
            }
        }
        $this->parity($index, $index, $queries);
        $search = new GraphSearch($index);
        $this->assertSame(['third'], array_column($search->find('Ą')['candidates'], 'id'));
        $this->assertSame(['sixth'], array_column($search->find('ą')['candidates'], 'id'));
        $this->assertSame(0, $search->find('a*?', 'class', 'app/Actions')['match_count']);
        $this->assertSame(['second', 'id-only-marker'], array_column($search->find('a', 'class', 'app/Actions')['candidates'], 'id'));
        $search->find('ab');
        $this->assertSame('short_dictionary_scan', $search->execution()['mode']);
    }

    public function test_invalid_and_empty_responses_are_unchanged(): void
    {
        $index = $this->index();
        $queries = [[''], ['  '], ['x', 'invented'], ['absent']];
        foreach (['', '/app', '../app', 'app/../tests', 'app//Actions', 'app\\Actions', 'vendor', 'a/node_modules/x', '.git', 'app:foo', "app\x01"] as $path) {
            $queries[] = ['x', null, $path];
        }
        $this->parity($index, $index, $queries);
        $this->assertArrayNotHasKey('count_basis', (new GraphSearch($index))->find(''));
        $this->assertSame('exact_in_analyzed_graph', (new GraphSearch($index))->find('absent')['count_basis']);
    }

    public function test_shared_sources_and_segment_filters(): void
    {
        $element = new CatalogElement('shared', 'receipts', 'queue', 2, 3, 0);
        $index = new CatalogIndex([new CatalogFacts('app/First.php', [$element]), new CatalogFacts('Modules/Second.php', [$element])]);
        $queries = [];
        foreach (['app/First.php', 'Modules/Second.php'] as $path) {
            foreach ([$path, dirname($path), dirname($path).'/'] as $prefix) {
                $queries[] = ['receipts', null, $prefix];
            }
            $queries[] = [$path];
            $this->assertSame(['shared'], array_column((new GraphSearch($index))->find($path)['candidates'], 'id'));
        }
        $this->parity($index, $index, $queries);
        $this->assertCount(2, (new GraphSearch($index))->find('receipts')['candidates'][0]['sources']);
    }

    public function test_sort_tuple_and_negative_comparators(): void
    {
        $index = new CatalogIndex([]);
        // Each successive axis breaks ties: rank, kind, original name, path, line, ID.
        foreach ([['z', 'query', 'function', 'z', 9], ['f', 'query', 'class', 'z', 9], ['e', 'query', 'class', 'a', 2], ['d', 'query', 'class', 'a', 1], ['c', 'query', 'class', 'a', 1], ['b', 'Query', 'class', 'a', 1], ['a', 'queryX', 'class', 'a', 1], ['s', 'xquery', 'class', 'a', 1]] as [$id, $name, $kind, $path, $line]) {
            $index->elements[$id] = ['id' => $id, 'name' => $name, 'kind' => $kind, 'path' => $path, 'line' => $line, 'roles' => [], 'sources' => [], 'metadata' => []];
        }
        $result = (new GraphSearch($index))->find('query');
        $this->assertSame(['b', 'c', 'd', 'e', 'f', 'z', 'a', 's'], array_column($result['candidates'], 'id'));
        $this->parity($index, $index, [['query']]);
        $reverse = clone $index;
        $reverse->elements = array_reverse($index->elements, true);
        $this->parity($reverse, $index, [['query']]);
        $cut = $result;
        array_pop($cut['candidates']);
        $this->assertNotSame($result, $cut);
        $wrongCount = [...$result, 'match_count' => 1];
        $this->assertNotSame($result, $wrongCount);
        $swap = $result;
        [$swap['candidates'][1], $swap['candidates'][2]] = [$swap['candidates'][2], $swap['candidates'][1]];
        $this->assertNotSame($result, $swap);
    }

    public function test_legacy_mutation_invalidates_without_hashing_each_query(): void
    {
        $index = $this->index();
        $search = new GraphSearch($index);
        $search->find('marker');
        $state = $index->searchState();
        $this->assertSame($state, $index->searchState());
        $index->elements['id-only-marker']['name'] = 'brand-new-name';
        $this->assertSame(['id-only-marker'], array_column($search->find('brand-new-name')['candidates'], 'id'));
        $this->assertNotSame($state, $index->searchState());
        unset($index->elements['id-only-marker']);
        $this->assertSame(0, $search->find('brand-new-name')['match_count']);
        $index->elements['second']['sources'][] = ['path' => 'other/source.php'];
        $this->assertSame(['second'], array_column($search->find('other/source.php')['candidates'], 'id'));
    }
}
