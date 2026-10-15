<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\IntegrationPaths;

final readonly class IntegrationDtoLeakCheck implements FileCheck
{
    private RoleClassifier $roles;

    public function __construct(private IntegrationPaths $paths, ?RoleClassifier $roles = null)
    {
        $this->roles = $roles ?? new RoleClassifier;
    }

    public function findings(FileContext $file): array
    {
        if (
            $this->paths->isIntegrationPath($file->path)
            || $this->paths->isUseCasePath($file->path)
            || $this->roles->classify($file->path, '', 'class') === 'infrastructure'
        ) {
            return [];
        }

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

        PhpAst::traverse($nodes, new class($state, $this->paths) extends NodeVisitorAbstract
        {
            public function __construct(private object $state, private IntegrationPaths $paths) {}

            public function enterNode(Node $node): null
            {
                if (Node::is($node, 'use-item') && $this->isIntegrationDtoName($node->name)) {
                    $this->state->lines[] = $node->getStartLine();
                }

                if (
                    (Node::is($node, 'param') || Node::is($node, 'statement.function') || Node::is($node, 'statement.class-method'))
                    && PhpAst::contains($node, fn (Node $child): bool => Node::is($child, 'name') && $this->isIntegrationDtoName($child))
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }

            private function isIntegrationDtoName(Node $name): bool
            {
                $value = $name->toString();

                return $this->paths->isIntegrationName($value)
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
