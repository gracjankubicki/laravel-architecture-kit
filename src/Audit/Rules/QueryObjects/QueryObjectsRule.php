<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\QueryObjects;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Audit\AuditRule;
use Taqie\ArchitectureKit\Audit\FileContext;
use Taqie\ArchitectureKit\Support\AuditFinding;
use Taqie\ArchitectureKit\Support\PhpAst;

final readonly class QueryObjectsRule implements AuditRule
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return in_array(Architecture::QueryObjects, $enabled, true)
            && (str_starts_with($path, 'app/Queries/') || str_starts_with($path, 'app/Http/Controllers/'));
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

        if (str_starts_with($file->path, 'app/Queries/')) {
            return $this->queryObjectFindings($file->path, $nodes);
        }

        $line = $this->controllerPrivateQueryLogicLine($nodes);

        if ($line === null) {
            return [];
        }

        return [
            $this->finding(
                'warn',
                $file->path,
                $line,
                'Controller owns non-trivial private read/query logic; move named read behavior to a Query Object.',
            ),
        ];
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, AuditFinding>
     */
    private function queryObjectFindings(string $path, array $nodes): array
    {
        $findings = [];

        foreach ($this->httpUseLines($nodes) as $use) {
            $findings[] = $this->finding('error', $path, $use['line'], $use['message']);
        }

        foreach ($this->handleBoundaryTypeLines($nodes) as $type) {
            $findings[] = $this->finding('error', $path, $type['line'], $type['message']);
        }

        foreach ($this->forbiddenCallLines($nodes) as $call) {
            $findings[] = $this->finding('error', $path, $call['line'], $call['message']);
        }

        return $findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function httpUseLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $uses = [];
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\UseUse) {
                    return null;
                }

                $name = $node->name->toString();

                if (
                    in_array($name, [
                        'Illuminate\\Http\\Request',
                        'Illuminate\\Http\\JsonResponse',
                        'Illuminate\\Http\\RedirectResponse',
                        'Illuminate\\Http\\Response',
                    ], true)
                ) {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Query Objects must not depend on HTTP request or response classes. Map filters in FormRequest/Data first.',
                    ];
                }

                if ($name === 'Illuminate\\Foundation\\Http\\FormRequest') {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Query Objects must not depend on FormRequest classes. Pass a Data Object, Value Object, or explicit typed arguments.',
                    ];
                }

                return null;
            }
        });

        return $state->uses;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function handleBoundaryTypeLines(array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $types = [];
        };

        $containsBoundaryType = fn (Node|string|null $type): bool => $this->containsHttpBoundaryType($type);
        $containsResponseType = fn (Node|string|null $type): bool => $this->containsHttpResponseType($type);

        PhpAst::traverse($nodes, new class($state, $containsBoundaryType, $containsResponseType) extends NodeVisitorAbstract
        {
            public function __construct(
                private object $state,
                private Closure $containsBoundaryType,
                private Closure $containsResponseType,
            ) {}

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\ClassMethod || $node->name->toString() !== 'handle') {
                    return null;
                }

                foreach ($node->params as $parameter) {
                    if (($this->containsBoundaryType)($parameter->type)) {
                        $this->state->types[] = [
                            'line' => $parameter->getStartLine(),
                            'message' => 'Query Object handle() must not accept HTTP request/response types.',
                        ];
                    }
                }

                if (($this->containsResponseType)($node->returnType)) {
                    $this->state->types[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Query Object handle() must not return HTTP response types.',
                    ];
                }

                return null;
            }
        });

        return $state->types;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string}>
     */
    private function forbiddenCallLines(array $nodes): array
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
                if ($node instanceof StaticCall && $node->name instanceof Node\Identifier) {
                    $class = $node->class instanceof Name ? $node->class->toString() : null;
                    $method = $node->name->toString();

                    if ($class === 'DB' && $method === 'transaction') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Query Objects must not own write transactions.',
                        ];
                    }

                    if ($method === 'dispatch') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Query Objects must not dispatch work.',
                        ];
                    }

                    if ($method === 'create') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Query Objects must not mutate data.',
                        ];
                    }
                }

                if (
                    $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['update', 'delete'], true)
                ) {
                    $this->state->calls[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Query Objects must not mutate data.',
                    ];
                }

                return null;
            }
        });

        return $state->calls;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function controllerPrivateQueryLogicLine(array $nodes): ?int
    {
        $state = new class
        {
            public ?int $line = null;
        };

        PhpAst::traverse($nodes, new class($state) extends NodeVisitorAbstract
        {
            public function __construct(private object $state) {}

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Stmt\ClassMethod || ! $node->isPrivate()) {
                    return null;
                }

                if (
                    PhpAst::contains($node, fn (Node $child): bool => $this->isQueryStaticCall($child))
                    && PhpAst::contains($node, fn (Node $child): bool => $this->isWhereMethodCall($child))
                ) {
                    $this->state->line ??= $node->getStartLine();
                }

                return null;
            }

            private function isQueryStaticCall(Node $node): bool
            {
                return $node instanceof StaticCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'query';
            }

            private function isWhereMethodCall(Node $node): bool
            {
                return $node instanceof MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['where', 'whereIn'], true);
            }
        });

        return $state->line;
    }

    private function containsHttpBoundaryType(Node|string|null $type): bool
    {
        foreach ($this->typeNames($type) as $name) {
            if (in_array($this->shortTypeName($name), ['Request', 'FormRequest', 'JsonResponse', 'RedirectResponse', 'StreamedResponse', 'Response'], true)) {
                return true;
            }
        }

        return false;
    }

    private function containsHttpResponseType(Node|string|null $type): bool
    {
        foreach ($this->typeNames($type) as $name) {
            if (in_array($this->shortTypeName($name), ['JsonResponse', 'RedirectResponse', 'StreamedResponse', 'Response'], true)) {
                return true;
            }
        }

        return false;
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

    private function finding(string $severity, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, 'query-objects', $path, $line, $message);
    }
}
