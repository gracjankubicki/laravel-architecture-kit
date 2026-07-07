<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks;

use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\IntegrationPaths;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final readonly class IntegrationDtoLeakCheck implements FileCheck
{
    public function __construct(private IntegrationPaths $paths) {}

    public function findings(FileContext $file): array
    {
        if ($this->paths->isIntegrationPath($file->path) || $this->paths->isUseCasePath($file->path)) {
            return [];
        }

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
                if ($node instanceof Stmt\UseUse && $this->isIntegrationDtoName($node->name)) {
                    $this->state->lines[] = $node->getStartLine();
                }

                if (
                    ($node instanceof Node\Param || $node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod)
                    && PhpAst::contains($node, fn (Node $child): bool => $child instanceof Name && $this->isIntegrationDtoName($child))
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }

            private function isIntegrationDtoName(Name $name): bool
            {
                $value = $name->toString();

                return str_starts_with($value, 'App\\Http\\Integrations\\')
                    && str_contains($value, '\\Dto\\');
            }
        });

        return array_map(
            fn (int $line): AuditFinding => new AuditFinding(
                'error',
                'saloon',
                $file->path,
                $line,
                'Integration DTOs must not leak outside Actions, Jobs, or app/Http/Integrations/**; map them to domain/application results at the use-case boundary.',
            ),
            array_values(array_unique($state->lines)),
        );
    }
}
