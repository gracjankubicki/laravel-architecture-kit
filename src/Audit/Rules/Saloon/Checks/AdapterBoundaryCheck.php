<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks;

use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\IntegrationPaths;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final readonly class AdapterBoundaryCheck implements FileCheck
{
    public function __construct(private IntegrationPaths $paths) {}

    public function findings(FileContext $file): array
    {
        if (! $this->paths->isForbiddenAdapterPath($file->path)) {
            return [];
        }

        $nodes = $file->ast();

        if ($nodes === null) {
            return [];
        }

        $state = new class
        {
            public bool $importsIntegration = false;

            /**
             * @var array<int, AuditFinding>
             */
            public array $findings = [];
        };

        PhpAst::traverse($nodes, new class($file->path, $state) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $path,
                private object $state,
            ) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof Stmt\UseUse && str_starts_with($node->name->toString(), 'App\\Http\\Integrations\\')) {
                    $this->state->importsIntegration = true;
                    $this->state->findings[] = $this->finding($node, 'Controllers, FormRequests, API Resources, and Models must not import integration classes; call integrations from Actions or queued Jobs.');
                }

                if ($node instanceof New_ && $node->class instanceof Name && str_ends_with($node->class->toString(), 'Connector')) {
                    $this->state->findings[] = $this->finding($node, 'Controllers, FormRequests, API Resources, and Models must not instantiate Saloon Connectors; use an Action or queued Job.');
                }

                if (
                    $this->state->importsIntegration
                    && $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['send', 'sendAsync'], true)
                ) {
                    $this->state->findings[] = $this->finding($node, 'Controllers, FormRequests, API Resources, and Models must not send Saloon requests; move the call to an Action or queued Job.');
                }

                return null;
            }

            private function finding(Node $node, string $message): AuditFinding
            {
                return new AuditFinding('error', 'saloon', $this->path, $node->getStartLine(), $message);
            }
        });

        return $state->findings;
    }
}
