<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;

final readonly class SaloonInsideTransactionCheck implements FileCheck
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

                if (! Node::is($callback, 'expression.closure') && ! Node::is($callback, 'expression.arrow-function')) {
                    return null;
                }

                PhpAst::traverse([$callback], new class($this->state) extends NodeVisitorAbstract
                {
                    public function __construct(private object $state) {}

                    public function enterNode(Node $node): null
                    {
                        if (
                            Node::is($node, 'expression.method-call')
                            && Node::is($node->name, 'identifier')
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
                return Node::is($node, 'expression.static-call')
                    && Node::is($node->class, 'name')
                    && $node->class->toString() === 'DB'
                    && Node::is($node->name, 'identifier')
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
