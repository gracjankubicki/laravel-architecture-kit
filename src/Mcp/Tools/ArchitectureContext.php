<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Context\ArchitectureContext as ContextQuery;
use GracjanKubicki\ArchitectureKit\Context\ArchitectureContextException;
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

#[Name('architecture-context')]
#[Description('Return bounded static dependencies, dependents, violations, and inspect paths for one exact project FQCN or app-relative PHP path.')]
#[IsReadOnly]
final class ArchitectureContext extends Tool
{
    use UsesArchitectureKitState;
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->required(),
            'limit' => $schema->integer()->min(0)->default(20),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (($message = $this->invalidInput($request, ['subject' => 'string', 'limit' => 'integer'])) !== null) {
            return $this->inputError('architecture-context', $message);
        }

        $subject = $request->get('subject');

        if (! is_string($subject) || trim($subject) === '') {
            return $this->inputError(
                'architecture-context',
                'Provide an exact project FQCN or app-relative PHP path.',
                'E_CONTEXT_SUBJECT_REQUIRED',
            );
        }

        $agent = new AgentOutput;

        try {
            $state = $this->projectState();
            $context = (new ContextQuery($this->files(), base_path()))->inspect(
                subject: $subject,
                enabled: $state->enabled,
                exclude: $state->exclude,
                limit: $agent->limit($request->get('limit', 20)),
            );
        } catch (ArchitectureContextException $exception) {
            return Response::structured($agent->architectureContextError($exception->errorCode, $exception->getMessage()));
        } catch (Throwable $exception) {
            return Response::structured($agent->architectureContextError('E_CONTEXT_FAILED', $exception->getMessage()));
        }

        return Response::structured($agent->architectureContext($context));
    }
}
