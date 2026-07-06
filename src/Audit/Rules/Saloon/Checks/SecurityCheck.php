<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\Saloon\Checks;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\NodeVisitorAbstract;
use Taqie\ArchitectureKit\Audit\Ast\PhpAst;
use Taqie\ArchitectureKit\Audit\AuditFinding;
use Taqie\ArchitectureKit\Audit\FileCheck;
use Taqie\ArchitectureKit\Audit\FileContext;

final readonly class SecurityCheck implements FileCheck
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
                if (
                    $node instanceof FuncCall
                    && $node->name instanceof Name
                    && in_array($node->name->toString(), ['serialize', 'unserialize'], true)
                    && PhpAst::contains($node, fn (Node $child): bool => $child instanceof Name && str_ends_with($child->toString(), 'Authenticator'))
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
