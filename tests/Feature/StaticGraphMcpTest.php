<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogSettings;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use GracjanKubicki\ArchitectureKit\Context\GraphSource;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ArchitectureGraph;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ArchitectureSearch;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Laravel\Mcp\Server\Transport\FakeTransporter;

final class StaticGraphMcpTest extends TestCase
{
    public function test_invalid_graph_and_search_arguments_are_input_errors_not_source_failures(): void
    {
        foreach ([
            ['subject' => 'App\\Example', 'depth' => 0], ['subject' => 'App\\Example', 'depth' => 21],
            ['subject' => 'App\\Example', 'depth' => '4'], ['subject' => 'App\\Example', 'mode' => null],
            ['subject' => 'App\\Example', 'target' => null], ['subject' => 'App\\Example', 'limit' => 101],
            ['subject' => 'App\\Example', 'offset' => 1], ['subject' => str_repeat('x', 4097)],
        ] as $args) {
            ArchitectureKitServer::tool(ArchitectureGraph::class, $args)->assertStructuredContent(fn ($json) => $json
                ->where('status', 'invalid_input')->where('ok', false)->where('analysis_complete', false)->etc());
        }
        foreach ([['query' => 'invoice', 'limit' => 101], ['query' => 'invoice', 'kind' => null],
            ['query' => 'invoice', 'offset' => 1], ['query' => str_repeat('x', 4097)]] as $args) {
            ArchitectureKitServer::tool(ArchitectureSearch::class, $args)->assertStructuredContent(fn ($json) => $json
                ->where('status', 'invalid_input')->where('ok', false)->where('analysis_complete', false)->etc());
        }
    }

    public function test_graph_pagination_keeps_complete_arguments_and_rejects_edited_snapshot(): void
    {
        $files = $this->fixture();
        $files->put($this->tempPath.'/app/Actions/SendInvoice.php', '<?php namespace App\\Actions; class SendInvoice { public function a() {} public function b() {} }');
        $source = (new GraphSource($files, $this->tempPath))->load();
        $args = ['subject' => 'App\\Actions\\SendInvoice', 'mode' => 'context', 'limit' => 1];
        ArchitectureKitServer::tool(ArchitectureGraph::class, $args)->assertStructuredContent(fn ($json) => $json
            ->where('next.arguments', [...$args, 'offset' => 1, 'snapshot' => $source['snapshot']])->has('result.records', 1)->etc());
        ArchitectureKitServer::tool(ArchitectureGraph::class, [...$args, 'offset' => 1, 'snapshot' => $source['snapshot']])
            ->assertStructuredContent(fn ($json) => $json->where('status', 'found')->has('result.records', 1)->etc());
        $files->put($this->tempPath.'/app/Actions/SendInvoice.php', '<?php namespace App\\Actions; class SendInvoice {}');
        clearstatcache();
        ArchitectureKitServer::tool(ArchitectureGraph::class, [...$args, 'offset' => 1, 'snapshot' => $source['snapshot']])
            ->assertStructuredContent(fn ($json) => $json->where('status', 'stale_snapshot')->where('result.records', [])->etc());
    }

