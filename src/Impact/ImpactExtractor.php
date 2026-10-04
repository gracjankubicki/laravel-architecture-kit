<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/** Per-file extraction with no application execution or retained AST. */
final class ImpactExtractor
{
    /** @var list<array<string, mixed>> */
    private array $calls = [];

    /** @var list<array<string, mixed>> */
    private array $notices = [];

    private int $nodes = 0;

    private bool $limited = false;

    public function extract(FileContext $file): ImpactFacts
    {
        $this->calls = $this->notices = [];
        $this->nodes = 0;
        $this->limited = false;
        $classes = [];
        if (($reason = self::sourceLimit(strlen($file->contents))) !== null) {
            return new ImpactFacts($file->path, [], [], [['line' => 1, 'reason' => $reason]]);
        }
        $ast = $file->ast();
        if ($ast === null) {
            return new ImpactFacts($file->path, [], [], [['line' => 1, 'reason' => 'Unparseable source.']]);
        }
        foreach ((new NodeFinder)->findInstanceOf($ast, Stmt\ClassLike::class) as $class) {
            if (! isset($class->namespacedName)) {
                $this->notices[] = ['line' => $class->getStartLine(), 'reason' => 'Anonymous class dispatch is unresolved.'];

                continue;
            }
            $name = $class->namespacedName->toString();
            $parents = [];
            if ($class instanceof Stmt\Class_ && $class->extends !== null) {
                $parents[] = $file->resolvedName($class->extends);
            }
            foreach ($class instanceof Stmt\Interface_ ? $class->extends : ($class instanceof Stmt\Class_ || $class instanceof Stmt\Enum_ ? $class->implements : []) as $parent) {
                $parents[] = $file->resolvedName($parent);
            }
            $traits = [];
            $adaptations = false;
            foreach ($class->getTraitUses() as $use) {
                $adaptations = $adaptations || $use->adaptations !== [];
                foreach ($use->traits as $trait) {
                    $traits[] = $file->resolvedName($trait);
                }
            }
            $properties = [];
            foreach ($class->getProperties() as $property) {
                $type = $this->type($file, $property->type, $name, $parents[0] ?? null);
                if ($type !== null) {
                    foreach ($property->props as $prop) {
                        $properties[$prop->name->toString()] = $type;
                    }
                }
            }
            $constructor = $class->getMethod('__construct');
            foreach ($constructor->params ?? [] as $param) {
                if ($param->flags !== 0 && is_string($param->var->name) && ($type = $this->type($file, $param->type, $name, $parents[0] ?? null)) !== null) {
                    $properties[$param->var->name] = $type;
                }
            }
            $methods = [];
            foreach ($class->getMethods() as $method) {
                $methods[strtolower($method->name->toString())] = ['name' => $method->name->toString(), 'line' => $method->getStartLine(), 'final' => $method->isFinal()];
                $vars = $method->isStatic() ? [] : ['this' => ['type' => $name, 'exact' => false]];
                foreach ($method->params as $param) {
                    if (is_string($param->var->name)) {
                        $vars[$param->var->name] = ['type' => $this->type($file, $param->type, $name, $parents[0] ?? null), 'exact' => false];
                    }
                }
                $this->walk($method->stmts ?? [], $file, $name, $name.'::'.$method->name->toString(), $parents[0] ?? null, $properties, $vars);
            }
            $classes[$name] = ['kind' => $class instanceof Stmt\Interface_ ? 'interface' : ($class instanceof Stmt\Trait_ ? 'trait' : 'class'),
                'line' => $class->getStartLine(), 'final' => $class instanceof Stmt\Class_ && $class->isFinal(), 'parents' => $parents,
                'traits' => $traits, 'adaptations' => $adaptations, 'properties' => $properties, 'methods' => $methods];
        }
        $vars = [];
        $this->walk($ast, $file, '', '(file) '.$file->path, null, [], $vars);

        return new ImpactFacts($file->path, $classes, $this->calls, $this->notices);
    }

