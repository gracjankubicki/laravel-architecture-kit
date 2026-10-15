<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Actions;

use Closure;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor as NodeVisitorAbstract;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\PortableAuditRule;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;

final readonly class ActionsRule implements PortableAuditRule
{
    public function __construct(private ?ProjectClassification $classification = null) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return ($this->classification?->kindMatches($path, 'action', str_starts_with($path, 'app/Actions/')) ?? str_starts_with($path, 'app/Actions/'))
            && in_array(Architecture::Actions, $enabled, true);
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
                if (! Node::is($node, 'use-item')) {
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
                if (! Node::is($node, 'statement.class-method') || $node->name->toString() !== 'handle') {
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

    private function finding(string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding('error', 'actions', $path, $line, $message);
    }
}
