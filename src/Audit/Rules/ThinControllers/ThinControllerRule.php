<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\ThinControllers;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Audit\Ast\PhpAst;
use Taqie\ArchitectureKit\Audit\AuditFinding;
use Taqie\ArchitectureKit\Audit\AuditRule;
use Taqie\ArchitectureKit\Audit\FileContext;

final readonly class ThinControllerRule implements AuditRule
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return str_starts_with($path, 'app/Http/Controllers/')
            && in_array(Architecture::Actions, $enabled, true);
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

        $findings = [];

        foreach ($this->workflowCallLines($nodes) as $call) {
            $findings[] = $this->finding('error', $file->path, $call['line'], $call['message']);
        }

        foreach ($this->serviceUseLines($nodes) as $line) {
            $findings[] = $this->finding(
                'warn',
                $file->path,
                $line,
                'Controller depends on an App\\Services class while Actions are enabled; prefer routing write use cases through an Action.',
            );
        }

        foreach ($this->serviceInjectionLines($nodes) as $line) {
            $findings[] = $this->finding(
                'warn',
                $file->path,
                $line,
                'Controller injects a Service while Actions are enabled; prefer routing write use cases through an Action.',
            );
        }

        return $findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function workflowCallLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $calls = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof MethodCall && $node->name instanceof Node\Identifier) {
                    $method = $node->name->toString();

                    if ($method === 'validate') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller performs inline validation; use a FormRequest.',
                        ];
                    }

                    if ($method === 'update') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller mutates a model directly; move the write use case to an Action.',
                        ];
                    }

                    if ($method === 'delete') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller deletes a model directly; move the write use case to an Action.',
                        ];
                    }
                }

                if ($node instanceof StaticCall && $node->name instanceof Node\Identifier) {
                    $class = $node->class instanceof Name ? $node->class->toString() : null;
                    $method = $node->name->toString();

                    if ($class === 'DB' && $method === 'transaction') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller owns a transaction; move the workflow to an Action.',
                        ];
                    }

                    if ($method === 'dispatch') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller dispatches work directly; move the workflow to an Action.',
                        ];
                    }

                    if ($method === 'create') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller creates a model directly; move the write use case to an Action.',
                        ];
                    }
                }

                return null;
            }
        });

        return $state->calls;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function serviceUseLines(array $nodes): array
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
                if ($node instanceof Stmt\UseUse && str_starts_with($node->name->toString(), 'App\\Services\\')) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->lines;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function serviceInjectionLines(array $nodes): array
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
                if (! $node instanceof Node\Param) {
                    return null;
                }

                foreach ($this->typeNames($node->type) as $name) {
                    if (str_ends_with($this->shortTypeName($name), 'Service')) {
                        $this->state->lines[] = $node->getStartLine();

                        break;
                    }
                }

                return null;
            }

            /**
             * @return array<int, string>
             */
            private function typeNames(Node|string|null $type): array
            {
                if ($type === null) {
                    return [];
                }

                if (is_string($type)) {
                    return [$type];
                }

                if ($type instanceof Name || $type instanceof Node\Identifier) {
                    return [$type->toString()];
                }

                if ($type instanceof Node\NullableType) {
                    return $this->typeNames($type->type);
                }

                if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
                    $names = [];

                    foreach ($type->types as $innerType) {
                        array_push($names, ...$this->typeNames($innerType));
                    }

                    return $names;
                }

                return [];
            }

            private function shortTypeName(string $name): string
            {
                $parts = explode('\\', $name);

                return $parts[count($parts) - 1];
            }
        });

        return $state->lines;
    }

    private function finding(string $severity, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, 'thin-controller', $path, $line, $message);
    }
}
