<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/** Abstract interpretation of source expressions; descriptors contain no AST or runtime objects. */
final class DataExtractor
{
    /** @var list<array<string, mixed>> */
    public array $effects = [];

    /** @var list<array<string, mixed>> */
    public array $notices = [];

    public bool $limited = false;

    private int $visits = 0;

    private int $identities = 0;

    /** @var array<string, bool> */
    private array $active = [];

    /** @var list<array<string, mixed>> */
    private array $trail = [];

    private ?string $effectRoot = null;

    public function __construct(private readonly DataCatalog $catalog, private readonly ExecutionSources $sources) {}

    public function extract(FileContext $file): void
    {
        $this->walk($file->ast() ?? [], $file, '(file) '.$file->path, '', [], [], 0);
    }

    /** @param list<Node> $nodes
     * @param  array<string, mixed>  $vars
     * @param  list<string>  $conditions
     * @return array{vars: array<string, mixed>, value: mixed}
     */
    private function walk(array $nodes, FileContext $file, string $from, string $class, array $vars, array $conditions, int $depth): array
    {
        $returns = [];
        foreach ($nodes as $node) {
            if (! $this->room($depth)) {
                break;
            }
            if ($node instanceof Stmt\ClassLike) {
                $name = DataCatalog::name($node, $file);
                foreach ($node->getMethods() as $method) {
                    $local = ['this' => $this->typed($name)];
                    foreach ($node->getProperties() as $property) {
                        foreach ($property->props as $prop) {
                            if ($property->type instanceof Node\Name) {
                                $local['this.'.$prop->name->toString()] = $this->typed($file->resolvedName($property->type));
                            }
                        }
                    }
                    foreach ($node->getMethod('__construct')->params ?? [] as $param) {
                        if ($param->flags !== 0 && $param->type instanceof Node\Name && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                            $local['this.'.$param->var->name] = $this->typed($file->resolvedName($param->type));
                        }
                    }
                    foreach ($method->params as $param) {
                        if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                            $local[$param->var->name] = $param->type instanceof Node\Name ? $this->typed($file->resolvedName($param->type)) : ['dynamic' => true];
                        }
                    }
                    $migration = $this->catalog->inherits($name, 'Illuminate\Database\Migrations\Migration');
                    $conditionsForMethod = $migration ? ['Declared migration '.$method->name->toString().'; application state is not inspected.'] : [];
                    $this->walk($method->stmts ?? [], $file, $name.'::'.$method->name->toString(), $name, $local, $conditionsForMethod, $depth + 1);
                }

                continue;
            }
            if ($node instanceof Stmt\Function_) {
                $this->walk($node->stmts, $file, '(function) '.$file->path.':'.$node->getStartFilePos(), '', [], ['Standalone function reachability is unresolved.'], $depth + 1);

                continue;
            }
            if ($node instanceof Stmt\Return_) {
                $returns[] = $this->value($node->expr, $file, $from, $class, $vars, $conditions, $depth + 1);

                continue;
            }
            if ($node instanceof Stmt\Expression || $node instanceof Expr) {
                $this->value($node instanceof Stmt\Expression ? $node->expr : $node, $file, $from, $class, $vars, $conditions, $depth + 1);

                continue;
            }
            $branch = $node instanceof Stmt\If_ || $node instanceof Stmt\ElseIf_ || $node instanceof Stmt\Else_ || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\For_ || $node instanceof Stmt\While_ || $node instanceof Stmt\Switch_ || $node instanceof Stmt\Case_ || $node instanceof Stmt\TryCatch || $node instanceof Stmt\Catch_;
            foreach ($node->getSubNodeNames() as $key) {
                $child = $node->$key;
                $children = $child instanceof Node ? [$child] : (is_array($child) ? array_values(array_filter($child, fn ($v) => $v instanceof Node)) : []);
                $result = $this->walk($children, $file, $from, $class, $vars, $branch ? [...$conditions, 'Source control-flow condition is not evaluated.'] : $conditions, $depth + 1);
                if ($branch) {
                    foreach ($result['vars'] as $key => $value) {
                        if (($vars[$key] ?? null) !== $value) {
                            $vars[$key] = ['dynamic' => true];
                        }
                    }
                } else {
                    $vars = $result['vars'];
                }
                if ($result['value'] !== null) {
                    $returns[] = $result['value'];
                }
            }
        }
        $value = $returns[0] ?? null;
        foreach ($returns as $candidate) {
            if ($candidate !== $value) {
                $value = null;
                break;
            }
        }

