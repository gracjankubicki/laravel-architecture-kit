<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\Actions;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Audit\Ast\PhpAst;
use Taqie\ArchitectureKit\Audit\AuditFinding;
use Taqie\ArchitectureKit\Audit\AuditRule;
use Taqie\ArchitectureKit\Audit\FileContext;

final readonly class ActionsRule implements AuditRule
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return str_starts_with($path, 'app/Actions/')
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

        foreach ($this->httpUseLines($nodes) as $use) {
            $findings[] = $this->finding($file->path, $use['line'], $use['message']);
        }

        foreach ($this->handleHttpTypeLines($nodes) as $type) {
            $findings[] = $this->finding($file->path, $type['line'], $type['message']);
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

                if (in_array($name, ['Illuminate\\Http\\Request', 'Illuminate\\Http\\JsonResponse', 'Illuminate\\Http\\RedirectResponse', 'Illuminate\\Http\\Response'], true)) {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Actions must not depend on HTTP request or response classes. Map HTTP input/output in the adapter layer.',
                    ];
                }

                if ($name === 'Illuminate\\Foundation\\Http\\FormRequest') {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Actions must not depend on FormRequest classes. Pass a Data Object, Value Object, or explicit typed arguments.',
                    ];
                }

                if (in_array($name, ['Symfony\\Component\\HttpFoundation\\Response', 'Symfony\\Component\\HttpFoundation\\StreamedResponse', 'Symfony\\Component\\HttpFoundation\\RedirectResponse'], true)) {
                    $this->state->uses[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Actions must not return Symfony HTTP responses. Return domain/application results and let the controller format HTTP.',
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
    private function handleHttpTypeLines(array $nodes): array
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
                            'message' => 'Action handle() must not accept or return HTTP request/response types.',
                        ];
                    }
                }

                if (($this->containsResponseType)($node->returnType)) {
                    $this->state->types[] = [
                        'line' => $node->getStartLine(),
                        'message' => 'Action handle() must not return HTTP response types.',
                    ];
                }

                return null;
            }
        });

        return $state->types;
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

    private function finding(string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding('error', 'actions', $path, $line, $message);
    }
}
