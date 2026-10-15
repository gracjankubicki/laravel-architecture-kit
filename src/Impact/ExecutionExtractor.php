<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Derived;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Finder as NodeFinder;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;

/** AST-free descriptions for the execution channel; never persisted as ImpactFacts. */
final class ExecutionExtractor
{
    /** @var array<string, mixed> */
    private array $result;

    private FileContext $file;

    /** @var array<string, mixed> */
    private array $scheduleAttributes = [];

    private ?string $callbackParameter = null;

    /** @var array<string, array<string, mixed>> */
    private array $schedulePending = [];

    private int $visits = 0;

    /** @var array<int, array<string, mixed>> */
    private array $authorizationContexts = [];

    /** @var array<string, string> */
    private array $parents = [];

    private bool $catalogMode = false;

    /** @return array<string, mixed> */
    public function extract(FileContext $file, bool $catalog = false): array
    {
        $this->file = $file;
        $this->catalogMode = $catalog;
        $this->parents = [];
        $this->visits = 0;
        $this->authorizationContexts = [];
        $this->scheduleAttributes = [];
        $this->schedulePending = [];
        $this->callbackParameter = null;
        $this->result = ['classes' => [], 'class_declarations' => [], 'operations' => [], 'calls' => [], 'notices' => [], 'paths' => [], 'returns' => [], 'limited' => false, ...($catalog ? ['middleware_returns' => []] : [])];
        $nodes = $file->syntax();
        if ($nodes === null) {
            $this->notice(null, 'Unparseable execution source.');
        } else {
            $this->authorizationContext($nodes);
            $this->walk($nodes, '(file) '.$file->path, '', [], []);
        }

        return $this->result;
    }

