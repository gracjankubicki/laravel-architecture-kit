<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\ApiResources;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\PortableAuditRule;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;

final readonly class ApiResourcesRule implements PortableAuditRule
{
    public function __construct(private ?ProjectClassification $classification = null) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return ($this->classification?->kindMatches($path, 'resource', str_starts_with($path, 'app/Http/Resources/')) ?? str_starts_with($path, 'app/Http/Resources/'))
            && in_array(Architecture::ApiResources, $enabled, true);
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
            fn (array $finding): AuditFinding => new AuditFinding(
                'error',
                'api-resource',
                $file->path,
                $finding['line'],
                $finding['message'],
            ),
            $this->forbiddenCallFindings($file, $nodes),
        );
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function forbiddenCallFindings(FileContext $file, array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $findings = [];
        };

        PhpAst::traverse($nodes, new class($file, $state) extends NodeVisitorAbstract
        {
            public function __construct(
                private FileContext $file,
                private object $state,
            ) {}

            public function enterNode(Node $node): null
            {
                if (
                    Node::is($node, 'expression.static-call')
                    && Node::is($node->class, 'name')
                    && Node::is($node->name, 'identifier')
                    && $node->name->toString() === 'query'
                    && $this->isProjectModel($node->class)
                ) {
                    $this->state->findings[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'API Resources must not query the database.',
                    ];
                }

                if (! Node::is($node, 'expression.method-call') || ! Node::is($node->name, 'identifier')) {
                    return null;
                }

                $method = $node->name->toString();

                if ($method === 'where' && $this->isModelQueryCall($node->var)) {
                    $this->state->findings[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'API Resources must format loaded data, not build queries.',
                    ];
                }

                if ($method === 'load' && $this->isResourceReceiver($node->var)) {
                    $this->state->findings[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'API Resources must not trigger loading.',
                    ];
                }

                if ($method === 'loadMissing' && $this->isResourceReceiver($node->var)) {
                    $this->state->findings[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'API Resources must not trigger lazy loading.',
                    ];
                }

                return null;
            }

            private function isProjectModel(Node $name): bool
            {
                $class = $this->file->resolvedName($name);

                return str_starts_with($class, 'App\\Models\\');
            }

            private function isModelQueryCall(Node $receiver): bool
            {
                return Node::is($receiver, 'expression.static-call')
                    && Node::is($receiver->name, 'identifier')
                    && $receiver->name->toString() === 'query'
                    && Node::is($receiver->class, 'name')
                    && $this->isProjectModel($receiver->class);
            }

            private function isResourceReceiver(Node $receiver): bool
            {
                return Node::is($receiver, 'expression.property-fetch')
                    && Node::is($receiver->var, 'expression.variable')
                    && $receiver->var->name === 'this'
                    && Node::is($receiver->name, 'identifier')
                    && $receiver->name->toString() === 'resource';
            }
        });

        return $state->findings;
    }
}
