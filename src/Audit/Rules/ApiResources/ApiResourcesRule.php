<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\ApiResources;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeVisitorAbstract;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Audit\Ast\PhpAst;
use Taqie\ArchitectureKit\Audit\AuditFinding;
use Taqie\ArchitectureKit\Audit\AuditRule;
use Taqie\ArchitectureKit\Audit\FileContext;

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
            $this->forbiddenCallFindings($nodes),
        );
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function forbiddenCallFindings(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $findings = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (
                    $node instanceof StaticCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'query'
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

                if ($method === 'where') {
                    $this->state->findings[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'API Resources must format loaded data, not build queries.',
                    ];
                }

                if ($method === 'load') {
                    $this->state->findings[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'API Resources must not trigger loading.',
                    ];
                }

                if ($method === 'loadMissing') {
                    $this->state->findings[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'API Resources must not trigger lazy loading.',
                    ];
                }

                return null;
            }
        });

        return $state->findings;
    }
}
