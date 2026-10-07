<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Context;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;
use PHPUnit\Framework\TestCase;

final class GraphSearchTest extends TestCase
{
    public function test_shared_resource_is_searchable_by_each_source_location(): void
    {
        $queue = new CatalogElement('shared', 'receipts', 'queue', 1, 1, 0);
        $index = new CatalogIndex([new CatalogFacts('app/First.php', [$queue]), new CatalogFacts('app/Second.php', [$queue])]);
        $search = new GraphSearch($index);
        $first = $search->find('receipts', path: 'app/First.php');
        $this->assertSame(1, $first['match_count']);
        $this->assertSame(['app/First.php', 'app/Second.php'], array_column($first['candidates'][0]['sources'], 'path'));
        $this->assertSame(1, $search->find('app/First.php')['match_count']);
        $this->assertSame('exact_in_analyzed_graph', $first['count_basis']);
    }

    private function search(): GraphSearch
    {
        $graph = (new ProjectGraphBuilder(catalog: true))->build([
            new FileContext('app/Actions/SendInvoice.php', '<?php namespace App\\Actions; class SendInvoice { function send() {} }'),
            new FileContext('app/Actions/SendInvoiceCopy.php', '<?php namespace App\\Actions; class SendInvoiceCopy {}'),
            new FileContext('app/ActionsExtra/SendInvoice.php', '<?php namespace App\\Other; class SendInvoice {}'),
        ]);

        return new GraphSearch(new CatalogIndex($graph->catalogFacts));
    }

    public function test_exact_then_prefix_then_substring_and_stable_source_evidence(): void
    {
        $search = $this->search();
        $rows = $search->find('APP\\ACTIONS\\SENDINVOICE', 'class');
        $this->assertSame('found', $rows['status']);
        $this->assertSame(['exact', 'prefix'], array_column($rows['candidates'], 'match'));
        $this->assertSame('app/Actions/SendInvoice.php', $rows['candidates'][0]['sources'][0]['path']);
        $this->assertSame(1, $rows['candidates'][0]['sources'][0]['line']);
        $this->assertSame($rows, $search->find('APP\\ACTIONS\\SENDINVOICE', 'class'));
        $this->assertSame(3, $search->find('invoice', 'class')['match_count']);
    }

    public function test_role_and_segment_prefix_filters_do_not_match_neighbor_directory(): void
    {
        $rows = $this->search()->find('invoice', 'action', 'app/Actions');
        $this->assertSame(2, $rows['match_count']);
        $this->assertSame(1, $this->search()->find('invoice', 'class', 'app/Actions/SendInvoice.php')['match_count']);
        $this->assertSame('empty', $this->search()->find('absent')['status']);
        $this->assertSame('empty', $this->search()->find('invoice*')['status']);
    }

    public function test_unknown_kind_blank_query_and_unsafe_paths_are_invalid(): void
    {
        $search = $this->search();
        $this->assertSame('invalid_input', $search->find('  ')['status']);
        $result = $search->find('invoice', 'invented');
        $this->assertSame('invalid_input', $result['status']);
        $this->assertContains('class', $result['supported_kinds']);
        foreach (['../app', '/app', 'app/../tests', 'app\\Actions', 'app//Actions', 'vendor', "app\0"] as $path) {
            $this->assertSame('invalid_input', $search->find('invoice', path: $path)['status'], $path);
        }
    }
}
