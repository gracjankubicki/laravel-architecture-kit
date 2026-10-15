<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\LifecycleAst;

final readonly class BeforeObserverMethodsCheck implements FileCheck
{
    public function __construct(private LifecycleAst $lifecycle) {}

    public function findings(FileContext $file): array
    {
        if (! $this->lifecycle->isObserverPath($file->path)) {
            return [];
        }

        $findings = [];

        foreach ($this->lifecycle->lifecycleMethods($file) as $method) {
            if (! in_array($method->name->toString(), LifecycleAst::BEFORE_METHODS, true)) {
                continue;
            }

            array_push($findings, ...$this->beforeObserverMethodFindings($file->path, $method));
        }

        return $findings;
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function beforeObserverMethodFindings(string $path, Node $method): array
    {
        $state = new class
        {
            /**
             * @var array<int, AuditFinding>
             */
            public array $findings = [];
        };

        PhpAst::traverse([$method], new class($path, $state, $method) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $path,
                private object $state,
                private Node $method,
            ) {}

            public function enterNode(Node $node): null
            {
                if ($this->isForbiddenFacadeCall($node)) {
                    $this->state->findings[] = $this->finding($node, 'error', 'Before-save observers must not use facades; delegate to one lifecycle handler or move side effects outside lifecycle.');
                }

                if (Node::is($node, 'expression.func-call') && Node::is($node->name, 'name') && $node->name->toString() === 'app') {
                    $this->state->findings[] = $this->finding($node, 'error', 'Before-save observers must not resolve collaborators with app(); inject one lifecycle handler.');
                }

                if ($this->isDispatchCall($node)) {
                    $this->state->findings[] = $this->finding($node, 'error', 'Before-save observers must not dispatch events or jobs; move side effects to after-save events/listeners.');
                }

                if ($this->isModelWriteCall($node)) {
                    $this->state->findings[] = $this->finding($node, 'error', 'Before-save observers must not write other models; they may only mutate the current model or block the save.');
                }

                if ($this->isModelCreationOrQueryCall($node)) {
                    $this->state->findings[] = $this->finding($node, 'error', 'Before-save observers must not create models or build queries; delegate to an explicit lifecycle handler or Action.');
                }

                if (
                    in_array($this->method->name->toString(), ['deleting', 'restoring'], true)
                    && $this->mutatesLifecycleParameter($node)
                ) {
                    $this->state->findings[] = $this->finding($node, 'warn', 'deleting/restoring handlers are gatekeepers only; verify invariants and throw named exceptions instead of mutating attributes.');
                }

                return null;
            }

            private function finding(Node $node, string $severity, string $message): AuditFinding
            {
                return new AuditFinding($severity, 'eloquent-lifecycle', $this->path, $node->getStartLine(), $message);
            }

            private function isForbiddenFacadeCall(Node $node): bool
            {
                return Node::is($node, 'expression.static-call')
                    && Node::is($node->class, 'name')
                    && in_array($node->class->toString(), ['DB', 'Http', 'Mail', 'Notification', 'Cache', 'Log', 'Auth'], true);
            }

            private function isDispatchCall(Node $node): bool
            {
                if (Node::is($node, 'expression.func-call') && Node::is($node->name, 'name')) {
                    return in_array($node->name->toString(), ['event', 'dispatch'], true);
                }

                return Node::is($node, 'expression.static-call')
                    && Node::is($node->name, 'identifier')
                    && $node->name->toString() === 'dispatch';
            }

            private function isModelWriteCall(Node $node): bool
            {
                return Node::is($node, 'expression.method-call')
                    && Node::is($node->name, 'identifier')
                    && in_array($node->name->toString(), ['save', 'update', 'delete'], true);
            }

            private function isModelCreationOrQueryCall(Node $node): bool
            {
                return Node::is($node, 'expression.static-call')
                    && Node::is($node->name, 'identifier')
                    && in_array($node->name->toString(), ['create', 'query'], true);
            }

            private function mutatesLifecycleParameter(Node $node): bool
            {
                if (! Node::is($node, 'expression.assign') && ! Node::is($node, 'expression.assign-op.coalesce')) {
                    return false;
                }

                $parameter = $this->firstParameterName();

                return $parameter !== null && $this->isParameterMemberFetch($node->var, $parameter);
            }

            private function firstParameterName(): ?string
            {
                $parameter = $this->method->params[0] ?? null;

                if (! Node::is($parameter, 'param') || ! Node::is($parameter->var, 'expression.variable') || ! is_string($parameter->var->name)) {
                    return null;
                }

                return $parameter->var->name;
            }

            private function isParameterMemberFetch(Node $node, string $parameter): bool
            {
                if (Node::is($node, 'expression.property-fetch') || Node::is($node, 'expression.array-dim-fetch')) {
                    return Node::is($node->var, 'expression.variable') && $node->var->name === $parameter;
                }

                return false;
            }
        });

        return $state->findings;
    }
}
