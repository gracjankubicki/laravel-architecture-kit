<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsOptions;
use GracjanKubicki\ArchitectureKit\Analysis\BasicSource;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogRelation;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureRecord;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;

final class NativeQueryParityTest extends NativeBasicFixture
{
    public function test_diamond_cycle_structural_edges_limits_seeds_selectors_and_test_candidates(): void
    {
        $elements = [];
        foreach (['file' => ['file', 'app/Graph.php'], 'type' => ['class', 'App\\Graph'], 'a' => ['method', 'App\\Graph::a'], 'b' => ['method', 'App\\Graph::b'], 'c' => ['method', 'App\\Graph::c'], 'd' => ['method', 'App\\Graph::d'], 'route' => ['route', '/invoice'], 'test' => ['pest-test', 'invoice test'], 'unresolved' => ['pest-test', 'unknown test']] as $id => [$kind, $name]) {
            $elements[] = new CatalogElement($id, $name, $kind, 1, 1, 0, metadata: $id === 'unresolved' ? ['test_registration_resolved' => false] : []);
        }
        $edges = [];
        foreach ([['file', 'type', 'contains'], ['type', 'a', 'contains'], ['type', 'b', 'contains'], ['type', 'c', 'contains'], ['type', 'd', 'contains'], ['a', 'b', 'calls'], ['a', 'c', 'calls'], ['c', 'd', 'calls'], ['b', 'd', 'calls'], ['d', 'a', 'calls'], ['route', 'a', 'route-handler'], ['test', 'route', 'http-test'], ['unresolved', 'd', 'calls'], ['a', 'type', 'type-reference'], ['a', 'type', 'callable-reference'], ['a', 'a', 'calls']] as [$from, $to, $kind]) {
            $edges[] = new CatalogRelation($from, $to, $kind, 1, 1, resolution: 'conditional', metadata: ['execution_proven' => false, 'condition' => 'synthetic']);
        }
        $facts = new CatalogFacts('app/Graph.php', $elements, $edges);
        $options = new BasicFactsOptions(false, true, false);
        $record = CaptureRecord::create(0, 'php', BasicSource::plain(new SourceInput($facts->path, '<?php', '8.5')), $options, new FileGraphEntry([], [], catalog: $facts));
        [$generation, $oracle] = $this->loadRecords([$record], new CatalogIndex([$facts]), $options);
        $this->assertSame(['a', 'b', 'd'], (new GraphQuery($oracle))->query('a', 'path', 'd')['records'][0]['nodes']);
        foreach ([[5000, 25000, 4], [1, 25000, 4], [5000, 1, 4], [5000, 25000, 1], [0, 0, 4], [5000, 25000, 0], [5000, 25000, -1], [5000, 25000, 21]] as [$nodes, $edges, $depth]) {
            $query = new GraphQuery($oracle, $nodes, $edges, true);
            foreach (['a', 'type', 'file', 'test', 'missing', '\\App\\Graph', 'Graph', 'app/Graph.php', ''] as $subject) {
                foreach (['context', 'impact', 'path'] as $mode) {
                    $target = $mode === 'path' ? 'd' : null;
                    $actual = $this->basic->query($generation, $this->identity, ['type' => $mode, 'subject' => $subject, 'target' => $target, 'depth' => $depth, 'max_nodes' => $nodes, 'max_edges' => $edges, 'short_names' => true]);
                    $this->assertSame($query->query($subject, $mode, $target, $depth), $actual);
                }
            }
        }
        $impact = (new GraphQuery($oracle))->query('d', 'impact');
        $actual = $this->basic->query($generation, $this->identity, ['type' => 'test_candidates', 'subject' => 'd']);
        $expected = $impact;
        $expected['records'] = array_values(array_filter($impact['records'], fn ($r) => $r['test_candidate']));
        $expected['status'] = $expected['records'] === [] ? 'empty' : 'found';
        $this->assertSame($expected, $actual);
        $this->assertSame(['test'], array_column(array_column($actual['records'], 'element'), 'id'));
        $lease = $this->client->request(['op' => 'pin', 'generation' => $generation, 'identity' => $this->identity])['lease'];
        $first = $this->client->request(['op' => 'query_page', 'generation' => $generation, 'identity' => $this->identity, 'lease' => $lease, 'query' => ['type' => 'search', 'query' => 'method'], 'start' => 0, 'limit' => 1]);
        $this->assertGreaterThan(1, $first['total']);
        $this->assertCount(1, TypedValue::decode($first['records']));
        $new = $this->basic->compose($generation, $this->identity);
        $this->assertNotSame($new, $generation);
        $this->expectExceptionMessage('native_graph_stale_snapshot');
        $this->client->request(['op' => 'query_page', 'generation' => $generation, 'identity' => $this->identity, 'lease' => $lease, 'query' => ['type' => 'search', 'query' => 'method'], 'start' => $first['next'], 'limit' => 1]);
    }
}
