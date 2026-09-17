<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Inertia;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditRule;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

final readonly class InertiaRule implements AuditRule
{
    /** @param array<int, Architecture|string> $enabled */
    public function supports(string $path, array $enabled): bool
    {
        return in_array(Architecture::Inertia, $enabled, true);
    }

    /** @return array<int, AuditFinding> */
    public function check(FileContext $file): array
    {
        $nodes = $file->ast();

        if ($nodes === null) {
            return [];
        }

        $findings = [];

        if ($this->isApplicationBoundary($file->path)) {
            $line = $this->inertiaDependencyLine($file, $nodes);

            if ($line !== null) {
                $findings[] = new AuditFinding(
                    severity: 'error',
                    rule: 'inertia',
                    path: $file->path,
                    line: $line,
                    message: 'Actions and Query Objects must not depend on Inertia. Return application results or loaded read models and compose the page response in the presentation layer.',
                    code: 'E_INERTIA_LAYER_DEPENDENCY',
                );
            }
        }

        foreach ($this->propExpressions($file, $nodes) as $props) {
            $issues = [];
            $this->collectRequestIssues(
                $props['expression'],
                $props['requestVariables'],
                $props['aliases'],
                true,
                $issues,
            );

            if (
                $issues === []
                && $props['expression'] instanceof Expr\Variable
                && is_string($props['expression']->name)
                && ! isset($props['requestVariables'][$props['expression']->name])
                && ! $this->isResolvedPropsVariable(
                    $props['expression'],
                    $props['requestVariables'],
                    $props['aliases'],
                )
            ) {
                $issues[] = [
                    'status' => 'warn',
                    'line' => $props['expression']->getStartLine(),
                ];
            }

            foreach ($issues as $issue) {
                $error = $issue['status'] === 'error';
                $findings[] = new AuditFinding(
                    severity: $error ? 'error' : 'warn',
                    rule: 'inertia',
                    path: $file->path,
                    line: $issue['line'],
                    message: $error
                        ? 'Inertia props must not receive the whole unfiltered request. Select named fields with validated(), safe(), only(...), input("field"), or collect("field").'
                        : 'Inertia request-derived props could not be resolved to an explicit field selection. Inspect the transformation before treating the payload as filtered.',
                    code: $error
                        ? 'E_INERTIA_UNFILTERED_REQUEST_PROPS'
                        : 'W_INERTIA_REQUEST_PROPS_ANALYSIS_INCOMPLETE',
                );
            }
        }

        return $this->uniqueFindings($findings);
    }

    private function isApplicationBoundary(string $path): bool
    {
        return str_starts_with($path, 'app/Actions/')
            || str_starts_with($path, 'app/Queries/');
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function inertiaDependencyLine(FileContext $file, array $nodes): ?int
    {
        $state = new class
        {
            public ?int $line = null;
        };

        PhpAst::traverse($nodes, new class($file, $state) extends NodeVisitorAbstract
        {
            public function __construct(
                private FileContext $file,
                private object $state,
            ) {}

            public function enterNode(Node $node): ?int
            {
                if (! $node instanceof Name) {
                    return null;
                }

                $resolved = $this->file->resolvedName($node);

                if ($resolved === 'Inertia' || str_starts_with($resolved, 'Inertia\\')) {
                    $this->state->line = $node->getStartLine();

                    return NodeTraverser::STOP_TRAVERSAL;
                }

                return null;
            }
        });

        return $state->line;
    }

    /**
     * @param  array<int, Node>  $nodes
     * @return array<int, array{expression: Node, requestVariables: array<string, true>, aliases: array<string, Node>}>
     */
    private function propExpressions(FileContext $file, array $nodes): array
    {
        $state = new class
        {
            /** @var array<int, array{expression: Node, requestVariables: array<string, true>, aliases: array<string, Node>}> */
            public array $expressions = [];
        };

        PhpAst::traverse($nodes, new class($file, $state) extends NodeVisitorAbstract
        {
            /** @var array<int, array{request: array<string, true>, factory: array<string, true>, aliases: array<string, Node>}> */
            private array $scopes = [
                ['request' => [], 'factory' => [], 'aliases' => []],
            ];

            /** @var array<int, bool> */
            private array $inertiaMiddlewareClasses = [];

            /** @var array<int, bool> */
            private array $inertiaShareMethods = [];

            private int $controlFlowDepth = 0;

            public function __construct(
                private FileContext $file,
                private object $state,
            ) {}

            public function enterNode(Node $node): null
            {
                if ($this->isControlFlowNode($node)) {
                    $this->controlFlowDepth++;
                }

                if ($node instanceof Stmt\Class_) {
                    $this->inertiaMiddlewareClasses[] = $node->extends instanceof Name
                        && $this->file->resolvedName($node->extends) === 'Inertia\Middleware';
                }

                if ($node instanceof Node\FunctionLike) {
                    $this->pushScope($node);
                    $this->inertiaShareMethods[] = $node instanceof Stmt\ClassMethod
                        && $node->name->toString() === 'share'
                        && end($this->inertiaMiddlewareClasses) === true;
                    $scope = $this->scope();
                    $node->setAttribute('architectureKitInertiaRequestVariables', $scope['request']);
                    $node->setAttribute('architectureKitInertiaAliases', $scope['aliases']);
                }

                if (
                    $node instanceof Expr\Variable
                    && is_string($node->name)
                ) {
                    $scope = $this->scope();
                    $node->setAttribute('architectureKitInertiaRequestVariablesAtUse', $scope['request']);
                    $node->setAttribute('architectureKitInertiaFactoryVariablesAtUse', $scope['factory']);
                    $node->setAttribute('architectureKitInertiaAliasesAtUse', $scope['aliases']);
                }

                if ($node instanceof Expr\StaticCall && $this->isInertiaClass($node->class)) {
                    $method = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

                    if ($method === 'render') {
                        $this->append($this->argument($node->args, 'props', 1));
                    } elseif ($method === 'share') {
                        $this->append($this->shareValue($node->args));
                    }
                }

                if (
                    $node instanceof Expr\FuncCall
                    && $node->name instanceof Name
                    && strtolower($node->name->toString()) === 'inertia'
                ) {
                    $this->append($this->argument($node->args, 'props', 1));
                }

                if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
                    $method = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

                    if ($method === 'render' && $this->isFactoryReceiver($node->var)) {
                        $this->append($this->argument($node->args, 'props', 1));
                    } elseif ($method === 'share' && $this->isFactoryReceiver($node->var)) {
                        $this->append($this->shareValue($node->args));
                    }
                }

                if (
                    $node instanceof Stmt\Return_
                    && $node->expr !== null
                    && end($this->inertiaShareMethods) === true
                ) {
                    $this->append($node->expr);
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
                    $index = array_key_last($this->scopes);

                    if ($this->controlFlowDepth > 0) {
                        unset($this->scopes[$index]['aliases'][$node->var->name]);
                    } else {
                        $this->scopes[$index]['aliases'][$node->var->name] = $node->expr;
                    }
                } elseif ($node instanceof Expr\Assign || $node instanceof Expr\AssignOp) {
                    $name = $this->arrayMutationVariable($node->var);

                    if ($name !== null) {
                        $this->recordArrayMutation($name, [$node->expr]);
                    }
                }

                if ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
                    $function = strtolower($node->name->toString());

                    if (in_array($function, ['array_push', 'array_unshift'], true)) {
                        $target = $node->args[0]->value ?? null;
                        $name = $target instanceof Node
                            ? $this->arrayMutationVariable($target)
                            : null;

                        if ($name !== null) {
                            $values = array_map(
                                static fn (Arg $argument): Expr => $argument->value,
                                array_slice($node->args, 1),
                            );
                            $this->recordArrayMutation($name, $values);
                        }
                    }
                }

                if ($node instanceof Node\FunctionLike) {
                    array_pop($this->scopes);
                    array_pop($this->inertiaShareMethods);
                }

                if ($node instanceof Stmt\Class_) {
                    array_pop($this->inertiaMiddlewareClasses);
                }

                if ($this->isControlFlowNode($node)) {
                    $this->controlFlowDepth--;
                }

                return null;
            }

            /** @param array<int, Expr> $values */
            private function recordArrayMutation(string $name, array $values): void
            {
                $index = array_key_last($this->scopes);

                if ($this->controlFlowDepth > 0 || $values === []) {
                    unset($this->scopes[$index]['aliases'][$name]);

                    return;
                }

                $previous = $this->scopes[$index]['aliases'][$name] ?? null;

                if (! $previous instanceof Expr) {
                    unset($this->scopes[$index]['aliases'][$name]);

                    return;
                }

                foreach ($values as $value) {
                    $composed = new Expr\Array_([
                        new Expr\ArrayItem($previous),
                        new Expr\ArrayItem($value),
                    ]);
                    $composed->setAttribute('architectureKitInertiaPreviousPropsSource', $previous);
                    $previous = $composed;
                }

                $this->scopes[$index]['aliases'][$name] = $previous;
            }

            private function isControlFlowNode(Node $node): bool
            {
                return $node instanceof Stmt\If_
                    || $node instanceof Stmt\ElseIf_
                    || $node instanceof Stmt\Else_
                    || $node instanceof Stmt\For_
                    || $node instanceof Stmt\Foreach_
                    || $node instanceof Stmt\While_
                    || $node instanceof Stmt\Do_
                    || $node instanceof Stmt\Switch_
                    || $node instanceof Stmt\Case_
                    || $node instanceof Stmt\TryCatch
                    || $node instanceof Stmt\Catch_
                    || $node instanceof Stmt\Finally_;
            }

            private function arrayMutationVariable(Node $node): ?string
            {
                while ($node instanceof Expr\ArrayDimFetch) {
                    $node = $node->var;
                }

                return $node instanceof Expr\Variable && is_string($node->name)
                    ? $node->name
                    : null;
            }

            /** @param array<string, true> $resolvingAliases */
            private function isFactoryReceiver(Node $node, array $resolvingAliases = []): bool
            {
                if ($node instanceof Expr\Variable && is_string($node->name)) {
                    $factories = $this->factoryVariablesAt($node);

                    if (isset($factories[$node->name])) {
                        return true;
                    }

                    $aliases = $this->aliasesAt($node);

                    if (! isset($aliases[$node->name]) || isset($resolvingAliases[$node->name])) {
                        return false;
                    }

                    $resolvingAliases[$node->name] = true;

                    return $this->isFactoryReceiver($aliases[$node->name], $resolvingAliases);
                }

                return $node instanceof Expr\FuncCall
                    && $node->name instanceof Name
                    && strtolower($node->name->toString()) === 'inertia'
                    && $node->args === [];
            }

            /** @return array<string, true> */
            private function factoryVariablesAt(Expr\Variable $variable): array
            {
                $scope = $this->scope();
                $value = $variable->getAttribute('architectureKitInertiaFactoryVariablesAtUse');

                if (! is_array($value)) {
                    return $scope['factory'];
                }

                $variables = [];

                foreach ($value as $name => $enabled) {
                    if (is_string($name) && $enabled === true) {
                        $variables[$name] = true;
                    }
                }

                return $variables;
            }

            /** @return array<string, Node> */
            private function aliasesAt(Expr\Variable $variable): array
            {
                $scope = $this->scope();
                $value = $variable->getAttribute('architectureKitInertiaAliasesAtUse');

                if (! is_array($value)) {
                    return $scope['aliases'];
                }

                $aliases = [];

                foreach ($value as $name => $expression) {
                    if (is_string($name) && $expression instanceof Node) {
                        $aliases[$name] = $expression;
                    }
                }

                return $aliases;
            }

            private function isInertiaClass(Node $class): bool
            {
                if (! $class instanceof Name) {
                    return false;
                }

                return in_array($this->file->resolvedName($class), [
                    'Inertia\\Inertia',
                    'Inertia\\ResponseFactory',
                ], true);
            }

            /** @param array<int, Arg> $arguments */
            private function shareValue(array $arguments): ?Node
            {
                $named = $this->argument($arguments, 'value', 1);

                if ($named !== null) {
                    return $named;
                }

                return $this->argument($arguments, 'props', 0)
                    ?? $this->argument($arguments, 'key', 0);
            }

            /** @param array<int, Arg> $arguments */
            private function argument(array $arguments, string $name, int $position): ?Node
            {
                foreach ($arguments as $argument) {
                    if ($argument->name?->toString() === $name) {
                        return $argument->value;
                    }
                }

                return $arguments[$position]->value ?? null;
            }

            private function append(?Node $node): void
            {
                if ($node !== null) {
                    $scope = $this->scope();
                    $this->state->expressions[] = [
                        'expression' => $node,
                        'requestVariables' => $scope['request'],
                        'aliases' => $scope['aliases'],
                    ];
                }
            }

            /** @return array{request: array<string, true>, factory: array<string, true>, aliases: array<string, Node>} */
            private function scope(): array
            {
                return $this->scopes[array_key_last($this->scopes)];
            }

            private function pushScope(Node\FunctionLike $function): void
            {
                $parent = $this->scope();
                $scope = ['request' => [], 'factory' => [], 'aliases' => []];

                if ($function instanceof Expr\ArrowFunction) {
                    $scope = $parent;
                } elseif ($function instanceof Expr\Closure) {
                    foreach ($function->uses as $use) {
                        if (! is_string($use->var->name)) {
                            continue;
                        }

                        $name = $use->var->name;

                        foreach (['request', 'factory', 'aliases'] as $key) {
                            if (isset($parent[$key][$name])) {
                                $scope[$key][$name] = $parent[$key][$name];
                            }
                        }
                    }
                }

                foreach ($function->getParams() as $parameter) {
                    if (! $parameter->var instanceof Expr\Variable || ! is_string($parameter->var->name)) {
                        continue;
                    }

                    $name = $parameter->var->name;
                    unset($scope['request'][$name], $scope['factory'][$name], $scope['aliases'][$name]);

                    foreach ($this->typeNames($parameter->type) as $type) {
                        if (
                            in_array($type, ['Illuminate\\Http\\Request', 'Illuminate\\Foundation\\Http\\FormRequest'], true)
                            || str_starts_with($type, 'App\\Http\\Requests\\')
                        ) {
                            $scope['request'][$name] = true;
                        }

                        if ($type === 'Inertia\\ResponseFactory') {
                            $scope['factory'][$name] = true;
                        }
                    }
                }

                $this->scopes[] = $scope;
            }

            /** @return array<int, string> */
            private function typeNames(Node|string|null $type): array
            {
                if ($type instanceof Name) {
                    return [$this->file->resolvedName($type)];
                }

                if ($type instanceof Node\NullableType) {
                    return $this->typeNames($type->type);
                }

                if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
                    $names = [];

                    foreach ($type->types as $inner) {
                        array_push($names, ...$this->typeNames($inner));
                    }

                    return $names;
                }

                return [];
            }
        });

        return $state->expressions;
    }

    /**
     * @param  array<string, true>  $requestVariables
     * @param  array<string, Node>  $aliases
     * @param  array<int, array{status: 'error'|'warn', line: int}>  $issues
     * @param  array<string, true>  $resolvingAliases
     */
    private function collectRequestIssues(
        Node $node,
        array $requestVariables,
        array $aliases,
        bool $direct,
        array &$issues,
        array $resolvingAliases = [],
    ): void {
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            $aliases = $this->aliasesAt($node, $aliases);
            $requestVariables = $this->requestVariablesAt($node, $requestVariables);
        }

        if ($node instanceof Expr\Variable && is_string($node->name) && isset($aliases[$node->name])) {
            if (isset($resolvingAliases[$node->name])) {
                $issues[] = ['status' => 'warn', 'line' => $node->getStartLine()];

                return;
            }

            $resolvingAliases[$node->name] = true;
            $this->collectRequestIssues(
                $aliases[$node->name],
                $requestVariables,
                $aliases,
                $direct,
                $issues,
                $resolvingAliases,
            );

            return;
        }

        $requestState = $this->requestState($node, $requestVariables, $aliases, $resolvingAliases);

        if ($requestState !== null) {
            if ($requestState !== 'filtered') {
                $issues[] = [
                    'status' => $requestState === 'unfiltered' && $direct ? 'error' : 'warn',
                    'line' => $node->getStartLine(),
                ];
            }

            if ($requestState === 'filtered' && ($default = $this->requestDefault($node)) !== null) {
                $this->collectRequestIssues(
                    $default,
                    $requestVariables,
                    $aliases,
                    $direct,
                    $issues,
                    $resolvingAliases,
                );
            }

            return;
        }

        if ($node instanceof Expr\StaticCall && $this->isParentShare($node)) {
            return;
        }

        if ($node instanceof Expr\FuncCall && $this->isTransparentArrayComposition($node)) {
            foreach ($node->args as $argument) {
                $this->collectRequestIssues($argument->value, $requestVariables, $aliases, $direct, $issues, $resolvingAliases);
            }

            return;
        }

        if ($node instanceof Expr\Array_) {
            foreach ($node->items as $item) {
                if ($item !== null) {
                    $this->collectRequestIssues($item->value, $requestVariables, $aliases, $direct, $issues, $resolvingAliases);
                }
            }

            return;
        }

        if ($node instanceof Expr\ArrowFunction) {
            $this->collectRequestIssues(
                $node->expr,
                $node->getAttribute('architectureKitInertiaRequestVariables', $requestVariables),
                $node->getAttribute('architectureKitInertiaAliases', $aliases),
                $direct,
                $issues,
                $resolvingAliases,
            );

            return;
        }

        if ($node instanceof Expr\Closure) {
            $closureRequestVariables = $node->getAttribute('architectureKitInertiaRequestVariables', $requestVariables);
            $closureAliases = $node->getAttribute('architectureKitInertiaAliases', $aliases);

            foreach ($node->stmts as $statement) {
                $this->collectRequestIssues(
                    $statement,
                    $closureRequestVariables,
                    $closureAliases,
                    $direct,
                    $issues,
                    $resolvingAliases,
                );
            }

            return;
        }

        if ($node instanceof Stmt\Return_ && $node->expr !== null) {
            $this->collectRequestIssues($node->expr, $requestVariables, $aliases, $direct, $issues, $resolvingAliases);

            return;
        }

        if ($node instanceof Expr\Assign) {
            $this->collectRequestIssues(
                $node->expr,
                $requestVariables,
                $aliases,
                $direct,
                $issues,
                $resolvingAliases,
            );

            return;
        }

        $indirect = $node instanceof Expr\FuncCall
            || $node instanceof Expr\StaticCall
            || $node instanceof Expr\MethodCall
            || $node instanceof Expr\NullsafeMethodCall
            || $node instanceof Expr\New_;

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node) {
                $this->collectRequestIssues(
                    $value,
                    $requestVariables,
                    $aliases,
                    $indirect ? false : $direct,
                    $issues,
                    $resolvingAliases,
                );
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->collectRequestIssues(
                            $child,
                            $requestVariables,
                            $aliases,
                            $indirect ? false : $direct,
                            $issues,
                            $resolvingAliases,
                        );
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, true>  $requestVariables
     * @param  array<string, Node>  $aliases
     * @param  array<string, true>  $resolvingAliases
     * @return 'filtered'|'unfiltered'|'unknown'|null
     */
    private function requestState(
        Node $node,
        array $requestVariables,
        array $aliases = [],
        array $resolvingAliases = [],
    ): ?string {
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            $aliases = $this->aliasesAt($node, $aliases);
            $requestVariables = $this->requestVariablesAt($node, $requestVariables);

            if (isset($aliases[$node->name])) {
                if (isset($resolvingAliases[$node->name])) {
                    return 'unknown';
                }

                $resolvingAliases[$node->name] = true;

                return $this->requestState(
                    $aliases[$node->name],
                    $requestVariables,
                    $aliases,
                    $resolvingAliases,
                );
            }

            return isset($requestVariables[$node->name]) ? 'unfiltered' : null;
        }

        if ($node instanceof Expr\FuncCall && $this->isRequestHelper($node)) {
            return $this->singleSelectionState(
                $node->args,
                name: 'key',
                arraysAllowed: true,
                emptySelectionIsUnfiltered: true,
            );
        }

        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            $receiver = $this->requestState($node->var, $requestVariables, $aliases, $resolvingAliases);

            if ($receiver === null) {
                return null;
            }

            if ($receiver !== 'unfiltered') {
                return $receiver;
            }

            $method = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

            if ($method === null) {
                return 'unknown';
            }

            if (in_array($method, ['validated', 'safe'], true)) {
                return 'filtered';
            }

            if ($method === 'only') {
                return $node->args === []
                    ? 'filtered'
                    : $this->selectionState($node->args, arraysAllowed: true, emptySelectionIsUnfiltered: false);
            }

            if ($method === 'all') {
                return $this->selectionState($node->args, arraysAllowed: true, emptySelectionIsUnfiltered: true);
            }

            if (in_array($method, ['input', 'collect'], true)) {
                return $this->singleSelectionState(
                    $node->args,
                    name: 'key',
                    arraysAllowed: false,
                    emptySelectionIsUnfiltered: true,
                );
            }

            if ($method === 'toArray') {
                return 'unfiltered';
            }

            if (in_array($method, [
                'user', 'route', 'cookie', 'header', 'boolean', 'integer', 'string',
                'date', 'enum', 'has', 'filled', 'file',
            ], true)) {
                return 'filtered';
            }

            return 'unknown';
        }

        return null;
    }

    /**
     * @param  array<int, Arg>  $arguments
     * @return 'filtered'|'unfiltered'|'unknown'
     */
    private function selectionState(array $arguments, bool $arraysAllowed, bool $emptySelectionIsUnfiltered): string
    {
        if ($arguments === []) {
            return $emptySelectionIsUnfiltered ? 'unfiltered' : 'filtered';
        }

        foreach ($arguments as $argument) {
            if ($this->isNull($argument->value)) {
                return $emptySelectionIsUnfiltered ? 'unfiltered' : 'filtered';
            }

            if (! $this->isLiteralSelection($argument->value, $arraysAllowed)) {
                return 'unknown';
            }

            if ($argument->value instanceof Expr\Array_ && $argument->value->items === []) {
                return $emptySelectionIsUnfiltered ? 'unfiltered' : 'filtered';
            }
        }

        return 'filtered';
    }

    /**
     * @param  array<int, Arg>  $arguments
     * @return 'filtered'|'unfiltered'|'unknown'
     */
    private function singleSelectionState(
        array $arguments,
        string $name,
        bool $arraysAllowed,
        bool $emptySelectionIsUnfiltered,
    ): string {
        $selector = $this->argumentValue($arguments, $name, 0);

        if ($selector === null || $this->isNull($selector)) {
            return $emptySelectionIsUnfiltered ? 'unfiltered' : 'filtered';
        }

        if (! $this->isLiteralSelection($selector, $arraysAllowed)) {
            return 'unknown';
        }

        if ($selector instanceof Expr\Array_ && $selector->items === []) {
            return $emptySelectionIsUnfiltered ? 'unfiltered' : 'filtered';
        }

        return 'filtered';
    }

    private function requestDefault(Node $node): ?Node
    {
        if ($node instanceof Expr\FuncCall && $this->isRequestHelper($node)) {
            return $this->argumentValue($node->args, 'default', 1);
        }

        if (
            ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall)
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'input'
        ) {
            return $this->argumentValue($node->args, 'default', 1);
        }

        return null;
    }

    /** @param array<int, Arg> $arguments */
    private function argumentValue(array $arguments, string $name, int $position): ?Node
    {
        foreach ($arguments as $argument) {
            if ($argument->name?->toString() === $name) {
                return $argument->value;
            }
        }

        return isset($arguments[$position]) && $arguments[$position]->name === null
            ? $arguments[$position]->value
            : null;
    }

    /**
     * @param  array<string, true>  $requestVariables
     * @param  array<string, Node>  $aliases
     * @param  array<string, true>  $resolvingAliases
     */
    private function isResolvedPropsVariable(
        Expr\Variable $variable,
        array $requestVariables,
        array $aliases,
        array $resolvingAliases = [],
    ): bool {
        $aliases = $this->aliasesAt($variable, $aliases);
        $requestVariables = $this->requestVariablesAt($variable, $requestVariables);

        if (! is_string($variable->name) || ! isset($aliases[$variable->name])) {
            return false;
        }

        if (isset($resolvingAliases[$variable->name])) {
            return false;
        }

        $resolvingAliases[$variable->name] = true;
        $expression = $aliases[$variable->name];

        if ($expression instanceof Expr\Array_) {
            $previous = $expression->getAttribute('architectureKitInertiaPreviousPropsSource');

            return ! $previous instanceof Node
                || $this->isResolvedWholePropsExpression(
                    $previous,
                    $requestVariables,
                    $aliases,
                    $resolvingAliases,
                );
        }

        if ($expression instanceof Expr\Variable) {
            return $this->isResolvedPropsVariable(
                $expression,
                $requestVariables,
                $aliases,
                $resolvingAliases,
            );
        }

        return $this->requestState(
            $expression,
            $requestVariables,
            $aliases,
            $resolvingAliases,
        ) === 'filtered';
    }

    /**
     * @param  array<string, true>  $requestVariables
     * @param  array<string, Node>  $aliases
     * @param  array<string, true>  $resolvingAliases
     */
    private function isResolvedWholePropsExpression(
        Node $expression,
        array $requestVariables,
        array $aliases,
        array $resolvingAliases,
    ): bool {
        if ($expression instanceof Expr\Variable) {
            return $this->isResolvedPropsVariable(
                $expression,
                $requestVariables,
                $aliases,
                $resolvingAliases,
            );
        }

        if ($expression instanceof Expr\Array_) {
            $previous = $expression->getAttribute('architectureKitInertiaPreviousPropsSource');

            return ! $previous instanceof Node
                || $this->isResolvedWholePropsExpression(
                    $previous,
                    $requestVariables,
                    $aliases,
                    $resolvingAliases,
                );
        }

        return in_array(
            $this->requestState($expression, $requestVariables, $aliases, $resolvingAliases),
            ['filtered', 'unfiltered'],
            true,
        );
    }

    /**
     * @param  array<string, Node>  $fallback
     * @return array<string, Node>
     */
    private function aliasesAt(Expr\Variable $variable, array $fallback): array
    {
        $value = $variable->getAttribute('architectureKitInertiaAliasesAtUse');

        if (! is_array($value)) {
            return $fallback;
        }

        $aliases = [];

        foreach ($value as $name => $expression) {
            if (is_string($name) && $expression instanceof Node) {
                $aliases[$name] = $expression;
            }
        }

        return $aliases;
    }

    /**
     * @param  array<string, true>  $fallback
     * @return array<string, true>
     */
    private function requestVariablesAt(Expr\Variable $variable, array $fallback): array
    {
        $value = $variable->getAttribute('architectureKitInertiaRequestVariablesAtUse');

        if (! is_array($value)) {
            return $fallback;
        }

        $variables = [];

        foreach ($value as $name => $enabled) {
            if (is_string($name) && $enabled === true) {
                $variables[$name] = true;
            }
        }

        return $variables;
    }

    private function isLiteralSelection(Node $node, bool $arraysAllowed): bool
    {
        if ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_) {
            return true;
        }

        if (! $arraysAllowed || ! $node instanceof Expr\Array_) {
            return false;
        }

        foreach ($node->items as $item) {
            if (
                $item === null
                || $item->unpack
                || ! $item->value instanceof Node\Scalar\String_ && ! $item->value instanceof Node\Scalar\Int_
            ) {
                return false;
            }
        }

        return true;
    }

    private function isNull(Node $node): bool
    {
        return $node instanceof Expr\ConstFetch
            && strtolower($node->name->toString()) === 'null';
    }

    private function isRequestHelper(Expr\FuncCall $call): bool
    {
        return $call->name instanceof Name
            && strtolower($call->name->toString()) === 'request';
    }

    private function isParentShare(Expr\StaticCall $call): bool
    {
        return $call->class instanceof Name
            && strtolower($call->class->toString()) === 'parent'
            && $call->name instanceof Node\Identifier
            && $call->name->toString() === 'share';
    }

    private function isTransparentArrayComposition(Expr\FuncCall $call): bool
    {
        return $call->name instanceof Name
            && in_array(strtolower($call->name->toString()), ['array_merge', 'array_replace'], true);
    }

    /**
     * @param  array<int, AuditFinding>  $findings
     * @return array<int, AuditFinding>
     */
    private function uniqueFindings(array $findings): array
    {
        $unique = [];

        foreach ($findings as $finding) {
            $unique[$finding->code.'|'.$finding->line] = $finding;
        }

        return array_values($unique);
    }
}
