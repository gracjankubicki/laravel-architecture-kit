<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\ThinControllers;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditRule;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final readonly class ThinControllerRule implements AuditRule
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function __construct(
        private array $enabled,
    ) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return str_starts_with($path, 'app/Http/Controllers/')
            && in_array(Architecture::ThinControllers, $enabled, true);
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

        foreach ($this->workflowCallLines($file, $nodes) as $call) {
            $findings[] = $this->finding('error', $file->path, $call['line'], $call['message'], $call['code'] ?? null);
        }

        if (in_array(Architecture::Actions, $this->enabled, true)) {
            foreach ($this->serviceUseLines($nodes) as $line) {
                $findings[] = $this->finding(
                    'warn',
                    $file->path,
                    $line,
                    'Controller depends on an App\\Services class while Actions are enabled; prefer routing write use cases through an Action.',
                    'W_THIN_CONTROLLER_SERVICE_DEPENDENCY',
                );
            }

            foreach ($this->serviceInjectionLines($nodes) as $line) {
                $findings[] = $this->finding(
                    'warn',
                    $file->path,
                    $line,
                    'Controller injects a Service while Actions are enabled; prefer routing write use cases through an Action.',
                    'W_THIN_CONTROLLER_SERVICE_DEPENDENCY',
                );
            }
        }

        return $findings;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{line: int, message: string, code?: string}>
     */
    private function workflowCallLines(FileContext $file, array $nodes): array
    {
        $state = new class
        {
            /**
             * @var array<int, array{line: int, message: string}>
             */
            public array $calls = [];
        };

        PhpAst::traverse($nodes, new class($file, $state) extends NodeVisitorAbstract
        {
            /** @var array<int, array<string, true>> */
            private array $modelParameterScopes = [];

            public function __construct(
                private FileContext $file,
                private object $state,
            ) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof Stmt\ClassMethod) {
                    $this->modelParameterScopes[] = $this->modelParameters($node);

                    return null;
                }

                if ($node instanceof MethodCall && $node->name instanceof Node\Identifier) {
                    $method = $node->name->toString();

                    if ($method === 'validate') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller performs inline validation; use a FormRequest.',
                            'code' => 'E_THIN_CONTROLLER_INLINE_VALIDATION',
                        ];
                    }

                    if ($method === 'update' && $this->isModelParameter($node->var)) {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller mutates a model directly; move the write use case to an Action.',
                            'code' => 'E_THIN_CONTROLLER_MODEL_WRITE',
                        ];
                    }

                    if ($method === 'delete' && $this->isModelParameter($node->var)) {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller deletes a model directly; move the write use case to an Action.',
                            'code' => 'E_THIN_CONTROLLER_MODEL_WRITE',
                        ];
                    }
                }

                if ($node instanceof StaticCall && $node->name instanceof Node\Identifier) {
                    $class = $node->class instanceof Name ? $this->file->resolvedName($node->class) : null;
                    $method = $node->name->toString();

                    if ($class === 'Illuminate\\Support\\Facades\\DB' && $method === 'transaction') {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller owns a transaction; move the workflow to an Action.',
                            'code' => 'E_THIN_CONTROLLER_TRANSACTION',
                        ];
                    }

                    if ($method === 'dispatch' && $class !== null && $this->isWorkflowDispatchTarget($class)) {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller dispatches work directly; move the workflow to an Action.',
                            'code' => 'E_THIN_CONTROLLER_DISPATCH',
                        ];
                    }

                    if ($method === 'create' && $class !== null && str_starts_with($class, 'App\\Models\\')) {
                        $this->state->calls[] = [
                            'line' => $node->getStartLine(),
                            'message' => 'Controller creates a model directly; move the write use case to an Action.',
                            'code' => 'E_THIN_CONTROLLER_MODEL_WRITE',
                        ];
                    }
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Stmt\ClassMethod) {
                    array_pop($this->modelParameterScopes);
                }

                return null;
            }

            /** @return array<string, true> */
            private function modelParameters(Stmt\ClassMethod $method): array
            {
                $parameters = [];

                foreach ($method->params as $parameter) {
                    if (
                        ! $parameter->type instanceof Name
                        || ! is_string($parameter->var->name)
                        || ! str_starts_with($this->file->resolvedName($parameter->type), 'App\\Models\\')
                    ) {
                        continue;
                    }

                    $parameters[$parameter->var->name] = true;
                }

                return $parameters;
            }

            private function isModelParameter(Node\Expr $receiver): bool
            {
                if (! $receiver instanceof Node\Expr\Variable || ! is_string($receiver->name)) {
                    return false;
                }

                $scope = $this->modelParameterScopes[array_key_last($this->modelParameterScopes)] ?? [];

                return isset($scope[$receiver->name]);
            }

            private function isWorkflowDispatchTarget(string $class): bool
            {
                return str_starts_with($class, 'App\\Jobs\\') || str_starts_with($class, 'App\\Events\\');
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
                    if (str_starts_with($name, 'App\\Services\\')) {
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

                if ($type instanceof Name) {
                    $resolved = $type->getAttribute('resolvedName');

                    return [$resolved instanceof Name ? $resolved->toString() : $type->toString()];
                }

                if ($type instanceof Node\Identifier) {
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
        });

        return $state->lines;
    }

    private function finding(string $severity, string $path, int $line, string $message, ?string $code = null): AuditFinding
    {
        return new AuditFinding($severity, 'thin-controller', $path, $line, $message, code: $code);
    }
}
