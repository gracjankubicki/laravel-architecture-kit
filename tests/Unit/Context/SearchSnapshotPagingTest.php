<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Context;

use GracjanKubicki\ArchitectureKit\Catalog\Search\SearchState;
use GracjanKubicki\ArchitectureKit\Context\GraphPage;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;
use GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Search\LegacyGraphSearch;
use GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Search\SearchFixture;

final class SearchSnapshotPagingTest extends SearchFixture
{
    public function test_all_pages_are_complete_and_equal_to_independent_legacy_envelopes(): void
    {
        $index = $this->index();
        foreach ([[], ['limit' => 1], ['limit' => 0], ['limit' => 100], ['offset' => 6, 'snapshot' => 'g1'], ['offset' => 99, 'snapshot' => 'g1']] as $arguments) {
            $arguments = ['query' => 'a', 'kind' => null, 'path' => null, ...$arguments];
            $result = (new GraphSearch($index))->find('a');
            $oracle = (new LegacyGraphSearch($index))->find('a');
            $all = [];
            do {
                $page = GraphPage::make('architecture-search', $arguments, 'g1', ['app', 'tests'], $index->diagnostics, $result, 'candidates');
                $expected = LegacyGraphPage::make('architecture-search', $arguments, 'g1', ['app', 'tests'], $index->diagnostics, $oracle, 'candidates');
                $this->assertSame($expected, $page);
                $this->assertSame($result['match_count'], $page['result']['match_count']);
                array_push($all, ...$page['result']['candidates']);
                $this->evidence(['expected' => $expected, 'actual' => $page]);
                $arguments = $page['next']['arguments'] ?? null;
            } while ($arguments !== null);
            if (($page['limits']['limit'] ?? null) !== 0 && ($page['limits']['offset'] ?? 0) < count($result['candidates'])) {
                $this->assertSame($result['candidates'], $all);
            }
        }
        $empty = (new GraphSearch($index))->find('absent');
        $this->assertSame(LegacyGraphPage::make('search', [], 'g1', [], [], $empty, 'candidates'), GraphPage::make('search', [], 'g1', [], [], $empty, 'candidates'));
    }

    public function test_parameter_errors_and_snapshot_change_preserve_whole_envelope(): void
    {
        $result = (new GraphSearch($this->index()))->find('a');
        foreach ([['limit' => -1], ['limit' => 101], ['limit' => '1'], ['limit' => 1.0], ['offset' => -1], ['offset' => '1'], ['offset' => 1], ['snapshot' => 'old'], ['offset' => 1, 'snapshot' => 3]] as $arguments) {
            $this->assertSame(LegacyGraphPage::make('search', $arguments, 'new', [], [], $result, 'candidates'), GraphPage::make('search', $arguments, 'new', [], [], $result, 'candidates'));
        }
    }

    public function test_snapshot_lease_finishes_on_one_generation_and_cursor_does_not_retain_it(): void
    {
        $files = ['app/A.php' => '<?php class QueryA {} class QueryB {}'];
        $generation = $this->prepare($files);
        $old = $generation->index();
        $old->attachSearchState(SearchState::fromGeneration($generation));
        $leased = new GraphSearch($old);
        $firstResult = $leased->find('Query');
        $first = GraphPage::make('search', ['query' => 'Query', 'limit' => 1], $generation->id(), [], [], $firstResult, 'candidates');
        $cursor = $first['next']['arguments'];
        $files['app/A.php'] = '<?php class QueryC {}';
        $patch = $this->patch($generation, $files, $files);
        $nextState = $old->searchState()->apply($patch);
        $active = $patch->target->index();
        $active->attachSearchState($nextState);
        // Controlled publication boundary: active reference replaced, old query holds its view.
        $this->assertSame($firstResult, $leased->find('Query'));
        $stale = GraphPage::make('search', $cursor, $patch->target->id(), [], [], (new GraphSearch($active))->find('Query'), 'candidates');
        $this->assertSame('stale_snapshot', $stale['status']);
        $this->assertSame([], $stale['result']['candidates']);
        $this->assertNull($stale['next']);
        $oldReference = \WeakReference::create($old);
        unset($leased, $old);
        gc_collect_cycles();
        $this->assertNull($oldReference->get());
        $this->assertIsString($cursor['snapshot']);
        $this->evidence(['first' => $first, 'after_publication' => $stale, 'cursor_holds_no_lease' => true]);
    }

    public function test_whole_envelope_budget_reserves_runtime_metadata_and_keeps_diagnostics(): void
    {
        $index = $this->index();
        $index->elements['second']['metadata']['large'] = str_repeat('x', 60000);
        $result = (new GraphSearch($index))->find('a');
        $diagnostics = array_fill(0, 24, ['code' => 'catalog_incomplete', 'message' => str_repeat('gap', 1200)]);
        foreach ([0, 2048, 8192] as $reserved) {
            $page = GraphPage::make('search', ['query' => 'a', 'limit' => 100], 'g1', ['app'], $diagnostics, $result, 'candidates', $reserved);
            $expected = LegacyGraphPage::make('search', ['query' => 'a', 'limit' => 100], 'g1', ['app'], $diagnostics, $result, 'candidates', $reserved);
            $this->assertSame($expected, $page);
            $this->assertSame(24, $page['diagnostics']['summary']['catalog_incomplete']);
            $this->assertLessThanOrEqual(GraphPage::MAX_BYTES - $reserved, strlen(json_encode($page, JSON_THROW_ON_ERROR)));
            $this->assertSame(count($page['result']['candidates']) < count($result['candidates']), $page['truncated']);
            $this->evidence(['reserved' => $reserved, 'bytes' => strlen(json_encode($page)), 'page' => $page]);
        }
        $largeBase = GraphPage::make('search', [], 'g1', [str_repeat('x', 70000)], [], $result, 'candidates', 8192);
        $this->assertSame('unavailable', $largeBase['status']);
        $this->assertSame(1, $largeBase['diagnostics']['summary']['envelope_limit']);
        $oversized = $result;
        $oversized['candidates'] = [['id' => 'huge', 'metadata' => str_repeat('x', 70000)]];
        $page = GraphPage::make('search', [], 'g1', [], [], $oversized, 'candidates', 8192);
        $this->assertSame('oversized_record', $page['diagnostics']['details'][0]['code']);
        $this->assertSame([], $page['result']['candidates']);
        $this->assertNull($page['next']);
    }

    public function test_oversized_record_diagnostic_cannot_consume_reserved_bytes(): void
    {
        // Tune the base just below its budget. The extra oversized_record detail crossed
        // the reserved boundary in HEAD even though the final check allowed MAX_BYTES.
        $result = ['status' => 'found', 'candidates' => [['id' => 'oversized', 'large' => str_repeat('x', 70000)]], 'padding' => str_repeat('x', 56090)];
        $legacy = LegacyGraphPage::make('search', [], 'g1', [], [], $result, 'candidates', 9000);
        $this->assertGreaterThan(GraphPage::MAX_BYTES - 9000, strlen(json_encode($legacy, JSON_THROW_ON_ERROR)));
        $page = GraphPage::make('search', [], 'g1', [], [], $result, 'candidates', 9000);
        $this->assertLessThanOrEqual(GraphPage::MAX_BYTES - 9000, strlen(json_encode($page, JSON_THROW_ON_ERROR)));
        $this->assertNull($page['next']);
    }
}