        return ['vars' => $vars, 'value' => $value];
    }

    /** @param array<string, mixed> $vars
     * @param  list<string>  $conditions
     */
    private function value(?Node $node, FileContext $file, string $from, string $class, array &$vars, array $conditions, int $depth): mixed
    {
        if ($node === null || ! $this->room($depth)) {
            return null;
        }
        if ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_ || $node instanceof Expr\ClassConstFetch) {
            return DataCatalog::literal($node, $file, $class);
        }
        if ($node instanceof Expr\ConstFetch) {
            return strtolower($node->name->toString()) === 'null' ? null : ['dynamic' => true];
        }
        if ($node instanceof Expr\Variable) {
            return is_string($node->name) && array_key_exists($node->name, $vars) ? $vars[$node->name] : ['dynamic' => true];
        }
        if ($node instanceof Expr\Assign) {
            $value = $this->value($node->expr, $file, $from, $class, $vars, $conditions, $depth + 1);
            if ($node->var instanceof Expr\Variable && is_string($node->var->name)) {
                $vars[$node->var->name] = $value;
            } elseif ($node->var instanceof Expr\PropertyFetch && $node->var->var instanceof Expr\Variable && is_string($node->var->var->name) && $node->var->name instanceof Node\Identifier) {
                $vars[$node->var->var->name.'.'.$node->var->name->toString()] = $value;
            }

            return $value;
        }
        if ($node instanceof Expr\Array_) {
            $values = [];
            foreach ($node->items as $item) {
                if ($item !== null) {
                    $key = $item->key === null ? count($values) : $this->value($item->key, $file, $from, $class, $vars, $conditions, $depth + 1);
                    $value = $this->value($item->value, $file, $from, $class, $vars, $conditions, $depth + 1);
                    if (is_string($key) || is_int($key)) {
                        $values[$key] = $value;
                    }
                }
            }

            return $values;
        }
        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            // Capture the declaration as a descriptor; creating a callback does not invoke it.
            $captures = $vars;
            if ($node instanceof Expr\Closure) {
                $captures = [];
                foreach ($node->uses as $use) {
                    if (is_string($use->var->name)) {
                        $captures[$use->var->name] = $use->byRef ? ['dynamic' => true] : ($vars[$use->var->name] ?? ['dynamic' => true]);
                    }
                }
                if (! $node->static) {
                    foreach ($vars as $key => $value) {
                        if ($key === 'this' || str_starts_with($key, 'this.')) {
                            $captures[$key] = $value;
                        }
                    }
                }
            }
            foreach ($node->params as $param) {
                if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                    $captures[$param->var->name] = $param->type instanceof Node\Name ? $this->typed($file->resolvedName($param->type)) : ['dynamic' => true];
                }
            }
            $callbackSymbol = '(callback) '.$file->path.':'.$node->getStartFilePos();
            $previousRoot = $this->effectRoot;
            $previousTrail = $this->trail;
            $this->effectRoot = null;
            $this->trail = [];
            $this->walk($node instanceof Expr\Closure ? $node->stmts : [$node->expr], $file, $callbackSymbol, $class, $captures, ['Callback must be invoked through a recognized path.'], $depth + 1);
            $this->effectRoot = $previousRoot;
            $this->trail = $previousTrail;

            return ['type' => 'closure', 'node' => $node, 'vars' => $captures, 'path' => $file->path, 'class' => $class];
        }
        if ($node instanceof Expr\New_) {
            if ($node->class instanceof Stmt\Class_) {
                $this->walk([$node->class], $file, $from, $class, $vars, $conditions, $depth + 1);

                return null;
            }
            foreach ($node->args as $arg) {
                $this->value($arg->value, $file, $from, $class, $vars, $conditions, $depth + 1);
            }

            return $node->class instanceof Node\Name ? $this->typed($file->resolvedName($node->class)) : null;
        }
        if ($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch) {
            if ($node->var instanceof Expr\Variable && is_string($node->var->name) && $node->name instanceof Node\Identifier && isset($vars[$node->var->name.'.'.$node->name->toString()])) {
                return $vars[$node->var->name.'.'.$node->name->toString()];
            }
            $receiver = $this->value($node->var, $file, $from, $class, $vars, $conditions, $depth + 1);
            if (is_array($receiver) && isset($receiver['model']) && $node->name instanceof Node\Identifier) {
                $method = $this->catalog->method($receiver['model'], $node->name->toString());
                if ($method !== null) {
                    $query = $this->invoke($method, $receiver, [], $file, $node, $from, $conditions, $depth + 1);
                    if (is_array($query) && ($query['type'] ?? '') === 'query') {
                        $this->emit($query, ['read'], $file, $node, $from, 'lazy-load', [...$conditions, 'Relation is read only when not already loaded and lazy loading is allowed.']);
                    } else {
                        $this->notice($file, $node, $from, 'Model accessor/relation is unresolved.');
                    }

                    return $query;
                }
            }

            return null;
        }
        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\StaticCall) {
            if ($node->isFirstClassCallable()) {
                return null;
            }
            $method = $node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : null;
            $receiver = null;
            if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name) {
                $name = in_array(strtolower($node->class->toString()), ['self', 'static'], true) ? $class : $file->resolvedName($node->class);
                $receiver = $this->typed($name);
                if (in_array($name, ['Illuminate\Support\Facades\DB', 'Illuminate\Database\DatabaseManager'], true)) {
                    $receiver = ['type' => 'connection', 'connection' => DataCatalog::connection(null)];
                } elseif ($name === 'Illuminate\Support\Facades\Schema') {
                    $connection = $this->catalog->inherits($class, 'Illuminate\Database\Migrations\Migration') ? $this->catalog->property($class, 'connection') : null;
                    $receiver = ['type' => 'schema', 'connection' => DataCatalog::connection($connection)];
                }
            } elseif (! $node instanceof Expr\StaticCall) {
                $receiver = $this->value($node->var, $file, $from, $class, $vars, $conditions, $depth + 1);
            }
            $args = [];
            foreach ($node->args as $arg) {
                $args[$arg->name?->toString() ?? count($args)] = $this->value($arg->value, $file, $from, $class, $vars, $conditions, $depth + 1);
            }
            if ($node instanceof Expr\NullsafeMethodCall) {
                $conditions[] = 'Receiver must be non-null.';
            }
            if ($method === null) {
                $this->notice($file, $node, $from, 'Dynamic DATA call name is unresolved.');

                return null;
            }
            if (! is_array($receiver)) {
                return null;
            }
            $result = $this->call($receiver, $method, $args, $file, $node, $from, $class, $conditions, $depth + 1);
            $root = $node;
            while ($root instanceof Expr\MethodCall || $root instanceof Expr\NullsafeMethodCall) {
                $root = $root->var;
            }
            if ($root instanceof Expr\Variable && is_string($root->name) && ($vars[$root->name]['type'] ?? '') === 'query' && (! ($vars[$root->name]['object'] ?? false) || in_array($method, ['setconnection', 'settable'], true)) && is_array($result) && ($result['type'] ?? '') === 'query') {
                $identity = $vars[$root->name]['identity'] ?? null;
                foreach ($vars as $key => $value) {
                    if (is_array($value) && $identity !== null && ($value['identity'] ?? null) === $identity) {
                        $vars[$key] = $result;
                    }
                }
                $vars[$root->name] = $result;
            }

            return $result;
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name && strtolower($node->name->toString()) === 'tap') {
            $args = [];
            foreach ($node->args as $arg) {
                $args[] = $this->value($arg->value, $file, $from, $class, $vars, $conditions, $depth + 1);
            }

            return $args[0] ?? null;
        }
        foreach ($node->getSubNodeNames() as $key) {
            $child = $node->$key;
            foreach ($child instanceof Node ? [$child] : (is_array($child) ? $child : []) as $value) {
                if ($value instanceof Node) {
                    $this->value($value, $file, $from, $class, $vars, $conditions, $depth + 1);
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $receiver
     * @param  array<int|string, mixed>  $args
     * @param  list<string>  $conditions
     */
    private function call(array $receiver, string $method, array $args, FileContext $file, Node $node, string $from, string $class, array $conditions, int $depth): mixed
    {
        $type = $receiver['type'] ?? '';
        $owner = $receiver['model'] ?? $receiver['class'] ?? null;
        if ($owner !== null && ($own = $this->catalog->method($owner, $method)) !== null) {
            if ($type !== 'query' && ! $this->catalog->returnsData($own)) {
                // Ordinary method effects are reached through ExecutionLinks, not reinterpreted here.
                return null;
            }
            if ($own['scope'] ?? false) {
                $receiver['object'] = false;
                $args = [$receiver, ...array_values($args)];
            }

            return $this->invoke($own, $receiver, $args, $file, $node, $from, $conditions, $depth + 1);
        }
        if ($type === 'query' && isset($receiver['builder']) && ($own = $this->catalog->method($receiver['builder'], $method)) !== null) {
            return $this->invoke($own, $receiver, $args, $file, $node, $from, $conditions, $depth + 1);
        }
        if ($type === 'query' && isset($receiver['model']) && ($scope = $this->catalog->method($receiver['model'], 'scope'.$method)) !== null) {
            $receiver['object'] = false;
            $result = $this->invoke($scope, ['type' => 'object', 'class' => $receiver['model']], [$receiver, ...array_values($args)], $file, $node, $from, $conditions, $depth + 1);

            return is_array($result) ? $result : $receiver;
        }
        if (in_array($type, ['connection', 'schema'], true) && $method === 'connection') {
            $receiver['connection'] = DataCatalog::connection(array_key_exists('name', $args) ? $args['name'] : (array_key_exists(0, $args) ? $args[0] : null));

            return $receiver;
        }
        if ($type === 'connection') {
            if ($method === 'table' || $method === 'query') {
                return $this->query($method === 'table' ? ($args['table'] ?? $args[0] ?? null) : null, $receiver['connection']);
            }
            if (in_array($method, ['select', 'selectone', 'cursor', 'insert', 'update', 'delete', 'statement', 'affectingstatement', 'unprepared'], true)) {
                $sql = $args['query'] ?? $args[0] ?? null;
                if (! is_string($sql)) {
                    $this->notice($file, $node, $from, 'Dynamic SQL is unresolved.');
                } else {
                    $parsed = (new DataSql)->inspect($sql);
                    foreach ($parsed['effects'] as $effect) {
                        $query = $this->query(null, $receiver['connection']);
                        $query['tables'] = [['table' => $effect['table'], 'role' => 'primary']];
                        $query['unknown'] = false;
                        $this->emit($query, [$effect['kind']], $file, $node, $from, 'sql:'.$method, $conditions);
                    }
                    if ($parsed['unknown']) {
                        $this->notice($file, $node, $from, 'SQL syntax/tables are only partially recognized.');
                    }
                }

                return null;
            }
            if ($method === 'transaction') {
                return $this->closure($args['callback'] ?? $args[0] ?? null, $receiver, $file, $node, $from, $class, [...$conditions, 'Transaction callback is invoked; commit/rollback is not evaluated.'], $depth + 1);
            }
            if (in_array($method, ['raw', 'getquerygrammar'], true)) {
                return ['type' => 'raw', 'sql' => $args[0] ?? null];
            }
            $this->notice($file, $node, $from, 'Connection operation is unresolved: '.$method);

            return null;
        }
        if ($type === 'schema') {
            $table = $args['table'] ?? $args[0] ?? null;
            if (in_array($method, ['create', 'table', 'drop', 'dropifexists', 'dropcolumns', 'rename'], true)) {
                $this->emit($this->query($table, $receiver['connection']), ['schema'], $file, $node, $from, $method, $conditions);
                if ($method === 'rename') {
                    $this->emit($this->query($args['to'] ?? $args[1] ?? null, $receiver['connection']), ['schema'], $file, $node, $from, 'rename-target', $conditions);
                }
            } elseif (in_array($method, ['hastable', 'hascolumn', 'hascolumns', 'gettables', 'getcolumns'], true)) {
                $this->emit($this->query($table, $receiver['connection']), ['schema-read'], $file, $node, $from, $method, $conditions);
            } else {
                $this->notice($file, $node, $from, 'Schema operation/table set is unresolved: '.$method);
            }
            foreach ($args as $arg) {
                if (is_array($arg) && ($arg['type'] ?? '') === 'closure') {
                    $this->closure($arg, ['type' => 'blueprint'], $file, $node, $from, $class, $conditions, $depth + 1);
                }
            }

            return null;
        }
        if ($type === 'blueprint') {
            return $receiver;
        }
        if ($type !== 'query') {
            return null;
        }
        if (isset($receiver['model']) && in_array($method, ['hasmany', 'hasone', 'belongsto', 'belongstomany', 'morphmany', 'morphone', 'morphto', 'morphtomany', 'morphedbymany', 'hasmanythrough', 'hasonethrough'], true)) {
            return $this->relation($receiver, $method, $args, $file, $node, $from);
        }
        if (in_array($method, ['setconnection', 'on'], true)) {
            $receiver['connection'] = DataCatalog::connection($args[0] ?? null);

            return $receiver;
        }
        if ($method === 'settable' || $method === 'from') {
            $receiver['tables'] = $this->query($args['table'] ?? $args[0] ?? null, $receiver['connection'])['tables'];
            $receiver['unknown'] = $receiver['tables'] === [];

            return $receiver;
        }
        if ($method === 'newquery' || $method === 'query') {
            $receiver['object'] = false;

            return $receiver;
        }
        if (in_array($method, ['join', 'leftjoin', 'rightjoin', 'crossjoin', 'joinwhere', 'leftjoinwhere'], true)) {
            $table = $this->table($args['table'] ?? $args[0] ?? null);
            $receiver['tables'][] = ['table' => $table, 'role' => 'read'];
            $receiver['unknown'] = $receiver['unknown'] || $table === null;

            return $receiver;
        }
        if (in_array($method, ['fromsub', 'joinsub', 'leftjoinsub', 'rightjoinsub', 'wherexists', 'whereexists', 'wherenotexists', 'union', 'unionall', 'selectsub'], true)) {
            $sub = $args['query'] ?? $args[0] ?? null;
            if (is_array($sub) && ($sub['type'] ?? '') === 'closure') {
                $sub = $this->closure($sub, $this->query(null, $receiver['connection']), $file, $node, $from, $class, $conditions, $depth + 1);
            }
            if (is_string($sub)) {
                $parsed = (new DataSql)->inspect($sub);
                foreach ($parsed['effects'] as $effect) {
                    $receiver['tables'][] = ['table' => $effect['table'], 'role' => 'read'];
                }
                $receiver['unknown'] = $receiver['unknown'] || $parsed['unknown'];
            } elseif (is_array($sub) && ($sub['type'] ?? '') === 'query') {
                if ($method === 'fromsub') {
                    $receiver['tables'] = [];
                }
                foreach ($sub['tables'] as $table) {
                    $receiver['tables'][] = [...$table, 'role' => 'read', 'connection' => $sub['connection']];
                }
                $receiver['unknown'] = $receiver['unknown'] || $sub['unknown'];
            } else {
                $receiver['unknown'] = true;
            }

            return $receiver;
        }
        if (in_array($method, ['with', 'withcount', 'withsum', 'withavg', 'withmin', 'withmax', 'withaggregate', 'has', 'wherehas', 'doesnthave', 'wheredoesnthave'], true)) {
            $relations = $args['relations'] ?? $args['relation'] ?? $args[0] ?? ['dynamic' => true];
            $normalized = $this->relationNames([...$receiver, 'eager' => [], 'eager_constraints' => []], $relations);
            $constraint = $args['callback'] ?? $args[1] ?? null;
            if (in_array($method, ['wherehas', 'wheredoesnthave'], true) && $constraint !== null) {
                foreach ($normalized['eager'] as $relation) {
                    if (is_array($constraint) && ($constraint['type'] ?? '') === 'closure') {
                        $normalized['eager_constraints'][$relation] = $constraint;
                    } else {
                        $normalized['unknown'] = true;
                    }
                }
            }
            $receiver['unknown'] = $receiver['unknown'] || $normalized['unknown'];
            if ($method === 'with') {
                $receiver['eager'] = [...$receiver['eager'], ...$normalized['eager']];
                $receiver['eager_constraints'] = [...($receiver['eager_constraints'] ?? []), ...$normalized['eager_constraints']];
            } else {
                $receiver['relation_reads'] = [...($receiver['relation_reads'] ?? []), ...$normalized['eager']];
                $receiver['relation_constraints'] = [...($receiver['relation_constraints'] ?? []), ...$normalized['eager_constraints']];
            }

            return $receiver;
        }
        if (in_array($method, ['load', 'loadmissing', 'loadcount'], true)) {
            $loading = $receiver;
            $loading['eager'] = [];
            $loading['eager_constraints'] = [];
            foreach ($args as $relations) {
                $loading = $this->relationNames($loading, $relations);
            }
            if ($loading['unknown']) {
                $this->notice($file, $node, $from, 'Explicit load relation names/constraints are unresolved.');
            }
            $this->eager($loading, $file, $node, $from, [...$conditions, $method === 'loadmissing' ? 'Relation must not already be loaded.' : 'Explicit relation load.'], $depth + 1);

            return $receiver;
        }
        $read = ['get', 'first', 'firstorfail', 'find', 'findorfail', 'findmany', 'sole', 'value', 'pluck', 'count', 'sum', 'avg', 'min', 'max', 'exists', 'doesntexist', 'paginate', 'simplepaginate', 'cursorpaginate', 'cursor', 'lazy', 'lazybyid', 'chunk', 'chunkbyid', 'each', 'eachbyid'];
        $write = ['save', 'savequietly', 'saveorfail', 'push', 'pushquietly', 'create', 'createquietly', 'insert', 'insertorignore', 'insertusing', 'update', 'updatequietly', 'updateorfail', 'upsert', 'delete', 'deletequietly', 'destroy', 'forcedelete', 'forcedeletequietly', 'forcedestroy', 'restore', 'restorequietly', 'increment', 'incrementquietly', 'decrement', 'decrementquietly', 'truncate', 'attach', 'detach', 'sync', 'syncwithoutdetaching', 'syncwithpivotvalues', 'toggle', 'updateexistingpivot'];
        $both = ['firstorcreate', 'updateorcreate', 'createorfirst', 'updateorinsert', 'incrementorcreate'];
        if (in_array($method, [...$read, ...$write, ...$both], true)) {
            $kinds = in_array($method, $both, true) ? ['read', 'write'] : [in_array($method, $write, true) ? 'write' : 'read'];
            if (in_array($method, ['sync', 'syncwithoutdetaching', 'syncwithpivotvalues', 'toggle'], true)) {
                $kinds = ['read', 'write'];
            }
            if (in_array($method, ['cursor', 'lazy', 'lazybyid'], true)) {
                $conditions[] = 'Lazy query executes only when consumed.';
            }
            if (in_array($method, $both, true)) {
                $conditions[] = 'Read/write branches depend on matching records and database constraints.';
            }
            $query = $receiver;
            if (in_array($method, ['attach', 'detach', 'sync', 'syncwithoutdetaching', 'syncwithpivotvalues', 'toggle', 'updateexistingpivot'], true)) {
                $query['tables'] = array_values(array_filter($query['tables'], fn ($t) => $t['role'] === 'pivot'));
                $query['unknown'] = $query['unknown'] || $query['tables'] === [];
                $this->notice($file, $node, $from, 'Additional writes from relation touching/custom pivot hooks require inspection.');
            }
            $this->emit($query, $kinds, $file, $node, $from, $method, $conditions);
            if (in_array('read', $kinds, true)) {
                $hydrating = in_array($method, ['get', 'first', 'firstorfail', 'find', 'findorfail', 'findmany', 'sole', 'paginate', 'simplepaginate', 'cursorpaginate', 'lazy', 'lazybyid', 'chunk', 'chunkbyid', 'each', 'eachbyid', ...$both], true);
                $eagerQuery = $receiver;
                if (! $hydrating) {
                    $eagerQuery['eager'] = [];
                    $eagerQuery['eager_constraints'] = [];
                }
                $eagerQuery['eager'] = [...$eagerQuery['eager'], ...($receiver['relation_reads'] ?? [])];
                $eagerQuery['eager_constraints'] = [...($eagerQuery['eager_constraints'] ?? []), ...($receiver['relation_constraints'] ?? [])];
                $this->eager($eagerQuery, $file, $node, $from, $conditions, $depth + 1);
            }
            foreach ($this->descriptors($args) as $arg) {
                if (($arg['type'] ?? '') === 'query') {
                    $this->emit($arg, ['read'], $file, $node, $from, $method.':subquery', $conditions);
                }
            }
            $receiver['object'] = in_array($method, ['first', 'firstorfail', 'find', 'findorfail', 'create', 'createquietly', ...$both], true);
            if (in_array($method, ['push', 'pushquietly'], true)) {
                $this->notice($file, $node, $from, 'Writes to runtime-loaded relations are unresolved.');
            }

            return $receiver['object'] ? $receiver : null;
        }
        if (in_array($method, ['associate', 'dissociate'], true)) {
            // Changes in-memory foreign keys; save is required for a write.
            if (($receiver['relation_kind'] ?? '') === 'belongsto' && isset($receiver['relation_owner'])) {
                return [...$receiver['relation_owner'], 'source_via' => $receiver['source_via'] ?? [], 'preparation_paths' => $receiver['preparation_paths'] ?? []];
            }
            $this->notice($file, $node, $from, 'Association owner is unresolved.');

            return null;
        }
        if (in_array($method, ['replicate', 'fill', 'forcefill', 'make', 'firstornew'], true)) {
            if ($method === 'firstornew') {
                $this->emit($receiver, ['read'], $file, $node, $from, $method, $conditions);
            }
            $receiver['object'] = true;

            return $receiver;
        }
        if (in_array($method, ['where', 'orwhere', 'wherein', 'wherenotin', 'wherecolumn', 'wherenull', 'wherenotnull', 'wherebetween', 'orderby', 'orderbydesc', 'groupby', 'having', 'select', 'addselect', 'distinct', 'limit', 'take', 'skip', 'offset', 'lockforupdate', 'sharedlock', 'withoutglobalscopes', 'withoutglobalscope', 'withtrashed', 'onlytrashed', 'withouttrashed', 'withglobalscope', 'reorder', 'latest', 'oldest', 'when', 'unless', 'wherejsoncontains', 'wheredate', 'wheretime', 'wheremonth', 'whereyear', 'wherekey', 'wherekeynot', 'tosql', 'getbindings', 'tobase'], true)) {
            foreach ($args as $arg) {
                if (is_array($arg) && ($arg['type'] ?? '') === 'closure') {
                    $nested = $this->closure($arg, $receiver, $file, $node, $from, $class, $conditions, $depth + 1);
                    if (is_array($nested) && ($nested['type'] ?? '') === 'query') {
                        $receiver = $nested;
                    }
                } elseif (is_array($arg) && ($arg['type'] ?? '') === 'query') {
                    foreach ($arg['tables'] as $table) {
                        $receiver['tables'][] = [...$table, 'role' => 'read', 'connection' => $arg['connection']];
                    }
                    $receiver['unknown'] = $receiver['unknown'] || $arg['unknown'];
                } elseif (is_array($arg) && ($arg['type'] ?? '') === 'raw') {
                    $receiver['unknown'] = true;
                }
            }
            foreach ($this->descriptors($args) as $arg) {
                if (($arg['type'] ?? '') === 'query') {
                    foreach ($arg['tables'] as $table) {
                        $receiver['tables'][] = [...$table, 'role' => 'read', 'connection' => $arg['connection']];
                    }
                    $receiver['unknown'] = $receiver['unknown'] || $arg['unknown'];
                } elseif (($arg['type'] ?? '') === 'raw') {
                    $receiver['unknown'] = true;
                }
            }
            $receiver['object'] = false;

            return $receiver;
        }
        if (str_ends_with($method, 'raw')) {
            $receiver['unknown'] = true;

            return $receiver;
        }
        $this->notice($file, $node, $from, 'Query/model method or macro is unresolved: '.$method);
        $receiver['unknown'] = true;

        return $receiver;
    }

    /** @param array<string, mixed> $receiver
     * @param  array<int|string, mixed>  $args
     * @return array<string, mixed>
     */
    private function relation(array $receiver, string $method, array $args, FileContext $file, Node $node, string $from): array
    {
        $related = $args['related'] ?? $args[0] ?? null;
        if (! is_string($related) || ! $this->catalog->inherits($related, 'Illuminate\Database\Eloquent\Model')) {
            $query = $this->query(null, $receiver['connection']);
            $query['unknown'] = true;

            return $query;
        }
        $query = [...$this->catalog->model($related), 'identity' => ++$this->identities];
        if ($query['connection']['kind'] === 'default') {
            $query['connection'] = $receiver['connection'];
        }
        $query['object'] = false;
        $query['relation_owner'] = $receiver;
        $query['relation_kind'] = $method;
        if (in_array($method, ['belongstomany', 'morphtomany', 'morphedbymany'], true)) {
            $pivot = $args['table'] ?? ($method === 'belongstomany' ? ($args[1] ?? null) : ($args[2] ?? null));
            if ($pivot === null && $method === 'belongstomany') {
                $joiningTable = $this->catalog->method($receiver['model'], 'joiningTable');
                if ($joiningTable !== null) {
                    $pivot = $joiningTable['literal_return'] ?? ['dynamic' => true];
                } else {
                    $segments = [];
                    foreach ([$receiver['model'], $related] as $model) {
                        $segment = $this->catalog->method($model, 'joiningTableSegment');
                        $segments[] = $segment === null ? Str::snake(class_basename($model)) : ($segment['literal_return'] ?? null);
                    }
                    if (count(array_filter($segments, 'is_string')) === 2) {
                        sort($segments);
                        $pivot = implode('_', $segments);
                    }
                }
            } elseif ($pivot === null) {
                $morph = $args['name'] ?? $args[1] ?? null;
                $pivot = is_string($morph) ? Str::plural($morph) : null;
            }
            $query['tables'][] = ['table' => $this->table($pivot), 'role' => 'pivot'];
            $query['unknown'] = $query['unknown'] || ! is_string($pivot);
        }
        if (in_array($method, ['hasmanythrough', 'hasonethrough'], true)) {
            $through = $args['through'] ?? $args[1] ?? null;
            if (is_string($through) && $this->catalog->inherits($through, 'Illuminate\Database\Eloquent\Model')) {
                foreach ($this->catalog->model($through)['tables'] as $table) {
                    $query['tables'][] = [...$table, 'role' => 'read'];
                }
            } else {
                $query['unknown'] = true;
            }
        }

        return $query;
    }

    /** @param array<string, mixed> $method
     * @param  array<string, mixed>  $receiver
     * @param  array<int|string, mixed>  $args
     * @param  list<string>  $conditions
     */
    private function invoke(array $method, array $receiver, array $args, FileContext $callerFile, Node $site, string $from, array $conditions, int $depth): mixed
    {
        $id = strtolower($method['symbol']);
        if (isset($this->active[$id]) || ! $this->room($depth)) {
            $this->notice($callerFile, $site, $from, 'Recursive/custom DATA return is unresolved.');

            return null;
        }
        $this->active[$id] = true;
        $bodyFile = $this->sources->read($method['path']);
        if ($bodyFile === null) {
            unset($this->active[$id]);
            $this->notice($callerFile, $site, $from, 'Custom DATA source changed or is unavailable.');

            return null;
        }
        $ownerNode = (new NodeFinder)->findFirst($bodyFile->ast() ?? [], fn ($n) => $n instanceof Stmt\ClassLike && DataCatalog::name($n, $bodyFile) === $method['class']);
        $found = $ownerNode instanceof Stmt\ClassLike ? $ownerNode->getMethod($method['name']) : null;
        $this->trail[] = ['from' => $from, 'to' => $method['symbol'], 'kind' => 'data-source-call', 'path' => $callerFile->path, 'line' => $site->getStartLine(), 'certainty' => 'declared', 'conditions' => $conditions];
        $previousRoot = $this->effectRoot;
        $this->effectRoot ??= $from;
        $local = ['this' => $receiver];
        if ($found instanceof Stmt\ClassMethod) {
            foreach ($found->params as $i => $param) {
                if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                    $local[$param->var->name] = $args[$param->var->name] ?? $args[$i] ?? null;
                }
            }
            $result = $this->walk($found->stmts ?? [], $bodyFile, $method['symbol'], $method['class'], $local, $conditions, $depth + 1);
            $value = $result['value'];
            if ($value === null && (($method['scope'] ?? false) || ($method['legacy_scope'] ?? false)) && isset($found->params[0]) && $found->params[0]->var instanceof Expr\Variable && is_string($found->params[0]->var->name)) {
                $value = $result['vars'][$found->params[0]->var->name] ?? null;
            } elseif ($value === null && ($receiver['type'] ?? '') === 'query') {
                $value = $result['vars']['this'] ?? null;
            }
        } else {
            $this->notice($callerFile, $site, $from, 'Custom DATA method body is unavailable.');
            $value = null;
        }
        if (is_array($value) && ($value['type'] ?? '') === 'query') {
            $existing = $value['source_via'] ?? [];
            $value['source_via'] = array_slice($existing, 0, count($this->trail)) === $this->trail ? $existing : $this->trail;
            $value['preparation_paths'] = [...($value['preparation_paths'] ?? []), $this->trail];
        }
        $this->effectRoot = $previousRoot;
        array_pop($this->trail);
        unset($this->active[$id]);
        $bodyFile->releaseAst();

        return $value;
    }

    /** @param array<string, mixed> $receiver
     * @param  list<string>  $conditions
     */
    private function closure(mixed $closure, array $receiver, FileContext $file, Node $site, string $from, string $class, array $conditions, int $depth): mixed
    {
        if (! is_array($closure) || ($closure['type'] ?? '') !== 'closure') {
            $this->notice($file, $site, $from, 'DATA callback is unresolved.');

            return null;
        }
        $node = $closure['node'];
        $vars = $closure['vars'];
        $declarationFile = $this->sources->read($closure['path']);
        if ($declarationFile === null) {
            $this->notice($file, $site, $from, 'DATA callback declaration source changed or is unavailable.');

            return null;
        }
        $parameter = $node->params[0]->var ?? null;
        if ($parameter instanceof Expr\Variable && is_string($parameter->name)) {
            $vars[$parameter->name] = $receiver;
        }
        $conditions[] = 'Recognized query/callback invocation; callback conditions are not evaluated.';
        $previousRoot = $this->effectRoot;
        $this->effectRoot ??= $from;
        $callbackSymbol = '(callback) '.$declarationFile->path.':'.$node->getStartFilePos();
        $this->trail[] = ['from' => $from, 'to' => $callbackSymbol, 'kind' => 'data-callback', 'path' => $file->path, 'line' => $site->getStartLine(), 'certainty' => 'possible', 'conditions' => $conditions];
        if ($node instanceof Expr\ArrowFunction) {
            $value = $this->value($node->expr, $declarationFile, $callbackSymbol, $closure['class'], $vars, $conditions, $depth + 1);
        } else {
            $result = $this->walk($node->stmts, $declarationFile, $callbackSymbol, $closure['class'], $vars, $conditions, $depth + 1);
            $value = $result['value'] ?? ($parameter instanceof Expr\Variable && is_string($parameter->name) ? ($result['vars'][$parameter->name] ?? $receiver) : $receiver);
        }
        if (is_array($value) && ($value['type'] ?? '') === 'query') {
            $value['preparation_paths'] = [...($value['preparation_paths'] ?? []), $this->trail];
        }
        array_pop($this->trail);
        $this->effectRoot = $previousRoot;

        return $value;
    }

    /** @param array<string, mixed> $query
     * @param  list<string>  $conditions
     */
    private function eager(array $query, FileContext $file, Node $site, string $from, array $conditions, int $depth): void
    {
        foreach ($query['eager'] as $name) {
            $current = $query;
            if (! is_string($name)) {
                $this->notice($file, $site, $from, 'Eager relation name is dynamic.');

                continue;
            }
            foreach (explode('.', $name) as $part) {
                $method = isset($current['model']) ? $this->catalog->method($current['model'], $part) : null;
                $current = $method === null ? null : $this->invoke($method, $current, [], $file, $site, $from, $conditions, $depth + 1);
                if (! is_array($current) || ($current['type'] ?? '') !== 'query') {
                    $this->notice($file, $site, $from, 'Eager relation is unresolved: '.$name);
                    break;
                }
                if (isset($query['eager_constraints'][$name])) {
                    $constrained = $this->closure($query['eager_constraints'][$name], $current, $file, $site, $from, $method['class'], $conditions, $depth + 1);
                    if (is_array($constrained) && ($constrained['type'] ?? '') === 'query') {
                        $current = $constrained;
                    }
                }
                $this->emit($current, ['read'], $file, $site, $from, 'eager-load', [...$conditions, 'Relation query depends on parent results.']);
            }
        }
    }

    /** @param array<string, mixed> $query
     * @param  list<string>  $kinds
     * @param  list<string>  $conditions
     */
    private function emit(array $query, array $kinds, FileContext $file, Node $site, string $from, string $operation, array $conditions): void
    {
        $from = $this->effectRoot ?? $from;
        foreach ($query['tables'] as $table) {
            if (! is_string($table['table'])) {
                $this->notice($file, $site, $from, 'DATA table name is unresolved.');

                continue;
            }
            $tableKinds = $table['role'] === 'read' ? ['read'] : $kinds;
            if ($table['role'] === 'pivot' && ! in_array($operation, ['attach', 'detach', 'sync', 'syncwithoutdetaching', 'syncwithpivotvalues', 'toggle', 'updateexistingpivot', 'create', 'createquietly', 'save', 'savequietly', 'firstorcreate', 'updateorcreate', 'createorfirst'], true)) {
                $tableKinds = ['read'];
            }
            foreach ($tableKinds as $kind) {
                if (! $this->room(0)) {
                    return;
                }
                $this->effects[] = ['from' => $from, 'model' => $query['model'] ?? null, 'table' => $table['table'], 'connection' => $table['connection'] ?? $query['connection'], 'kind' => $kind, 'operation' => $operation, 'path' => $file->path, 'line' => $site->getStartLine(), 'offset' => $site->getStartFilePos(), 'role' => $table['role'], 'certainty' => 'possible', 'conditions' => $conditions, 'source_via' => $this->trail, 'preparation_via' => $query['source_via'] ?? [], 'preparation_paths' => $query['preparation_paths'] ?? [], 'migration' => $this->catalog->inherits(explode('::', $from)[0], 'Illuminate\Database\Migrations\Migration') ? (explode('::', $from)[1] ?? null) : null];
            }
        }
        if ($query['unknown'] || $query['connection']['kind'] === 'dynamic' || $query['tables'] === []) {
            $this->notice($file, $site, $from, 'DATA query/table/connection is partially unresolved.');
        }
    }

    /** @return array<string, mixed> */
    private function typed(string $class): array
    {
        if ($this->catalog->inherits($class, 'Illuminate\Database\Eloquent\Model')) {
            return [...$this->catalog->model($class), 'identity' => ++$this->identities];
        }
        if ($this->catalog->inherits($class, 'Illuminate\Database\Eloquent\Builder') || $this->catalog->inherits($class, 'Illuminate\Database\Query\Builder')) {
            return [...$this->query(null, DataCatalog::connection(null)), 'builder' => $class];
        }
        if ($this->catalog->inherits($class, 'Illuminate\Database\Connection') || $class === 'Illuminate\Database\ConnectionInterface') {
            return ['type' => 'connection', 'connection' => DataCatalog::connection(['dynamic' => true])];
        }
        if ($this->catalog->inherits($class, 'Illuminate\Database\Schema\Builder')) {
            return ['type' => 'schema', 'connection' => DataCatalog::connection(['dynamic' => true])];
        }

        return ['type' => 'object', 'class' => $class];
    }

    /** @param array<string, mixed> $connection
     * @return array<string, mixed>
     */
    private function query(mixed $table, array $connection): array
    {
        $name = $this->table($table);

        return ['type' => 'query', 'identity' => ++$this->identities, 'tables' => $name === null ? [] : [['table' => $name, 'role' => 'primary']], 'connection' => $connection, 'eager' => [], 'unknown' => $name === null, 'object' => false];
    }

    private function table(mixed $table): ?string
    {
        if (! is_string($table)) {
            return null;
        }
        $name = preg_split('/\s+(?:as\s+)?/i', trim($table))[0];

        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_$]*(?:\.[a-zA-Z_][a-zA-Z0-9_$]*)*$/D', $name) ? $name : null;
    }

    /** @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function relationNames(array $query, mixed $relations): array
    {
        if (is_string($relations)) {
            $query['eager'][] = explode(':', $relations)[0];

            return $query;
        }
        if (! is_array($relations) || isset($relations['dynamic']) || isset($relations['type'])) {
            $query['unknown'] = true;

            return $query;
        }
        foreach ($relations as $key => $value) {
            $name = is_string($key) ? $key : $value;
            if (! is_string($name)) {
                $query['unknown'] = true;

                continue;
            }
            $query['eager'][] = explode(':', $name)[0];
            if (is_string($key)) {
                if (is_array($value) && ($value['type'] ?? '') === 'closure') {
                    $query['eager_constraints'][$name] = $value;
                } else {
                    $query['unknown'] = true;
                }
            }
        }

        return $query;
    }

    /** @return list<array<string, mixed>> */
    private function descriptors(mixed $value, int $depth = 0): array
    {
        if (! is_array($value) || $depth > 32) {
            return [];
        }
        if (isset($value['type'])) {
            return [$value];
        }
        $result = [];
        foreach ($value as $item) {
            array_push($result, ...$this->descriptors($item, $depth + 1));
        }

        return $result;
    }

    private function notice(FileContext $file, Node $node, string $from, string $reason): void
    {
        if (count($this->notices) < 10000) {
            $this->notices[] = ['from' => $this->effectRoot ?? $from, 'path' => $file->path, 'line' => $node->getStartLine(), 'reason' => $reason, 'source_via' => $this->trail];
        } else {
            $this->limited = true;
        }
    }

    private function room(int $depth): bool
    {
        if (++$this->visits > 100000 || $depth > 64 || count($this->effects) >= 10000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;

            return false;
        }

        return true;
    }
}
