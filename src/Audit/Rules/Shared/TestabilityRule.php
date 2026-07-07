<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Shared;

use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditRule;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final readonly class TestabilityRule implements AuditRule
{
    /**
     * @param  array<int, mixed>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return str_starts_with($path, 'app/Http/Controllers/')
            || str_starts_with($path, 'app/Services/')
            || str_starts_with($path, 'app/Actions/')
            || str_starts_with($path, 'app/Queries/')
            || str_starts_with($path, 'app/Http/Resources/')
            || str_contains($path, 'Payload');
    }

    /**
     * @return array<int, AuditFinding>
     */
    public function check(FileContext $file): array
    {
        $nodes = $file->ast();

        if ($nodes === null) {
            return [];
        }

        return array_map(
            fn (int $line): AuditFinding => $this->finding(
                'warn',
                $file->path,
                $line,
                'Do not replace app(...) with a private static factory that creates a collaborator; inject the dependency or move creation behind an enabled architecture boundary so the code stays testable.',
            ),
            $this->privateStaticFactoryNewLines($nodes),
        );
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function privateStaticFactoryNewLines(array $nodes): array
    {
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
                    ! $node instanceof Stmt\ClassMethod
                    || ! $node->isPrivate()
                    || ! $node->isStatic()
                    || ! PhpAst::contains($node, fn (Node $child): bool => $this->isCollaboratorNewReturn($child))
                ) {
                    return null;
                }

                $this->state->lines[] = $node->getStartLine();

                return null;
            }

            private function isCollaboratorNewReturn(Node $node): bool
            {
                if (! $node instanceof Stmt\Return_ || ! $node->expr instanceof Node\Expr\New_) {
                    return false;
                }

                $class = $node->expr->class;

                return $class instanceof Name
                    && ! in_array($class->toString(), ['self', 'static', 'parent'], true);
            }
        });

        return $state->lines;
    }

    private function finding(string $severity, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, 'testability', $path, $line, $message);
    }
}
