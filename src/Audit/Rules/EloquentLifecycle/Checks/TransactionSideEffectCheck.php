<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Traverser as NodeTraverser;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;

final readonly class TransactionSideEffectCheck implements FileCheck
{
    public function findings(FileContext $file): array
    {
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

        PhpAst::traverse($nodes, new class($file, $state) extends NodeVisitorAbstract
        {
            public function __construct(
                private FileContext $file,
                private object $state,
            ) {}

            public function enterNode(Node $node): null
            {
                if (! $this->isDbTransactionCall($node)) {
                    return null;
                }

                $callback = $node->args[0]->value ?? null;

                if (! Node::is($callback, 'expression.closure') && ! Node::is($callback, 'expression.arrow-function')) {
                    return null;
                }

                PhpAst::traverse([$callback], new class($this->file, $this->state) extends NodeVisitorAbstract
                {
                    public function __construct(
                        private FileContext $file,
                        private object $state,
                    ) {}

                    public function enterNode(Node $node): ?int
                    {
                        if ($this->isAfterCommitCall($node)) {
                            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                        }

                        if ($this->isTransactionSideEffect($node)) {
                            $this->state->findings[] = new AuditFinding(
                                'warn',
                                'transaction-side-effects',
                                $this->file->path,
                                $node->getStartLine(),
                                'Do not dispatch events, jobs, notifications, mail, HTTP, or external API calls inside an open DB transaction; move the side effect after commit.',
                            );
                        }

                        return null;
                    }

                    private function isAfterCommitCall(Node $node): bool
                    {
                        return Node::is($node, 'expression.static-call')
                            && Node::is($node->class, 'name')
                            && $this->file->resolvedClassName($node) === 'Illuminate\\Support\\Facades\\DB'
                            && Node::is($node->name, 'identifier')
                            && $node->name->toString() === 'afterCommit';
                    }

                    private function isTransactionSideEffect(Node $node): bool
                    {
                        if (Node::is($node, 'expression.func-call') && Node::is($node->name, 'name')) {
                            return in_array($node->name->toString(), ['event', 'dispatch'], true);
                        }

                        if (Node::is($node, 'expression.static-call') && Node::is($node->name, 'identifier')) {
                            $class = Node::is($node->class, 'name') ? $this->file->resolvedClassName($node) : null;
                            $method = $node->name->toString();

                            if ($class !== null && in_array($class, [
                                'Illuminate\\Support\\Facades\\Event',
                                'Illuminate\\Support\\Facades\\Bus',
                                'Illuminate\\Support\\Facades\\Mail',
                                'Illuminate\\Support\\Facades\\Notification',
                                'Illuminate\\Support\\Facades\\Http',
                            ], true)) {
                                return true;
                            }

                            return $method === 'dispatch'
                                && $class !== null
                                && (str_starts_with($class, 'App\\Events\\') || str_starts_with($class, 'App\\Jobs\\'));
                        }

                        if (Node::is($node, 'expression.method-call') && Node::is($node->name, 'identifier')) {
                            return in_array($node->name->toString(), ['notify', 'send', 'sendAsync'], true);
                        }

                        return false;
                    }
                });

                return null;
            }

            private function isDbTransactionCall(Node $node): bool
            {
                return Node::is($node, 'expression.static-call')
                    && Node::is($node->class, 'name')
                    && $this->file->resolvedClassName($node) === 'Illuminate\\Support\\Facades\\DB'
                    && Node::is($node->name, 'identifier')
                    && $node->name->toString() === 'transaction';
            }
        });

        return $state->findings;
    }
}
