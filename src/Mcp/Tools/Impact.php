<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
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

#[Name('impact')]
#[Description('Before changing a class, file or Class::method, inspect bounded direct and indirect relationships. Short names return candidates when ambiguous. Resolved, possible contract calls, references and class-only context are separate. Inspect uncertain evidence before dependent decisions. Test candidates do not prove coverage; relationships alone are not BREAKING verdicts. For a method change use change=signature, optionally supply a PHP signature without body. Breaking rows prove supported static incompatibilities; inspect check rows, types and limits. No breaking rows does not prove safety. No runtime execution. Increase limit/depth to expand a truncated report.')]
#[IsReadOnly]
final class Impact extends Tool
{
    use UsesArchitectureKitState;
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return ['change' => $schema->string()->enum(['signature']), 'signature' => $schema->string()->description('Single PHP method declaration without a body. Implies change=signature. Use fully qualified class types.'), 'subject' => $schema->string()->required(), 'limit' => $schema->integer()->min(0)->max(500)->default(20), 'depth' => $schema->integer()->min(1)->max(32)->default(4)];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (($message = $this->invalidInput($request, ['subject' => 'string', 'change' => 'string', 'signature' => 'string', 'limit' => 'integer', 'depth' => 'integer'])) !== null) {
            return Response::structured(ArchitectureImpact::error('E_INVALID_TOOL_INPUT', $message));
        }
        try {
            $state = $this->projectState();
            $result = (new ArchitectureImpact($this->files(), base_path(), $state->auditScope, $state->graphCache, $state->graphConfiguration()))
                ->inspect($request->get('subject', ''), $state->exclude, $request->get('limit', 20), $request->get('depth', 4), $request->get('change'), $request->get('signature'));
        } catch (Throwable $exception) {
            $result = ArchitectureImpact::error('E_IMPACT_FAILED', $exception->getMessage());
        }

        return Response::structured($result);
    }
}
