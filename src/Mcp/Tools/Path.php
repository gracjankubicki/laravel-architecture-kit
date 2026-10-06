<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Impact\ArchitecturePath;
use GracjanKubicki\ArchitectureKit\Mcp\Concerns\UsesArchitectureKitState;
use GracjanKubicki\ArchitectureKit\Mcp\Concerns\ValidatesMcpInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('path')]
#[Description('Find several bounded paths from A to B. Select each endpoint by FQCN, unambiguous short class name, Class::method or project PHP path. Dependencies project methods to classes and show strong/weak classification, not repair cost. Execution paths preserve ordinary calls and supported HTTP, job, event, model, Artisan and scheduler conditions. Read found separately from status, freshness, limits and unresolved notices. No path means no path in the analyzed graph, not runtime impossibility. External calls are terminal boundaries with unread declarations; vendor is excluded. Inspect execution.authorization in impact for precise Gate/policy rules, route and Form Request checks, conditions, boolean/exception usage, shared rule site counts and unresolved boundaries. Use path to expand individual authorization chains and reach for immutable continuation pages. Inline allowIf/denyIf bypass policies and hooks; missing checks are not security verdicts. No application code is executed.')]
#[IsReadOnly]
final class Path extends Tool
{
    use UsesArchitectureKitState;
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return ['from' => $schema->string()->required(), 'to' => $schema->string()->required(), 'limit' => $schema->integer()->min(0)->max(500)->default(20), 'depth' => $schema->integer()->min(1)->max(32)->default(8)];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (($message = $this->invalidInput($request, ['from' => 'string', 'to' => 'string', 'limit' => 'integer', 'depth' => 'integer'])) !== null) {
            return Response::structured(ArchitecturePath::error('E_INVALID_TOOL_INPUT', $message));
        }
        try {
            $state = DiscoverySettings::load($this->files(), base_path());
            $result = (new ArchitecturePath($this->files(), base_path(), $state->scope, $state->cache, $state->fingerprint))->inspect($request->get('from', ''), $request->get('to', ''), $state->exclude, $request->get('limit', 20), $request->get('depth', 8));
        } catch (Throwable $exception) {
            $result = ArchitecturePath::error('E_PATH_FAILED', $exception->getMessage());
        }

        return Response::structured($result);
    }
}
