<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\Checks;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\IntegrationPaths;

final readonly class AdapterBoundaryCheck implements FileCheck
{
    public function __construct(private IntegrationPaths $paths) {}

    public function findings(FileContext $file): array
    {
        if (! $this->paths->isForbiddenAdapterPath($file->path)) {
            return [];
        }

        $nodes = $file->syntax();

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

        PhpAst::traverse($nodes, new class($file->path, $state, $this->paths) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $path,
                private object $state,
                private IntegrationPaths $paths,
            ) {}

            public function enterNode(Node $node): null
            {
                if (Node::is($node, 'use-item') && $this->paths->isIntegrationName($node->name->toString())) {
                    $this->state->importsIntegration = true;
                    $this->state->findings[] = $this->finding($node, 'Controllers, FormRequests, API Resources, and Models must not import integration classes; call integrations from Actions or queued Jobs.');
                }

                if (Node::is($node, 'expression.new') && Node::is($node->class, 'name') && str_ends_with($node->class->toString(), 'Connector')) {
                    $this->state->findings[] = $this->finding($node, 'Controllers, FormRequests, API Resources, and Models must not instantiate Saloon Connectors; use an Action or queued Job.');
                }

                if (
                    $this->state->importsIntegration
                    && Node::is($node, 'expression.method-call')
                    && Node::is($node->name, 'identifier')
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