    /** Check before allocating either source or parser nodes. */
    public static function sourceLimit(int $bytes): ?string
    {
        if ($bytes > 100_000) {
            return 'Impact source size limit exceeded.';
        }
        $memoryLimit = MemoryLimit::bytes();
        if ($memoryLimit !== null && memory_get_usage(true) + $bytes * 200 + 262144 > $memoryLimit * 0.65) {
            return 'Impact memory limit reached during extraction.';
        }

        return null;
    }

    /** @param list<Node> $nodes
     * @param  array<string, string>  $properties
     * @param  array<string, array{type: ?string, exact: bool}>  $vars
     */
    private function walk(array $nodes, FileContext $file, string $class, string $from, ?string $parent, array $properties, array &$vars): void
    {
        foreach ($nodes as $node) {
            if (++$this->nodes > 20000) {
                if (! $this->limited) {
                    $this->notices[] = ['line' => $node->getStartLine(), 'reason' => 'Per-file impact AST limit reached.'];
                    $this->limited = true;
                }

                return;
            }
            if ($node instanceof Stmt\Function_) {
                $this->notices[] = ['line' => $node->getStartLine(), 'reason' => 'Standalone function bodies are outside method dispatch analysis.'];

                continue;
            }
            if ($node instanceof Stmt\ClassLike) {
                continue;
            }
            if ($node instanceof Expr) {
                $this->expression($node, $file, $class, $from, $parent, $properties, $vars);

                continue;
            }
            if ($node instanceof Stmt\Unset_) {
                foreach ($node->vars as $var) {
                    if ($var instanceof Expr\Variable && is_string($var->name)) {
                        $vars[$var->name] = ['type' => null, 'exact' => false];
                    }
                }
            }
            if ($node instanceof Stmt\Foreach_) {
                foreach ([$node->keyVar, $node->valueVar] as $var) {
                    if ($var instanceof Expr\Variable && is_string($var->name)) {
                        $vars[$var->name] = ['type' => null, 'exact' => false];
                    }
                }
            }
            if ($node instanceof Stmt\Expression) {
                $this->expression($node->expr, $file, $class, $from, $parent, $properties, $vars);

                continue;
            }
            $branched = $node instanceof Stmt\If_ || $node instanceof Stmt\Switch_ || $node instanceof Stmt\TryCatch || $node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\While_ || $node instanceof Stmt\Do_;
            // Conditions execute before branch bodies. Loops may revisit the body
            // after any write in the loop, so never seed it with a stale local type.
            $keys = $node->getSubNodeNames();
            if ($node instanceof Stmt\If_ || $node instanceof Stmt\Switch_) {
                $this->expression($node->cond, $file, $class, $from, $parent, $properties, $vars);
                $keys = array_values(array_diff($keys, ['cond']));
            }
            if ($node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\While_ || $node instanceof Stmt\Do_) {
                foreach ((new NodeFinder)->find([$node], static fn (Node $child): bool => $child instanceof Expr\Assign || $child instanceof Expr\AssignRef || $child instanceof Expr\AssignOp || $child instanceof Expr\PreInc || $child instanceof Expr\PostInc || $child instanceof Expr\PreDec || $child instanceof Expr\PostDec || $child instanceof Expr\CallLike || $child instanceof Stmt\Unset_ || $child instanceof Stmt\Foreach_) as $write) {
                    if ($write instanceof Expr\AssignRef) {
                        // An alias may mutate any local on the next iteration.
                        foreach (array_keys($vars) as $var) {
                            if ($var !== 'this') {
                                $vars[$var] = ['type' => null, 'exact' => false];
                            }
                        }

                        continue;
                    }
                    if ($write instanceof Expr\CallLike) {
                        $targets = $write->isFirstClassCallable() ? [] : array_map(static fn (Node\Arg $arg): Expr => $arg->value, $write->getArgs());
                    } elseif ($write instanceof Stmt\Unset_) {
                        $targets = $write->vars;
                    } elseif ($write instanceof Stmt\Foreach_) {
                        $targets = [$write->keyVar, $write->valueVar];
                    } else {
                        /** @var Expr\Assign|Expr\AssignOp|Expr\PreInc|Expr\PostInc|Expr\PreDec|Expr\PostDec $write */
                        $targets = [$write->var];
                        if (! ($write->var instanceof Expr\Variable && is_string($write->var->name)) && ! $write->var instanceof Expr\PropertyFetch) {
                            // Destructuring/dynamic targets can overwrite any local.
                            foreach (array_keys($vars) as $var) {
                                if ($var !== 'this') {
                                    $vars[$var] = ['type' => null, 'exact' => false];
                                }
                            }
                        }
                    }
                    foreach ($targets as $target) {
                        if ($target instanceof Expr\Variable && is_string($target->name)) {
                            $vars[$target->name] = ['type' => null, 'exact' => false];
                        }
                    }
                }
            }
            $changed = [];
            foreach ($keys as $key) {
                $branch = $vars;
                $this->walk($this->children($node->$key), $file, $class, $from, $parent, $properties, $branch);
                if (! $branched) {
                    $vars = $branch;
                } else {
                    foreach (array_unique([...array_keys($vars), ...array_keys($branch)]) as $var) {
                        if (($vars[$var] ?? null) !== ($branch[$var] ?? null)) {
                            $changed[$var] = true;
                        }
                    }
                }
            }
            foreach ($changed as $var => $_) {
                $vars[$var] = ['type' => null, 'exact' => false];
            }
        }
    }

