<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Context;

use GracjanKubicki\ArchitectureKit\Context\GraphPage;
use PHPUnit\Framework\TestCase;

final class GraphPageTest extends TestCase
{
    public function test_pages_require_same_snapshot_and_return_complete_continuation_arguments(): void
    {
        $result = ['status' => 'found', 'match_count' => 3, 'candidates' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']]];
        $args = ['query' => 'invoice', 'kind' => 'class', 'limit' => 1];
        $first = GraphPage::make('architecture-search', $args, 'snapshot-a', ['app'], [], $result, 'candidates');
        $this->assertSame([['id' => 'a']], $first['result']['candidates']);
        $this->assertSame([...$args, 'offset' => 1, 'snapshot' => 'snapshot-a'], $first['next']['arguments']);
        $second = GraphPage::make('architecture-search', $first['next']['arguments'], 'snapshot-a', ['app'], [], $result, 'candidates');
        $this->assertSame([['id' => 'b']], $second['result']['candidates']);
        $stale = GraphPage::make('architecture-search', $first['next']['arguments'], 'snapshot-b', ['app'], [], $result, 'candidates');
        $this->assertSame('stale_snapshot', $stale['status']);
        $this->assertFalse($stale['ok']);
        $this->assertSame([], $stale['result']['candidates']);
        $this->assertSame('invalid_input', GraphPage::make('architecture-search', ['offset' => 1], 'snapshot-a', [], [], $result, 'candidates')['status']);
    }

    public function test_zero_limit_and_oversized_records_never_create_nonadvancing_continuations(): void
    {
        $result = ['status' => 'found', 'candidates' => [['id' => 'large', 'metadata' => str_repeat('x', 70000)]]];
        $page = GraphPage::make('architecture-search', ['query' => 'x'], 'a', [], [], $result, 'candidates');
        $this->assertTrue($page['truncated']);
        $this->assertSame([], $page['result']['candidates']);
        $this->assertNull($page['next']);
        $this->assertSame('large', $page['diagnostics']['details'][0]['subject']);
        $this->assertLessThanOrEqual(GraphPage::MAX_BYTES, strlen(json_encode($page)));
        $zero = GraphPage::make('architecture-search', ['limit' => 0], 'a', [], [], $result, 'candidates');
        $this->assertTrue($zero['truncated']);
        $this->assertNull($zero['next']);
    }

    public function test_diagnostic_summary_survives_detail_output_limit(): void
    {
        $diagnostics = array_fill(0, 30, ['code' => 'dynamic_call', 'path' => 'app/Example.php', 'message' => str_repeat('x', 10000)]);
        $page = GraphPage::make('architecture-search', [], 'a', [], $diagnostics, ['status' => 'found', 'candidates' => [['id' => 'example', 'path' => 'app/Example.php']]], 'candidates');
        $this->assertFalse($page['analysis_complete']);
        $this->assertSame(30, $page['diagnostics']['summary']['dynamic_call']);
        $this->assertLessThan(20, count($page['diagnostics']['details']));
        $this->assertLessThanOrEqual(GraphPage::MAX_BYTES, strlen(json_encode($page)));
        $this->assertTrue($page['diagnostics']['details_truncated']);
    }

    public function test_details_prioritize_query_evidence_while_summary_keeps_whole_project_gaps(): void
    {
        $diagnostics = [['code' => 'unrelated', 'subject' => 'other'], ['code' => 'relevant', 'subject' => 'selected'], ['code' => 'catalog_incomplete']];
        $page = GraphPage::make('architecture-graph', [], 'a', [], $diagnostics,
            ['status' => 'empty', 'subject' => ['id' => 'selected'], 'records' => []], 'records');
        $this->assertSame(['relevant', 'catalog_incomplete'], array_column($page['diagnostics']['details'], 'code'));
        $this->assertSame(1, $page['diagnostics']['summary']['unrelated']);
        $this->assertFalse($page['analysis_complete']);
    }

    public function test_oversized_base_metadata_has_a_controlled_bounded_response(): void
    {
        $page = GraphPage::make('architecture-search', [], 'a', [str_repeat('a', 70000)], [], ['status' => 'empty', 'candidates' => []], 'candidates');
        $this->assertSame('unavailable', $page['status']);
        $this->assertFalse($page['ok']);
        $this->assertSame(1, $page['diagnostics']['summary']['envelope_limit']);
        $this->assertLessThanOrEqual(GraphPage::MAX_BYTES, strlen(json_encode($page)));
    }
}
