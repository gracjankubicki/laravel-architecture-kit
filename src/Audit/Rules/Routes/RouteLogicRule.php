<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Routes;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditRule;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Shared\WorkflowSignals;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\NodeVisitorAbstract;

/**
 * Business logic closed inside a route definition.
 *
 * A route closure is the one place an agent can finish a feature without touching any
 * folder the audit used to read, which made the gate green for reasons that had nothing
 * to do with the code. The signals are the ones the controller rule already uses, so a
 * workflow does not become acceptable by moving one file up.
 */
final readonly class RouteLogicRule implements AuditRule
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return str_starts_with($path, 'routes/');
    }

    /**
     * @return array<int, AuditFinding>
     */
    public function check(FileContext $file): array
    {
        $nodes = $file->ast();

        if ($nodes === null) {
            return [];
        }

        $findings = [];

        foreach ($this->workflowCalls($file, $nodes) as $call) {
            $findings[] = new AuditFinding(
                severity: 'error',
                rule: 'route-logic',
                path: $file->path,
                line: $call['line'],
                message: $call['message'],
                code: $call['code'],
            );
        }

        return $findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string, code: string}>
     */
    private function workflowCalls(FileContext $file, array $nodes): array
    {
        $state = new class
        {
            /** @var array<int, array{line: int, message: string, code: string}> */
            public array $calls = [];
        };

        PhpAst::traverse($nodes, new class($file, $state) extends NodeVisitorAbstract
        {
            /** @var array<int, array<string, true>> */
            private array $modelVariableScopes = [];

            public function __construct(
                private FileContext $file,
                private object $state,
            ) {}

            public function enterNode(Node $node): null
            {
                // A route closure is the unit of work here, the way a method is in a
                // controller: a model typed in its signature is the one being written to.
                if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
                    $this->modelVariableScopes[] = $this->scopeFor($node);

                    return null;
                }

                if ($node instanceof MethodCall && $node->name instanceof Node\Identifier) {
                    $this->inspectMethodCall($node, $node->name->toString());
                }

                if ($node instanceof StaticCall && $node->name instanceof Node\Identifier) {
                    $this->inspectStaticCall($node, $node->name->toString());
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
                    array_pop($this->modelVariableScopes);
                }

                return null;
            }

            private function inspectMethodCall(MethodCall $node, string $method): void
            {
                if (WorkflowSignals::isValidationCall($method)) {
                    $this->record($node->getStartLine(), 'Route performs inline validation; move the request to a controller with a FormRequest.', 'E_ROUTE_INLINE_VALIDATION');
                }

                if (WorkflowSignals::isModelWriteMethod($method) && $this->isModelVariable($node->var)) {
                    $this->record($node->getStartLine(), 'Route writes to a model directly; move the write use case to an Action.', 'E_ROUTE_MODEL_WRITE');
                }
            }

            private function inspectStaticCall(StaticCall $node, string $method): void
            {
                $class = $node->class instanceof Name ? $this->file->resolvedName($node->class) : null;

                if ($class === null) {
                    return;
                }

                if (WorkflowSignals::isTransactionCall($class, $method)) {
                    $this->record($node->getStartLine(), 'Route owns a transaction; move the workflow to an Action.', 'E_ROUTE_TRANSACTION');
                }

                if ($method === 'dispatch' && WorkflowSignals::isWorkflowDispatchTarget($class)) {
                    $this->record($node->getStartLine(), 'Route dispatches work directly; move the workflow to an Action.', 'E_ROUTE_DISPATCH');
                }

                if ($method === 'create' && WorkflowSignals::isModelClass($class)) {
                    $this->record($node->getStartLine(), 'Route creates a model directly; move the write use case to an Action.', 'E_ROUTE_MODEL_WRITE');
                }
            }

            private function record(int $line, string $message, string $code): void
            {
                $this->state->calls[] = ['line' => $line, 'message' => $message, 'code' => $code];
            }

            /**
             * Models a closure can write to: the ones it declares, plus the ones it
             * inherited. A write nested inside DB::transaction(function () use ($invoice))
             * is still a write to that model.
             *
             * @return array<string, true>
             */
            private function scopeFor(Node\FunctionLike $function): array
            {
                $scope = $this->modelParameters($function);

                if (! $function instanceof Node\Expr\Closure) {
                    // An arrow function reads the enclosing scope wholesale.
                    return $function instanceof Node\Expr\ArrowFunction
                        ? [...$this->currentScope(), ...$scope]
                        : $scope;
                }

                $inherited = $this->currentScope();

                foreach ($function->uses as $use) {
                    if (is_string($use->var->name) && isset($inherited[$use->var->name])) {
                        $scope[$use->var->name] = true;
                    }
                }

                return $scope;
            }

            /**
             * @return array<string, true>
             */
            private function currentScope(): array
            {
                return $this->modelVariableScopes[array_key_last($this->modelVariableScopes)] ?? [];
            }

            /**
             * @return array<string, true>
             */
            private function modelParameters(Node\FunctionLike $function): array
            {
                $parameters = [];

                foreach ($function->getParams() as $parameter) {
                    if (
                        ! $parameter->type instanceof Name
                        || ! is_string($parameter->var->name)
                        || ! WorkflowSignals::isModelClass($this->file->resolvedName($parameter->type))
                    ) {
                        continue;
                    }

                    $parameters[$parameter->var->name] = true;
                }

                return $parameters;
            }

            private function isModelVariable(Node\Expr $receiver): bool
            {
                if (! $receiver instanceof Node\Expr\Variable || ! is_string($receiver->name)) {
                    return false;
                }

                return isset($this->currentScope()[$receiver->name]);
            }
        });

        return $state->calls;
    }
}
