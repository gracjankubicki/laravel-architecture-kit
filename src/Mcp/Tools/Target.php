<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Mcp\Concerns\ValidatesMcpInput;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Target\ArchitectureTarget;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('architecture-target')]
#[Description('Requires explicit analysis_mode=deep, task and prior human consent. Ask the user before granting analysis-consent. Read .architecture-kit/target.json and compare current source roles, kinds, modules, placements and dependencies with desired architecture. Use a class, method or future PHP file as subject. Read migration order, current audit separately, suppression labels, uncertainty and freshness. Default informational; explicit warn/block and new/all. New-only requires a human-accepted reference for the exact target and analysis settings. Reference candidate is unaccepted data; never write, approve or refresh it automatically. No source execution, refactor or application cache writes; Git is optional.')]
#[IsReadOnly]
final class Target extends Tool
{
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return ['analysis_mode' => $schema->string()->enum(['basic', 'deep'])->default('basic')->description('Full assessment requires deep; Basic returns not_run.'), 'task' => $schema->string()->description('Task with prior human consent for Deep assessment.'), 'subject' => $schema->string()->default('')->description('Optional class, Class::method or existing or planned PHP file path. Omit to inspect project-wide target progress.'), 'limit' => $schema->integer()->min(0)->max(500)->default(50)->description('Maximum displayed records. Zero returns summary information without removing analysis limits.')];
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
        try {
            RepositoryContext::requiredDeep($request->get('analysis_mode', 'basic'), $request->get('task'));
        } catch (Throwable $exception) {
            return Response::structured([...ArchitectureTarget::error('E_TARGET_CONSENT', $exception->getMessage()),
                'analysis' => ['mode' => 'basic', 'status' => 'not_run', 'deep_run' => false, 'checks_complete' => false]]);
        }
        if (($invalid = $this->invalidInput($request, ['subject' => 'string', 'limit' => 'integer'])) !== null) {
            return Response::structured(ArchitectureTarget::error('E_TARGET_INPUT', $invalid));
        }

        return Response::structured((new ArchitectureTarget(RepositoryContext::root()))->inspect($request->get('subject', ''), $request->get('limit', 50)));
    }
}
