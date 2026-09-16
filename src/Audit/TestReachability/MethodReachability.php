<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\TestReachability;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceClass;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceIndex;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Method-scoped traversal. Its results must never seed the class dependency BFS. */
final class MethodReachability
{
    private TestReachabilityResult $result;

    /** @var array<string, ?string> */
    private array $visited = [];

    /** @var array<string, true> */
    private array $active = [];

    private int $nodes = 0;

    public function __construct(private SourceIndex $sources, private FactoryResolver $factories) {}

    public function analyze(string $class, string $method): TestReachabilityResult
    {
        $this->result = new TestReachabilityResult;
        $this->visited = $this->active = [];
        $this->nodes = 0;
        if ($this->sources->method($class, '__construct') !== null) {
            $this->visit($class, '__construct', [], []);
        }
        $this->visit($class, $method, [], []);

        return $this->result;
    }

    /** @param array<int|string, ?string> $arguments
     * @param  list<string>  $trace
     */
    private function visit(string $class, string $method, array $arguments, array $trace): ?string
    {
        $key = strtolower($class.'::'.$method).serialize($arguments);
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
        $vars = ['this' => $class];
        foreach ($node->params as $i => $param) {
            if (is_string($param->var->name)) {
                $vars[$param->var->name] = $arguments[$param->var->name] ?? $arguments[$i] ?? $this->type($source, $param->type);
            }
        }
        $returns = [];
        $this->walk($node->stmts, $source, $vars, $trace, $returns);
        unset($this->active[$key]);

        return $this->visited[$key] = $this->type($source, $node->returnType) ?? (count(array_unique($returns, SORT_REGULAR)) === 1 ? ($returns[0] ?? null) : null);
    }

    /** @param list<Node> $nodes
     * @param  array<string, ?string>  $vars
     * @param  list<string>  $trace
     * @param  list<?string>  $returns
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
                $returns[] = $node->expr !== null ? $this->expression($node->expr, $source, $vars, $trace) : null;

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

    /** @param array<string, ?string> $vars
     * @param  list<string>  $trace
     */
    private function expression(Expr $expr, SourceClass $source, array &$vars, array $trace): ?string
    {
        if (++$this->nodes > 20000) {
            $this->result->incomplete($source->file->path, $expr->getStartLine(), 'Method AST node budget exceeded.');

            return null;
        }
        if ($expr instanceof Expr\Variable) {
            return is_string($expr->name) ? ($vars[$expr->name] ?? null) : null;
        }
        if ($expr instanceof Expr\Closure || $expr instanceof Expr\ArrowFunction) {
            return '@callback';
        }
        if ($expr instanceof Expr\Assign) {
            $value = $this->expression($expr->expr, $source, $vars, $trace);
            if ($expr->var instanceof Expr\Variable && is_string($expr->var->name)) {
                $vars[$expr->var->name] = $value;
            }

            return $value;
        }
        if ($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch) {
            $owner = $this->expression($expr->var, $source, $vars, $trace);

            return $owner !== null && $expr->name instanceof Node\Identifier ? $this->property($owner, $expr->name->toString(), $source->file->path, $expr->getStartLine()) : null;
        }
        if ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall || $expr instanceof Expr\StaticCall || $expr instanceof Expr\New_ || $expr instanceof Expr\FuncCall) {
            if ($expr->isFirstClassCallable()) {
                return '@callback';
            }
            $receiver = match (true) {
                $expr instanceof Expr\MethodCall, $expr instanceof Expr\NullsafeMethodCall => $this->expression($expr->var, $source, $vars, $trace),
                ($expr instanceof Expr\StaticCall || $expr instanceof Expr\New_) && $expr->class instanceof Node\Name => $this->name($source, $expr->class, $vars['this'] ?? $source->name),
                default => null,
            };
            $args = [];
            foreach ($expr->getArgs() as $i => $arg) {
                $args[$arg->name?->toString() ?? $i] = $this->expression($arg->value, $source, $vars, $trace);
            }
            if ($expr instanceof Expr\FuncCall) {
                $function = $expr->name instanceof Node\Name ? $source->file->resolvedName($expr->name) : null;
                $known = $function !== null && ((function_exists($function) && (new \ReflectionFunction($function))->isInternal()) || in_array($function, ['response', 'view', 'redirect', 'route', 'config', 'now', 'today', 'trans', '__', 'abort', 'abort_if', 'abort_unless', 'event', 'dispatch', 'dispatch_sync'], true));
                if (! $known) {
                    $this->result->incomplete($source->file->path, $expr->getStartLine(), 'Unresolved function or callback '.($function ?? '(dynamic)').'.');
                }

                return '@external';
            }
            $method = $expr instanceof Expr\New_ ? '__construct' : ($expr->name instanceof Node\Identifier ? $expr->name->toString() : null);
            if ($receiver === null || $method === null) {
                $this->result->incomplete($source->file->path, $expr->getStartLine(), 'Unresolved receiver or dynamic method.');

                return null;
            }
            if ($method === 'factory') {
                $this->result->merge($this->factories->resolve($receiver, $source->file->path, $expr->getStartLine()));
            }
            if ($this->sources->method($receiver, $method) !== null) {
                if ($method !== '__construct' && $receiver !== ($vars['this'] ?? null) && $this->sources->method($receiver, '__construct') !== null) {
                    $this->visit($receiver, '__construct', [], $trace);
                }
                $return = $this->visit($receiver, $method, $args, $trace);

                return $expr instanceof Expr\New_ ? $receiver : $return;
            }
            if ($expr instanceof Expr\New_) {
                if ($this->sources->get($receiver) !== null) {
                    $this->result->reach($receiver, [...$trace, $receiver.'::__construct']);
                } elseif ($this->sources->inScope($receiver)) {
                    $this->result->incomplete($source->file->path, $expr->getStartLine(), $this->sources->unavailableReason($receiver) ?? 'Constructor source unavailable: '.$receiver);
                }

                return $receiver;
            }
            // Framework calls are the boundary. Project methods above always win.
            if (str_starts_with($receiver, 'Illuminate\\') || str_starts_with($receiver, '@') || $this->sources->isA($receiver, 'Illuminate\\Database\\Eloquent\\Model')) {
                return '@external';
            }
            if ($this->sources->inScope($receiver)) {
                $this->result->incomplete($source->file->path, $expr->getStartLine(), $this->sources->unavailableReason($receiver) ?? 'Unresolved project method '.$receiver.'::'.$method.'.');
            }

            return '@external';
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

    private function property(string $class, string $name, string $path, int $line, int $depth = 0): ?string
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

    private function type(SourceClass $source, Node|string|null $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            return $this->type($source, $type->type);
        }

        return $type instanceof Node\Name ? $this->name($source, $type, $source->name) : null;
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
}
