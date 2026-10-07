<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Context\GraphPage;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;
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

#[Name('architecture-search')]
#[Description('Find a PHP or Laravel element by literal name, path, route, table or kind. Returns ranked candidates with IDs and source locations. Inspect analysis_complete and diagnostics before selecting a candidate. Discovery reads source files without executing the application or tests.')]
#[IsReadOnly]
final class ArchitectureSearch extends Tool
{
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Non-empty literal fragment; exact matches rank first.')->required(),
            'kind' => $schema->string()->description('Exact element kind or role from supported_kinds.'),
            'path' => $schema->string()->description('Project-relative file or directory prefix, matched on path segments.'),
            'limit' => $schema->integer()->min(0)->max(100)->default(20)->description('Maximum whole records per page; 0 returns summary only.'),
            'offset' => $schema->integer()->min(0)->default(0)->description('Zero-based offset; later pages require snapshot.'),
            'snapshot' => $schema->string()->description('Previous response snapshot. Restart when inputs change.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $arguments = $request->all(['query', 'kind', 'path', 'limit', 'offset', 'snapshot']);
        $invalid = $this->invalidInput($request, ['query' => 'string', 'kind' => 'string', 'path' => 'string', 'limit' => 'integer', 'offset' => 'integer', 'snapshot' => 'string']);
        $oversized = false;
        foreach ($arguments as $value) {
            $oversized = $oversized || is_string($value) && strlen($value) > 4096;
            if ($value === null) {
                $invalid = 'Null arguments are not supported; omit optional arguments.';
            }
        }
        if (($arguments['limit'] ?? 20) > 100 || ($arguments['offset'] ?? 0) > 0 && ! is_string($arguments['snapshot'] ?? null)) {
            $invalid = 'Limit must be at most 100; later pages require snapshot.';
        }
        if ($invalid !== null || $oversized || ! is_string($request->get('query')) || trim($request->get('query')) === '') {
            return Response::structured(GraphPage::make('architecture-search', [], '', [], [],
                ['status' => 'invalid_input', 'candidates' => [], 'message' => $invalid ?? 'A non-empty query is required.'], 'candidates'));
        }
        try {
            $source = (new GraphSource(app(Filesystem::class), base_path()))->load();
            $result = (new GraphSearch($source['index']))->find($request->get('query'), $request->get('kind'), $request->get('path'));
            if ($source['changed']) {
                $result['status'] = 'stale_snapshot';
                $result['candidates'] = [];
            }

            return Response::structured(GraphPage::make('architecture-search', $arguments, $source['snapshot'], $source['scope'], $source['index']->diagnostics, $result, 'candidates'));
        } catch (Throwable) {
            return Response::structured(['v' => 1, 'cmd' => 'architecture-search', 'ok' => false, 'status' => 'unavailable',
                'snapshot' => null, 'scope' => [], 'analysis_complete' => false, 'truncated' => false,
                'limits' => ['max_bytes' => GraphPage::MAX_BYTES], 'diagnostics' => ['summary' => ['source_unavailable' => 1],
                    'details' => [['code' => 'source_unavailable', 'message' => 'Source settings or graph could not be read safely. Inspect project configuration.']]],
                'result' => ['candidates' => []], 'next' => null]);
        }
    }
}