    /** @param list<Node> $nodes
     * @param  array<string, mixed>  $vars
     * @param  list<string>  $conditions
     * @return array<string, mixed>
     */
    private function walk(array $nodes, string $from, string $class, array $vars, array $conditions, int $depth = 0): array
    {
        foreach ($nodes as $node) {
            if (! $this->room($node, $depth)) {
                break;
            }
            if (Node::is($node, 'statement.class-like')) {
                if (! isset($node->namespacedName) && ! $this->catalogMode) {
                    $this->notice($node, 'Anonymous execution class is unresolved.');

                    continue;
                }
                $name = isset($node->namespacedName) ? $node->namespacedName->toString() : '(anonymous) '.$this->file->path.':'.$node->getStartFilePos();
                $meta = ['name' => $name, 'path' => $this->file->path, 'line' => $node->getStartLine(), 'offset' => $node->getStartFilePos(), 'parents' => [], 'traits' => [], 'properties' => [], 'methods' => [], 'attributes' => [], 'abstract' => Node::is($node, 'statement.class') && $node->isAbstract(), 'kind' => Node::is($node, 'statement.interface') ? 'interface' : (Node::is($node, 'statement.trait') ? 'trait' : (Node::is($node, 'statement.enum') ? 'enum' : 'class'))];
                if (Node::is($node, 'statement.class') && $node->extends !== null) {
                    $meta['parents'][] = $this->file->resolvedName($node->extends);
                    $this->parents[strtolower($name)] = $meta['parents'][0];
                }
                foreach (Node::is($node, 'statement.class') ? $node->implements : (Node::is($node, 'statement.interface') ? $node->extends : []) as $parent) {
                    $meta['parents'][] = $this->file->resolvedName($parent);
                }
                foreach ($node->getTraitUses() as $use) {
                    if ($use->adaptations !== []) {
                        $meta['adaptations'] = true;
                    }
                    foreach ($use->traits as $trait) {
                        $meta['traits'][] = $this->file->resolvedName($trait);
                    }
                }
                foreach ($node->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        $values = [];
                        foreach ($attribute->args as $arg) {
                            $values[$arg->name?->toString() ?? count($values)] = $this->value($arg->value, $from, $name, [], [], $depth + 1);
                        }
                        $meta['attributes'][$this->file->resolvedName($attribute->name)] = $values;
                    }
                }
                $classVars = ['this' => ['type' => 'object', 'class' => $name]];
                foreach ($node->getProperties() as $property) {
                    foreach ($property->props as $prop) {
                        $meta['properties'][$prop->name->toString()] = $prop->default === null ? null : $this->value($prop->default, $from, $name, [], [], $depth + 1);
                        $classVars['this.'.$prop->name->toString()] = $this->typed($property->type, $name);
                    }
                }
                foreach ($node->getMethod('__construct')->params ?? [] as $param) {
                    if ($param->flags !== 0 && Node::is($param->var, 'expression.variable') && is_string($param->var->name)) {
                        $classVars['this.'.$param->var->name] = $this->typed($param->type, $name);
                    }
                }
                foreach ($node->getMethods() as $method) {
                    $methodVars = $classVars;
                    foreach ($method->params as $param) {
                        if (Node::is($param->var, 'expression.variable') && is_string($param->var->name)) {
                            $methodVars[$param->var->name] = $this->typed($param->type, $name);
                            if ($param->flags !== 0) {
                                $methodVars['this.'.$param->var->name] = $methodVars[$param->var->name];
                                $classVars['this.'.$param->var->name] = $methodVars[$param->var->name];
                            }
                        }
                    }
                    $symbol = $name.'::'.$method->name->toString();
                    $methodVars = $this->walk($method->stmts ?? [], $symbol, $name, $methodVars, [], $depth + 1);
                    $meta['methods'][strtolower($method->name->toString())] = ['symbol' => $symbol, 'name' => $method->name->toString(), 'public' => $method->isPublic(), 'abstract' => Node::is($node, 'statement.interface') || $method->isAbstract(), 'parameter' => isset($method->params[0]) ? $this->types($method->params[0]->type, $name) : [], 'returns' => $this->result['returns'][$symbol] ?? [], 'parameters' => array_map(fn ($p) => ['name' => is_string($p->var->name) ? $p->var->name : '', 'types' => $this->types($p->type, $name), 'nullable' => AuthorizationSignature::allowsGuests($p), 'route_resolvable' => AuthorizationSignature::routeResolvable($p->type)], $method->params), ...($this->catalogMode ? ['middleware_candidates' => $this->result['middleware_returns'][$symbol] ?? []] : []), 'conditional_return' => count(array_filter($method->stmts ?? [], fn ($s) => Node::is($s, 'statement.return'))) !== 1, 'source' => $this->site($method)];
                }
                $this->result['classes'][strtolower($name)] = $meta;
                $this->result['class_declarations'][] = $meta;

                continue;
            }
            if (Node::is($node, 'statement.function')) {
                if ($this->catalogMode) {
                    $functionVars = [];
                    foreach ($node->params as $param) {
                        if (Node::is($param->var, 'expression.variable') && is_string($param->var->name)) {
                            $functionVars[$param->var->name] = $this->typed($param->type, '');
                        }
                    }
                    $symbol = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
                    $this->walk($node->stmts, $symbol, '', $functionVars, $conditions, $depth + 1);
                } else {
                    $this->notice($node, 'Standalone function execution bodies are unresolved.');
                }

                continue;
            }
            if (Node::is($node, 'statement.return') && $node->expr !== null) {
                $value = $this->value($node->expr, $from, $class, $vars, $conditions, $depth + 1);
                $this->result['returns'][$from][] = $value;
                if ($this->catalogMode) {
                    $this->result['middleware_returns'][$from][] = $this->middlewareCandidate($node->expr, $value, $class);
                }

                continue;
            }
            if (Node::is($node, 'statement.expression')) {
                if (Node::is($node->expr, 'expression.assign') && Node::is($node->expr->var, 'expression.variable') && is_string($node->expr->var->name)) {
                    $vars[$node->expr->var->name] = $this->value($node->expr->expr, $from, $class, $vars, $conditions, $depth + 1);
                } else {
                    $this->value($node->expr, $from, $class, $vars, $conditions, $depth + 1);
                }
                if ($this->catalogMode && (Node::is($node->expr, 'expression.assign') || Node::is($node->expr, 'expression.assign-op') || Node::is($node->expr, 'expression.assign-ref'))) {
                    $target = $node->expr->var;
                    if (Node::is($target, 'expression.array-dim-fetch')) {
                        while (Node::is($target, 'expression.array-dim-fetch')) {
                            $target = $target->var;
                        }
                        if (Node::is($target, 'expression.variable') && is_string($target->name)) {
                            $vars[$target->name] = null;
                        }
                    }
                    if (Node::is($node->expr, 'expression.assign-ref') && Node::is($node->expr->expr, 'expression.variable') && is_string($node->expr->expr->name)) {
                        $vars[$node->expr->expr->name] = null;
                    }
                }
                foreach ((new NodeFinder)->findOfKind([$node->expr], 'expression.call-like') as $call) {
                    if ($call->isFirstClassCallable()) {
                        continue;
                    }
                    foreach ($call->getArgs() as $arg) {
                        if (Node::is($arg->value, 'expression.variable') && is_string($arg->value->name)) {
                            $vars[$arg->value->name] = null;
                        }
                    }
                }

                continue;
            }
            if ($this->catalogMode && Node::is($node, 'statement.unset')) {
                foreach ($node->vars as $target) {
                    while (Node::is($target, 'expression.array-dim-fetch')) {
                        $target = $target->var;
                    }
                    if (Node::is($target, 'expression.variable') && is_string($target->name)) {
                        $vars[$target->name] = null;
                    }
                }
            }
            if (Node::is($node, 'expression')) {
                $this->value($node, $from, $class, $vars, $conditions, $depth + 1);

                continue;
            }
            $branch = Node::is($node, 'statement.if') || Node::is($node, 'statement.else-if') || Node::is($node, 'statement.else') || Node::is($node, 'statement.try-catch') || Node::is($node, 'statement.catch') || Node::is($node, 'statement.switch') || Node::is($node, 'statement.case') || Node::is($node, 'statement.for') || Node::is($node, 'statement.foreach') || Node::is($node, 'statement.while');
            $next = $branch ? [...$conditions, 'Source control-flow condition is not evaluated.'] : $conditions;
            foreach ($node->getSubNodeNames() as $key) {
                $child = $node->$key;
                $children = Node::is($child, 'node') ? [$child] : (is_array($child) ? array_values(array_filter($child, fn ($v) => Node::is($v, 'node'))) : []);
                $changed = $this->walk($children, $from, $class, $vars, $next, $depth + 1);
                if ($branch) {
                    foreach ($changed as $var => $value) {
                        if (($vars[$var] ?? null) !== $value) {
                            $vars[$var] = null;
                        }
                    }
                } else {
                    $vars = $changed;
                }
            }
        }

