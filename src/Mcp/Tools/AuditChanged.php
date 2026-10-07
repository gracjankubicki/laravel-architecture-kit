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

#[Name('audit-changed')]
#[Description('Inspect changed application files against enabled architecture rules. Supply a Git base when needed, or changed=false for the full audit scope. Read findings, suggestions and incomplete analysis separately. Use explain-finding for a reported occurrence and guard for the final gate.')]
#[IsReadOnly]
class AuditChanged extends Tool
{
    use UsesArchitectureKitState;
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'changed' => $schema->boolean()->default(true)->description('Audit changed files when true; inspect the full configured audit scope when false.'),
            'base' => $schema->string()->nullable()->description('Optional Git base revision for detecting changed files. Omit to use the normal working-tree comparison.'),
            'strict' => $schema->boolean()->default(false)->description('Treat audit warnings as blocking when true. Inspect findings and incomplete analysis separately.'),
            'limit' => $schema->integer()->min(0)->default(20)->description('Maximum displayed records. Zero returns summary information without removing analysis limits.'),
            'full' => $schema->boolean()->default(false)->description('Include the full report instead of the bounded agent summary when true.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (($message = $this->invalidInput($request, ['changed' => 'boolean', 'base' => 'string', 'strict' => 'boolean', 'limit' => 'integer', 'full' => 'boolean'])) !== null) {
            return $this->inputError('audit', $message);
        }

        $agent = new AgentOutput;

        try {
            $state = $this->projectState();
            $audit = $this->audit(
                state: $state,
                changedOnly: $request->get('changed', true),
                baseRef: $request->get('base'),
            );
            $strict = $request->get('strict', false);

            return Response::structured($agent->audit(
                result: $audit,
                ok: $audit->errors() === 0 && (! $strict || $audit->warnings() === 0),
                limit: $agent->limit($request->get('limit', 20)),
                full: $request->get('full', false),
            ));
        } catch (Throwable $exception) {
            return Response::structured($agent->error('audit', $exception->getMessage()));
        }
    }
}
