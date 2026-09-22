<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\TestReachability;

use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkContext;
use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkSemantics;
use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkValue;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceClass;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceIndex;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Method-scoped traversal. Its results must never seed the class dependency BFS. */
final class MethodReachability
{
    private TestReachabilityResult $result;

    /** @var array<string, FrameworkValue|null> */
    private array $visited = [];

    /** @var array<string, true> */
    private array $active = [];

    private int $nodes = 0;

    private FrameworkSemantics $framework;

    public function __construct(private SourceIndex $sources, private FactoryResolver $factories, FrameworkContext $context = new FrameworkContext)
    {
        $this->framework = new FrameworkSemantics($sources, $context);
    }

    public function analyze(string $class, string $method, ?string $entryPath = null, int $entryLine = 1): TestReachabilityResult
    {
        $this->result = new TestReachabilityResult;
        $this->visited = $this->active = [];
        $this->nodes = 0;
        $entrypoint = $this->framework->entrypoint($class, $method);
        if ($entrypoint->handled) {
            foreach ($entrypoint->targets as $target) {
                $this->visit($target['class'], $target['method'], $target['arguments'] ?? [], []);
            }
            foreach ($entrypoint->callbacks as $callback) {
                $this->walkCallback($callback, [$class.'::'.$method]);
            }
            if ($entrypoint->incomplete !== null) {
                $origin = $entrypoint->callbacks[0]->callbackSource ?? null;
                $this->result->incomplete($origin?->file->path ?? $entryPath ?? 'routes/web.php', $origin?->node->getStartLine() ?? $entryLine, $entrypoint->incomplete);
            }

            return $this->result;
        }
        if ($this->sources->method($class, '__construct') !== null) {
            $this->visit($class, '__construct', [], []);
        }
        $this->visit($class, $method, [], []);

        return $this->result;
    }

    /** @param array<int|string, FrameworkValue|null> $arguments
     * @param  list<string>  $trace
     */
    private function visit(string $class, string $method, array $arguments, array $trace): ?FrameworkValue
    {
        $key = strtolower($class.'::'.$method).json_encode(array_map(fn (?FrameworkValue $value): array => $this->valueKey($value), $arguments), JSON_THROW_ON_ERROR);
        if (array_key_exists($key, $this->visited)) {
            return $this->visited[$key];
        }
        $source = $this->sources->get($class);
        if ($source === null) {
            return null;
        }
        if (isset($this->active[$key]) || count($trace) >= 12 || count($this->visited) + count($this->active) >= 128) {
            $this->result->incomplete($source->file->path, $source->node->getStartLine(), 'Call cycle or method analysis limit at '.$class.'::'.$method.'.');

            return null;
        }
        $ambiguousTraits = 0;
        if ($source->node->getMethod($method) === null) {
            foreach ($source->node->getTraitUses() as $use) {
                foreach ($use->traits as $trait) {
                    if ($this->sources->method($source->file->resolvedName($trait), $method) !== null) {
                        $ambiguousTraits++;
                    }
                }
            }
        }
        if ($ambiguousTraits > 1) {
            $this->result->incomplete($source->file->path, $source->node->getStartLine(), 'Ambiguous trait method '.$class.'::'.$method.'.');

            return null;
        }
        $found = $this->sources->method($class, $method);
        if ($found === null || $found[1]->stmts === null) {
            $this->result->incomplete($source->file->path, $source->node->getStartLine(), 'No concrete method source for '.$class.'::'.$method.'.');

            return null;
        }
        [$source, $node] = $found;
        $trace[] = $class.'::'.$method;
        $this->result->reach($class, $trace);
        $this->result->reach($source->name, $trace);
        $this->active[$key] = true;
        $vars = ['this' => FrameworkValue::type($class)];
        foreach ($node->params as $i => $param) {
            if (is_string($param->var->name)) {
                $vars[$param->var->name] = $arguments[$param->var->name] ?? $arguments[$i] ?? $this->type($source, $param->type);
            }
        }
        $returns = [];
        $this->walk($node->stmts, $source, $vars, $trace, $returns);
        unset($this->active[$key]);

        $inferred = FrameworkValue::merge($returns);

        return $this->visited[$key] = ($inferred !== null && ! $inferred->isUnknown() ? $inferred : $this->type($source, $node->returnType));
    }

