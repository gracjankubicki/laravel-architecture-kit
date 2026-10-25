<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Context\ArchitectureContext as ContextQuery;
use GracjanKubicki\ArchitectureKit\Context\ArchitectureContextException;
use GracjanKubicki\ArchitectureKit\Mcp\Concerns\UsesArchitectureKitState;
use GracjanKubicki\ArchitectureKit\Mcp\Concerns\ValidatesMcpInput;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
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
#[Description('Before editing a known PHP symbol, inspect its direct static dependencies, dependents, violations, candidate tests and source locations. Provide an exact FQCN or app-relative PHP path. Use architecture-search to find an unknown element and architecture-graph for Laravel flows or transitive impact. Results do not prove execution or passing tests.')]
#[IsReadOnly]
final class ArchitectureContext extends Tool
{
    use UsesArchitectureKitState;
    use ValidatesMcpInput;

    public function schema(JsonSchema $schema): array
    {
        return ['analysis_mode' => $schema->string()->enum(['basic', 'deep'])->default('basic')->description('Deep requires prior human consent recorded for task; full only controls report size.'), 'task' => $schema->string()->description('Task with previously recorded consent.'),
            'subject' => $schema->string()->required()->description('Exact project FQCN or app-relative PHP file path. Use architecture-search first when the symbol is unknown.'),
            'limit' => $schema->integer()->min(0)->default(20)->description('Maximum displayed records. Zero returns summary information without removing analysis limits.'),
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
            $mode = RepositoryContext::requestedMode($request->get('analysis_mode', 'basic'), $request->get('task'));
            $state = $this->projectState();
            $context = (new ContextQuery(
                $this->files(),
                RepositoryContext::root(),
                scope: $state->auditScope,
                cache: $state->graphCache,
                cacheConfiguration: $state->graphConfiguration(),
                mode: $mode,
            ))->inspect(
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
