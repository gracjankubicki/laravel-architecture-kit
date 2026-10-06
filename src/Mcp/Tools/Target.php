<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Mcp\Concerns\ValidatesMcpInput;
use GracjanKubicki\ArchitectureKit\Target\ArchitectureTarget;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('architecture-target')]
#[Description('Read .architecture-kit/target.json and compare current source roles, kinds, modules, placements and dependencies with desired architecture. Use a class, method or future PHP file as subject. Read migration order, current audit separately, suppression labels, uncertainty and freshness. Default informational; explicit warn/block and new/all. New-only requires a human-accepted reference for the exact target and analysis settings. Reference candidate is unaccepted data; never write, approve or refresh it automatically. No source execution, refactor or application cache writes; Git is optional.')]
#[IsReadOnly]
final class Target extends Tool
{
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return ['subject' => $schema->string()->default(''), 'limit' => $schema->integer()->min(0)->max(500)->default(50)];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return ['v' => $schema->integer()->enum([1])->required(), 'cmd' => $schema->string()->enum(['architecture-target'])->required(), 'ok' => $schema->boolean()->required(),
            'configured' => $schema->boolean(), 'target' => $schema->object(), 'source' => $schema->object(), 'analysis' => $schema->object(), 'gate' => $schema->object(), 'totals' => $schema->object(),
            'elements' => $schema->array()->items($schema->object()), 'subject' => $schema->object()->nullable(), 'migration_order' => $schema->array()->items($schema->object()),
            'audit' => $schema->object(), 'progress' => $schema->object()->nullable(), 'reference_candidate' => $schema->object()->nullable(),
            'notices' => $schema->array()->items($schema->object()), 'limitations' => $schema->array()->items($schema->string()), 'next' => $schema->array()->items($schema->string()), 'm' => $schema->string(), 'msg' => $schema->string()];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (($invalid = $this->invalidInput($request, ['subject' => 'string', 'limit' => 'integer'])) !== null) {
            return Response::structured(ArchitectureTarget::error('E_TARGET_INPUT', $invalid));
        }

        return Response::structured((new ArchitectureTarget(base_path()))->inspect($request->get('subject', ''), $request->get('limit', 50)));
    }
}
