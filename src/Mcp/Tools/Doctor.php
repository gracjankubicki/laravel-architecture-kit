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

#[Name('doctor')]
#[Description('Inspect Architecture Kit configuration and generated-resource freshness without changing files. Use this for setup failures, then follow the reported repair action and rerun doctor. This does not audit application behavior.')]
#[IsReadOnly]
class Doctor extends Tool
{
    use UsesArchitectureKitState;
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(0)->default(20)->description('Maximum displayed records. Zero returns summary information without removing analysis limits.'),
            'full' => $schema->boolean()->default(false)->description('Include the full report instead of the bounded agent summary when true.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (($message = $this->invalidInput($request, ['limit' => 'integer', 'full' => 'boolean'])) !== null) {
            return $this->inputError('doctor', $message);
        }

        $agent = new AgentOutput;
        $state = $this->projectState();

        return Response::structured($agent->doctor(
            result: $this->doctor($state),
            limit: $agent->limit($request->get('limit', 20)),
            full: $request->get('full', false),
        ));
    }
}