    /** @param array<string, string> $properties
     * @param  array<string, array{type: ?string, exact: bool}>  $vars
     * @return array{type: ?string, exact: bool}
     */
    private function expression(Expr $expr, FileContext $file, string $class, string $from, ?string $parent, array $properties, array &$vars): array
    {
        $unknown = ['type' => null, 'exact' => false];
        if (++$this->nodes > 20000) {
            if (! $this->limited) {
                $this->notices[] = ['line' => $expr->getStartLine(), 'reason' => 'Per-file impact AST limit reached.'];
                $this->limited = true;
            }

            return $unknown;
        }
        if ($expr instanceof Expr\Variable) {
            return is_string($expr->name) ? ($vars[$expr->name] ?? $unknown) : $unknown;
        }
        if ($expr instanceof Expr\Closure || $expr instanceof Expr\ArrowFunction) {
            $this->notices[] = ['line' => $expr->getStartLine(), 'reason' => 'Callback body is not a proved immediate invocation.'];

            return $unknown;
        }
        if ($expr instanceof Expr\Assign || $expr instanceof Expr\AssignRef) {
            $value = $this->expression($expr->expr, $file, $class, $from, $parent, $properties, $vars);
            if ($expr instanceof Expr\AssignRef) {
                foreach (array_keys($vars) as $var) {
                    if ($var !== 'this') {
                        $vars[$var] = $unknown;
                    }
                }

                return $unknown;
            }
            if ($expr->var instanceof Expr\Variable && is_string($expr->var->name)) {
                $vars[$expr->var->name] = $value;
            } elseif ($expr->var instanceof Expr\PropertyFetch && $expr->var->name instanceof Node\Identifier) {
                $owner = $this->expression($expr->var->var, $file, $class, $from, $parent, $properties, $vars);
                if ($owner['type'] !== null) {
                    $vars['@property:'.$owner['type'].'#'.$expr->var->name->toString()] = $unknown;
                }
            } else {
                // Destructuring or dynamic write targets cannot retain previous locals.
                foreach (array_keys($vars) as $var) {
                    if ($var !== 'this') {
                        $vars[$var] = $unknown;
                    }
                }
            }

            return $value;
        }
        if ($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch) {
            $owner = $this->expression($expr->var, $file, $class, $from, $parent, $properties, $vars);
            if ($owner['type'] !== null && $expr->name instanceof Node\Identifier) {
                $prop = $expr->name->toString();
                if (isset($vars['@property:'.$owner['type'].'#'.$prop])) {
                    return $vars['@property:'.$owner['type'].'#'.$prop];
                }

                return ['type' => $owner['type'] === $class && isset($properties[$prop]) ? $properties[$prop] : '@property:'.$owner['type'].'#'.$prop, 'exact' => false];
            }

            return $unknown;
        }
        if ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall || $expr instanceof Expr\StaticCall || $expr instanceof Expr\New_) {
            $receiver = $expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall
                ? $this->expression($expr->var, $file, $class, $from, $parent, $properties, $vars)
                : ['type' => $expr->class instanceof Node\Name ? $this->type($file, $expr->class, $class, $parent) : null, 'exact' => $expr->class instanceof Node\Name && strtolower($expr->class->toString()) !== 'static'];
            $method = $expr instanceof Expr\New_ ? '__construct' : ($expr->name instanceof Node\Identifier ? $expr->name->toString() : null);
            $this->calls[] = ['from' => $from, 'receiver' => $receiver['type'], 'method' => $method, 'exact' => $receiver['exact'],
                'kind' => $expr->isFirstClassCallable() ? 'reference' : 'call', 'line' => $expr->getStartLine()];
            if (! $expr->isFirstClassCallable()) {
                foreach ($expr->getArgs() as $arg) {
                    $this->expression($arg->value, $file, $class, $from, $parent, $properties, $vars);
                    // Application calls may mutate arguments by reference. Do not carry stale types.
                    if ($arg->value instanceof Expr\Variable && is_string($arg->value->name)) {
                        $vars[$arg->value->name] = $unknown;
                    }
                }
            }

            return $expr instanceof Expr\New_ ? $receiver : $unknown;
        }
        if ($expr instanceof Expr\Array_ && count($expr->items) === 2) {
            $first = $expr->items[0];
            $second = $expr->items[1];
            if ($first !== null && $second?->value instanceof Node\Scalar\String_
                && ($first->key === null || ($first->key instanceof Node\Scalar\Int_ && $first->key->value === 0))
                && ($second->key === null || ($second->key instanceof Node\Scalar\Int_ && $second->key->value === 1))) {
                $value = $first->value;
                $receiver = $value instanceof Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strtolower($value->name->toString()) === 'class'
                    ? ['type' => $this->type($file, $value->class, $class, $parent), 'exact' => true]
                    : $this->expression($value, $file, $class, $from, $parent, $properties, $vars);
                if ($receiver['type'] !== null) {
                    $this->calls[] = ['from' => $from, 'receiver' => $receiver['type'], 'method' => $second->value->value, 'exact' => $receiver['exact'], 'kind' => 'reference', 'line' => $expr->getStartLine()];

                    return $unknown;
                }
            }
        }
        $branching = $expr instanceof Expr\Ternary || $expr instanceof Expr\Match_ || $expr instanceof Expr\BinaryOp\BooleanAnd || $expr instanceof Expr\BinaryOp\BooleanOr || $expr instanceof Expr\BinaryOp\Coalesce;
        foreach ($expr->getSubNodeNames() as $key) {
            $branch = $vars;
            $this->walk($this->children($expr->$key), $file, $class, $from, $parent, $properties, $branch);
            if ($branching) {
                foreach (array_keys($branch) as $var) {
                    if ($branch[$var] !== ($vars[$var] ?? null)) {
                        $vars[$var] = $unknown;
                    }
                }
            } else {
                $vars = $branch;
            }
        }
        if ($expr instanceof Expr\FuncCall) {
            foreach ($expr->getArgs() as $arg) {
                if ($arg->value instanceof Expr\Variable && is_string($arg->value->name)) {
                    $vars[$arg->value->name] = $unknown;
                }
            }
        }
        if ($expr instanceof Expr\AssignOp || $expr instanceof Expr\PreInc || $expr instanceof Expr\PostInc || $expr instanceof Expr\PreDec || $expr instanceof Expr\PostDec) {
            if ($expr->var instanceof Expr\Variable && is_string($expr->var->name)) {
                $vars[$expr->var->name] = $unknown;
            }
        }

        return $unknown;
    }

    private function type(FileContext $file, Node|string|null $type, string $class, ?string $parent): ?string
    {
        if ($type instanceof Node\NullableType) {
            return $this->type($file, $type->type, $class, $parent);
        }
        if (! $type instanceof Node\Name) {
            return null;
        }

        return match (strtolower($type->toString())) {
            'self', 'static' => $class !== '' ? $class : null,
            'parent' => $parent,
            default => $file->resolvedName($type),
        };
    }

    /** @return list<Node> */
    private function children(mixed $value): array
    {
        return $value instanceof Node ? [$value] : (is_array($value) ? array_values(array_filter($value, fn ($v): bool => $v instanceof Node)) : []);
    }
}
