<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\ApiResources;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditRule;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeVisitorAbstract;

final readonly class ApiResourcesRule implements AuditRule
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return str_starts_with($path, 'app/Http/Resources/')
            && in_array(Architecture::ApiResources, $enabled, true);
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
                    $node instanceof StaticCall
                    && $node->class instanceof Node\Name
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'query'
                    && $this->isProjectModel($node->class)
                ) {
                    $this->state->findings[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'API Resources must not query the database.',
                    ];
                }

                if (! $node instanceof MethodCall || ! $node->name instanceof Node\Identifier) {
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

            private function isProjectModel(Node\Name $name): bool
            {
                $class = $this->file->resolvedName($name);

                return str_starts_with($class, 'App\\Models\\');
            }

            private function isModelQueryCall(Node\Expr $receiver): bool
            {
                return $receiver instanceof StaticCall
                    && $receiver->name instanceof Node\Identifier
                    && $receiver->name->toString() === 'query'
                    && $receiver->class instanceof Node\Name
                    && $this->isProjectModel($receiver->class);
            }

            private function isResourceReceiver(Node\Expr $receiver): bool
            {
                return $receiver instanceof Node\Expr\PropertyFetch
                    && $receiver->var instanceof Node\Expr\Variable
                    && $receiver->var->name === 'this'
                    && $receiver->name instanceof Node\Identifier
                    && $receiver->name->toString() === 'resource';
            }
        });

        return $state->findings;
    }
}
