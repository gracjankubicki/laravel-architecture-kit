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
use Throwable;

#[Name('guard')]
#[Description('After code changes, run the Architecture Kit gate with the intended changed-file scope and strictness. Read structured ok, findings and incomplete analysis. Use explain-finding for an occurrence or doctor for setup problems. Success does not prove passing application tests.')]
#[IsReadOnly]
class Guard extends Tool
{
    use UsesArchitectureKitState;
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'changed' => $schema->boolean()->default(true)->description('Audit changed files when true; inspect the full configured audit scope when false.'),
            'base' => $schema->string()->nullable()->description('Optional Git base revision for detecting changed files. Omit to use the normal working-tree comparison.'),
            'target' => $schema->boolean()->default(false)->description('Also evaluate the separately configured desired-architecture gate when true. This does not accept or refresh its reference.'),
            'strict' => $schema->boolean()->default(true)->description('Treat audit warnings as blocking when true. Inspect findings and incomplete analysis separately.'),
            'limit' => $schema->integer()->min(0)->default(20)->description('Maximum displayed records. Zero returns summary information without removing analysis limits.'),
            'full' => $schema->boolean()->default(false)->description('Include the full report instead of the bounded agent summary when true.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (($message = $this->invalidInput($request, ['changed' => 'boolean', 'base' => 'string', 'strict' => 'boolean', 'limit' => 'integer', 'full' => 'boolean', 'target' => 'boolean'])) !== null) {
            return $this->inputError('guard', $message);
        }

        $agent = new AgentOutput;

        try {
            $state = $this->projectState();

            return Response::structured($agent->guard($this->guard(
                state: $state,
                changedOnly: $request->get('changed', true),
                baseRef: $request->get('base'),
                strict: $request->get('strict', true),
                includeTarget: $request->get('target', false),
            ), $agent->limit($request->get('limit', 20)), $request->get('full', false)));
        } catch (Throwable $exception) {
            return Response::structured($agent->error('guard', $exception->getMessage()));
        }
    }
}
