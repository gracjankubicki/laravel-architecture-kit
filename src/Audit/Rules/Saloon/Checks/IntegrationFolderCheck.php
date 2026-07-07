<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks;

use GracjanKubicki\ArchitectureKit\Audit\Ast\ClassInspector;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\IntegrationPaths;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final readonly class IntegrationFolderCheck implements FileCheck
{
    public function __construct(private IntegrationPaths $paths) {}

    public function findings(FileContext $file): array
    {
        if (! $this->paths->isIntegrationPath($file->path)) {
            return [];
        }

        $nodes = $file->ast();
        $class = $nodes === null ? null : ClassInspector::firstClass($nodes);
        $findings = [];

        if ($this->paths->isIntegrationDtoPath($file->path)) {
            if ($class === null || ! $this->paths->looksLikeIntegrationDto($class)) {
                $findings[] = new AuditFinding(
                    'error',
                    'folder-purity',
                    $file->path,
                    1,
                    'Integration DTOs under app/Http/Integrations/**/Dto/** must be final readonly Data/Dto/Result objects.',
                );
            }

            return $findings;
        }

        if (
            ($class === null || ! ClassInspector::classExtendsAny($class, ['Connector', 'Request', 'SoloRequest']))
            && ! $this->paths->isIntegrationSupportPath($file->path)
        ) {
            $findings[] = new AuditFinding(
                'error',
                'folder-purity',
                $file->path,
                1,
                'app/Http/Integrations/** must contain Saloon Connectors, Requests, integration DTOs, or integration-local support only.',
            );
        }

        if ($nodes !== null) {
            array_push($findings, ...$this->integrationFolderAstFindings($file->path, $nodes));
        }

        return $findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, AuditFinding>
     */
    private function integrationFolderAstFindings(string $path, array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, AuditFinding>
             */
            public array $findings = [];
        };

        PhpAst::traverse($nodes, new class($path, $state) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $path,
                private object $state,
            ) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof StaticCall && $node->class instanceof Name && $node->class->toString() === 'DB') {
                    $this->state->findings[] = new AuditFinding(
                        'error',
                        'folder-purity',
                        $this->path,
                        $node->getStartLine(),
                        'Integrations must not contain business persistence logic; map results in Actions or Jobs.',
                    );
                }

                if ($node instanceof Stmt\UseUse && str_starts_with($node->name->toString(), 'App\\Models\\')) {
                    $this->state->findings[] = new AuditFinding(
                        'error',
                        'folder-purity',
                        $this->path,
                        $node->getStartLine(),
                        'Integrations must not depend on Eloquent models; pass typed input and map results outside the integration boundary.',
                    );
                }

                return null;
            }
        });

        return $state->findings;
    }
}
