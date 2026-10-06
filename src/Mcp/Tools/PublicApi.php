<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Mcp\Concerns\ValidatesMcpInput;
use GracjanKubicki\ArchitectureKit\PublicApi\ArchitecturePublicApi;
use GracjanKubicki\ArchitectureKit\PublicApi\SemverAdvice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('public-api')]
#[Description('Compare package production Composer PHP, Artisan, published resources/events and declared MCP contracts from a Git revision to working files or another revision. Requires a package Git checkout, not only vendor installation. No checkout, source execution, migration, version bump or publication. Read before/after identities, freshness, lower bounds, notices and check rows; missing alarms do not prove behavioural safety. Stable major/minor advice is a minimum from concrete changes. 0.x needs explicit zero_policy; unchanged contracts do not prove patch. public_paths only limits scope; it does not make private/internal declarations public.')]
#[IsReadOnly]
final class PublicApi extends Tool
{
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return ['from' => $schema->string()->required(), 'to' => $schema->string()->default('working'),
            'public_paths' => $schema->array()->items($schema->string()), 'current_version' => $schema->string(),
            'zero_policy' => $schema->string()->enum(SemverAdvice::ZERO_POLICIES), 'limit' => $schema->integer()->min(0)->max(500)->default(50)];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return ['v' => $schema->integer()->enum([1])->required(), 'cmd' => $schema->string()->enum(['public-api'])->required(),
            'ok' => $schema->boolean()->required(), 'sources' => $schema->object(), 'analysis' => $schema->object(),
            'changes' => $schema->array()->items($schema->object()), 'totals' => $schema->object(),
            'notices' => $schema->array()->items($schema->object()), 'semver' => $schema->object(),
            'limitations' => $schema->array()->items($schema->string()), 'next' => $schema->array()->items($schema->string()),
            'm' => $schema->string(), 'msg' => $schema->string()];
    }

    public function handle(Request $request): ResponseFactory
    {
        $invalid = $this->invalidInput($request, ['from' => 'string', 'to' => 'string', 'current_version' => 'string', 'zero_policy' => 'string', 'limit' => 'integer']);
        $paths = $request->get('public_paths', []);
        if ($invalid !== null || ! is_array($paths) || ! array_is_list($paths) || count(array_filter($paths, 'is_string')) !== count($paths)) {
            return Response::structured(ArchitecturePublicApi::error('E_PUBLIC_API_INPUT', $invalid ?? 'public_paths must be a list of literal string paths.'));
        }

        return Response::structured((new ArchitecturePublicApi(base_path()))->compare($request->get('from', ''), $request->get('to', 'working'), $paths, $request->get('current_version'), $request->get('zero_policy'), $request->get('limit', 50)));
    }
}
