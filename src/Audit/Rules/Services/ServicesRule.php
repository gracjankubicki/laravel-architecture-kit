<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Services;

use Closure;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\PortableAuditRule;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;

final readonly class ServicesRule implements PortableAuditRule
{
    public function __construct(private ?ProjectClassification $classification = null) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return ($this->classification?->kindMatches($path, 'service', str_starts_with($path, 'app/Services/')) ?? str_starts_with($path, 'app/Services/'))
            && in_array(Architecture::Services, $enabled, true);
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
    private function firstClass(array $nodes): ?Node
    {
        foreach ($nodes as $node) {
            if (Node::is($node, 'statement.class')) {
                return $node;
            }

            if (Node::is($node, 'statement.namespace')) {
                $class = $this->firstClass($node->stmts);

                if (Node::is($class, 'statement.class')) {
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
                if (! Node::is($node, 'use-item')) {
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
                if (! Node::is($node, 'statement.class-method')) {
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
                if (Node::is($node, 'statement.class-method') && $node->isPublic() && $node->isStatic()) {
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

        if (Node::is($type, 'name') || Node::is($type, 'identifier')) {
            return [$type->toString()];
        }

        if (Node::is($type, 'nullable-type')) {
            return $this->typeNames($type->type);
        }

        if (Node::is($type, 'union-type') || Node::is($type, 'intersection-type')) {
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
