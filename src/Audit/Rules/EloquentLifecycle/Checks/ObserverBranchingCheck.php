<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks;

use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\LifecycleAst;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final readonly class ObserverBranchingCheck implements FileCheck
{
    public function __construct(private LifecycleAst $lifecycle) {}

    public function findings(FileContext $file): array
    {
        if (! $this->lifecycle->isObserverPath($file->path)) {
            return [];
        }

        $nodes = $file->ast();

        if ($nodes === null) {
            return [];
        }

        $state = new class
        {
            /**
             * @var array<int, AuditFinding>
             */
            public array $findings = [];
        };

        PhpAst::traverse($nodes, new class($file->path, $state, $this->lifecycle) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $path,
                private object $state,
                private LifecycleAst $lifecycle,
            ) {}

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\ClassMethod || ! $node->name instanceof Node\Identifier) {
                    return null;
                }

                if (! in_array($node->name->toString(), [...LifecycleAst::BEFORE_METHODS, ...LifecycleAst::AFTER_METHODS], true)) {
                    return null;
                }

                foreach ($node->stmts ?? [] as $statement) {
                    $this->inspectLifecycleStatement($statement);
                }

                return null;
            }

            private function inspectLifecycleStatement(Node $node): void
            {
                if ($node instanceof Stmt\If_) {
                    if (! $this->lifecycle->isTechnicalLifecycleCondition($node->cond)) {
                        $this->state->findings[] = new AuditFinding(
                            'error',
                            'eloquent-lifecycle',
                            $this->path,
                            $node->getStartLine(),
                            'Business branching does not belong in observer methods; move the condition to a lifecycle handler or named listener.',
                        );
                    }
                } elseif ($node instanceof Stmt\Foreach_ || $node instanceof Stmt\While_ || $node instanceof Stmt\Switch_) {
                    $this->state->findings[] = new AuditFinding(
                        'warn',
                        'eloquent-lifecycle',
                        $this->path,
                        $node->getStartLine(),
                        'Observer methods should not orchestrate loops or switch logic; move branching rules to a lifecycle handler or named listener.',
                    );
                } elseif ($node instanceof Stmt\Expression && $node->expr instanceof Node\Expr\Match_) {
                    $this->state->findings[] = new AuditFinding(
                        'warn',
                        'eloquent-lifecycle',
                        $this->path,
                        $node->getStartLine(),
                        'Observer methods should not contain match-based orchestration; move branching rules to a lifecycle handler or named listener.',
                    );
                }
            }
        });

        return $state->findings;
    }
}
