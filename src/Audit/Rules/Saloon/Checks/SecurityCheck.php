<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;

final readonly class SecurityCheck implements FileCheck
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
                if (
                    Node::is($node, 'expression.func-call')
                    && Node::is($node->name, 'name')
                    && in_array($node->name->toString(), ['serialize', 'unserialize'], true)
                    && PhpAst::contains($node, fn (Node $child): bool => Node::is($child, 'name') && str_ends_with($child->toString(), 'Authenticator'))
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return array_map(
            fn (int $line): AuditFinding => new AuditFinding(
                'warn',
                'saloon',
                $file->path,
                $line,
                'Do not serialize/unserialize Saloon authenticators; persist token fields explicitly.',
            ),
            array_values(array_unique($state->lines)),
        );
    }
}
