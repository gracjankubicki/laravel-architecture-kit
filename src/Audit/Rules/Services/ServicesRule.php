<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\Services;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Audit\AuditRule;
use Taqie\ArchitectureKit\Audit\FileContext;
use Taqie\ArchitectureKit\Support\AuditFinding;
use Taqie\ArchitectureKit\Support\PhpAst;

final readonly class ServicesRule implements AuditRule
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return str_starts_with($path, 'app/Services/')
            && in_array(Architecture::Services, $enabled, true);
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
        $class = $this->firstClass($nodes);
        $className = $class?->name?->toString() ?? basename($file->path, '.php');

        if (! str_ends_with($className, 'Service')) {
            $findings[] = $this->finding('error', 'services', $file->path, 1, 'Service classes under app/Services/** must use the Service suffix.');
        }

        foreach ($this->httpUseLines($nodes) as $use) {
            $findings[] = $this->finding('error', 'services', $file->path, $use['line'], $use['message']);
        }

        foreach ($this->methodBoundaryTypeLines($nodes) as $type) {
            $findings[] = $this->finding('error', 'services', $file->path, $type['line'], $type['message']);
        }

        foreach ($this->publicStaticMethodLines($nodes) as $line) {
            $findings[] = $this->finding(
                'error',
                'services',
                $file->path,
                $line,
                'Services must not expose public static application behavior; use constructor-injected Services or a more specific pure type.',
            );
        }

        foreach ($this->serviceLocatorCallLines($nodes) as $line) {
            $findings[] = $this->finding(
                'warn',
                'service-locator',
                $file->path,
                $line,
                'Avoid service locator app(...) inside Services; inject collaborators explicitly so the Service stays testable.',
            );
        }

        return $findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function firstClass(array $nodes): ?Stmt\Class_
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Class_) {
                return $node;
            }

            if ($node instanceof Stmt\Namespace_) {
                $class = $this->firstClass($node->stmts);

                if ($class instanceof Stmt\Class_) {
                    return $class;
                }
            }
        }

        return null;
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
                        'message' => 'Services must not depend on HTTP request or response classes. Map HTTP input/output in the adapter layer.',
                    ];
                }

                if ($name === 'Illuminate\\Foundation\\Http\\FormRequest') {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Services must not depend on FormRequest classes. Pass a Data Object, Value Object, model, or explicit typed arguments.',
                    ];
                }

                if (
                    in_array($name, [
                        'Symfony\\Component\\HttpFoundation\\Response',
                        'Symfony\\Component\\HttpFoundation\\StreamedResponse',
                        'Symfony\\Component\\HttpFoundation\\RedirectResponse',
                    ], true)
                ) {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Services must not return Symfony HTTP responses. Return domain/application results and let the controller format HTTP.',
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
    private function methodBoundaryTypeLines(array $nodes): array
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
                if (! $node instanceof Stmt\ClassMethod) {
                    return null;
                }

                foreach ($node->params as $parameter) {
                    if (($this->containsBoundaryType)($parameter->type)) {
                        $this->state->types[] = [
                            'line' => $parameter->getStartLine(),
                            'message' => 'Service methods must not accept HTTP request/response types.',
                        ];
                    }
                }

                if (($this->containsResponseType)($node->returnType)) {
                    $this->state->types[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Service methods must not return HTTP response types.',
                    ];
                }

                return null;
            }
        });

        return $state->types;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, int>
     */
    private function publicStaticMethodLines(array $nodes): array
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
                if ($node instanceof Stmt\ClassMethod && $node->isPublic() && $node->isStatic()) {
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
                    $node instanceof FuncCall
                    && $node->name instanceof Name
                    && $node->name->toString() === 'app'
                    && isset($node->args[0])
                    && $node->args[0]->value instanceof Node\Expr\ClassConstFetch
                ) {
                    $this->state->lines[] = $node->getStartLine();
                }

                return null;
            }
        });

        return $state->lines;
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

    private function finding(string $severity, string $rule, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, $rule, $path, $line, $message);
    }
}
