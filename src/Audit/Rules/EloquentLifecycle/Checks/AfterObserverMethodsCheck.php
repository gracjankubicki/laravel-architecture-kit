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

final readonly class AfterObserverMethodsCheck implements FileCheck
{
    public function __construct(private LifecycleAst $lifecycle) {}

    public function findings(FileContext $file): array
    {
        if (! $this->lifecycle->isObserverPath($file->path)) {
            return [];
        }

        $findings = [];

        foreach ($this->lifecycle->lifecycleMethods($file) as $method) {
            if (! in_array($method->name->toString(), LifecycleAst::AFTER_METHODS, true)) {
                continue;
            }

            array_push($findings, ...$this->afterObserverMethodFindings($file->path, $method));
        }

        return $findings;
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function afterObserverMethodFindings(string $path, Node $method): array
    {
        if ($this->lifecycle->isSingleNamedEventDispatch($method)) {
            return [];
        }

        $state = new class
        {
            /**
             * @var array<int, AuditFinding>
             */
            public array $findings = [];
        };

        PhpAst::traverse([$method], new class($path, $state) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $path,
                private object $state,
            ) {}

            public function enterNode(Node $node): null
            {
                if ($this->isForbiddenFacadeCall($node)) {
                    $this->state->findings[] = $this->finding($node, 'After-save observers should dispatch one named event; put side effects in after-commit listeners or jobs.');
                }

                if (Node::is($node, 'expression.func-call') && Node::is($node->name, 'name') && $node->name->toString() === 'app') {
                    $this->state->findings[] = $this->finding($node, 'After-save observers should not resolve collaborators with app(); dispatch one named event or delegate through an enabled boundary.');
                }

                if (Node::is($node, 'expression.func-call') && Node::is($node->name, 'name') && $node->name->toString() === 'dispatch') {
                    $this->state->findings[] = $this->finding($node, 'After-save observers should not dispatch jobs directly; dispatch one named event and let listeners own async work.');
                }

                if (
                    Node::is($node, 'expression.static-call')
                    && Node::is($node->name, 'identifier')
                    && $node->name->toString() === 'dispatch'
                ) {
                    $this->state->findings[] = $this->finding($node, 'After-save observers should dispatch one named event; do not dispatch jobs directly from the observer.');
                }

                if ($this->isModelWriteCall($node)) {
                    $this->state->findings[] = $this->finding($node, 'After-save observers should not write models directly; move reactions to named listeners or Actions.');
                }

                if ($this->isModelCreationOrQueryCall($node)) {
                    $this->state->findings[] = $this->finding($node, 'After-save observers should not create models or build queries directly; move reactions to named listeners or Actions.');
                }

                return null;
            }

            private function finding(Node $node, string $message): AuditFinding
            {
                return new AuditFinding('warn', 'eloquent-lifecycle', $this->path, $node->getStartLine(), $message);
            }

            private function isForbiddenFacadeCall(Node $node): bool
            {
                return Node::is($node, 'expression.static-call')
                    && Node::is($node->class, 'name')
                    && in_array($node->class->toString(), ['DB', 'Http', 'Mail', 'Notification', 'Cache', 'Log', 'Auth'], true);
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
        });

        return $state->findings;
    }
}
