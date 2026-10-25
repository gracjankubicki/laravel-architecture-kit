<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Query;
use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkContext;
use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkSemantics;
use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkValue;
use GracjanKubicki\ArchitectureKit\Audit\Framework\ReflectionSemantics;

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
    private const CHAIN = ['query', 'newquery', 'newmodelquery', 'where', 'wherekey', 'wherekeynot', 'orwhere', 'wherenull', 'wherenotnull', 'wherein', 'wherenotin', 'wherebetween', 'wheredate', 'whereyear', 'wheremonth', 'wherehas', 'orwherehas', 'has', 'doesnthave', 'wheredoesnthave', 'with', 'without', 'withcount', 'withsum', 'withavg', 'orderby', 'orderbydesc', 'latest', 'oldest', 'limit', 'take', 'skip', 'offset', 'select', 'addselect', 'distinct', 'groupby', 'having', 'join', 'leftjoin', 'rightjoin', 'withoutglobalscopes', 'withoutglobalscope', 'withtrashed', 'onlytrashed', 'usewritepdo', 'lockforupdate', 'sharedlock', 'when', 'unless', 'tap', 'each'];

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
        AnalysisRuntime::stage('deep.method-analyzer');
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
                $this->result->addAt('unknown', $origin, 1, $entrypoint->incomplete, [$class.'::'.$method], $entrypoint->incompleteReason);
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
    private function visitMethod(string $class, string $method, array $arguments, array $trace, ?FrameworkValue $receiver = null): ?FrameworkValue
    {
        $found = $this->sources->method($class, $method);
        if ($found === null) {
            $this->result->addAt(
                'unknown',
                $class.'.php',
                1,
                $this->sources->unavailableReason($class) ?? 'Source is unavailable for '.$class.'::'.$method.'.',
                $trace,
                $this->sources->unavailableCode($class),
            );

            return null;
        }
        [$source, $node] = $found;
        $key = strtolower($class.'::'.$method);
        if (isset($this->active[$key]) || count($trace) >= 12 || ++$this->calls > 128) {
            $this->result->add('unknown', $source, $node->getStartLine(), 'Call cycle or analysis limit reached at '.$key, $trace, 'analysis_limit');

            return null;
        }
        if ($node->stmts === null) {
            $this->result->add('unknown', $source, $node->getStartLine(), 'No concrete implementation for '.$key, $trace);

            return null;
        }
        $this->active[$key] = true;
        $trace[] = $class.'::'.$method;
        $variables = ['this' => $receiver ?? FrameworkValue::type($class)];
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
                $this->result->add('unknown', $source, $node->getStartLine(), 'AST node limit reached.', $trace, 'analysis_limit');

                return;
            }
            if (Node::is($node, 'statement.class-like') || Node::is($node, 'statement.function')) {
                continue;
            }
            if (Node::is($node, 'statement.if')) {
                $this->expression($node->cond, $source, $variables, $trace);
                $branches = [];
                $branch = $this->narrow($variables, $node->cond, true, $source);
                $this->walk($node->stmts, $source, $branch, $trace, $returns);
                $branches[] = $branch;
                $previous = $variables;
                foreach ($node->elseifs as $elseif) {
                    $branch = $this->narrow($previous, $elseif->cond, true, $source);
                    $this->expression($elseif->cond, $source, $branch, $trace);
                    $this->walk($elseif->stmts, $source, $branch, $trace, $returns);
                    $branches[] = $branch;
                    $previous = $this->narrow($previous, $elseif->cond, false, $source);
                }
                $branch = $this->narrow($previous, $node->cond, false, $source);
                $this->walk($node->else->stmts ?? [], $source, $branch, $trace, $returns);
                $branches[] = $branch;
                $this->mergeVariables($variables, $branches);

                continue;
            }
            if (Node::is($node, 'statement.foreach')) {
                $iterable = $this->expression($node->expr, $source, $variables, $trace);
                $branch = $variables;
                // A later iteration can observe assignments from an earlier one.
                // Forget carried facts before inspecting the body once, within the same budget.
                foreach (Query::walk($node->stmts) as $child) {
                    if (++$this->nodes > 20_000) {
                        $this->unknown($source, $node, 'Foreach preparation node limit reached.', $trace, 'analysis_limit');

                        return;
                    }
                    if (Node::is($child, 'statement.foreach')) {
                        foreach ([$child->keyVar, $child->valueVar] as $binding) {
                            if (Node::is($binding, 'expression.variable') && is_string($binding->name)) {
                                $branch[$binding->name] = null;
                            } elseif ($binding !== null) {
                                $this->unknown($source, $binding, 'Nested foreach binding is unresolved.', $trace, 'unresolved_receiver');
                            }
                        }
                    }
                    if (Node::is($child, 'expression.assign') || Node::is($child, 'expression.assign-ref') || Node::is($child, 'expression.assign-op')) {
                        if (Node::is($child->var, 'expression.variable') && is_string($child->var->name)) {
                            $branch[$child->var->name] = null;
                        }
                    }
                }
                $element = $iterable?->type === '@array' && ! $iterable->isAmbiguous()
                    ? ($iterable->element ?? FrameworkValue::merge(array_values($iterable->items))) : null;
                if ($node->byRef) {
                    $element = null;
                    $this->unknown($source, $node, 'Foreach reference binding is unresolved.', $trace, 'unresolved_receiver');
                }
                foreach ([$node->keyVar, $node->valueVar] as $index => $variable) {
                    if (Node::is($variable, 'expression.variable') && is_string($variable->name)) {
                        $branch[$variable->name] = $index === 0 ? FrameworkValue::scalar() : $element;
                    } elseif ($variable !== null) {
                        $this->expression($variable, $source, $branch, $trace);
                        $this->unknown($source, $variable, 'Foreach binding is unresolved.', $trace, 'unresolved_receiver');
                    }
                }
                $returnCount = count($returns);
                $this->walk($node->stmts, $source, $branch, $trace, $returns);
                if (count($returns) > $returnCount) {
                    $returns[] = null; // The iterable may be empty.
                }
                $this->mergeVariables($variables, [$variables, $branch]);

                continue;
            }
            if (Node::is($node, 'statement.return')) {
                $value = $node->expr === null ? FrameworkValue::scalar() : $this->expression($node->expr, $source, $variables, $trace);
                $this->followReturnedValue($value, $source, $node, $variables, $trace);
                $returns[] = $value;

                return;
            } elseif (Node::is($node, 'expression')) {
                $this->expression($node, $source, $variables, $trace);
            } elseif (Node::is($node, 'statement.expression')) {
                $this->expression($node->expr, $source, $variables, $trace);
            } else {
                // Inspect every branch but do not let a conditional assignment become
                // an unconditional type fact for a later call.
                $branch = $variables;
                foreach ($node->getSubNodeNames() as $name) {
                    $child = $node->$name;
                    $children = Node::is($child, 'node') ? [$child] : (is_array($child) ? array_values(array_filter($child, fn ($n) => Node::is($n, 'node'))) : []);
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
     *
     * @phpstan-impure
     */
    private function expression(Node $expr, SourceClass $source, array &$variables, array $trace): ?FrameworkValue
    {
        if (++$this->nodes > 20_000) {
            $this->result->add('unknown', $source, $expr->getStartLine(), 'AST node limit reached.', $trace, 'analysis_limit');

            return null;
        }
        if (Node::is($expr, 'expression.variable')) {
            return is_string($expr->name) ? ($variables[$expr->name] ?? null) : null;
        }
        if (Node::is($expr, 'expression.closure') || Node::is($expr, 'expression.arrow-function')) {
            return FrameworkValue::callback($source, $expr, $variables);
        }
        if (Node::is($expr, 'expression.ternary')) {
            $condition = $this->expression($expr->cond, $source, $variables, $trace);
            $yes = $this->narrow($variables, $expr->cond, true, $source);
            $no = $this->narrow($variables, $expr->cond, false, $source);
            $left = $expr->if !== null ? $this->expression($expr->if, $source, $yes, $trace) : $condition;
            $right = $this->expression($expr->else, $source, $no, $trace);
            $this->mergeVariables($variables, [$yes, $no]);

            return FrameworkValue::merge([$left, $right]);
        }
        if (Node::is($expr, 'expression.binary-op.boolean-and') || Node::is($expr, 'expression.binary-op.boolean-or') || Node::is($expr, 'expression.binary-op.logical-and') || Node::is($expr, 'expression.binary-op.logical-or') || Node::is($expr, 'expression.binary-op.coalesce')) {
            $left = $this->expression($expr->left, $source, $variables, $trace);
            $branch = $variables;
            $right = $this->expression($expr->right, $source, $branch, $trace);
            $this->mergeVariables($variables, [$variables, $branch]);

            return Node::is($expr, 'expression.binary-op.coalesce') ? ($left == $right ? $left : FrameworkValue::unknown()) : FrameworkValue::scalar();
        }
        if (Node::is($expr, 'expression.match')) {
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
        if (Node::is($expr, 'expression.assign')) {
            $type = $this->expression($expr->expr, $source, $variables, $trace);
            if (Node::is($expr->var, 'expression.variable') && is_string($expr->var->name)) {
                $variables[$expr->var->name] = $type;
            } else {
                $this->expression($expr->var, $source, $variables, $trace);
            }

            return $type;
        }
        if (Node::is($expr, 'expression.class-const-fetch') && Node::is($expr->class, 'name') && Node::is($expr->name, 'identifier') && strtolower($expr->name->toString()) === 'class') {
            $class = $this->className($source, $expr->class);

            return new FrameworkValue(type: $class, literal: $class);
        }
        if (Node::is($expr, 'expression.const-fetch') && strtolower($expr->name->toString()) === 'null') {
            return null;
        }
        if (Node::is($expr, 'scalar.string')) {
            return FrameworkValue::scalar($expr->value);
        }
        if (Node::is($expr, 'expression.array')) {
            $items = [];
            foreach ($expr->items as $index => $item) {
                if ($item === null) {
                    continue;
                }
                if (Node::is($item->key, 'expression')) {
                    $this->expression($item->key, $source, $variables, $trace);
                }
                $key = Node::is($item->key, 'scalar.string') ? $item->key->value : (Node::is($item->key, 'scalar.int') ? $item->key->value : $index);
                $items[$key] = $this->expression($item->value, $source, $variables, $trace);
            }

            return FrameworkValue::array($items);
        }
        if (Node::is($expr, 'expression.array-dim-fetch')) {
            $array = $this->expression($expr->var, $source, $variables, $trace);
            if ($expr->dim !== null) {
                $this->expression($expr->dim, $source, $variables, $trace);
            }
            $key = Node::is($expr->dim, 'scalar.int') || Node::is($expr->dim, 'scalar.string') ? $expr->dim->value : null;

            return $key !== null ? ($array?->items[$key] ?? $array?->element) : $array?->element;
        }
        if (Node::is($expr, 'expression.property-fetch') || Node::is($expr, 'expression.nullsafe-property-fetch')) {
            $owner = $this->expression($expr->var, $source, $variables, $trace);
            if (Node::is($expr->name, 'expression')) {
                $this->expression($expr->name, $source, $variables, $trace);
                $this->unknown($source, $expr, 'Dynamic property access.', $trace);
            }

            return $owner?->type !== null && Node::is($expr->name, 'identifier')
                ? $this->property($owner->type, $expr->name->toString(), $trace)
                : null;
        }
        if (Node::is($expr, 'expression.method-call') || Node::is($expr, 'expression.nullsafe-method-call') || Node::is($expr, 'expression.static-call') || Node::is($expr, 'expression.new') || Node::is($expr, 'expression.func-call')) {
            $receiver = null;
            if (Node::is($expr, 'expression.method-call') || Node::is($expr, 'expression.nullsafe-method-call')) {
                $receiver = $this->expression($expr->var, $source, $variables, $trace);
            } elseif ((Node::is($expr, 'expression.static-call') || Node::is($expr, 'expression.new')) && Node::is($expr->class, 'name')) {
                $receiver = FrameworkValue::type($this->className($source, $expr->class));
            } elseif ((Node::is($expr, 'expression.static-call') || Node::is($expr, 'expression.new')) && Node::is($expr->class, 'expression')) {
                $this->expression($expr->class, $source, $variables, $trace);
            }
            if (! Node::is($expr, 'expression.new') && Node::is($expr->name, 'expression')) {
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
            if (Node::is($expr, 'expression.new')) {
                $receiverType = $receiver?->type;
                if ($receiverType === null || $this->sources->get($receiverType) === null) {
                    if ($receiverType !== null
                        && ($this->sources->inScope($receiverType) || str_starts_with($receiverType, 'App\\'))
                        && ! $this->framework->isKnownExternal($receiverType)) {
                        $this->unknown($source, $expr, 'Unresolved constructor.', $trace, $this->sources->unavailableReason($receiverType) === null ? 'unresolved_call' : $this->sources->unavailableCode($receiverType));
                    }
                } elseif ($this->sources->method($receiverType, '__construct') !== null) {
                    $this->visitMethod($receiverType, '__construct', $arguments, $trace);
                }

                return $receiverType !== null && $this->sources->isA($receiverType, 'Illuminate\Http\Resources\Json\JsonResource')
                    ? FrameworkValue::resource($receiverType, $this->sources->isA($receiverType, 'Illuminate\Http\Resources\Json\ResourceCollection'))
                    : $receiver;
            }
            if (Node::is($expr, 'expression.func-call')) {
                $resolved = Node::is($expr->name, 'name') ? $this->sources->functionCall($expr->name, $source) : null;
                $function = strtolower($resolved['name'] ?? '');
                if (($resolved['reason'] ?? null) !== null) {
                    $this->unknown($source, $expr, 'Function source selection is unavailable for '.$function.'().', $trace, $resolved['reason']);

                    return null;
                }
                if (($resolved['source'] ?? null) !== null && $resolved['node'] !== null) {
                    return $this->visitFunction($resolved['source'], $resolved['node'], $arguments, $trace);
                }
                if (in_array($function, ['event', 'dispatch', 'dispatch_sync'], true)) {
                    $this->result->add('effect', $source, $expr->getStartLine(), $function.'()', $trace);

                    return FrameworkValue::scalar();
                }
                $framework = $this->framework->describe(null, $function, null, $arguments, $source, $expr);
                if ($framework->handled) {
                    if ($function === 'preg_match') {
                        foreach ($expr->getArgs() as $position => $arg) {
                            if (($arg->name?->toString() === 'matches' || $position === 2 && $arg->name === null)
                                && Node::is($arg->value, 'expression.variable') && is_string($arg->value->name)) {
                                $variables[$arg->value->name] = FrameworkValue::array([]);
                            }
                        }
                    }

                    return $this->applyFramework($framework, $source, $expr, $variables, $trace);
                }
                if (! in_array($function, ['count', 'strlen', 'strtolower', 'strtoupper', 'trim', 'ltrim', 'rtrim', 'sprintf', 'implode', 'explode', 'in_array', 'array_key_exists', 'array_values', 'array_keys', 'array_unique', 'array_merge', 'is_null', 'is_string', 'is_int', 'is_array', 'abs', 'round', 'min', 'max', 'config', 'now', 'today', 'trans', '__'], true)) {
                    $this->unknown($source, $expr, 'Unresolved function/callback '.$function.'().', $trace);
                }

                return FrameworkValue::scalar();
            }
            $receiverType = $receiver?->type;
            $method = Node::is($expr->name, 'identifier') ? $expr->name->toString() : null;
            if ($receiverType !== null && str_starts_with($receiverType, 'App\\Services\\') && str_starts_with($source->file->path, 'app/Http/Controllers/')) {
                $this->result->services[strtolower($receiverType)] ??= ['type' => $receiverType, 'line' => $expr->getStartLine(), 'path' => $source->file->path,
                    'trace' => $method === null ? $trace : [...$trace, $receiverType.'::'.$method]];
            }
            if ($receiverType === null || $method === null) {
                $this->unknown($source, $expr, 'Unresolved receiver or dynamic method.', $trace, 'unresolved_receiver');

                return null;
            }
            if ($receiver->isAmbiguous() && ! Node::is($expr, 'expression.nullsafe-method-call')) {
                $this->unknown($source, $expr, 'Receiver has multiple possible or nullable types at '.$method.'().', $trace, 'unresolved_receiver');
            }
            $lower = strtolower($method);
            // Resolve an explicitly declared builder before its framework methods.
            // Keep ordinary model overrides ahead of this forwarding boundary.
            if ($this->sources->method($receiverType, $method) === null
                && $this->sources->isA($receiverType, self::MODEL)
                && in_array($lower, [...self::CHAIN, ...self::READS], true)
                && $this->sources->method($receiverType, 'newEloquentBuilder') !== null) {
                $builder = $this->customBuilder($receiverType, $source, $expr, $trace);
                if ($builder === null) {
                    return null;
                }
                $receiver = $builder;
                $receiverType = $builder->type;
            }
            // Project overrides must be inspected before recognizing a framework API.
            if ($this->sources->method($receiverType, $method) !== null) {
                if ($receiverType !== (isset($variables['this']) ? $variables['this']->type : null) && $this->sources->method($receiverType, '__construct') !== null) {
                    $this->visitMethod($receiverType, '__construct', [], $trace);
                }

                return $this->visitMethod($receiverType, $method, $arguments, $trace, $receiver);
            }
            if (($unavailable = $this->sources->unavailableReason($receiverType)) !== null) {
                $this->unknown($source, $expr, $unavailable, $trace, $this->sources->unavailableCode($receiverType));

                return null;
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
                if (! Node::is($callback, 'expression.closure') && ! Node::is($callback, 'expression.arrow-function')) {
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
                $this->unknown($source, $expr, Node::is($sql, 'scalar.string') ? 'Raw SQL requires review.' : 'Dynamic SQL cannot be classified.', $trace, 'unresolved_sql');

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
            if ($model && in_array($lower, ['fill', 'forcefill'], true)) {
                // Laravel's inherited forceFill delegates to the model's fill.
                if ($lower === 'forcefill' && $this->sources->method($receiverType, 'fill') !== null) {
                    return $this->visitMethod($receiverType, 'fill', $arguments, $trace, $receiver);
                }

                return $receiver;
            }
            if ($query || $relation) {
                if (in_array($lower, self::WRITES, true) || ($relation && in_array($lower, ['attach', 'detach', 'sync', 'syncwithoutdetaching', 'syncwithpivotvalues', 'toggle', 'updateexistingpivot', 'savemany', 'createmany'], true))) {
                    $this->result->add('write', $source, $expr->getStartLine(), $receiverType.'::'.$method.'()', $trace);

                    return FrameworkValue::scalar();
                }
                if (in_array($lower, self::CHAIN, true)) {
                    if (in_array($lower, ['when', 'unless', 'tap', 'each'], true)) {
                        $this->queryCallbacks($expr, $receiver, $lower, $source, $variables, $trace, $arguments);
                    } else {
                        $this->callbacks($expr->getArgs(), $source, $variables, $trace);
                    }

                    return $model ? FrameworkValue::type('@query:'.$receiverType) : $receiver;
                }
                if (in_array($lower, self::READS, true)) {
                    return $this->queryReadValue($receiverType, $lower, $model);
                }
                if ($model && in_array($lower, self::RELATIONS, true)) {
                    return FrameworkValue::type('@relation');
                }
                if ($model && $lower === 'tokens') {
                    return FrameworkValue::collection(FrameworkValue::type(self::MODEL));
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
            $this->unknown(
                $source,
                $expr,
                $this->sources->unavailableReason($receiverType)
                    ?? 'Unresolved call '.$receiverType.'::'.$method.'().',
                $trace,
                $this->sources->unavailableReason($receiverType) === null ? 'unresolved_call' : $this->sources->unavailableCode($receiverType),
            );

            return null;
        }
        if (Node::is($expr, 'expression.include') || Node::is($expr, 'expression.eval') || Node::is($expr, 'expression.shell-exec')) {
            $this->unknown($source, $expr, 'Dynamic code execution.', $trace);
        }
        foreach ($expr->getSubNodeNames() as $name) {
            $child = $expr->$name;
            foreach (Node::is($child, 'node') ? [$child] : (is_array($child) ? $child : []) as $node) {
                if (Node::is($node, 'node')) {
                    $returns = [];
                    $this->walk([$node], $source, $variables, $trace, $returns);
                }
            }
        }

        return Node::is($expr, 'expression.const-fetch') || Node::is($expr, 'scalar') || Node::is($expr, 'expression.binary-op') || Node::is($expr, 'expression.cast') ? FrameworkValue::scalar() : null;
    }

    /**
     * @param  list<Node>  $args
     * @param  array<string, FrameworkValue|null>  $variables
     * @param  list<string>  $trace
     */
    private function callbacks(array $args, SourceClass $source, array $variables, array $trace): void
    {
        foreach ($args as $arg) {
            if (Node::is($arg->value, 'expression.closure') || Node::is($arg->value, 'expression.arrow-function')) {
                $callback = $arg->value;
                foreach ($callback->params as $param) {
                    if (is_string($param->var->name)) {
                        $variables[$param->var->name] = $this->type($source, $param->type)
                            ?? FrameworkValue::unknown();
                    }
                }
                $returns = [];
                $this->walk(Node::is($callback, 'expression.closure') ? $callback->stmts : [$callback->expr], $source, $variables, $trace, $returns);
            }
        }
    }

    /**
     * @param  array<string, FrameworkValue|null>  $variables
     * @param  list<string>  $trace
     * @param  array<int|string, FrameworkValue|null>  $arguments
     */
    private function queryCallbacks(
        Node $call,
        ?FrameworkValue $receiver,
        string $method,
        SourceClass $source,
        array $variables,
        array $trace,
        array $arguments,
    ): void {
        foreach ($call->getArgs() as $index => $arg) {
            if (! Node::is($arg->value, 'expression.closure') && ! Node::is($arg->value, 'expression.arrow-function')) {
                continue;
            }
            $callback = FrameworkValue::callback($source, $arg->value, $variables);
            $receiverType = $receiver->type ?? '';
            $parameterTypes = match ($method) {
                'when', 'unless' => $index === 0
                    ? [0 => $receiver, 1 => $arguments[0] ?? null, 'query' => $receiver, 'value' => $arguments[0] ?? null]
                    : [0 => $receiver, 1 => $arguments[0] ?? null, 'query' => $receiver, 'default' => $arguments[0] ?? null],
                'tap' => [0 => $receiver, 'query' => $receiver],
                'each' => [
                    0 => str_starts_with($receiverType, '@query:')
                        ? FrameworkValue::type(substr($receiverType, 7))
                        : $receiver,
                    1 => FrameworkValue::scalar(),
                    'item' => str_starts_with($receiverType, '@query:')
                        ? FrameworkValue::type(substr($receiverType, 7))
                        : $receiver,
                    'key' => FrameworkValue::scalar(),
                ],
                default => [],
            };
            $this->walkCallback($callback->withParameters($parameterTypes), $trace);
        }
    }

    /** @param list<string> $trace */
    private function property(string $class, string $name, array $trace, int $depth = 0): ?FrameworkValue
    {
        if ($depth >= 12 || ($source = $this->sources->get($class)) === null) {
            return null;
        }
        if ($name === 'tokens' && $this->sources->isA($class, self::MODEL)) {
            return FrameworkValue::collection(FrameworkValue::type(self::MODEL));
        }
        $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
        $accessor = 'get'.$studly.'Attribute';
        if ($this->sources->isA($class, self::MODEL) && $this->sources->method($class, $accessor) !== null) {
            return $this->visitMethod($class, $accessor, [], $trace);
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
        if (Node::is($source->node, 'statement.class') && $source->node->extends !== null) {
            return $this->property($source->file->resolvedName($source->node->extends), $name, $trace, $depth + 1);
        }

        return null;
    }

    private function type(SourceClass $source, Node|string|null $type): ?FrameworkValue
    {
        if (Node::is($type, 'name')) {
            return FrameworkValue::type($this->className($source, $type));
        }
        if (Node::is($type, 'nullable-type')) {
            return $this->type($source, $type->type)?->nullable();
        }
        if (Node::is($type, 'union-type')) {
            $values = [];
            foreach ($type->types as $part) {
                if (Node::is($part, 'identifier') && strtolower($part->toString()) === 'null') {
                    $values[] = null;
                } elseif (($value = $this->type($source, $part)) !== null) {
                    $values[] = $value;
                }
            }

            return FrameworkValue::union($values);
        }
        if (Node::is($type, 'identifier')) {
            return match (strtolower($type->toString())) {
                'null' => FrameworkValue::scalar()->nullable(),
                'mixed', 'object', 'callable', 'iterable' => null,
                default => FrameworkValue::scalar(),
            };
        }

        return null;
    }

    private function className(SourceClass $source, Node $name): string
    {
        if (strtolower($name->toString()) === 'parent' && Node::is($source->node, 'statement.class') && $source->node->extends !== null) {
            return $source->file->resolvedName($source->node->extends);
        }

        return in_array(strtolower($name->toString()), ['self', 'static'], true) ? $source->name : $this->framework->resolveAlias($source->file->resolvedName($name));
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
        $callbackResults = [];
        foreach ($result->callbacks as $callback) {
            $callbackResults[] = $this->walkCallback($callback, $trace);
        }
        if ($result->incomplete !== null) {
            $this->unknown($source, $expr, $result->incomplete, $trace, $result->incompleteReason);
        }

        return $this->resolveCallbackResults($result->value, $callbackResults);
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
        $this->followReturnedValue($value->element, $source, $node, $variables, $trace);
    }

    /** @param list<string> $trace */
    private function walkCallback(FrameworkValue $value, array $trace): ?FrameworkValue
    {
        if ($value->callback === null || $value->callbackSource === null) {
            return null;
        }
        $variables = $value->captures;
        foreach ($value->callback->params as $index => $param) {
            if (is_string($param->var->name)) {
                $variables[$param->var->name] = $value->parameterTypes[$param->var->name]
                    ?? $value->parameterTypes[$index]
                    ?? $this->type($value->callbackSource, $param->type)
                    ?? FrameworkValue::unknown();
            }
        }
        $returns = [];
        $this->walk(Node::is($value->callback, 'expression.closure') ? $value->callback->stmts : [$value->callback->expr], $value->callbackSource, $variables, [...$trace, '{callback}'], $returns);

        return FrameworkValue::merge($returns);
    }

    /** @param list<string> $trace */
    private function customBuilder(string $model, SourceClass $source, Node $call, array $trace): ?FrameworkValue
    {
        $builder = $this->visitMethod($model, 'newEloquentBuilder', [FrameworkValue::type(self::QUERY)], $trace);
        if ($builder === null || $builder->isUnknown() || $builder->isAmbiguous() || $builder->nullable
            || $builder->type === null || ! $this->sources->isA($builder->type, self::BUILDER)) {
            $this->unknown($source, $call, 'Custom Eloquent builder cannot be resolved unambiguously.', $trace);

            return null;
        }

        return new FrameworkValue(type: $builder->type, element: FrameworkValue::type($model), possibleTypes: [$builder->type]);
    }

    private function queryReadValue(string $receiverType, string $method, bool $model): FrameworkValue
    {
        $element = $model
            ? FrameworkValue::type($receiverType)
            : (str_starts_with($receiverType, '@query:')
                ? FrameworkValue::type(substr($receiverType, 7))
                : FrameworkValue::unknown());

        return match ($method) {
            'get', 'all', 'cursor', 'lazy' => FrameworkValue::collection($element),
            'pluck' => FrameworkValue::collection(FrameworkValue::scalar()),
            'first', 'find', 'solevalue', 'value' => $element->nullable(),
            'firstorfail', 'findorfail' => $element,
            default => FrameworkValue::scalar(),
        };
    }

    /**
     * @param  array<string, FrameworkValue|null>  $variables
     * @return array<string, FrameworkValue|null>
     */
    private function narrow(array $variables, Node $condition, bool $truth, SourceClass $source): array
    {
        if (Node::is($condition, 'expression.boolean-not')) {
            return $this->narrow($variables, $condition->expr, ! $truth, $source);
        }
        if (Node::is($condition, 'expression.instanceof')) {
            return $this->narrowInstance($variables, $condition, $truth, $source);
        }
        if ($truth && (Node::is($condition, 'expression.binary-op.boolean-and') || Node::is($condition, 'expression.binary-op.logical-and'))) {
            $variables = $this->narrow($variables, $condition->left, true, $source);

            return $this->narrow($variables, $condition->right, true, $source);
        }
        if (! $truth && (Node::is($condition, 'expression.binary-op.boolean-or') || Node::is($condition, 'expression.binary-op.logical-or'))) {
            $variables = $this->narrow($variables, $condition->left, false, $source);

            return $this->narrow($variables, $condition->right, false, $source);
        }

        return $variables;
    }

    /**
     * @param  array<string, FrameworkValue|null>  $variables
     * @return array<string, FrameworkValue|null>
     */
    private function narrowInstance(array $variables, Node $condition, bool $truth, SourceClass $source): array
    {
        if (! Node::is($condition->expr, 'expression.variable')
            || ! is_string($condition->expr->name)
            || ! Node::is($condition->class, 'name')) {
            return $variables;
        }
        $name = $condition->expr->name;
        $value = $variables[$name] ?? null;
        if ($value === null || $value->isUnknown()) {
            return $variables;
        }
        $class = $this->className($source, $condition->class);
        if ($truth && ! $this->sources->inScope($class) && array_filter($value->typeNames(),
            fn (string $type): bool => ! $this->sources->inScope($type) && ReflectionSemantics::refinesType($type, $class))) {
            $variables[$name] = FrameworkValue::type($class);

            return $variables;
        }
        $matching = array_values(array_filter(
            $value->typeNames(),
            fn (string $type): bool => strcasecmp($type, $class) === 0 || $this->sources->isA($type, $class),
        ));
        $kept = $truth ? $matching : array_values(array_filter(
            $value->typeNames(),
            fn (string $type): bool => ! in_array($type, $matching, true),
        ));
        $narrowed = $kept === [] ? null : FrameworkValue::union(array_map(FrameworkValue::type(...), $kept));
        $variables[$name] = $narrowed !== null && $value->nullable ? $narrowed->markAmbiguous() : $narrowed;

        return $variables;
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

    /**
     * @param  list<string>  $trace
     */
    private function unknown(SourceClass $source, Node $node, string $detail, array $trace, string $reason = 'unresolved_call'): void
    {
        $this->result->add('unknown', $source, $node->getStartLine(), $detail, $trace, $reason);
    }

    /** @param array<int|string, FrameworkValue|null> $arguments
     * @param  list<string>  $trace
     */
    private function visitFunction(SourceClass $source, Node $node, array $arguments, array $trace): ?FrameworkValue
    {
        $key = strtolower($source->name);
        if (isset($this->active[$key]) || count($trace) >= 12 || ++$this->calls > 128) {
            $this->unknown($source, $node, 'Function cycle or analysis limit reached.', $trace, 'analysis_limit');

            return null;
        }
        $this->active[$key] = true;
        $variables = [];
        foreach ($node->params as $position => $param) {
            if (is_string($param->var->name)) {
                $variables[$param->var->name] = $arguments[$param->var->name] ?? $arguments[$position] ?? $this->type($source, $param->type);
            }
        }
        $returns = [];
        $this->walk($node->stmts, $source, $variables, [...$trace, $node->namespacedName->toString().'()'], $returns);
        unset($this->active[$key]);

        return FrameworkValue::merge($returns) ?? $this->type($source, $node->returnType);
    }
}
