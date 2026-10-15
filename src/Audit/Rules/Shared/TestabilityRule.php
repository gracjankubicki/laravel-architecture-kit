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

final readonly class TestabilityRule implements PortableAuditRule
{
    public function __construct(private ?ProjectClassification $classification = null) {}

    /**
     * @param  array<int, mixed>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return ($this->classification?->kindMatches($path, 'controller', str_starts_with($path, 'app/Http/Controllers/')) ?? str_starts_with($path, 'app/Http/Controllers/'))
            || ($this->classification?->kindMatches($path, 'service', str_starts_with($path, 'app/Services/')) ?? str_starts_with($path, 'app/Services/'))
            || ($this->classification?->kindMatches($path, 'action', str_starts_with($path, 'app/Actions/')) ?? str_starts_with($path, 'app/Actions/'))
            || ($this->classification?->kindMatches($path, 'query', str_starts_with($path, 'app/Queries/')) ?? str_starts_with($path, 'app/Queries/'))
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
                    ! Node::is($node, 'statement.class-method')
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
                if (! Node::is($node, 'statement.return') || ! Node::is($node->expr, 'expression.new')) {
                    return false;
                }

                $class = $node->expr->class;

                return Node::is($class, 'name')
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
