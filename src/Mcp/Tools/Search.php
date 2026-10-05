<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Discovery\ArchitectureDiscovery;
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

#[Name('search')]
#[Description('Find the starting point when only a literal name/path fragment, route URL/name, command name or declaration kind is known. Matching is case-insensitive, exact matches first, with no fuzzy match or automatic selection. Inspect candidate kind, location, notes and supported selector; choose explicitly and pass selector to impact/path. File selectors include more than one route or command callback. Empty query needs a kind filter. Job classification uses supported contracts or dispatch, not the Jobs directory. Read totals with total_is_lower_bound, truncated, notices, status and freshness. No runtime registration or execution is proved. Project settings are parsed statically; dynamic audit settings are rejected without execution. Tables use impact with table instead.')]
#[IsReadOnly]
final class Search extends Tool
{
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->description('Literal fragment, not a regex. Omit only with kind.'), 'kind' => $schema->string()->enum(ArchitectureDiscovery::KINDS), 'limit' => $schema->integer()->min(0)->max(500)->default(20)];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (($message = $this->invalidInput($request, ['query' => 'string', 'kind' => 'string', 'limit' => 'integer'])) !== null) {
            return Response::structured(ArchitectureDiscovery::error('E_INVALID_TOOL_INPUT', $message));
        }

        return Response::structured((new ArchitectureDiscovery(app(Filesystem::class), base_path()))->search($request->get('query', ''), $request->get('kind'), $request->get('limit', 20)));
    }
}
