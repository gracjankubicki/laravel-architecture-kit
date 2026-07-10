<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Mcp\Concerns\UsesArchitectureKitState;
use GracjanKubicki\ArchitectureKit\Mcp\Concerns\ValidatesMcpInput;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('guard')]
#[Description('Run the same Architecture Kit gate used by hooks and CLI.')]
#[IsReadOnly]
class Guard extends Tool
{
    use UsesArchitectureKitState;
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'changed' => $schema->boolean()->default(true),
            'base' => $schema->string()->nullable(),
            'strict' => $schema->boolean()->default(true),
            'limit' => $schema->integer()->min(0)->default(20),
            'full' => $schema->boolean()->default(false),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (($message = $this->invalidInput($request, ['changed' => 'boolean', 'base' => 'string', 'strict' => 'boolean', 'limit' => 'integer', 'full' => 'boolean'])) !== null) {
            return $this->inputError('guard', $message);
        }

        $agent = new AgentOutput;
        $state = $this->projectState();

        return Response::structured($agent->guard($this->guard(
            state: $state,
            changedOnly: $request->get('changed', true),
            baseRef: $request->get('base'),
            strict: $request->get('strict', true),
        ), $agent->limit($request->get('limit', 20)), $request->get('full', false)));
    }
}
