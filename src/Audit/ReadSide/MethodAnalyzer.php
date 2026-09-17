<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkContext;
use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkSemantics;
use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkValue;
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

    private FrameworkSemantics $framework;

    public function __construct(private SourceIndex $sources, FrameworkContext $context = new FrameworkContext)
    {
        $this->framework = new FrameworkSemantics($sources, $context);
    }

    public function analyze(string $class, string $method): MethodEffects
    {
        $this->result = new MethodEffects;
        $this->nodes = $this->calls = 0;
        $this->active = [];
        $entrypoint = $this->framework->entrypoint($class, $method);
        if ($entrypoint->handled) {
            foreach ($entrypoint->targets as $target) {
                $this->visitMethod($target['class'], $target['method'], $target['arguments'] ?? [], [$class.'::'.$method]);
            }
            foreach ($entrypoint->callbacks as $callback) {
                $this->walkCallback($callback, [$class.'::'.$method]);
            }
            if ($entrypoint->incomplete !== null) {
                $origin = $this->framework->context()->origins[0] ?? 'routes/web.php';
                $this->result->addAt('unknown', $origin, 1, $entrypoint->incomplete, [$class.'::'.$method]);
            }

            return $this->result;
        }
        if ($this->sources->method($class, '__construct') !== null) {
            $this->visitMethod($class, '__construct', [], []);
        }
        $this->visitMethod($class, $method, [], []);

        return $this->result;
    }

    /**
     * @param  array<int|string, FrameworkValue|null>  $arguments
     * @param  list<string>  $trace
     */
    private function visitMethod(string $class, string $method, array $arguments, array $trace): ?FrameworkValue
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
        $variables = ['this' => FrameworkValue::type($class)];
        foreach ($node->params as $i => $param) {
            if (is_string($param->var->name)) {
                $argument = $arguments[$param->var->name] ?? $arguments[$i] ?? null;
                $variables[$param->var->name] = $argument ?? $this->type($source, $param->type);
            }
        }
        $returns = [];
        $this->walk($node->stmts, $source, $variables, $trace, $returns);
        unset($this->active[$key]);

        $inferred = FrameworkValue::merge($returns);

        return $inferred !== null && ! $inferred->isUnknown() ? $inferred : $this->type($source, $node->returnType);
    }

    /**
     * @param  list<Node>  $nodes
     * @param  array<string, FrameworkValue|null>  $variables
     * @param  list<string>  $trace
     * @param  list<FrameworkValue|null>  $returns
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
                $value = $node->expr === null ? FrameworkValue::scalar() : $this->expression($node->expr, $source, $variables, $trace);
                $this->followReturnedValue($value, $source, $node, $variables, $trace);
                $returns[] = $value;

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
     * @param  array<string, FrameworkValue|null>  $variables
     * @param  list<string>  $trace
     */
    private function expression(Expr $expr, SourceClass $source, array &$variables, array $trace): ?FrameworkValue
    {
        if (++$this->nodes > 20_000) {
            $this->result->add('unknown', $source, $expr->getStartLine(), 'AST node limit reached.', $trace);

            return null;
        }
        if ($expr instanceof Expr\Variable) {
            return is_string($expr->name) ? ($variables[$expr->name] ?? null) : null;
        }
        if ($expr instanceof Expr\Closure || $expr instanceof Expr\ArrowFunction) {
            return FrameworkValue::callback($source, $expr, $variables);
        }
        if ($expr instanceof Expr\Ternary) {
            $condition = $this->expression($expr->cond, $source, $variables, $trace);
            $yes = $no = $variables;
            $left = $expr->if !== null ? $this->expression($expr->if, $source, $yes, $trace) : $condition;
            $right = $this->expression($expr->else, $source, $no, $trace);
            $this->mergeVariables($variables, [$yes, $no]);

            return $left == $right ? $left : FrameworkValue::unknown();
        }
        if ($expr instanceof Expr\BinaryOp\BooleanAnd || $expr instanceof Expr\BinaryOp\BooleanOr || $expr instanceof Expr\BinaryOp\LogicalAnd || $expr instanceof Expr\BinaryOp\LogicalOr || $expr instanceof Expr\BinaryOp\Coalesce) {
            $left = $this->expression($expr->left, $source, $variables, $trace);
            $branch = $variables;
            $right = $this->expression($expr->right, $source, $branch, $trace);
            $this->mergeVariables($variables, [$variables, $branch]);

            return $expr instanceof Expr\BinaryOp\Coalesce ? ($left == $right ? $left : FrameworkValue::unknown()) : FrameworkValue::scalar();
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

            return FrameworkValue::merge($types);
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
        if ($expr instanceof Expr\ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof Node\Identifier && strtolower($expr->name->toString()) === 'class') {
            $class = $this->className($source, $expr->class);

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
                    $this->expression($item->key, $source, $variables, $trace);
                }
                $key = $item->key instanceof Node\Scalar\String_ ? $item->key->value : ($item->key instanceof Node\Scalar\Int_ ? $item->key->value : $index);
                $items[$key] = $this->expression($item->value, $source, $variables, $trace);
            }

            return FrameworkValue::array($items);
        }
        if ($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch) {
            $owner = $this->expression($expr->var, $source, $variables, $trace);
            if ($expr->name instanceof Expr) {
                $this->expression($expr->name, $source, $variables, $trace);
                $this->unknown($source, $expr, 'Dynamic property access.', $trace);
            }

            return $owner?->type !== null && $expr->name instanceof Node\Identifier ? $this->property($owner->type, $expr->name->toString()) : null;
        }
        if ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall || $expr instanceof Expr\StaticCall || $expr instanceof Expr\New_ || $expr instanceof Expr\FuncCall) {
            $receiver = null;
            if ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall) {
                $receiver = $this->expression($expr->var, $source, $variables, $trace);
            } elseif (($expr instanceof Expr\StaticCall || $expr instanceof Expr\New_) && $expr->class instanceof Name) {
                $receiver = FrameworkValue::type($this->className($source, $expr->class));
            } elseif (($expr instanceof Expr\StaticCall || $expr instanceof Expr\New_) && $expr->class instanceof Expr) {
                $this->expression($expr->class, $source, $variables, $trace);
            }
            if (! $expr instanceof Expr\New_ && $expr->name instanceof Expr) {
                $this->expression($expr->name, $source, $variables, $trace);
            }
            if ($expr->isFirstClassCallable()) {
                return FrameworkValue::type('@callback');
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
                $receiverType = $receiver?->type;
                if ($receiverType === null || $this->sources->get($receiverType) === null) {
                    $this->unknown($source, $expr, 'Unresolved constructor.', $trace);
                } elseif ($this->sources->method($receiverType, '__construct') !== null) {
                    $this->visitMethod($receiverType, '__construct', $arguments, $trace);
                }

                return $receiverType !== null && $this->sources->isA($receiverType, 'Illuminate\Http\Resources\Json\JsonResource')
                    ? FrameworkValue::resource($receiverType, $this->sources->isA($receiverType, 'Illuminate\Http\Resources\Json\ResourceCollection'))
                    : $receiver;
            }
            if ($expr instanceof Expr\FuncCall) {
                $function = $expr->name instanceof Name ? strtolower(ltrim($expr->name->toString(), '\\')) : '';
                if (in_array($function, ['event', 'dispatch', 'dispatch_sync'], true)) {
                    $this->result->add('effect', $source, $expr->getStartLine(), $function.'()', $trace);
                }
                $framework = $this->framework->describe(null, $function, null, $arguments, $source, $expr);
                if ($framework->handled) {
                    return $this->applyFramework($framework, $source, $expr, $variables, $trace);
                }
                if (! in_array($function, ['count', 'strlen', 'strtolower', 'strtoupper', 'trim', 'ltrim', 'rtrim', 'sprintf', 'implode', 'explode', 'in_array', 'array_key_exists', 'array_values', 'array_keys', 'array_unique', 'array_merge', 'is_null', 'is_string', 'is_int', 'is_array', 'abs', 'round', 'min', 'max', 'config', 'now', 'today', 'trans', '__'], true)) {
                    $this->unknown($source, $expr, 'Unresolved function/callback '.$function.'().', $trace);
                }

                return FrameworkValue::scalar();
            }
            $receiverType = $receiver?->type;
            if ($receiverType !== null && str_starts_with($receiverType, 'App\\Services\\') && str_starts_with($source->file->path, 'app/Http/Controllers/')) {
                $this->result->services[strtolower($receiverType)] ??= ['type' => $receiverType, 'line' => $expr->getStartLine(), 'path' => $source->file->path];
            }
            $method = $expr->name instanceof Node\Identifier ? $expr->name->toString() : null;
            if ($receiverType === null || $method === null) {
                $this->unknown($source, $expr, 'Unresolved receiver or dynamic method.', $trace);

                return null;
            }
            $lower = strtolower($method);
            // Project overrides must be inspected before recognizing a framework API.
            if ($this->sources->method($receiverType, $method) !== null) {
                if ($receiverType !== (isset($variables['this']) ? $variables['this']->type : null) && $this->sources->method($receiverType, '__construct') !== null) {
                    $this->visitMethod($receiverType, '__construct', [], $trace);
                }

                return $this->visitMethod($receiverType, $method, $arguments, $trace);
            }
            $framework = $this->framework->describe($receiver, null, $method, $arguments, $source, $expr);
            if ($framework->handled) {
                return $this->applyFramework($framework, $source, $expr, $variables, $trace);
            }
            $db = $receiverType === self::DB || $receiverType === '@connection' || $this->sources->isA($receiverType, 'Illuminate\\Database\\Connection');
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
                $this->result->add('write', $source, $expr->getStartLine(), $receiverType.'::'.$method.'()', $trace);

                return FrameworkValue::scalar();
            }
            if ($db && in_array($lower, ['statement', 'unprepared', 'select', 'selectone', 'cursor'], true)) {
                $sql = $expr->getArgs()[0]->value ?? null;
                // SELECT can call mutating functions. Raw SQL is not a proved read.
                $this->unknown($source, $expr, $sql instanceof Node\Scalar\String_ ? 'Raw SQL requires review.' : 'Dynamic SQL cannot be classified.', $trace);

                return null;
            }
            if ($db && $lower === 'connection') {
                return FrameworkValue::type('@connection');
            }
            if ($db && $lower === 'table') {
                return FrameworkValue::type('@query');
            }
            $model = $this->sources->isA($receiverType, self::MODEL);
            $query = str_starts_with($receiverType, '@query') || $model || $this->sources->isA($receiverType, self::BUILDER) || $this->sources->isA($receiverType, self::QUERY);
            $relation = $receiverType === '@relation' || $this->sources->isA($receiverType, self::RELATION);
            if ($query || $relation) {
                if (in_array($lower, self::WRITES, true) || ($relation && in_array($lower, ['attach', 'detach', 'sync', 'syncwithoutdetaching', 'syncwithpivotvalues', 'toggle', 'updateexistingpivot', 'savemany', 'createmany'], true))) {
                    $this->result->add('write', $source, $expr->getStartLine(), $receiverType.'::'.$method.'()', $trace);

                    return FrameworkValue::scalar();
                }
                if (in_array($lower, self::CHAIN, true)) {
                    $this->callbacks($expr->getArgs(), $source, $variables, $trace);

                    return FrameworkValue::type($model ? '@query:'.$receiverType : $receiverType);
                }
                if (in_array($lower, self::READS, true)) {
                    if (in_array($lower, ['first', 'firstorfail', 'find', 'findorfail'], true)) {
                        return $model ? FrameworkValue::type($receiverType) : (str_starts_with($receiverType, '@query:') ? FrameworkValue::type(substr($receiverType, 7)) : null);
                    }

                    return in_array($lower, ['get', 'all', 'pluck'], true) ? FrameworkValue::type('@collection') : FrameworkValue::scalar();
                }
                if ($model && in_array($lower, self::RELATIONS, true)) {
                    return FrameworkValue::type('@relation');
                }
                if ($model && in_array($lower, ['toarray', 'tojson', 'getkey', 'getattribute', 'getraworiginal'], true)) {
                    return FrameworkValue::scalar();
                }
            }
            if (($receiverType === 'Illuminate\\Support\\Facades\\Bus' && in_array($lower, ['dispatch', 'dispatchsync', 'dispatchnow', 'dispatchafterresponse', 'dispatchtoqueue'], true))
                || ($receiverType === 'Illuminate\\Support\\Facades\\Event' && in_array($lower, ['dispatch', 'until', 'push'], true))
                || ($receiverType === 'Illuminate\\Support\\Facades\\Notification' && in_array($lower, ['send', 'sendnow'], true))
                || ($receiverType === '@notification' && in_array($lower, ['notify', 'notifynow'], true))
                || ((str_starts_with($receiverType, 'App\\Jobs\\') || str_starts_with($receiverType, 'App\\Events\\')) && in_array($lower, ['dispatch', 'dispatchsync', 'dispatchafterresponse'], true))
                || (($receiverType === 'Illuminate\\Support\\Facades\\Mail' || $receiverType === '@mail') && in_array($lower, ['send', 'queue', 'later', 'sendnow'], true))
                || ($model && in_array($lower, ['notify', 'notifynow'], true))) {
                $this->result->add('effect', $source, $expr->getStartLine(), $receiverType.'::'.$method.'()', $trace);

                return FrameworkValue::scalar();
            }
            if (($receiverType === 'Illuminate\\Support\\Facades\\Notification' || $receiverType === '@notification') && $lower === 'route') {
                return FrameworkValue::type('@notification');
            }
            if (($receiverType === 'Illuminate\\Support\\Facades\\Mail' || $receiverType === '@mail') && in_array($lower, ['to', 'cc', 'bcc', 'locale', 'mailer'], true)) {
                return FrameworkValue::type('@mail');
            }
            $this->unknown($source, $expr, 'Unresolved call '.$receiverType.'::'.$method.'().', $trace);

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

        return $expr instanceof Expr\ConstFetch || $expr instanceof Node\Scalar || $expr instanceof Expr\BinaryOp || $expr instanceof Expr\Cast ? FrameworkValue::scalar() : null;
    }

    /**
     * @param  list<Node\Arg>  $args
     * @param  array<string, FrameworkValue|null>  $variables
     * @param  list<string>  $trace
     */
    private function callbacks(array $args, SourceClass $source, array $variables, array $trace): void
    {
        foreach ($args as $arg) {
            if ($arg->value instanceof Expr\Closure || $arg->value instanceof Expr\ArrowFunction) {
                $callback = $arg->value;
                foreach ($callback->params as $param) {
                    if (is_string($param->var->name)) {
                        $variables[$param->var->name] = $this->type($source, $param->type) ?? FrameworkValue::type('@query');
                    }
                }
                $returns = [];
                $this->walk($callback instanceof Expr\Closure ? $callback->stmts : [$callback->expr], $source, $variables, $trace, $returns);
            }
        }
    }

    private function property(string $class, string $name, int $depth = 0): ?FrameworkValue
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

    private function type(SourceClass $source, Node|string|null $type): ?FrameworkValue
    {
        if ($type instanceof Name) {
            return FrameworkValue::type($this->className($source, $type));
        }
        if ($type instanceof Node\NullableType) {
            return $this->type($source, $type->type);
        }
        if ($type instanceof Node\Identifier) {
            return in_array(strtolower($type->toString()), ['mixed', 'object', 'callable', 'iterable'], true) ? null : FrameworkValue::scalar();
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
     * @param  array<string, FrameworkValue|null>  $variables
     * @param  list<array<string, FrameworkValue|null>>  $branches
     */
    private function mergeVariables(array &$variables, array $branches): void
    {
        foreach ($branches as $branch) {
            foreach ($branch as $name => $type) {
                $types = array_map(fn (array $path): ?FrameworkValue => $path[$name] ?? null, $branches);
                $variables[$name] = FrameworkValue::merge($types);
            }
        }
    }

    /**
     * @param  array<string, FrameworkValue|null>  $variables
     * @param  list<string>  $trace
     */
    private function applyFramework(object $result, SourceClass $source, Node $expr, array $variables, array $trace): ?FrameworkValue
    {
        foreach ($result->effects as $effect) {
            $this->result->add($effect['kind'], $source, $expr->getStartLine(), $effect['detail'], $trace);
        }
        foreach ($result->targets as $target) {
            $this->visitMethod($target['class'], $target['method'], $target['arguments'] ?? [], $trace);
        }
        foreach ($result->callbacks as $callback) {
            $this->walkCallback($callback, $trace);
        }
        if ($result->incomplete !== null) {
            $this->unknown($source, $expr, $result->incomplete, $trace);
        }

        return $result->value;
    }

    /**
     * @param  array<string, FrameworkValue|null>  $variables
     * @param  list<string>  $trace
     */
    private function followReturnedValue(?FrameworkValue $value, SourceClass $source, Node $node, array $variables, array $trace): void
    {
        if ($value === null) {
            return;
        }
        if ($value->resourceClass !== null) {
            $result = $this->framework->describe($value, null, '@return', [], $source, $node);
            if ($result->handled) {
                $this->applyFramework($result, $source, $node, $variables, $trace);
            }
        }
        foreach ($value->items as $item) {
            $this->followReturnedValue($item, $source, $node, $variables, $trace);
        }
    }

    /** @param list<string> $trace */
    private function walkCallback(FrameworkValue $value, array $trace): void
    {
        if ($value->callback === null || $value->callbackSource === null) {
            return;
        }
        $variables = $value->captures;
        foreach ($value->callback->params as $param) {
            if (is_string($param->var->name)) {
                $variables[$param->var->name] = $this->type($value->callbackSource, $param->type) ?? FrameworkValue::unknown();
            }
        }
        $returns = [];
        $this->walk($value->callback instanceof Expr\Closure ? $value->callback->stmts : [$value->callback->expr], $value->callbackSource, $variables, [...$trace, '{callback}'], $returns);
    }

    /**
     * @param  list<string>  $trace
     */
    private function unknown(SourceClass $source, Node $node, string $detail, array $trace): void
    {
        $this->result->add('unknown', $source, $node->getStartLine(), $detail, $trace);
    }
}
