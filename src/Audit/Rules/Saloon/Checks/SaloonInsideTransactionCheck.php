<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks;

use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\NodeVisitorAbstract;

final readonly class SaloonInsideTransactionCheck implements FileCheck
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
             * @var array<int, int>
             */
            public array $lines = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (! $this->isDbTransactionCall($node)) {
                    return null;
                }

                $callback = $node->args[0]->value ?? null;

                if (! $callback instanceof Node\Expr\Closure && ! $callback instanceof Node\Expr\ArrowFunction) {
                    return null;
                }

                PhpAst::traverse([$callback], new class($this->state) extends NodeVisitorAbstract
                {
                    public function __construct(private object $state) {}

                    public function enterNode(Node $node): null
                    {
                        if (
                            $node instanceof MethodCall
                            && $node->name instanceof Node\Identifier
                            && in_array($node->name->toString(), ['send', 'sendAsync'], true)
                        ) {
                            $this->state->lines[] = $node->getStartLine();
                        }

                        return null;
                    }
                });

                return null;
            }

            private function isDbTransactionCall(Node $node): bool
            {
                return $node instanceof StaticCall
                    && $node->class instanceof Name
                    && $node->class->toString() === 'DB'
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'transaction';
            }
        });

        return array_map(
            fn (int $line): AuditFinding => new AuditFinding(
                'warn',
                'transaction-side-effects',
                $file->path,
                $line,
                'Do not send Saloon requests inside an open DB transaction; move the external API call after commit or to a queued Job.',
            ),
            array_values(array_unique($state->lines)),
        );
    }
}