        return $vars;
    }

    /** @param array<string, mixed> $vars
     * @param  list<string>  $conditions
     */
    private function value(?Node $expr, string $from, string $class, array $vars, array $conditions, int $depth): mixed
    {
        if ($expr === null || ! $this->room($expr, $depth)) {
            return null;
        }
        if (Node::is($expr, 'expression.closure') || Node::is($expr, 'expression.arrow-function')) {
            $injectedParameter = $this->callbackParameter;
            $this->callbackParameter = null;
            $symbol = '(callback) '.$this->file->path.':'.$expr->getStartFilePos();
            if (Node::is($expr, 'expression.closure')) {
                $captures = [];
                foreach ($expr->uses as $use) {
                    if (is_string($use->var->name)) {
                        $captures[$use->var->name] = $use->byRef ? null : ($vars[$use->var->name] ?? null);
                    }
                }
                foreach ($vars as $var => $value) {
                    if (! $expr->static && ($var === 'this' || str_starts_with($var, 'this.'))) {
                        $captures[$var] = $value;
                    }
                }
                $vars = $captures;
            } elseif ($expr->static) {
                $vars = array_filter($vars, fn ($var) => $var !== 'this' && ! str_starts_with($var, 'this.'), ARRAY_FILTER_USE_KEY);
            }
            foreach ($expr->params as $param) {
                if (Node::is($param->var, 'expression.variable') && is_string($param->var->name)) {
                    $vars[$param->var->name] = $this->typed($param->type, $class) ?? ($injectedParameter === null ? null : ['type' => 'object', 'class' => $injectedParameter]);
                }
            }
            $this->walk(Node::is($expr, 'expression.closure') ? $expr->stmts : ($this->catalogMode ? [Derived::returnValue($expr->expr)] : [$expr->expr]), $symbol, $class, $vars, [], $depth + 1);

            return ['type' => 'callback', 'symbol' => $symbol, 'source' => $this->site($expr), 'guest' => isset($expr->params[0]) && AuthorizationSignature::allowsGuests($expr->params[0]), 'events' => isset($expr->params[0]) ? $this->types($expr->params[0]->type, $class) : []];
        }
        if (Node::is($expr, 'expression.array')) {
            $array = [];
            foreach ($expr->items as $item) {
                if ($item === null) {
                    continue;
                }
                if ($item->unpack) {
                    $this->notice($item, 'Unpacked execution collection is unresolved.');

                    return null;
                }
                $value = $this->value($item->value, $from, $class, $vars, $conditions, $depth + 1);
                $key = $item->key === null ? null : $this->literal($item->key, $class, $vars);
                if ($item->key === null) {
                    $array[] = $value;
                } elseif (is_string($key) || is_int($key)) {
                    $array[$key] = $value;
                } else {
                    $this->notice($item, 'Dynamic execution collection key is unresolved.');
                }
            }

            if ($this->catalogMode) {
                $descriptor = ['type' => 'catalog-array', 'items' => $array];
                $descriptor['middleware_items'] = $this->middlewareCandidate($expr, $descriptor, $class);

                return $descriptor;
            }

            return $array;
        }
        if (Node::is($expr, 'expression.new')) {
            $type = Node::is($expr->class, 'name') ? $this->name($expr->class, $class) : null;
            if ($this->catalogMode && $type === 'Illuminate\Routing\Controllers\Middleware') {
                $args = [];
                foreach ($expr->getArgs() as $arg) {
                    $args[$arg->name?->toString() ?? count($args)] = $this->value($arg->value, $from, $class, $vars, $conditions, $depth + 1);
                }

                return ['type' => 'controller_middleware', 'middleware' => $args['middleware'] ?? $args[0] ?? null,
                    'only' => $args['only'] ?? $args[1] ?? [], 'except' => $args['except'] ?? $args[2] ?? [], 'source' => $this->site($expr)];
            }
            if ($this->catalogMode && Node::is($expr->class, 'statement.class')) {
                $type = '(anonymous) '.$this->file->path.':'.$expr->class->getStartFilePos();
                $this->walk([$expr->class], $from, $class, [], $conditions, $depth + 1);
            }
            $this->call($expr, $from, $type, '__construct', $conditions);
            foreach ($expr->getArgs() as $arg) {
                $this->value($arg->value, $from, $class, $vars, $conditions, $depth + 1);
            }

            return $type === null ? null : ['type' => 'object', 'class' => $type];
        }
        if (Node::is($expr, 'expression.call-like')) {
            if ($expr->isFirstClassCallable()) {
                return $this->callableReference($expr, $class, $vars);
            }
            $method = Node::is($expr, 'expression.func-call') ? (Node::is($expr->name, 'name') ? $this->file->resolvedName($expr->name) : '') : ((Node::is($expr, 'expression.static-call') || Node::is($expr, 'expression.method-call') || Node::is($expr, 'expression.nullsafe-method-call')) && Node::is($expr->name, 'identifier') ? $expr->name->toString() : '');
            $receiver = Node::is($expr, 'expression.static-call') ? (Node::is($expr->class, 'name') ? ['type' => 'class', 'class' => $this->name($expr->class, $class)] : null) : ((Node::is($expr, 'expression.method-call') || Node::is($expr, 'expression.nullsafe-method-call')) ? $this->value($expr->var, $from, $class, $vars, $conditions, $depth + 1) : null);
            $ownerHint = is_array($receiver) ? ($receiver['class'] ?? null) : null;
            $savedAttributes = $this->scheduleAttributes;
            if (strtolower($method) === 'group' && ($receiver['type'] ?? '') === 'schedule_attributes') {
                $this->scheduleAttributes = $receiver['options'];
                unset($this->schedulePending[$from]);
            }
            $args = [];
            foreach ($expr->getArgs() as $arg) {
                $argumentVars = $vars;
                $savedParameter = $this->callbackParameter;
                if ($ownerHint === 'Illuminate\\Foundation\\Configuration\\ApplicationBuilder' && strtolower($method) === 'withmiddleware') {
                    $this->callbackParameter = 'Illuminate\\Foundation\\Configuration\\Middleware';
                }
                if ($ownerHint === 'Illuminate\\Foundation\\Configuration\\ApplicationBuilder' && strtolower($method) === 'withschedule') {
                    $this->callbackParameter = 'Illuminate\\Console\\Scheduling\\Schedule';
                }
                if ($this->catalogMode && $ownerHint === 'Illuminate\\Foundation\\Configuration\\ApplicationBuilder' && strtolower($method) === 'withexceptions') {
                    $this->callbackParameter = 'Illuminate\\Foundation\\Configuration\\Exceptions';
                }
                if (($ownerHint === 'Illuminate\\Support\\Facades\\Artisan' || $this->kernelOwner($ownerHint)) && strtolower($method) === 'command') {
                    $argumentVars['this'] = ['type' => 'object', 'class' => 'Illuminate\\Foundation\\Console\\ClosureCommand'];
                }
                if (strtolower($method) === 'group' && ($receiver['type'] ?? '') === 'schedule_attributes') {
                    $this->callbackParameter = 'Illuminate\\Console\\Scheduling\\Schedule';
                }
                $args[$arg->name?->toString() ?? count($args)] = $this->value($arg->value, $from, $class, $argumentVars, $conditions, $depth + 1);
                $this->callbackParameter = $savedParameter;
            }
            $this->scheduleAttributes = $savedAttributes;
            $owner = is_array($receiver) ? ($receiver['class'] ?? null) : null;
            $short = Node::is($expr, 'expression.func-call') && $method === 'dispatch_sync' ? 'dispatchsync' : strtolower($method);
            // Keep application methods whose names also belong to Laravel APIs.
            if ($owner !== null && in_array($short, ['dispatch', 'dispatchsync', 'dispatchnow', 'dispatchafterresponse', 'dispatchif', 'dispatchunless', 'withchain', 'withoutevents', 'observe', 'created', 'creating', 'updated', 'updating', 'saving', 'saved', 'deleting', 'deleted', 'retrieved', 'restoring', 'restored', 'trashed', 'replicating', 'forcedeleting', 'forcedeleted'], true)) {
                $this->call($expr, $from, $owner, $method, $conditions);
            }
            $site = $this->site($expr);
            if ($this->catalogMode && ($receiver['type'] ?? null) === 'controller_middleware' && in_array($short, ['only', 'except'], true)) {
                $receiver[$short] = $args[$short] ?? $args[0] ?? null;

                return $receiver;
            }
            $op = ['from' => $from, 'source' => $site, 'conditions' => $conditions, 'method' => $short, 'owner' => $owner, 'receiver' => $receiver, 'args' => $args];
            if ($this->catalogMode && $short === 'middleware' && $owner !== null && str_ends_with(strtolower($from), '::__construct')
                && Node::is($expr, 'expression.method-call') && Node::is($expr->var, 'expression.variable') && $expr->var->name === 'this') {
                $this->result['operations'][] = ['kind' => 'middleware_controller', ...$op];

                return ['type' => 'controller_middleware_options', 'registration_site' => $site, 'options' => []];
            }
            if ($this->catalogMode && ($receiver['type'] ?? null) === 'controller_middleware_options' && in_array($short, ['only', 'except'], true)) {
                $receiver['options'][$short] = $args['methods'] ?? $args[$short] ?? (count($args) > 1 ? $args : ($args[0] ?? null));
                $this->result['operations'][] = ['kind' => 'middleware_controller_options', ...$op, 'registration_site' => $receiver['registration_site'], 'options' => $receiver['options']];

                return $receiver;
            }
            if ($this->catalogMode && Node::is($expr, 'expression.func-call') && Node::is($expr->name, 'name')) {
                $op['helper'] = $method;
            }
            if (in_array($short, ['authorize', 'authorizeforuser', 'authorizeresource', 'allows', 'denies', 'check', 'inspect', 'any', 'none', 'can', 'cannot', 'cant', 'canany', 'allowif', 'denyif', 'policy', 'define', 'before', 'after', 'guesspolicynamesusing', 'foruser'], true)) {
                $this->result['operations'][] = ['kind' => 'authorization_candidate', ...$op, ...($this->authorizationContexts[$expr->getStartFilePos()] ?? [])];
                if ($short === 'foruser' && in_array($owner, ['Illuminate\Support\Facades\Gate', 'Illuminate\Contracts\Auth\Access\Gate', 'Illuminate\Auth\Access\Gate'], true)) {
                    return ['type' => 'object', 'class' => $owner, 'authorization_user' => $args['user'] ?? $args[0] ?? null];
                }
            }
            if ($owner === 'Illuminate\Foundation\Configuration\Middleware' && $short === 'alias') {
                $this->result['operations'][] = ['kind' => 'middleware_alias', ...$op];
            }
            if ($this->catalogMode && $owner === 'Illuminate\Foundation\Configuration\ApplicationBuilder' && $short === 'withmiddleware') {
                $this->result['operations'][] = ['kind' => 'middleware_registration', ...$op];
            }
            if ($this->catalogMode && $owner === 'Illuminate\Foundation\Configuration\Middleware' && in_array($short, ['use', 'append', 'prepend', 'remove', 'replace'], true)) {
                $this->result['operations'][] = ['kind' => 'middleware_global', ...$op];

                return $receiver;
            }
            if ($this->catalogMode && $owner === 'Illuminate\Foundation\Configuration\Middleware' && in_array($short, ['group', 'appendtogroup', 'prependtogroup', 'removefromgroup', 'replaceingroup', 'web', 'api'], true)) {
                $this->result['operations'][] = ['kind' => 'middleware_group', ...$op];

                return $receiver;
            }
            if ($this->catalogMode && in_array($owner, ['Illuminate\Support\Facades\Auth', 'Illuminate\Auth\AuthManager'], true) && in_array($short, ['extend', 'provider', 'viarequest'], true)) {
                $this->result['operations'][] = ['kind' => 'auth_registration', ...$op];
            }
            if ($this->catalogMode && $owner === 'Illuminate\Foundation\Configuration\ApplicationBuilder' && $short === 'withexceptions') {
                $this->result['operations'][] = ['kind' => 'exception_activation', ...$op];
            }
            if ($this->catalogMode && ($receiver['type'] ?? null) === 'exception_report_handler' && $short === 'stop') {
                $this->result['operations'][] = ['kind' => 'exception_options', ...$op, 'registration_site' => $receiver['registration_site']];

                return $receiver;
            }
            if ($this->catalogMode && $owner !== null && in_array($short, ['report', 'render', 'reportable', 'renderable'], true)) {
                $this->result['operations'][] = ['kind' => 'exception_registration', ...$op];

                return in_array($short, ['report', 'reportable'], true)
                    ? ['type' => 'exception_report_handler', 'registration_site' => $site] : $receiver;
            }
            if ($this->catalogMode && $owner !== null && in_array($short, ['dontreport', 'ignore', 'stopignoring', 'dontreportwhen', 'dontreportduplicates'], true)) {
                $this->result['operations'][] = ['kind' => 'exception_control', ...$op];

                return $receiver;
            }
            $bus = $owner === 'Illuminate\Support\Facades\Bus' || in_array($owner, ['Illuminate\Contracts\Bus\Dispatcher', 'Illuminate\Contracts\Bus\QueueingDispatcher', 'Illuminate\Bus\Dispatcher'], true);
            $events = $owner === 'Illuminate\Support\Facades\Event' || in_array($owner, ['Illuminate\Contracts\Events\Dispatcher', 'Illuminate\Events\Dispatcher'], true);
            $global = Node::is($expr, 'expression.func-call') && ! str_contains($method, '\\');
            if ($global && $short === 'auth') {
                return ['type' => 'object', 'class' => 'Illuminate\Contracts\Auth\Guard'];
            }
            if ($short === 'user' && (in_array($owner, ['Illuminate\Http\Request', 'Illuminate\Foundation\Http\FormRequest', 'Illuminate\Contracts\Auth\Guard', 'Illuminate\Support\Facades\Auth'], true) || $owner !== null && in_array($this->parents[strtolower($owner)] ?? null, ['Illuminate\Http\Request', 'Illuminate\Foundation\Http\FormRequest'], true))) {
                return ['type' => 'object', 'class' => 'Illuminate\Contracts\Auth\Access\Authorizable'];
            }
            if ($global && in_array($short, ['app', 'resolve'], true) && is_string($args[0] ?? null)) {
                return ['type' => 'object', 'class' => $args[0]];
            }
            if ($bus && $short === 'map') {
                $this->result['operations'][] = ['kind' => 'bus_map', ...$op];

                return null;
            }
            if ($short === 'withevents' && $owner === 'Illuminate\Foundation\Configuration\ApplicationBuilder') {
                $this->result['operations'][] = ['kind' => 'discovery', ...$op];
            }
            if ($owner === 'Illuminate\Foundation\Application' && $short === 'configure') {
                $this->result['operations'][] = ['kind' => 'application', ...$op];

                return ['type' => 'object', 'class' => 'Illuminate\Foundation\Configuration\ApplicationBuilder'];
            }
            if ($owner === 'Illuminate\Foundation\Configuration\ApplicationBuilder' && in_array($short, ['withcommands', 'withrouting', 'withschedule'], true)) {
                $consoleCommandsNull = false;
                if ($this->catalogMode && $short === 'withrouting') {
                    foreach ($expr->getArgs() as $position => $arg) {
                        if (($arg->name?->toString() ?? $position) === 'commands' || $arg->name === null && $position === 3) {
                            $consoleCommandsNull = Node::is($arg->value, 'expression.const-fetch') && strtolower($arg->value->name->toString()) === 'null';
                        }
                    }
                }
                $this->result['operations'][] = ['kind' => 'console_registration', ...$op, ...($this->catalogMode ? ['console_commands_null' => $consoleCommandsNull] : [])];
            }
            if (($owner === 'Illuminate\Support\Facades\Artisan' || $this->kernelOwner($owner)) && $short === 'command') {
                $this->result['operations'][] = ['kind' => 'console_closure', ...$op];

                return ['type' => 'console_closure', 'registration_site' => $site, 'options' => []];
            }
            if (($receiver['type'] ?? '') === 'console_closure') {
                $receiver['options'][$short] = $args;
                $this->result['operations'][] = ['kind' => 'console_closure_options', ...$op, 'registration_site' => $receiver['registration_site'], 'options' => $receiver['options']];

                return $receiver;
            }
            if (in_array($short, ['call', 'queue', 'callsilent', 'callsilently', 'command', 'commands', 'load', 'registercommand', 'addcommands', 'addcommandpaths', 'addcommandroutepaths'], true) && $owner !== null) {
                $this->result['operations'][] = ['kind' => 'console_candidate', ...$op];
            }
            $schedule = in_array($owner, ['Illuminate\Support\Facades\Schedule', 'Illuminate\Console\Scheduling\Schedule'], true);
            if ($schedule && in_array($short, ['command', 'job', 'call', 'exec'], true)) {
                $options = $this->schedulePending[$from] ?? $this->scheduleAttributes;
                unset($this->schedulePending[$from]);
                $this->result['operations'][] = ['kind' => 'schedule', ...$op, 'options' => $options];

                return ['type' => 'schedule_event', 'schedule_site' => $site, 'options' => $options];
            }
            if ($schedule || in_array($receiver['type'] ?? '', ['schedule_event', 'schedule_attributes'], true)) {
                if ($short === 'group') {
                    $this->result['operations'][] = ['kind' => 'schedule_group', ...$op];

                    return null;
                }
                $descriptor = $receiver;
                $descriptor['type'] ??= 'schedule_attributes';
                // A typed Schedule starts an attributes builder, not an application object.
                if ($schedule) {
                    $descriptor['type'] = 'schedule_attributes';
                }
                $descriptor['options'] ??= $this->schedulePending[$from] ?? $this->scheduleAttributes;
                if (in_array($short, ['before', 'after', 'then', 'onsuccess', 'onfailure', 'when', 'skip', 'thenwithoutput', 'onsuccesswithoutput', 'onfailurewithoutput'], true)) {
                    $descriptor['options']['callbacks'][] = ['method' => $short, 'value' => $args['callback'] ?? $args[0] ?? null, 'source' => $site];
                } else {
                    $descriptor['options'][$short] = $args === [] ? true : $args;
                }
                if ($descriptor['type'] === 'schedule_event' && in_array($short, ['name', 'description', 'withoutoverlapping', 'ononeserver'], true)) {
                    $descriptor['options']['attribute_calls'][] = ['method' => $short, 'args' => $args, 'source' => $site];
                }
                if ($descriptor['type'] === 'schedule_attributes') {
                    $this->schedulePending[$from] = $descriptor['options'];
                }
                if (isset($descriptor['schedule_site'])) {
                    $this->result['operations'][] = ['kind' => 'schedule_options', ...$op, 'schedule_site' => $descriptor['schedule_site'], 'options' => $descriptor['options']];
                }

                return $descriptor;
            }
            if ($owner === 'Illuminate\Foundation\Configuration\ApplicationBuilder') {
                return $receiver;
            }
            if ($short === 'guessclassnamesusing') {
                $this->notice($expr, 'Custom event class-name guessing is unresolved.');
            }
            if (($events && in_array($short, ['listen', 'subscribe'], true)) || ($short === 'observe' && $owner !== null)) {
                $this->result['operations'][] = ['kind' => $short, ...$op];

                return null;
            }
            if ($global && in_array($short, ['event', 'broadcast'], true) || $events && in_array($short, ['dispatch', 'until'], true)) {
                $this->result['operations'][] = ['kind' => 'event', ...$op];

                return null;
            }
            if (($global && $short === 'queueable') || $method === 'Illuminate\Events\queueable') {
                return ['type' => 'queued_callback', 'value' => $args[0] ?? null, 'options' => [], ...($this->catalogMode ? ['helper' => $method] : [])];
            }
            if (($bus && in_array($short, ['chain', 'batch'], true)) || ($owner !== null && $short === 'withchain')) {
                return ['type' => $short === 'batch' ? 'batch' : 'chain', 'jobs' => $args[0] ?? null, 'contract_owner' => $short === 'withchain' ? $owner : null, 'first' => $short === 'withchain' ? $owner : null, 'options' => [], 'source' => $site];
            }
            $pending = is_array($receiver) && in_array($receiver['type'] ?? '', ['chain', 'batch', 'pending', 'queued_callback'], true);
            $jobModifier = is_array($receiver) && ($receiver['type'] ?? '') === 'object' && in_array($short, ['onqueue', 'onconnection', 'delay', 'aftercommit', 'beforecommit', 'chain'], true);
            if (($pending || $jobModifier) && in_array($short, ['then', 'catch', 'finally', 'before', 'progress', 'allowfailures', 'onqueue', 'onconnection', 'delay', 'aftercommit', 'beforecommit', 'afterresponse', 'chain'], true)) {
                if (in_array($short, ['then', 'catch', 'finally', 'before', 'progress'], true)) {
                    $receiver['options'][$short][] = $args[0] ?? null;
                } else {
                    $receiver['options'][$short] = $args[0] ?? true;
                }
                if (isset($receiver['dispatch_site'])) {
                    $this->result['operations'][] = ['kind' => 'dispatch_options', 'dispatch_site' => $receiver['dispatch_site'], 'options' => $receiver['options'], ...$op];
                }

                return $receiver;
            }
            if ($short === 'withoutevents' && $owner !== null) {
                $this->result['operations'][] = ['kind' => 'quiet', ...$op];

                return null;
            }
            $dispatchMethods = ['dispatch', 'dispatchsync', 'dispatchnow', 'dispatchafterresponse', 'dispatchif', 'dispatchunless'];
            if (($global && in_array($short, $dispatchMethods, true)) || ($bus && in_array($short, [...$dispatchMethods, 'bulk'], true)) || ($owner !== null && in_array($short, $dispatchMethods, true)) || ($pending && in_array($short, ['dispatch', 'dispatchif', 'dispatchunless', 'dispatchafterresponse'], true))) {
                $description = $pending ? $receiver : (($global || $bus) ? ($args['job'] ?? $args['command'] ?? $args[0] ?? null) : ['type' => 'object', 'class' => $owner]);
                if (in_array($short, ['dispatchif', 'dispatchunless'], true)) {
                    $condition = $args['boolean'] ?? $args[0] ?? null;
                    if (is_bool($condition) && (($short === 'dispatchif' && ! $condition) || ($short === 'dispatchunless' && $condition))) {
                        return null;
                    }
                    $op['conditions'][] = 'Dispatch condition must pass.';
                }
                $this->result['operations'][] = ['kind' => 'dispatch', 'value' => $description, ...$op];

                return ['type' => 'pending', 'value' => $description, 'options' => [], 'dispatch_site' => $site, 'dispatch_from' => $from];
            }
            $routeAction = $args['action'] ?? $args[$short === 'match' ? 2 : 1] ?? null;
            if (in_array($owner, ['Illuminate\Support\Facades\Route', 'Illuminate\Routing\Router'], true) && is_array($routeAction) && ($routeAction['type'] ?? '') === 'callback') {
                $this->result['operations'][] = ['kind' => 'alias', 'from' => '(route) '.$this->file->path.':'.$expr->getStartFilePos(), 'value' => $routeAction, 'source' => $site, 'conditions' => []];
            }
            if (in_array($short, ['created', 'creating', 'updated', 'updating', 'saving', 'saved', 'deleting', 'deleted', 'retrieved', 'restoring', 'restored', 'trashed', 'replicating', 'forcedeleting', 'forcedeleted'], true) && $owner !== null
                && (isset($args[0]) || $this->catalogMode && (array_key_exists(0, $args) || array_key_exists('callback', $args)))) {
                $this->result['operations'][] = ['kind' => 'model_listen', ...$op];

                return null;
            }
            if ($owner !== null && in_array($short, ['save', 'savequietly', 'create', 'createquietly', 'update', 'updatequietly', 'delete', 'deletequietly', 'destroy', 'restore', 'restorequietly', 'forcedelete', 'forcedeletequietly', 'firstorcreate', 'updateorcreate', 'replicate', 'increment', 'decrement', 'get', 'first', 'find', 'findorfail'], true)) {
                $this->result['operations'][] = ['kind' => 'model_operation', ...$op];
            }
            $this->call($expr, $from, $owner, $method, $conditions);
            if ($owner !== null && in_array($short, ['query', 'where', 'wherein', 'orderby', 'with', 'withoutglobalsecopes'], true)) {
                return ['type' => 'builder', 'class' => $owner];
            }
            if ($owner !== null && in_array($short, ['create', 'firstorcreate', 'updateorcreate', 'find', 'findorfail', 'first'], true)) {
                return ['type' => 'object', 'class' => $owner];
            }
            if ($global && in_array($short, ['base_path', 'app_path'], true) && is_string($args[0] ?? '')) {
                return ($short === 'app_path' ? 'app/' : '').($args[0] ?? '');
            }

            if ($global && $short === 'dirname' && is_string($args[0] ?? null) && is_int($args[1] ?? 1)) {
                return dirname($args[0], $args[1] ?? 1);
            }

            return null;
        }
        if (Node::is($expr, 'expression.include')) {
            $path = $this->value($expr->expr, $from, $class, $vars, $conditions, $depth + 1);
            if (is_string($path)) {
                $this->result['paths'][] = $path;
            } else {
                $this->notice($expr, 'Dynamic execution source include is unresolved.');
            }

            return null;
        }
        if (Node::is($expr, 'expression.ternary') || Node::is($expr, 'expression.match') || Node::is($expr, 'expression.binary-op.boolean-and') || Node::is($expr, 'expression.binary-op.boolean-or') || Node::is($expr, 'expression.binary-op.coalesce')) {
            $conditions[] = 'Source expression condition is not evaluated.';
        }
        foreach ($expr->getSubNodeNames() as $key) {
            $child = $expr->$key;
            if (Node::is($child, 'expression')) {
                $this->value($child, $from, $class, $vars, $conditions, $depth + 1);
            }
        }

        return $this->literal($expr, $class, $vars);
    }

    /** @param list<Node> $nodes
     * @param  list<string>  $caught
     */
    private function authorizationContext(array $nodes, string $usage = 'requires_check', array $caught = [], int $depth = 0): void
    {
        foreach ($nodes as $node) {
            if (! $this->room($node, $depth)) {
                break;
            }
            if (Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function') || Node::is($node, 'statement.class-method')) {
                $caught = [];
                $usage = 'requires_check';
            }
            if (Node::is($node, 'statement.expression')) {
                $usage = Node::is($node->expr, 'expression.assign') ? 'assigned_requires_check' : 'ignored';
            } elseif (Node::is($node, 'statement.return')) {
                $usage = 'returned';
            }
            if (Node::is($node, 'expression.call-like')) {
                $this->authorizationContexts[$node->getStartFilePos()] = ['usage' => $usage, 'caught' => $caught];
            }
            foreach ($node->getSubNodeNames() as $key) {
                $child = $node->$key;
                $children = Node::is($child, 'node') ? [$child] : (is_array($child) ? array_values(array_filter($child, fn ($v) => Node::is($v, 'node'))) : []);
                $nextCaught = $caught;
                if (Node::is($node, 'statement.try-catch') && $key === 'stmts') {
                    foreach ($node->catches as $catch) {
                        foreach ($catch->types as $type) {
                            $nextCaught[] = $this->file->resolvedName($type);
                        }
                    }
                }
                $nextUsage = Node::is($node, 'arg') ? 'argument_requires_check' : ($key === 'cond' && (Node::is($node, 'statement.if') || Node::is($node, 'statement.else-if') || Node::is($node, 'expression.ternary') || Node::is($node, 'statement.while')) ? 'conditional_branch' : $usage);
                $this->authorizationContext($children, $nextUsage, $nextCaught, $depth + 1);
            }
        }
    }

    /** @param array<string, mixed> $vars */
    private function literal(Node $expr, string $class, array $vars): mixed
    {
        if (Node::is($expr, 'scalar.magic-const.dir')) {
            return dirname($this->file->path) === '.' ? '' : dirname($this->file->path);
        }
        if (Node::is($expr, 'scalar.string') || Node::is($expr, 'scalar.int')) {
            return $expr->value;
        }
        if (Node::is($expr, 'expression.const-fetch')) {
            return match (strtolower($expr->name->toString())) {
                'true' => true, 'false' => false, default => null
            };
        }
        if (Node::is($expr, 'expression.class-const-fetch') && Node::is($expr->class, 'name') && Node::is($expr->name, 'identifier') && strtolower($expr->name->toString()) === 'class') {
            return $this->name($expr->class, $class);
        }
        if (Node::is($expr, 'expression.variable') && is_string($expr->name)) {
            return $vars[$expr->name] ?? null;
        }
        if (Node::is($expr, 'expression.property-fetch') && Node::is($expr->var, 'expression.variable') && $expr->var->name === 'this' && Node::is($expr->name, 'identifier')) {
            return $vars['this.'.$expr->name->toString()] ?? null;
        }
        if (Node::is($expr, 'expression.binary-op.concat')) {
            $a = $this->literal($expr->left, $class, $vars);
            $b = $this->literal($expr->right, $class, $vars);

            return is_string($a) && is_string($b) ? $a.$b : null;
        }
        if (Node::is($expr, 'expression.array')) {
            $array = [];
            foreach ($expr->items as $item) {
                if ($item === null || $item->unpack) {
                    return null;
                }
                $value = $this->literal($item->value, $class, $vars);
                $key = $item->key === null ? null : $this->literal($item->key, $class, $vars);
                if ($item->key === null) {
                    $array[] = $value;
                } elseif (is_string($key) || is_int($key)) {
                    $array[$key] = $value;
                } else {
                    return null;
                }
            }

            return $array;
        }

        return null;
    }

    private function kernelOwner(?string $owner): bool
    {
        for ($i = 0; $owner !== null && $i < 32; $i++) {
            if ($owner === 'Illuminate\Foundation\Console\Kernel') {
                return true;
            }
            $owner = $this->parents[strtolower($owner)] ?? null;
        }

        return false;
    }

    private function name(Node $name, string $class): string
    {
        return match (strtolower($name->toString())) {
            'self', 'static' => $class,
            'parent' => $this->parents[strtolower($class)] ?? 'parent',
            default => $this->file->resolvedName($name),
        };
    }

    /** @return list<string> */
    private function types(?Node $type, string $class): array
    {
        if (Node::is($type, 'name')) {
            return [$this->name($type, $class)];
        }
        if (Node::is($type, 'nullable-type')) {
            return $this->types($type->type, $class);
        }
        if (Node::is($type, 'union-type')) {
            $names = [];
            foreach ($type->types as $part) {
                array_push($names, ...$this->types($part, $class));
            }

            return $names;
        }

        return [];
    }

    /** Only class selectors and known middleware/callable descriptors, never generic return payloads.
     */
    private function middlewareCandidate(Node $expr, mixed $value, string $class, int $depth = 0): mixed
    {
        if ($depth >= 8 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->result['limited'] = true;

            return null;
        }
        if (Node::is($expr, 'expression.variable') && is_array($value) && ($value['type'] ?? null) === 'catalog-array') {
            return $value['middleware_items'] ?? null;
        }
        if (Node::is($expr, 'expression.class-const-fetch') && Node::is($expr->class, 'name') && Node::is($expr->name, 'identifier') && strtolower($expr->name->toString()) === 'class') {
            return $this->name($expr->class, $class);
        }
        if (Node::is($expr, 'expression.array')) {
            if (count($expr->items) > 1000) {
                $this->result['limited'] = true;

                return null;
            }
            $result = [];
            $items = is_array($value) && ($value['type'] ?? null) === 'catalog-array' ? $value['items'] : [];
            foreach ($expr->items as $position => $item) {
                $result[] = $item !== null && ! $item->unpack && $item->key === null
                    ? $this->middlewareCandidate($item->value, $items[$position] ?? null, $class, $depth + 1) : null;
            }

            return ['type' => 'catalog-array', 'items' => $result];
        }

        return is_array($value) && in_array($value['type'] ?? null, ['controller_middleware', 'callback', 'reference'], true) ? $value : null;
    }

    /** @return array{type: string, class: string}|null */
    private function typed(?Node $type, string $class): ?array
    {
        $names = $this->types($type, $class);

        return count($names) === 1 ? ['type' => 'object', 'class' => $names[0]] : null;
    }

    /** @param array<string, mixed> $vars
     * @return array<string, mixed>|null
     */
    private function callableReference(Node $expr, string $class, array $vars): ?array
    {
        if ($this->catalogMode && Node::is($expr, 'expression.func-call') && Node::is($expr->name, 'name')) {
            $resolved = $this->file->resolvedName($expr->name);
            $namespaced = $expr->name->getAttribute('namespacedName');

            return ['type' => 'reference', 'symbol' => $resolved, 'callable_form' => 'function',
                'first_class' => true, 'function_names' => array_values(array_unique(Node::is($namespaced, 'name') ? [$namespaced->toString(), $resolved] : [$resolved]))];
        }
        if (Node::is($expr, 'expression.static-call') && Node::is($expr->class, 'name') && Node::is($expr->name, 'identifier')) {
            return ['type' => 'reference', 'symbol' => $this->name($expr->class, $class).'::'.$expr->name->toString(), ...($this->catalogMode ? ['first_class' => true, 'callable_form' => 'static-first-class', 'creator_class' => $class] : [])];
        }
        if (Node::is($expr, 'expression.method-call') && Node::is($expr->name, 'identifier')) {
            $receiver = $this->literal($expr->var, $class, $vars);
            if (is_array($receiver) && isset($receiver['class'])) {
                return ['type' => 'reference', 'symbol' => $receiver['class'].'::'.$expr->name->toString(), ...($this->catalogMode ? ['first_class' => true, 'callable_form' => 'object-first-class', 'creator_class' => $class] : [])];
            }
        }

        return null;
    }

    /** @param list<string> $conditions */
    private function call(Node $node, string $from, ?string $receiver, string $method, array $conditions): void
    {
        $this->result['calls'][] = ['from' => $from, 'receiver' => $receiver, 'method' => $method, 'source' => $this->site($node), 'conditions' => $conditions];
    }

    /** @return array{path: string, line: int, offset: int} */
    private function site(Node $node): array
    {
        return ['path' => $this->file->path, 'line' => max(1, $node->getStartLine()), 'offset' => $node->getStartFilePos()];
    }

    private function room(Node $node, int $depth): bool
    {
        if (++$this->visits > 20000 || $depth > 48 || ImpactExtractor::sourceLimit(0) !== null) {
            if (! $this->result['limited']) {
                $this->notice($node, 'Execution per-file AST/depth/memory limit reached.');
                $this->result['limited'] = true;
            }

            return false;
        }

        return true;
    }

    private function notice(?Node $node, string $reason): void
    {
        $this->result['notices'][] = ['path' => $this->file->path, 'line' => $node?->getStartLine() ?? 1, 'reason' => $reason];
    }
}
