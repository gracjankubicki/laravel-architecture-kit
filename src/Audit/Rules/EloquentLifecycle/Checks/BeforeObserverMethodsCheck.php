<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use Taqie\ArchitectureKit\Audit\Ast\PhpAst;
use Taqie\ArchitectureKit\Audit\AuditFinding;
use Taqie\ArchitectureKit\Audit\FileCheck;
use Taqie\ArchitectureKit\Audit\FileContext;
use Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\LifecycleAst;

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
    private function beforeObserverMethodFindings(string $path, Stmt\ClassMethod $method): array
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
                private Stmt\ClassMethod $method,
            ) {}

            public function enterNode(Node $node): null
            {
                if ($this->isForbiddenFacadeCall($node)) {
                    $this->state->findings[] = $this->finding($node, 'error', 'Before-save observers must not use facades; delegate to one lifecycle handler or move side effects outside lifecycle.');
                }

                if ($node instanceof FuncCall && $node->name instanceof Name && $node->name->toString() === 'app') {
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
                return $node instanceof StaticCall
                    && $node->class instanceof Name
                    && in_array($node->class->toString(), ['DB', 'Http', 'Mail', 'Notification', 'Cache', 'Log', 'Auth'], true);
            }

            private function isDispatchCall(Node $node): bool
            {
                if ($node instanceof FuncCall && $node->name instanceof Name) {
                    return in_array($node->name->toString(), ['event', 'dispatch'], true);
                }

                return $node instanceof StaticCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'dispatch';
            }

            private function isModelWriteCall(Node $node): bool
            {
                return $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['save', 'update', 'delete'], true);
            }

            private function isModelCreationOrQueryCall(Node $node): bool
            {
                return $node instanceof StaticCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['create', 'query'], true);
            }

            private function mutatesLifecycleParameter(Node $node): bool
            {
                if (! $node instanceof Assign && ! $node instanceof AssignOp\Coalesce) {
                    return false;
                }

                $parameter = $this->firstParameterName();

                return $parameter !== null && $this->isParameterMemberFetch($node->var, $parameter);
            }

            private function firstParameterName(): ?string
            {
                $parameter = $this->method->params[0] ?? null;

                if (! $parameter instanceof Node\Param || ! $parameter->var instanceof Variable || ! is_string($parameter->var->name)) {
                    return null;
                }

                return $parameter->var->name;
            }

            private function isParameterMemberFetch(Node $node, string $parameter): bool
            {
                if ($node instanceof PropertyFetch || $node instanceof ArrayDimFetch) {
                    return $node->var instanceof Variable && $node->var->name === $parameter;
                }

                return false;
            }
        });

        return $state->findings;
    }
}
