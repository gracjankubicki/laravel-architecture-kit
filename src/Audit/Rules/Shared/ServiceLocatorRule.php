<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\Shared;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\NodeVisitorAbstract;
use Taqie\ArchitectureKit\Audit\AuditRule;
use Taqie\ArchitectureKit\Audit\FileContext;
use Taqie\ArchitectureKit\Support\AuditFinding;
use Taqie\ArchitectureKit\Support\PhpAst;

final readonly class ServiceLocatorRule implements AuditRule
{
    /**
     * @param  array<int, mixed>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return str_starts_with($path, 'app/Http/Controllers/')
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
                'Avoid service locator app(...) in controllers, resources, and payload helpers; prefer explicit dependencies or move behavior behind an enabled architecture boundary.',
            ),
            $this->serviceLocatorCallLines($nodes),
        );
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function serviceLocatorCallLines(array $nodes): array
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
                    $node instanceof FuncCall
                    && $node->name instanceof Name
                    && $node->name->toString() === 'app'
                    && isset($node->args[0])
                    && $node->args[0]->value instanceof Node\Expr\ClassConstFetch
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->lines;
    }

    private function finding(string $severity, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, 'service-locator', $path, $line, $message);
    }
}
