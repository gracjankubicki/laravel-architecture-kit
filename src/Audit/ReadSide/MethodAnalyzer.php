<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/** Conservative, bounded interpretation of calls; never executes application PHP. */
final class MethodAnalyzer
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';

    private const QUERY = 'Illuminate\\Database\\Query\\Builder';

    private const RELATION = 'Illuminate\\Database\\Eloquent\\Relations\\Relation';

    private const DB = 'Illuminate\\Support\\Facades\\DB';

    /** @var list<string> */
    private const WRITES = ['save', 'savequietly', 'saveorfail', 'saveorrestore', 'update', 'updatequietly', 'updateorfail', 'delete', 'deletequietly', 'deleteorfail', 'destroy', 'forcedelete', 'forcedeletequietly', 'forcedestroy', 'restore', 'restorequietly', 'create', 'createquietly', 'createorfirst', 'firstorcreate', 'updateorcreate', 'updateorinsert', 'insert', 'insertgetid', 'insertorignore', 'insertusing', 'insertorignoreusing', 'upsert', 'increment', 'decrement', 'incrementeach', 'decrementeach', 'incrementquietly', 'decrementquietly', 'touch', 'touchquietly', 'push', 'pushquietly', 'truncate'];

    /** @var list<string> */
    private const CHAIN = ['query', 'newquery', 'newmodelquery', 'where', 'orwhere', 'wherenull', 'wherenotnull', 'wherein', 'wherenotin', 'wherebetween', 'wheredate', 'whereyear', 'wheremonth', 'wherehas', 'orwherehas', 'has', 'doesnthave', 'wheredoesnthave', 'with', 'without', 'withcount', 'withsum', 'withavg', 'orderby', 'orderbydesc', 'latest', 'oldest', 'limit', 'take', 'skip', 'offset', 'select', 'addselect', 'distinct', 'groupby', 'having', 'join', 'leftjoin', 'rightjoin', 'withoutglobalscopes', 'withoutglobalscope', 'withtrashed', 'onlytrashed', 'usewritepdo', 'lockforupdate', 'sharedlock'];

    /** @var list<string> */
    private const READS = ['get', 'all', 'first', 'firstorfail', 'find', 'findorfail', 'findmany', 'value', 'solevalue', 'pluck', 'count', 'exists', 'doesntexist', 'sum', 'avg', 'min', 'max', 'paginate', 'simplepaginate', 'cursorpaginate', 'cursor', 'lazy', 'tosql', 'torawsql', 'getbindings'];

    /** @var list<string> */
    private const RELATIONS = ['hasone', 'hasmany', 'belongsto', 'belongstomany', 'morphone', 'morphmany', 'morphto', 'morphtomany', 'morphedbymany', 'hasmanythrough', 'hasonethrough'];

    private MethodEffects $result;

    private int $nodes = 0;

    private int $calls = 0;

    /** @var array<string, true> */
    private array $active = [];

    public function __construct(private SourceIndex $sources) {}

    public function analyze(string $class, string $method): MethodEffects
    {
        $this->result = new MethodEffects;
        $this->nodes = $this->calls = 0;
        $this->active = [];
        if ($this->sources->method($class, '__construct') !== null) {
            $this->visitMethod($class, '__construct', [], []);
        }
        $this->visitMethod($class, $method, [], []);

        return $this->result;
    }

    /**
     * @param  array<int|string, ?string>  $arguments
     * @param  list<string>  $trace
     */
    private function visitMethod(string $class, string $method, array $arguments, array $trace): ?string
    {
        $found = $this->sources->method($class, $method);
        if ($found === null) {
            return null;
        }
        [$source, $node] = $found;
        $key = strtolower($class.'::'.$method);
        if (isset($this->active[$key]) || count($trace) >= 12 || ++$this->calls > 128) {
            $this->result->add('unknown', $source, $node->getStartLine(), 'Call cycle or analysis limit reached at '.$key, $trace);

            return null;
        }
        if ($node->stmts === null) {
            $this->result->add('unknown', $source, $node->getStartLine(), 'No concrete implementation for '.$key, $trace);

            return null;
        }
        $this->active[$key] = true;
        $trace[] = $class.'::'.$method;
        $variables = ['this' => $class];
        foreach ($node->params as $i => $param) {
            if (is_string($param->var->name)) {
                $argument = $arguments[$param->var->name] ?? $arguments[$i] ?? null;
                $variables[$param->var->name] = $argument ?? $this->type($source, $param->type);
            }
        }
        $returns = [];
        $this->walk($node->stmts, $source, $variables, $trace, $returns);
        unset($this->active[$key]);

        return $this->type($source, $node->returnType) ?? (count(array_unique($returns, SORT_REGULAR)) === 1 ? ($returns[0] ?? null) : null);
    }

    /**
     * @param  list<Node>  $nodes
     * @param  array<string, ?string>  $variables
     * @param  list<string>  $trace
     * @param  list<?string>  $returns
     */
    private function walk(array $nodes, SourceClass $source, array &$variables, array $trace, array &$returns): void
    {
        foreach ($nodes as $node) {
            if (++$this->nodes > 20_000) {
                $this->result->add('unknown', $source, $node->getStartLine(), 'AST node limit reached.', $trace);

                return;
            }
            if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_) {
                continue;
            }
            if ($node instanceof Stmt\If_) {
                $this->expression($node->cond, $source, $variables, $trace);
                $branches = [];
                $branch = $variables;
                $this->walk($node->stmts, $source, $branch, $trace, $returns);
                $branches[] = $branch;
                foreach ($node->elseifs as $elseif) {
                    $branch = $variables;
                    $this->expression($elseif->cond, $source, $branch, $trace);
                    $this->walk($elseif->stmts, $source, $branch, $trace, $returns);
                    $branches[] = $branch;
                }
                $branch = $variables;
                $this->walk($node->else->stmts ?? [], $source, $branch, $trace, $returns);
                $branches[] = $branch;
                $this->mergeVariables($variables, $branches);

                continue;
            }
            if ($node instanceof Stmt\Return_) {
                $returns[] = $node->expr === null ? '@scalar' : $this->expression($node->expr, $source, $variables, $trace);

                return;
            } elseif ($node instanceof Expr) {
                $this->expression($node, $source, $variables, $trace);
            } elseif ($node instanceof Stmt\Expression) {
                $this->expression($node->expr, $source, $variables, $trace);
            } else {
                // Inspect every branch but do not let a conditional assignment become
                // an unconditional type fact for a later call.
                $branch = $variables;
                foreach ($node->getSubNodeNames() as $name) {
                    $child = $node->$name;
                    $children = $child instanceof Node ? [$child] : (is_array($child) ? array_values(array_filter($child, fn ($n) => $n instanceof Node)) : []);
                    $this->walk($children, $source, $branch, $trace, $returns);
                }
                foreach (array_unique([...array_keys($variables), ...array_keys($branch)]) as $name) {
                    if (($variables[$name] ?? null) !== ($branch[$name] ?? null)) {
                        $variables[$name] = null;
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, ?string>  $variables
     * @param  list<string>  $trace
     */
    private function expression(Expr $expr, SourceClass $source, array &$variables, array $trace): ?string
    {
        if (++$this->nodes > 20_000) {
            $this->result->add('unknown', $source, $expr->getStartLine(), 'AST node limit reached.', $trace);

            return null;
        }
        if ($expr instanceof Expr\Variable) {
            return is_string($expr->name) ? ($variables[$expr->name] ?? null) : null;
        }
        if ($expr instanceof Expr\Closure || $expr instanceof Expr\ArrowFunction) {
            return '@closure';
        }
        if ($expr instanceof Expr\Ternary) {
            $condition = $this->expression($expr->cond, $source, $variables, $trace);
            $yes = $no = $variables;
            $left = $expr->if !== null ? $this->expression($expr->if, $source, $yes, $trace) : $condition;
            $right = $this->expression($expr->else, $source, $no, $trace);
            $this->mergeVariables($variables, [$yes, $no]);

            return $left === $right ? $left : null;
        }
        if ($expr instanceof Expr\BinaryOp\BooleanAnd || $expr instanceof Expr\BinaryOp\BooleanOr || $expr instanceof Expr\BinaryOp\LogicalAnd || $expr instanceof Expr\BinaryOp\LogicalOr || $expr instanceof Expr\BinaryOp\Coalesce) {
            $left = $this->expression($expr->left, $source, $variables, $trace);
            $branch = $variables;
            $right = $this->expression($expr->right, $source, $branch, $trace);
            $this->mergeVariables($variables, [$variables, $branch]);

            return $expr instanceof Expr\BinaryOp\Coalesce ? ($left === $right ? $left : null) : '@scalar';
        }
        if ($expr instanceof Expr\Match_) {
            $this->expression($expr->cond, $source, $variables, $trace);
            $branches = $types = [];
            foreach ($expr->arms as $arm) {
                $branch = $variables;
                foreach ($arm->conds ?? [] as $condition) {
                    $this->expression($condition, $source, $branch, $trace);
                }
                $types[] = $this->expression($arm->body, $source, $branch, $trace);
                $branches[] = $branch;
            }
            $this->mergeVariables($variables, $branches);

            return count(array_unique($types, SORT_REGULAR)) === 1 ? ($types[0] ?? null) : null;
        }
        if ($expr instanceof Expr\Assign) {
            $type = $this->expression($expr->expr, $source, $variables, $trace);
            if ($expr->var instanceof Expr\Variable && is_string($expr->var->name)) {
                $variables[$expr->var->name] = $type;
            } else {
                $this->expression($expr->var, $source, $variables, $trace);
            }

            return $type;
        }
        if ($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch) {
            $owner = $this->expression($expr->var, $source, $variables, $trace);
            if ($expr->name instanceof Expr) {
                $this->expression($expr->name, $source, $variables, $trace);
                $this->unknown($source, $expr, 'Dynamic property access.', $trace);
            }

            return $owner !== null && $expr->name instanceof Node\Identifier ? $this->property($owner, $expr->name->toString()) : null;
        }
        if ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall || $expr instanceof Expr\StaticCall || $expr instanceof Expr\New_ || $expr instanceof Expr\FuncCall) {
            $receiver = null;
            if ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall) {
                $receiver = $this->expression($expr->var, $source, $variables, $trace);
            } elseif (($expr instanceof Expr\StaticCall || $expr instanceof Expr\New_) && $expr->class instanceof Name) {
                $receiver = $this->className($source, $expr->class);
            } elseif (($expr instanceof Expr\StaticCall || $expr instanceof Expr\New_) && $expr->class instanceof Expr) {
                $this->expression($expr->class, $source, $variables, $trace);
            }
            if (! $expr instanceof Expr\New_ && $expr->name instanceof Expr) {
                $this->expression($expr->name, $source, $variables, $trace);
            }
            if ($expr->isFirstClassCallable()) {
                return '@closure';
            }
            $arguments = [];
            $unpacked = false;
            foreach ($expr->getArgs() as $i => $arg) {
                $arguments[$arg->name?->toString() ?? $i] = $this->expression($arg->value, $source, $variables, $trace);
                if ($arg->unpack) {
                    $unpacked = true;
                    $this->unknown($source, $expr, 'Unpacked call arguments cannot be resolved.', $trace);
                }
            }
            if ($unpacked) {
                $arguments = [];
            }
            if ($expr instanceof Expr\New_) {
                if ($receiver === null || $this->sources->get($receiver) === null) {
                    $this->unknown($source, $expr, 'Unresolved constructor.', $trace);
                } elseif ($this->sources->method($receiver, '__construct') !== null) {
                    $this->visitMethod($receiver, '__construct', $arguments, $trace);
                }

                return $receiver;
            }
            if ($expr instanceof Expr\FuncCall) {
                $function = $expr->name instanceof Name ? strtolower($expr->name->toString()) : '';
                if (in_array($function, ['event', 'dispatch', 'dispatch_sync'], true)) {
                    $this->result->add('effect', $source, $expr->getStartLine(), $function.'()', $trace);
                } elseif ($function === 'response') {
                    return '@response';
                } elseif (! in_array($function, ['count', 'strlen', 'strtolower', 'strtoupper', 'trim', 'ltrim', 'rtrim', 'sprintf', 'implode', 'explode', 'in_array', 'array_key_exists', 'array_values', 'array_keys', 'array_unique', 'array_merge', 'is_null', 'is_string', 'is_int', 'is_array', 'abs', 'round', 'min', 'max', 'config', 'now', 'today', 'trans', '__'], true)) {
                    $this->unknown($source, $expr, 'Unresolved function/callback '.$function.'().', $trace);
                }

                return '@scalar';
            }
            if ($receiver !== null && str_starts_with($receiver, 'App\\Services\\') && str_starts_with($source->file->path, 'app/Http/Controllers/')) {
                $this->result->services[strtolower($receiver)] ??= ['type' => $receiver, 'line' => $expr->getStartLine(), 'path' => $source->file->path];
            }
            $method = $expr->name instanceof Node\Identifier ? $expr->name->toString() : null;
            if ($receiver === null || $method === null) {
                $this->unknown($source, $expr, 'Unresolved receiver or dynamic method.', $trace);

                return null;
            }
            $lower = strtolower($method);
            // Project overrides must be inspected before recognizing a framework API.
            if ($this->sources->method($receiver, $method) !== null) {
                if ($receiver !== ($variables['this'] ?? null) && $this->sources->method($receiver, '__construct') !== null) {
                    $this->visitMethod($receiver, '__construct', [], $trace);
                }

                return $this->visitMethod($receiver, $method, $arguments, $trace);
            }
            $db = $receiver === self::DB || $receiver === '@connection' || $this->sources->isA($receiver, 'Illuminate\\Database\\Connection');
            if ($db && $lower === 'transaction') {
                $callback = null;
                foreach ($expr->getArgs() as $i => $arg) {
                    if ($arg->name?->toString() === 'callback' || ($i === 0 && $arg->name === null)) {
                        $callback = $arg->value;
                    }
                }
                if (! $callback instanceof Expr\Closure && ! $callback instanceof Expr\ArrowFunction) {
                    $this->unknown($source, $expr, 'Unresolved transaction callback.', $trace);
                }
                $this->callbacks($expr->getArgs(), $source, $variables, $trace);

                return null;
            }
            if ($db && in_array($lower, ['insert', 'update', 'delete', 'affectingstatement'], true)) {
                $this->result->add('write', $source, $expr->getStartLine(), $receiver.'::'.$method.'()', $trace);

                return '@scalar';
            }
            if ($db && in_array($lower, ['statement', 'unprepared', 'select', 'selectone', 'cursor'], true)) {
                $sql = $expr->getArgs()[0]->value ?? null;
                // SELECT can call mutating functions. Raw SQL is not a proved read.
                $this->unknown($source, $expr, $sql instanceof Node\Scalar\String_ ? 'Raw SQL requires review.' : 'Dynamic SQL cannot be classified.', $trace);

                return null;
            }
            if ($db && $lower === 'connection') {
                return '@connection';
            }
            if ($db && $lower === 'table') {
                return '@query';
            }
            $model = $this->sources->isA($receiver, self::MODEL);
            $query = str_starts_with($receiver, '@query') || $model || $this->sources->isA($receiver, self::BUILDER) || $this->sources->isA($receiver, self::QUERY);
            $relation = $receiver === '@relation' || $this->sources->isA($receiver, self::RELATION);
            if ($query || $relation) {
                if (in_array($lower, self::WRITES, true) || ($relation && in_array($lower, ['attach', 'detach', 'sync', 'syncwithoutdetaching', 'syncwithpivotvalues', 'toggle', 'updateexistingpivot', 'savemany', 'createmany'], true))) {
                    $this->result->add('write', $source, $expr->getStartLine(), $receiver.'::'.$method.'()', $trace);

                    return '@scalar';
                }
                if (in_array($lower, self::CHAIN, true)) {
                    $this->callbacks($expr->getArgs(), $source, $variables, $trace);

                    return $model ? '@query:'.$receiver : $receiver;
                }
                if (in_array($lower, self::READS, true)) {
                    if (in_array($lower, ['first', 'firstorfail', 'find', 'findorfail'], true)) {
                        return $model ? $receiver : (str_starts_with($receiver, '@query:') ? substr($receiver, 7) : null);
                    }

                    return in_array($lower, ['get', 'all', 'pluck'], true) ? '@collection' : '@scalar';
                }
                if ($model && in_array($lower, self::RELATIONS, true)) {
                    return '@relation';
                }
                if ($model && in_array($lower, ['toarray', 'tojson', 'getkey', 'getattribute', 'getraworiginal'], true)) {
                    return '@scalar';
                }
            }
            if (($receiver === 'Illuminate\\Support\\Facades\\Bus' && in_array($lower, ['dispatch', 'dispatchsync', 'dispatchnow', 'dispatchafterresponse', 'dispatchtoqueue'], true))
                || ($receiver === 'Illuminate\\Support\\Facades\\Event' && in_array($lower, ['dispatch', 'until', 'push'], true))
                || ($receiver === 'Illuminate\\Support\\Facades\\Notification' && in_array($lower, ['send', 'sendnow'], true))
                || ($receiver === '@notification' && in_array($lower, ['notify', 'notifynow'], true))
                || ((str_starts_with($receiver, 'App\\Jobs\\') || str_starts_with($receiver, 'App\\Events\\')) && in_array($lower, ['dispatch', 'dispatchsync', 'dispatchafterresponse'], true))
                || (($receiver === 'Illuminate\\Support\\Facades\\Mail' || $receiver === '@mail') && in_array($lower, ['send', 'queue', 'later', 'sendnow'], true))
                || ($model && in_array($lower, ['notify', 'notifynow'], true))) {
                $this->result->add('effect', $source, $expr->getStartLine(), $receiver.'::'.$method.'()', $trace);

                return '@scalar';
            }
            if (($receiver === 'Illuminate\\Support\\Facades\\Notification' || $receiver === '@notification') && $lower === 'route') {
                return '@notification';
            }
            if (($receiver === 'Illuminate\\Support\\Facades\\Mail' || $receiver === '@mail') && in_array($lower, ['to', 'cc', 'bcc', 'locale', 'mailer'], true)) {
                return '@mail';
            }
            if (($receiver === '@response' && in_array($lower, ['json', 'make', 'nocontent'], true))
                || ($this->sources->isA($receiver, 'Illuminate\\Http\\Request') && in_array($lower, ['input', 'query', 'validated', 'safe', 'all', 'user', 'route'], true))
                || ($this->sources->isA($receiver, 'Illuminate\\Http\\Resources\\Json\\JsonResource') && in_array($lower, ['make', 'collection', 'resolve', 'response'], true))
                || ($receiver === '@collection' && in_array($lower, ['count', 'isempty', 'isnotempty', 'toarray', 'all', 'values', 'keys', 'pluck', 'first'], true))) {
                return '@scalar';
            }
            $this->unknown($source, $expr, 'Unresolved call '.$receiver.'::'.$method.'().', $trace);

            return null;
        }
        if ($expr instanceof Expr\Include_ || $expr instanceof Expr\Eval_ || $expr instanceof Expr\ShellExec) {
            $this->unknown($source, $expr, 'Dynamic code execution.', $trace);
        }
        foreach ($expr->getSubNodeNames() as $name) {
            $child = $expr->$name;
            foreach ($child instanceof Node ? [$child] : (is_array($child) ? $child : []) as $node) {
                if ($node instanceof Node) {
                    $returns = [];
                    $this->walk([$node], $source, $variables, $trace, $returns);
                }
            }
        }

        return $expr instanceof Expr\ConstFetch || $expr instanceof Node\Scalar || $expr instanceof Expr\Array_ || $expr instanceof Expr\BinaryOp || $expr instanceof Expr\Cast ? '@scalar' : null;
    }

    /**
     * @param  list<Node\Arg>  $args
     * @param  array<string, ?string>  $variables
     * @param  list<string>  $trace
     */
    private function callbacks(array $args, SourceClass $source, array $variables, array $trace): void
    {
        foreach ($args as $arg) {
            if ($arg->value instanceof Expr\Closure || $arg->value instanceof Expr\ArrowFunction) {
                $callback = $arg->value;
                foreach ($callback->params as $param) {
                    if (is_string($param->var->name)) {
                        $variables[$param->var->name] = $this->type($source, $param->type) ?? '@query';
                    }
                }
                $returns = [];
                $this->walk($callback instanceof Expr\Closure ? $callback->stmts : [$callback->expr], $source, $variables, $trace, $returns);
            }
        }
    }

    private function property(string $class, string $name, int $depth = 0): ?string
    {
        if ($depth >= 12 || ($source = $this->sources->get($class)) === null) {
            return null;
        }
        foreach ($source->node->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === $name) {
                    return $this->type($source, $property->type);
                }
            }
        }
        $constructor = $source->node->getMethod('__construct');
        foreach ($constructor->params ?? [] as $param) {
            if ($param->flags !== 0 && $param->var->name === $name) {
                return $this->type($source, $param->type);
            }
        }
        if ($source->node instanceof Stmt\Class_ && $source->node->extends !== null) {
            return $this->property($source->file->resolvedName($source->node->extends), $name, $depth + 1);
        }

        return null;
    }

    private function type(SourceClass $source, Node|string|null $type): ?string
    {
        if ($type instanceof Name) {
            return $this->className($source, $type);
        }
        if ($type instanceof Node\NullableType) {
            return $this->type($source, $type->type);
        }
        if ($type instanceof Node\Identifier) {
            return in_array(strtolower($type->toString()), ['mixed', 'object', 'callable', 'iterable'], true) ? null : '@scalar';
        }

        return null;
    }

    private function className(SourceClass $source, Name $name): string
    {
        if (strtolower($name->toString()) === 'parent' && $source->node instanceof Stmt\Class_ && $source->node->extends !== null) {
            return $source->file->resolvedName($source->node->extends);
        }

        return in_array(strtolower($name->toString()), ['self', 'static'], true) ? $source->name : $source->file->resolvedName($name);
    }

    /**
     * @param  array<string, ?string>  $variables
     * @param  list<array<string, ?string>>  $branches
     */
    private function mergeVariables(array &$variables, array $branches): void
    {
        foreach ($branches as $branch) {
            foreach ($branch as $name => $type) {
                $types = array_map(fn (array $path): ?string => $path[$name] ?? null, $branches);
                $variables[$name] = count(array_unique($types, SORT_REGULAR)) === 1 ? $type : null;
            }
        }
    }

    /**
     * @param  list<string>  $trace
     */
    private function unknown(SourceClass $source, Node $node, string $detail, array $trace): void
    {
        $this->result->add('unknown', $source, $node->getStartLine(), $detail, $trace);
    }
}