    /** @param list<Node> $nodes
     * @param  array<string, FrameworkValue|null>  $vars
     * @param  list<string>  $trace
     * @param  list<FrameworkValue|null>  $returns
     */
    private function walk(array $nodes, SourceClass $source, array &$vars, array $trace, array &$returns): void
    {
        foreach ($nodes as $node) {
            if (++$this->nodes > 20000) {
                $this->result->incomplete($source->file->path, $node->getStartLine(), 'Method AST node budget exceeded.');

                return;
            }
            if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_) {
                continue;
            }
            if ($node instanceof Stmt\Return_) {
                $value = $node->expr !== null ? $this->expression($node->expr, $source, $vars, $trace) : null;
                $this->followReturnedValue($value, $source, $node, $trace);
                $returns[] = $value;

                return;
            }
            if ($node instanceof Expr) {
                $this->expression($node, $source, $vars, $trace);

                continue;
            }
            if ($node instanceof Stmt\Expression) {
                $this->expression($node->expr, $source, $vars, $trace);

                continue;
            }
            $branch = $vars;
            $changed = [];
            foreach ($node->getSubNodeNames() as $key) {
                $child = $node->$key;
                if ($node instanceof Stmt\If_) {
                    $branch = $vars;
                }
                $this->walk($this->children($child), $source, $branch, $trace, $returns);
                foreach ($branch as $name => $value) {
                    if (($vars[$name] ?? null) !== $value) {
                        $changed[$name] = true;
                    }
                }
            }
            foreach ($changed as $name => $_) {
                $vars[$name] = null;
            }
        }
    }

    /** @param array<string, FrameworkValue|null> $vars
     * @param  list<string>  $trace
     */
    private function expression(Expr $expr, SourceClass $source, array &$vars, array $trace): ?FrameworkValue
    {
        if (++$this->nodes > 20000) {
            $this->result->incomplete($source->file->path, $expr->getStartLine(), 'Method AST node budget exceeded.');

            return null;
        }
        if ($expr instanceof Expr\Variable) {
            return is_string($expr->name) ? ($vars[$expr->name] ?? null) : null;
        }
        if ($expr instanceof Expr\Closure || $expr instanceof Expr\ArrowFunction) {
            return FrameworkValue::callback($source, $expr, $vars);
        }
        if ($expr instanceof Expr\Assign) {
            $value = $this->expression($expr->expr, $source, $vars, $trace);
            if ($expr->var instanceof Expr\Variable && is_string($expr->var->name)) {
                $vars[$expr->var->name] = $value;
            }

            return $value;
        }
        if ($expr instanceof Expr\ClassConstFetch && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier && strtolower($expr->name->toString()) === 'class') {
            $class = $this->name($source, $expr->class, $source->name);

            return new FrameworkValue(type: $class, literal: $class);
        }
        if ($expr instanceof Node\Scalar\String_) {
            return FrameworkValue::scalar($expr->value);
        }
        if ($expr instanceof Expr\Array_) {
            $items = [];
            foreach ($expr->items as $index => $item) {
                if ($item === null) {
                    continue;
                }
                if ($item->key instanceof Expr) {
                    $this->expression($item->key, $source, $vars, $trace);
                }
                $key = $item->key instanceof Node\Scalar\String_ ? $item->key->value : ($item->key instanceof Node\Scalar\Int_ ? $item->key->value : $index);
                $items[$key] = $this->expression($item->value, $source, $vars, $trace);
            }

            return FrameworkValue::array($items);
        }
        if ($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch) {
            $owner = $this->expression($expr->var, $source, $vars, $trace);

            return $owner?->type !== null && $expr->name instanceof Node\Identifier ? $this->property($owner->type, $expr->name->toString(), $source->file->path, $expr->getStartLine()) : null;
        }
        if ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall || $expr instanceof Expr\StaticCall || $expr instanceof Expr\New_ || $expr instanceof Expr\FuncCall) {
            if ($expr->isFirstClassCallable()) {
                return FrameworkValue::type('@callback');
            }
            $receiver = match (true) {
                $expr instanceof Expr\MethodCall, $expr instanceof Expr\NullsafeMethodCall => $this->expression($expr->var, $source, $vars, $trace),
                ($expr instanceof Expr\StaticCall || $expr instanceof Expr\New_) && $expr->class instanceof Node\Name => FrameworkValue::type($this->name($source, $expr->class, isset($vars['this']) ? ($vars['this']->type ?? $source->name) : $source->name)),
                default => null,
            };
            $args = [];
            foreach ($expr->getArgs() as $i => $arg) {
                $args[$arg->name?->toString() ?? $i] = $this->expression($arg->value, $source, $vars, $trace);
            }
            if ($expr instanceof Expr\FuncCall) {
                $function = $expr->name instanceof Node\Name ? strtolower(ltrim($source->file->resolvedName($expr->name), '\\')) : null;
                $framework = $this->framework->describe(null, $function, null, $args, $source, $expr);
                if ($framework->handled) {
                    return $this->applyFramework($framework, $source, $expr, $trace);
                }
                $known = $function !== null && ((function_exists($function) && (new \ReflectionFunction($function))->isInternal()) || in_array($function, ['response', 'view', 'redirect', 'route', 'config', 'now', 'today', 'trans', '__', 'abort', 'abort_if', 'abort_unless', 'event', 'dispatch', 'dispatch_sync'], true));
                if (! $known) {
                    $this->result->incomplete($source->file->path, $expr->getStartLine(), 'Unresolved function or callback '.($function ?? '(dynamic)').'.');
                }

                return FrameworkValue::type('@external');
            }
            $method = $expr instanceof Expr\New_ ? '__construct' : ($expr->name instanceof Node\Identifier ? $expr->name->toString() : null);
            $receiverType = $receiver?->type;
            if ($receiverType === null || $method === null) {
                $this->result->incomplete($source->file->path, $expr->getStartLine(), 'Unresolved receiver or dynamic method.');

                return null;
            }
            if ($method === 'factory') {
                $this->result->merge($this->factories->resolve($receiverType, $source->file->path, $expr->getStartLine()));
            }
            if ($this->sources->method($receiverType, $method) !== null) {
                if ($method !== '__construct' && $receiverType !== (isset($vars['this']) ? $vars['this']->type : null) && $this->sources->method($receiverType, '__construct') !== null) {
                    $this->visit($receiverType, '__construct', [], $trace);
                }
                $return = $this->visit($receiverType, $method, $args, $trace);

                return $expr instanceof Expr\New_ ? $receiver : $return;
            }
            if ($expr instanceof Expr\New_) {
                if ($this->sources->get($receiverType) !== null) {
                    $this->result->reach($receiverType, [...$trace, $receiverType.'::__construct']);
                } elseif ($this->sources->inScope($receiverType)) {
                    $this->result->incomplete($source->file->path, $expr->getStartLine(), $this->sources->unavailableReason($receiverType) ?? 'Constructor source unavailable: '.$receiverType);
                }

                return $this->sources->isA($receiverType, 'Illuminate\Http\Resources\Json\JsonResource')
                    ? FrameworkValue::resource($receiverType, $this->sources->isA($receiverType, 'Illuminate\Http\Resources\Json\ResourceCollection'))
                    : $receiver;
            }
            $framework = $this->framework->describe($receiver, null, $method, $args, $source, $expr);
            if ($framework->handled) {
                return $this->applyFramework($framework, $source, $expr, $trace);
            }
            // Framework calls are the boundary. Project methods above always win.
            if (str_starts_with($receiverType, 'Illuminate\\') || str_starts_with($receiverType, '@') || $this->sources->isA($receiverType, 'Illuminate\\Database\\Eloquent\\Model')) {
                return FrameworkValue::type('@external');
            }
            if ($this->sources->inScope($receiverType)) {
                $this->result->incomplete($source->file->path, $expr->getStartLine(), $this->sources->unavailableReason($receiverType) ?? 'Unresolved project method '.$receiverType.'::'.$method.'.');
            }

            return FrameworkValue::type('@external');
        }
        $branch = $vars;
        foreach ($expr->getSubNodeNames() as $key) {
            $returns = [];
            $this->walk($this->children($expr->$key), $source, $branch, $trace, $returns);
        }
        foreach ($branch as $key => $value) {
            if (($vars[$key] ?? null) !== $value) {
                $vars[$key] = null;
            }
        }

        return null;
    }

    /** @return list<Node> */
    private function children(mixed $value): array
    {
        return $value instanceof Node ? [$value] : (is_array($value) ? array_values(array_filter($value, fn ($node) => $node instanceof Node)) : []);
    }

    private function property(string $class, string $name, string $path, int $line, int $depth = 0): ?FrameworkValue
    {
        if ($depth >= 12 || ($source = $this->sources->get($class)) === null) {
            if (($reason = $this->sources->unavailableReason($class)) !== null) {
                $this->result->incomplete($path, $line, $reason);
            }

            return null;
        }
        foreach ($source->node->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === $name) {
                    return $this->type($source, $property->type);
                }
            }
        }
        foreach ($source->node->getMethod('__construct')->params ?? [] as $param) {
            if ($param->flags !== 0 && $param->var->name === $name) {
                return $this->type($source, $param->type);
            }
        }

        return $source->node instanceof Stmt\Class_ && $source->node->extends !== null ? $this->property($source->file->resolvedName($source->node->extends), $name, $path, $line, $depth + 1) : null;
    }

    private function type(SourceClass $source, Node|string|null $type): ?FrameworkValue
    {
        if ($type instanceof Node\Name) {
            return FrameworkValue::type($this->name($source, $type, $source->name));
        }
        if ($type instanceof Node\NullableType) {
            return $this->type($source, $type->type)?->nullable();
        }
        if ($type instanceof Node\UnionType) {
            $values = [];
            foreach ($type->types as $part) {
                if ($part instanceof Node\Identifier && strtolower($part->toString()) === 'null') {
                    $values[] = null;
                } elseif (($value = $this->type($source, $part)) !== null) {
                    $values[] = $value;
                }
            }

            return FrameworkValue::union($values);
        }
        if ($type instanceof Node\Identifier) {
            return match (strtolower($type->toString())) {
                'null' => FrameworkValue::scalar()->nullable(),
                'mixed', 'object', 'callable', 'iterable' => null,
                default => FrameworkValue::scalar(),
            };
        }

        return null;
    }

    private function name(SourceClass $source, Node\Name $name, string $runtime): string
    {
        if (strtolower($name->toString()) === 'static') {
            return $runtime;
        }
        if (strtolower($name->toString()) === 'self') {
            return $source->name;
        }
        if (strtolower($name->toString()) === 'parent' && $source->node instanceof Stmt\Class_ && $source->node->extends !== null) {
            return $source->file->resolvedName($source->node->extends);
        }

        return $source->file->resolvedName($name);
    }

    /** @param list<string> $trace */
    private function applyFramework(object $result, SourceClass $source, Node $expr, array $trace): ?FrameworkValue
    {
        foreach ($result->targets as $target) {
            $this->visit($target['class'], $target['method'], $target['arguments'] ?? [], $trace);
        }
        $callbackResults = [];
        foreach ($result->callbacks as $callback) {
            $callbackResults[] = $this->walkCallback($callback, $trace);
        }
        if ($result->incomplete !== null) {
            $this->result->incomplete($source->file->path, $expr->getStartLine(), $result->incomplete);
        }

        return $this->resolveCallbackResults($result->value, $callbackResults);
    }

    /** @param list<string> $trace */
    private function followReturnedValue(?FrameworkValue $value, SourceClass $source, Node $node, array $trace): void
    {
        if ($value === null) {
            return;
        }
        if ($value->resourceClass !== null) {
            $result = $this->framework->describe($value, null, '@return', [], $source, $node);
            if ($result->handled) {
                $this->applyFramework($result, $source, $node, $trace);
            }
        }
        foreach ($value->items as $item) {
            $this->followReturnedValue($item, $source, $node, $trace);
        }
        $this->followReturnedValue($value->element, $source, $node, $trace);
    }

    /** @param list<string> $trace */
    private function walkCallback(FrameworkValue $value, array $trace): ?FrameworkValue
    {
        if ($value->callback === null || $value->callbackSource === null) {
            return null;
        }
        $vars = $value->captures;
        foreach ($value->callback->params as $index => $param) {
            if (is_string($param->var->name)) {
                $vars[$param->var->name] = $value->parameterTypes[$param->var->name]
                    ?? $value->parameterTypes[$index]
                    ?? $this->type($value->callbackSource, $param->type)
                    ?? FrameworkValue::unknown();
            }
        }
        $returns = [];
        $this->walk($value->callback instanceof Expr\Closure ? $value->callback->stmts : [$value->callback->expr], $value->callbackSource, $vars, [...$trace, '{callback}'], $returns);

        return FrameworkValue::merge($returns);
    }

    /** @param list<FrameworkValue|null> $results */
    private function resolveCallbackResults(?FrameworkValue $value, array $results): ?FrameworkValue
    {
        if ($value === null) {
            return null;
        }
        if ($value->type === '@callback-result') {
            return $results[0] ?? FrameworkValue::unknown();
        }
        if ($value->element?->type === '@callback-result') {
            return FrameworkValue::collection($results[0] ?? FrameworkValue::unknown());
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function valueKey(?FrameworkValue $value): array
    {
        if ($value === null) {
            return [
                'type' => null,
                'literal' => null,
                'resource' => null,
                'collection' => false,
                'callback' => null,
                'element' => null,
                'nullable' => false,
                'callback_result' => null,
                'parameters' => [],
                'possible_types' => [],
                'unknown' => false,
            ];
        }

        return [
            'type' => $value->type,
            'literal' => $value->literal,
            'resource' => $value->resourceClass,
            'collection' => $value->resourceCollection,
            'callback' => $value->callback?->getStartLine(),
            'element' => $value->element === null ? null : $this->valueKey($value->element),
            'nullable' => $value->nullable,
            'callback_result' => $value->callbackResult === null ? null : $this->valueKey($value->callbackResult),
            'parameters' => array_map(fn (?FrameworkValue $parameter): array => $this->valueKey($parameter), $value->parameterTypes),
            'possible_types' => $value->possibleTypes,
            'unknown' => $value->unknown,
        ];
    }
}
