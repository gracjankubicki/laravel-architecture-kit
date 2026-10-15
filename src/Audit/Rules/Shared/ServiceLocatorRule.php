<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Shared;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\PortableAuditRule;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;

final readonly class ServiceLocatorRule implements PortableAuditRule
{
    public function __construct(private ?ProjectClassification $classification = null) {}

    /**
     * @param  array<int, mixed>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return ($this->classification?->kindMatches($path, 'controller', str_starts_with($path, 'app/Http/Controllers/')) ?? str_starts_with($path, 'app/Http/Controllers/'))
            || ($this->classification?->kindMatches($path, 'resource', str_starts_with($path, 'app/Http/Resources/')) ?? str_starts_with($path, 'app/Http/Resources/'))
            || str_contains($path, 'Payload');
    }

    /**
     * @return array<int, AuditFinding>
     */
    public function check(FileContext $file): array
    {
        $nodes = $file->syntax();

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
                    Node::is($node, 'expression.func-call')
                    && Node::is($node->name, 'name')
                    && $node->name->toString() === 'app'
                    && isset($node->args[0])
                    && Node::is($node->args[0]->value, 'expression.class-const-fetch')
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
