<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Context\GraphPage;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use GracjanKubicki\ArchitectureKit\Context\GraphSource;
use GracjanKubicki\ArchitectureKit\Mcp\Concerns\ValidatesMcpInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Filesystem\Filesystem;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('architecture-graph')]
#[Description('Inspect a selected element: context returns direct typed relationships, path finds one shortest directed semantic path to target, and impact finds direct and transitive dependents with explanation paths. Use an ID from architecture-search, exact FQCN or file path. Inspect analysis_complete, traversal limits and diagnostics. Relationships and candidate tests do not prove execution or passing tests.')]
#[IsReadOnly]
final class ArchitectureGraph extends Tool
{
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->description('Element ID, exact FQCN or project-relative file path. Paths select the file element.')->required(),
            'mode' => $schema->string()->enum(['context', 'path', 'impact'])->default('context')->description('Direct relationships, a directed path, or possible change impact.'),
            'target' => $schema->string()->description('Destination selector, required for path mode. Omit this parameter entirely in context and impact modes; an empty string is invalid.'),
            'depth' => $schema->integer()->min(1)->max(20)->default(4)->description('Maximum hops for path or impact; context always uses one hop.'),
            'limit' => $schema->integer()->min(0)->max(100)->default(20)->description('Whole records per page; 0 returns summary only.'),
            'offset' => $schema->integer()->min(0)->default(0)->description('Zero-based page offset. Later pages require snapshot.'),
            'snapshot' => $schema->string()->description('Previous snapshot; changed inputs require restarting the query.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $arguments = $request->all(['subject', 'mode', 'target', 'depth', 'limit', 'offset', 'snapshot']);
        $invalid = $this->invalidInput($request, ['subject' => 'string', 'mode' => 'string', 'target' => 'string', 'depth' => 'integer', 'limit' => 'integer', 'offset' => 'integer', 'snapshot' => 'string']);
        foreach ($arguments as $value) {
            if (is_string($value) && strlen($value) > 4096) {
                $invalid = 'Selector exceeds the input budget.';
            }
            if ($value === null) {
                $invalid = 'Null arguments are not supported; omit optional arguments.';
            }
        }
        if (($arguments['limit'] ?? 20) > 100 || ($arguments['depth'] ?? 4) < 1 || ($arguments['depth'] ?? 4) > 20
            || ($arguments['offset'] ?? 0) > 0 && ! is_string($arguments['snapshot'] ?? null)) {
            $invalid = 'Limit must be at most 100, depth must be 1..20, and later pages require snapshot.';
        }
        if ($invalid !== null || ! is_string($request->get('subject')) || trim($request->get('subject')) === '') {
            return Response::structured(GraphPage::make('architecture-graph', [], '', [], [],
                ['status' => 'invalid_input', 'records' => [], 'message' => $invalid ?? 'A non-empty subject is required.'], 'records'));
        }
        try {
            $source = (new GraphSource(app(Filesystem::class), base_path()))->load();
            $result = (new GraphQuery($source['index']))->query($request->get('subject'), $request->get('mode', 'context'), $request->get('target'), $request->get('depth', 4));
            if (! $result['traversal_complete']) {
                $source['index']->diagnostics[] = ['code' => 'traversal_limit', 'message' => 'Traversal reached a depth, node or edge budget. Counts are lower bounds; a missing path is not a no-path result.'];
            }
            if ($source['changed']) {
                $result['status'] = 'stale_snapshot';
                $result['records'] = [];
            }

            return Response::structured(GraphPage::make('architecture-graph', $arguments, $source['snapshot'], $source['scope'], $source['index']->diagnostics, $result, 'records'));
        } catch (Throwable) {
            return Response::structured(GraphPage::make('architecture-graph', [], '', [],
                [['code' => 'source_unavailable', 'message' => 'Source settings or graph could not be read safely. Inspect project configuration.']],
                ['status' => 'unavailable', 'records' => []], 'records'));
        }
    }
}
