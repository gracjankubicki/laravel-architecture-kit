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

final readonly class ObserverBranchingCheck implements FileCheck
{
    public function __construct(private LifecycleAst $lifecycle) {}

    public function findings(FileContext $file): array
    {
        if (! $this->lifecycle->isObserverPath($file->path)) {
            return [];
        }

        $nodes = $file->syntax();

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
                if (! Node::is($node, 'statement.class-method')) {
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
                if (Node::is($node, 'statement.if')) {
                    if (! $this->lifecycle->isTechnicalLifecycleCondition($node->cond)) {
                        $this->state->findings[] = new AuditFinding(
                            'error',
                            'eloquent-lifecycle',
                            $this->path,
                            $node->getStartLine(),
                            'Business branching does not belong in observer methods; move the condition to a lifecycle handler or named listener.',
                        );
                    }
                } elseif (Node::is($node, 'statement.foreach') || Node::is($node, 'statement.while') || Node::is($node, 'statement.switch')) {
                    $this->state->findings[] = new AuditFinding(
                        'warn',
                        'eloquent-lifecycle',
                        $this->path,
                        $node->getStartLine(),
                        'Observer methods should not orchestrate loops or switch logic; move branching rules to a lifecycle handler or named listener.',
                    );
                } elseif (Node::is($node, 'statement.expression') && Node::is($node->expr, 'expression.match')) {
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