    public function test_mixed_laravel_flow_is_available_through_mcp_with_queue_and_schedule_evidence(): void
    {
        $files = new Filesystem;
        $sources = [
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; Route::post("pay", [App\\Controller::class, "pay"])->name("billing.pay");',
            'bootstrap/events.php' => 'use Illuminate\\Support\\Facades\\Event; Event::listen(App\\Paid::class, App\\Listener::class);',
            'app/Controller.php' => 'namespace App; class Controller { public function pay() { Action::run(); } public function unused() {} }',
            'app/Action.php' => 'namespace App; class Action { public static function run() { event(new Paid("payload-secret")); } } class Paid {}',
            'app/Listener.php' => 'namespace App; class Listener { public function handle(Paid $event) { Receipt::dispatch("payload-secret")->onQueue("receipts")->afterCommit(); } }',
            'app/Receipt.php' => 'namespace App; class Receipt implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Foundation\\Bus\\Dispatchable; public function handle() {} }',
            'app/OtherJob.php' => 'namespace App; class OtherJob implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Foundation\\Bus\\Dispatchable; public $queue = "receipts"; public function handle() {} }',
            'app/Report.php' => 'namespace App; class Report extends \\Illuminate\\Console\\Command { protected $signature = "reports:send {--token=signature-secret}"; public function handle() { Action::run(); } }',
            'bootstrap/app.php' => 'use Illuminate\\Foundation\\Application; Application::configure()->withCommands([App\\Report::class])->withSchedule(function (Illuminate\\Console\\Scheduling\\Schedule $schedule) { $schedule->command("reports:send --token=argument-secret")->dailyAt("08:00")->timezone("Europe/Warsaw"); });',
        ];
        foreach ($sources as $path => $source) {
            $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
            $files->put($this->tempPath.'/'.$path, '<?php '.$source);
        }
        $source = new GraphSource($files, $this->tempPath);
        $first = $source->load();
        $index = $first['index'];
        $names = array_column($index->elements, 'id', 'name');
        $route = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'route'))[0];
        $task = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'scheduled-task'))[0];
        ArchitectureKitServer::tool(ArchitectureSearch::class, ['query' => 'billing.pay', 'kind' => 'route'])
            ->assertOk()->assertStructuredContent(fn ($json) => $json->where('result.match_count', 1)->where('result.candidates.0.id', $route['id'])->etc());
        ArchitectureKitServer::tool(ArchitectureGraph::class, ['subject' => $route['id'], 'mode' => 'path', 'target' => $names['App\\Receipt::handle'], 'depth' => 8])
            ->assertOk()->assertStructuredContent(fn ($json) => $json->where('status', 'found')
            ->where('result.records.0.relations', function ($edges) {
                $edges = $edges->toArray();
                $this->assertSame(['route-handler', 'calls', 'event-dispatch', 'event-listener', 'job-handler'], array_column($edges, 'kind'));
                $this->assertSame('queue-requested', $edges[4]['metadata']['mode']);
                $this->assertSame('after-commit', $edges[4]['metadata']['timing']);
                $this->assertSame('receipts', $edges[4]['metadata']['queue']);
                $this->assertFalse($edges[4]['metadata']['execution_proven']);
                $this->assertNotEmpty($edges[4]['metadata']['conditions']);
                $this->assertNotEmpty($edges[4]['path']);
                $this->assertStringNotContainsString('-secret', json_encode($edges));

                return true;
            })->etc());
        ArchitectureKitServer::tool(ArchitectureGraph::class, ['subject' => $route['id'], 'mode' => 'path', 'target' => $names['App\\Receipt::handle'], 'depth' => 4])
            ->assertOk()->assertStructuredContent(fn ($json) => $json->where('status', 'unavailable')->where('result.traversal_complete', false)
            ->where('diagnostics.summary.traversal_limit', 1)->etc());
        $query = new GraphQuery($index);
        $schedule = $query->query($task['id'], 'path', $names['App\\Action::run'], 8);
        $this->assertSame('found', $schedule['status']);
        $this->assertContains('schedule-task', array_column($schedule['records'][0]['relations'], 'kind'));
        $this->assertSame('no_path_in_analyzed_graph', $query->query($route['id'], 'path', $names['App\\OtherJob::handle'], 8)['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($route['id'], 'path', $names['App\\Controller::unused'], 8)['status']);
        $impact = $query->query($names['App\\Receipt::handle'], 'impact', depth: 8);
        $this->assertContains($route['id'], array_column(array_column($impact['records'], 'element'), 'id'));
        $this->assertSame($first['snapshot'], $source->load()['snapshot']);
        // Changing registration must recompose cached callers and discard the old flow.
        $files->put($this->tempPath.'/bootstrap/events.php', '<?php // event registration removed');
        clearstatcache();
        $changed = $source->load();
        $this->assertNotSame($first['snapshot'], $changed['snapshot']);
        $this->assertSame('no_path_in_analyzed_graph', (new GraphQuery($changed['index']))->query($route['id'], 'path', $names['App\\Receipt::handle'], 8)['status']);
    }

    public function test_json_rpc_lists_and_calls_the_registered_search_contract(): void
    {
        $this->fixture();
        $transport = new class extends FakeTransporter
        {
            public array $messages = [];

            public function send(string $message, ?string $sessionId = null): void
            {
                $this->messages[] = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            }
        };
        $server = new ArchitectureKitServer($transport);
        $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['per_page' => 50]], JSON_THROW_ON_ERROR));
        $tools = array_column($transport->messages[0]['result']['tools'], null, 'name');
        $this->assertArrayHasKey('search', $tools);
        $schema = $tools['architecture-search']['inputSchema'];
        $this->assertSame(['query'], $schema['required']);
        $this->assertSame(100, $schema['properties']['limit']['maximum']);
        $this->assertSame(20, $schema['properties']['limit']['default']);
        $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'architecture-search', 'arguments' => ['query' => 'invoice', 'kind' => 'class']]], JSON_THROW_ON_ERROR));
        $reply = $transport->messages[1]['result']['structuredContent'];
        $this->assertSame('found', $reply['status']);
        $this->assertSame(2, $reply['result']['match_count']);
        $this->assertFalse($reply['analysis_complete']);
        $this->assertArrayHasKey('architecture-graph', $tools);
        $this->assertSame(20, $tools['architecture-graph']['inputSchema']['properties']['depth']['maximum']);
        $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'architecture-graph', 'arguments' => ['subject' => $reply['result']['candidates'][0]['id']]]], JSON_THROW_ON_ERROR));
        $graph = $transport->messages[2]['result']['structuredContent'];
        $this->assertSame('architecture-graph', $graph['cmd']);
        $this->assertSame('found', $graph['status']);
        $this->assertSame('context', $graph['result']['mode']);
    }

    public function test_source_calls_have_a_directed_path_and_reverse_impact_without_sibling_execution(): void
    {
        $files = $this->fixture();
        $files->put($this->tempPath.'/app/Actions/SendInvoice.php', '<?php namespace App\\Actions; class SendInvoice { public function send() { (new SendInvoiceCopy)->run(); } public function unused() {} }');
        $files->put($this->tempPath.'/app/Actions/SendInvoiceCopy.php', '<?php namespace App\\Actions; class SendInvoiceCopy { public function run() {} }');
        $source = (new GraphSource($files, $this->tempPath))->load();
        $names = array_column($source['index']->elements, 'id', 'name');
        ArchitectureKitServer::tool(ArchitectureGraph::class, ['subject' => $names['App\\Actions\\SendInvoice::send'], 'target' => $names['App\\Actions\\SendInvoiceCopy::run'], 'mode' => 'path'])
            ->assertOk()->assertStructuredContent(fn ($json) => $json->where('status', 'found')->has('result.records', 1)->has('result.records.0.relations', 1)->etc());
        ArchitectureKitServer::tool(ArchitectureGraph::class, ['subject' => 'App\\Actions\\SendInvoice', 'target' => $names['App\\Actions\\SendInvoice::unused'], 'mode' => 'path'])
            ->assertOk()->assertStructuredContent(fn ($json) => $json->where('status', 'no_path_in_analyzed_graph')->where('analysis_complete', false)->etc());
        ArchitectureKitServer::tool(ArchitectureGraph::class, ['subject' => $names['App\\Actions\\SendInvoiceCopy::run'], 'mode' => 'impact'])
            ->assertOk()->assertStructuredContent(fn ($json) => $json->where('status', 'found')->where('result.counts.direct', 1)
            ->where('result.records.0.element.name', 'App\\Actions\\SendInvoice::send')->etc());
    }

    private function fixture(): Filesystem
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app/Actions');
        $files->put($this->tempPath.'/app/Actions/SendInvoice.php', '<?php namespace App\\Actions; class SendInvoice {}');
        $files->put($this->tempPath.'/app/Actions/SendInvoiceCopy.php', '<?php namespace App\\Actions; class SendInvoiceCopy {}');

        return $files;
    }

    public function test_search_mcp_returns_evidence_and_explicit_incomplete_analysis(): void
    {
        $this->fixture();
        ArchitectureKitServer::tool(ArchitectureSearch::class, ['query' => 'invoice', 'kind' => 'class', 'limit' => 1])
            ->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('cmd', 'architecture-search')->where('status', 'found')->where('analysis_complete', false)
            ->where('result.match_count', 2)->has('result.candidates', 1)
            ->where('result.candidates.0.path', 'app/Actions/SendInvoice.php')
            ->missing('diagnostics.summary.catalog_incomplete')->where('next.arguments.offset', 1)->etc());
    }

    public function test_complete_source_inputs_are_complete_until_a_real_parse_failure_is_added(): void
    {
        $files = $this->fixture();
        foreach (CatalogSettings::ROOTS as $root) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$root);
        }
        ArchitectureKitServer::tool(ArchitectureSearch::class, ['query' => 'invoice', 'kind' => 'class'])
            ->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('status', 'found')->where('analysis_complete', true)
            ->missing('diagnostics.summary.catalog_incomplete')->etc());
        $files->put($this->tempPath.'/app/Broken.php', '<?php function broken(');
        ArchitectureKitServer::tool(ArchitectureSearch::class, ['query' => 'invoice', 'kind' => 'class'])
            ->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('status', 'found')->where('analysis_complete', false)
            ->where('diagnostics.summary.parse_error', 1)->etc());
    }

    public function test_source_snapshot_is_warm_stable_and_changes_after_edit_without_execution(): void
    {
        $files = $this->fixture();
        $files->ensureDirectoryExists($this->tempPath.'/bootstrap');
        $files->put($this->tempPath.'/bootstrap/app.php', '<?php throw new \\RuntimeException("must not execute");');
        $source = new GraphSource($files, $this->tempPath);
        $first = $source->load();
        $warm = $source->load();
        $this->assertSame($first['snapshot'], $warm['snapshot']);
        $this->assertEquals($first['index'], $warm['index']);
        $this->assertFalse($warm['changed']);
        $files->put($this->tempPath.'/app/Actions/SendInvoice.php', '<?php namespace App\\Actions; class SendInvoice { function send() {} }');
        clearstatcache();
        $this->assertNotSame($warm['snapshot'], $source->load()['snapshot']);
        ArchitectureKitServer::tool(ArchitectureSearch::class, ['query' => 'invoice', 'kind' => 'class', 'offset' => 1, 'snapshot' => $warm['snapshot']])
            ->assertStructuredContent(fn ($json) => $json->where('status', 'stale_snapshot')->where('ok', false)->where('result.candidates', [])->etc());
    }
}
