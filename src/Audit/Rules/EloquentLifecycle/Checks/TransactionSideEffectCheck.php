<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks;

use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

final readonly class TransactionSideEffectCheck implements FileCheck
{
    public function findings(FileContext $file): array
    {
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

                if (! $callback instanceof Node\Expr\Closure && ! $callback instanceof Node\Expr\ArrowFunction) {
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
                        return $node instanceof StaticCall
                            && $node->class instanceof Name
                            && $this->file->resolvedClassName($node) === 'Illuminate\\Support\\Facades\\DB'
                            && $node->name instanceof Node\Identifier
                            && $node->name->toString() === 'afterCommit';
                    }

                    private function isTransactionSideEffect(Node $node): bool
                    {
                        if ($node instanceof FuncCall && $node->name instanceof Name) {
                            return in_array($node->name->toString(), ['event', 'dispatch'], true);
                        }

                        if ($node instanceof StaticCall && $node->name instanceof Node\Identifier) {
                            $class = $node->class instanceof Name ? $this->file->resolvedClassName($node) : null;
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

                        if ($node instanceof MethodCall && $node->name instanceof Node\Identifier) {
                            return in_array($node->name->toString(), ['notify', 'send', 'sendAsync'], true);
                        }

                        return false;
                    }
                });

                return null;
            }

            private function isDbTransactionCall(Node $node): bool
            {
                return $node instanceof StaticCall
                    && $node->class instanceof Name
                    && $this->file->resolvedClassName($node) === 'Illuminate\\Support\\Facades\\DB'
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'transaction';
            }
        });

        return $state->findings;
    }
}
