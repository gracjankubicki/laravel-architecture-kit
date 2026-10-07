<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Context;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogRelation;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use PHPUnit\Framework\TestCase;

final class GraphQueryTest extends TestCase
{
    private function index(): CatalogIndex
    {
        $elements = [];
        foreach (['file' => ['file', 'app/Example.php'], 'type' => ['class', 'App\\Example'],
            'a' => ['method', 'App\\Example::a'], 'b' => ['method', 'App\\Example::b'], 'c' => ['method', 'App\\Example::c'],
            'd' => ['method', 'App\\Example::d'], 'route' => ['route', '/invoice'], 'test' => ['pest-test', 'invoice test']] as $id => [$kind, $name]) {
            $elements[] = new CatalogElement($id, $name, $kind, 1, 1, 0);
        }
        $relations = [];
        foreach ([['file', 'type', 'contains'], ['type', 'a', 'contains'], ['type', 'b', 'contains'],
            ['type', 'c', 'contains'], ['type', 'd', 'contains'], ['a', 'b', 'calls'], ['a', 'c', 'calls'],
            ['c', 'd', 'calls'], ['b', 'd', 'calls'], ['d', 'a', 'calls'], ['route', 'a', 'route-handler'],
            ['test', 'route', 'http-test'], ['a', 'type', 'type-reference']] as [$from, $to, $kind]) {
            $relations[] = new CatalogRelation($from, $to, $kind, 1, 1, metadata: ['execution_proven' => false]);
        }

        return new CatalogIndex([new CatalogFacts('app/Example.php', $elements, $relations)]);
    }

    public function test_path_is_directed_shortest_deterministic_and_ignores_contains_and_types(): void
    {
        $query = new GraphQuery($this->index());
        $path = $query->query('a', 'path', 'd');
        $this->assertSame('found', $path['status']);
        $this->assertSame(['a', 'b', 'd'], $path['records'][0]['nodes']);
        $this->assertSame(['calls', 'calls'], array_column($path['records'][0]['relations'], 'kind'));
        $this->assertFalse($path['records'][0]['execution_proven']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query('App\\Example', 'path', 'a')['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query('a', 'path', 'route')['status']);
        $this->assertSame(['a'], $query->query('a', 'path', 'a')['records'][0]['nodes']);
    }

    public function test_context_retains_structural_evidence_without_claiming_a_semantic_transition(): void
    {
        $result = (new GraphQuery($this->index()))->query('app/Example.php');
        $this->assertSame('found', $result['status']);
        $this->assertSame('contains', $result['records'][0]['relation']['kind']);
        $this->assertFalse($result['records'][0]['semantic_transition']);
    }

    public function test_impact_deduplicates_cycles_and_reports_entrypoints_and_tests_with_forward_explanations(): void
    {
        $result = (new GraphQuery($this->index()))->query('d', 'impact', depth: 4);
        $this->assertSame(2, $result['counts']['direct']);
        $this->assertSame(3, $result['counts']['indirect']);
        $rows = array_column($result['records'], null, 'distance');
        $this->assertNotEmpty($rows);
        $byId = [];
        foreach ($result['records'] as $row) {
            $byId[$row['element']['id']] = $row;
        }
        $this->assertTrue($byId['route']['entrypoint']);
        $this->assertTrue($byId['test']['test_candidate']);
        $this->assertFalse($byId['test']['test_executed']);
        $this->assertSame(['test', 'route', 'a', 'b'], array_column($byId['test']['explanation'], 'from'));
        $this->assertArrayNotHasKey('type', $byId);
        $classImpact = (new GraphQuery($this->index()))->query('App\\Example', 'impact');
        $this->assertContains('d', $classImpact['change_seeds']);
        $this->assertSame(1, $classImpact['counts']['direct']);
        $this->assertSame(1, $classImpact['counts']['indirect']);
    }

    public function test_limits_are_distinct_from_no_path_and_counts_become_lower_bounds(): void
    {
        $query = new GraphQuery($this->index());
        $path = $query->query('a', 'path', 'd', 1);
        $this->assertSame('unavailable', $path['status']);
        $this->assertFalse($path['traversal_complete']);
        $impact = $query->query('d', 'impact', depth: 1);
        $this->assertSame('lower_bound', $impact['counts']['basis']);
        $this->assertSame(2, $impact['counts']['direct']);
        $limited = (new GraphQuery($this->index(), maxEdges: 1))->query('a', 'path', 'd');
        $this->assertFalse($limited['traversal_complete']);
        $this->assertSame('unavailable', $limited['status']);
        $seedLimited = (new GraphQuery($this->index(), maxNodes: 2))->query('type', 'impact');
        $this->assertFalse($seedLimited['traversal_complete']);
        $this->assertCount(2, $seedLimited['change_seeds']);
        $this->assertSame('lower_bound', $seedLimited['counts']['basis']);
        $contextLimited = (new GraphQuery($this->index(), maxNodes: 1))->query('a');
        $this->assertFalse($contextLimited['traversal_complete']);
        $this->assertSame('lower_bound', $contextLimited['counts']['basis']);
    }

    public function test_conditional_queue_transition_keeps_its_timing_and_source_evidence(): void
    {
        $index = $this->index();
        $index->addRelation(['from' => 'route', 'to' => 'd', 'kind' => 'job-dispatch', 'resolution' => 'conditional',
            'path' => 'app/Dispatcher.php', 'line' => 42, 'end_line' => 42,
            'metadata' => ['mode' => 'queued', 'execution_proven' => false, 'condition' => 'source-condition']]);
        $result = (new GraphQuery($index))->query('route', 'path', 'd');
        $edge = $result['records'][0]['relations'][0];
        $this->assertSame('conditional', $edge['resolution']);
        $this->assertSame('queued', $edge['metadata']['mode']);
        $this->assertSame('source-condition', $edge['metadata']['condition']);
        $this->assertSame('app/Dispatcher.php', $edge['path']);
        $this->assertSame(42, $edge['line']);
    }

    public function test_selector_ambiguity_missing_and_invalid_arguments_are_distinct(): void
    {
        $index = $this->index();
        $duplicate = $index->elements['type'];
        $duplicate['id'] = 'duplicate';
        $duplicate['path'] = 'app/Other.php';
        $index->elements['duplicate'] = $duplicate;
        $query = new GraphQuery($index);
        $this->assertSame('ambiguous', $query->query('App\\Example')['status']);
        $this->assertCount(2, $query->query('App\\Example')['records']);
        $this->assertSame('not_found', $query->query('Example')['status']);
        $this->assertSame('ambiguous', $query->query('a', 'path', 'App\\Example')['status']);
        foreach ([['a', 'path', null, 4], ['a', 'context', 'b', 4], ['a', 'impact', null, 21], ['a', 'invented', null, 4]] as $args) {
            $this->assertSame('invalid_input', $query->query(...$args)['status']);
        }
    }
}
