<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use Illuminate\Support\Str;

/** Normalizes source declarations, never registers Laravel routes. */
final class HttpRouteExtractor
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $operations = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $notices = [];

    private int $visits = 0;

    private bool $limited = false;

    /** @var array<string, true> */
    private array $limitReasons = [];

    private FileContext $file;

    /** @var array<int, true> */
    private array $boundThisCalls = [];

    public function __construct(private readonly string $basePath, private readonly bool $catalog = false) {}

    /**
     * @param  array<int, true>  $boundThisCalls
     * @return array<string, mixed>
     */
    public function extract(FileContext $file, array $boundThisCalls = []): array
    {
        $this->file = $file;
        $this->boundThisCalls = $boundThisCalls;
        $this->operations = $this->notices = [];
        $this->visits = 0;
        $this->limited = false;
        $this->limitReasons = [];
        $ast = $file->syntax();
        if ($ast === null) {
            $this->notice(null, 'Unparseable HTTP source.');
        } else {
            $stack = $ast;
            $count = 0;
            while ($stack !== []) {
                $node = array_pop($stack);
                if (! Node::is($node, 'node')) {
                    continue;
                }
                if (++$count > 20000 || ImpactExtractor::sourceLimit(0) !== null) {
                    $this->limited = true;
                    $this->limitReasons[ImpactExtractor::sourceLimit(0) !== null ? 'memory' : 'structure'] = true;
                    $this->notice($node, 'HTTP per-file AST/memory limit reached.');

                    return ['operations' => [], 'notices' => $this->notices, 'limited' => true, 'limit_reasons' => array_keys($this->limitReasons)];
                }
                foreach ($node->getSubNodeNames() as $key) {
                    $value = $node->$key;
                    if (Node::is($value, 'node')) {
                        $stack[] = $value;
                    } elseif (is_array($value)) {
                        foreach ($value as $child) {
                            if (Node::is($child, 'node')) {
                                $stack[] = $child;
                            }
                        }
                    }
                }
            }
            $this->walk($ast, self::context());
        }

        return ['operations' => $this->operations, 'notices' => $this->notices, 'limited' => $this->limited, 'limit_reasons' => array_keys($this->limitReasons)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function context(): array
    {
        return ['prefix' => '', 'name' => '', 'domain' => null, 'middleware' => [], 'excluded_middleware' => [], 'constraints' => [], 'namespace' => '', 'controller' => null, 'possible' => false, 'reasons' => [], 'sites' => [], 'provider' => false, 'specified' => []];
    }

    /**
     * @param  list<Node>  $nodes
     * @param  array<string, mixed>  $context
     * @param  list<string>  $routers
     */
    private function walk(array $nodes, array $context, int $depth = 0, array $routers = []): void
    {
        if ($depth > 12) {
            $this->limited = true;
            $this->limitReasons[ImpactExtractor::sourceLimit(0) !== null ? 'memory' : 'structure'] = true;
            $this->notice(null, 'HTTP group/include depth limit reached.');

            return;
        }
        foreach ($nodes as $node) {
            if (++$this->visits > 20000 || count($this->operations) >= 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->limited = true;
                $this->limitReasons[ImpactExtractor::sourceLimit(0) !== null ? 'memory' : 'structure'] = true;
                $this->notice($node, 'HTTP AST/registration/memory limit reached.');

                return;
            }
            if (Node::is($node, 'statement.expression')) {
                $this->expression($node->expr, $context, $depth, $routers);
            } elseif (Node::is($node, 'statement.return') && $node->expr !== null) {
                if ($this->file->path === 'bootstrap/providers.php' && Node::is($node->expr, 'expression.array')) {
                    foreach ($this->value($node->expr) ?? [] as $provider) {
                        if (is_string($provider)) {
                            $this->operations[] = ['kind' => 'provider', 'class' => $provider, 'site' => $this->site($node), 'context' => $context];
                        } else {
                            $this->notice($node, 'Dynamic provider registration is unresolved.');
                        }
                    }
                } else {
                    $this->expression($node->expr, $context, $depth, $routers);
                }
            } elseif (Node::is($node, 'statement.namespace')) {
                $this->walk($node->stmts, $context, $depth + 1, $routers);
            } elseif (Node::is($node, 'statement.class-like') || Node::is($node, 'statement.class-method') || Node::is($node, 'statement.function')) {
                $nested = $context;
                if (Node::is($node, 'statement.class') && Node::is($node->extends, 'name')) {
                    $nested['provider'] = in_array($this->file->resolvedName($node->extends), ['Illuminate\\Support\\ServiceProvider', 'Illuminate\\Foundation\\Support\\Providers\\RouteServiceProvider'], true);
                }
                if ($this->catalog && Node::is($node, 'statement.class-like')) {
                    $nested['provider'] = true;
                    $nested['provider_owner'] = isset($node->namespacedName) ? $node->namespacedName->toString() : '(anonymous) '.$this->file->path.':'.$node->getStartFilePos();
                }
                if ($this->catalog && Node::is($node, 'statement.class-method') && isset($nested['provider_owner'])) {
                    $nested['provider_scope'] = $nested['provider_owner'].'::'.$node->name->toString();
                }
                if ($this->catalog && Node::is($node, 'statement.function')) {
                    $nested['provider_scope'] = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
                }
                $nested['possible'] = true;
                $nested['reasons'][] = 'Provider/function activation is not established by source inspection.';
                $typed = $routers;
                foreach (Node::is($node, 'statement.class-method') || Node::is($node, 'statement.function') ? $node->params : [] as $param) {
                    if (Node::is($param->type, 'name') && $this->file->resolvedName($param->type) === 'Illuminate\\Routing\\Router' && is_string($param->var->name)) {
                        $typed[] = $param->var->name;
                    }
                }
                $this->walk($node->stmts ?? [], $nested, $depth + 1, $typed);
            } elseif (Node::is($node, 'statement.if') || Node::is($node, 'statement.else-if') || Node::is($node, 'statement.else') || Node::is($node, 'statement.foreach') || Node::is($node, 'statement.for') || Node::is($node, 'statement.while') || Node::is($node, 'statement.switch') || Node::is($node, 'statement.case') || Node::is($node, 'statement.try-catch') || Node::is($node, 'statement.catch') || Node::is($node, 'statement.finally')) {
                $nested = $context;
                $nested['possible'] = true;
                $nested['reasons'][] = 'Conditional or repeated registration is not evaluated.';
                foreach ($node->getSubNodeNames() as $key) {
                    $value = $node->$key;
                    if (is_array($value)) {
                        $this->walk(array_values(array_filter($value, fn ($v) => Node::is($v, 'statement'))), $nested, $depth + 1, $routers);
                    } elseif (Node::is($value, 'statement')) {
                        $this->walk([$value], $nested, $depth + 1, $routers);
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $routers
     */
    private function expression(Node $expr, array $context, int $depth, array $routers): void
    {
        if ($depth > 12 || ++$this->visits > 20000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;
            $this->limitReasons[ImpactExtractor::sourceLimit(0) !== null ? 'memory' : 'structure'] = true;
            $this->notice($expr, 'HTTP expression/depth/memory limit reached.');

            return;
        }
        if (Node::is($expr, 'expression.ternary') || Node::is($expr, 'expression.binary-op.boolean-and') || Node::is($expr, 'expression.binary-op.boolean-or') || Node::is($expr, 'expression.binary-op.logical-and') || Node::is($expr, 'expression.binary-op.logical-or') || Node::is($expr, 'expression.binary-op.coalesce')) {
            $nested = $context;
            $nested['possible'] = true;
            $nested['reasons'][] = 'Conditional expression registration is not evaluated.';
            $before = count($this->operations);
            if (Node::is($expr, 'expression.ternary')) {
                $this->expression($expr->cond, $context, $depth + 1, $routers);
                if ($expr->if !== null) {
                    $this->expression($expr->if, $nested, $depth + 1, $routers);
                }
                $this->expression($expr->else, $nested, $depth + 1, $routers);
            } else {
                $this->expression($expr->left, $context, $depth + 1, $routers);
                $this->expression($expr->right, $nested, $depth + 1, $routers);
            }
            if (count($this->operations) > $before) {
                $this->notice($expr, 'Conditional expression registration is not evaluated.');
            }

            return;
        }
        if (Node::is($expr, 'expression.assign')) {
            $this->expression($expr->expr, $context, $depth + 1, $routers);

            return;
        }
        if ($this->catalog && (Node::is($expr, 'expression.closure') || Node::is($expr, 'expression.arrow-function'))) {
            $nested = $context;
            $nested['provider_scope'] = '(closure) '.$this->file->path.':'.$expr->getStartFilePos();
            $nested['provider'] = $context['provider'] && ! $expr->static;
            $nested['possible'] = true;
            $nested['reasons'][] = 'Callback declaration does not establish its invocation.';
            $this->callbackGroup($expr, $nested, $depth, $routers);

            return;
        }
        if ($this->catalog && Node::is($expr, 'expression.func-call') && (Node::is($expr->name, 'expression.closure') || Node::is($expr->name, 'expression.arrow-function'))) {
            $this->expression($expr->name, $context, $depth + 1, $routers);

            return;
        }
        if ($this->catalog && Node::is($expr, 'expression.method-call') && $this->applicationBuilder($expr)) {
            $this->providerRegistrations($expr, $context);
        }
        $builder = $expr;
        while (Node::is($builder, 'expression.method-call')) {
            if (Node::is($builder->name, 'identifier') && $builder->name->toString() === 'withRouting' && $this->applicationBuilder($builder->var)) {
                $this->routing($builder, $context, $depth, $routers);

                return;
            }
            $builder = $builder->var;
        }
        if (Node::is($expr, 'expression.include')) {
            $this->load($expr, $expr->expr, $context);

            return;
        }
        if (Node::is($expr, 'expression.method-call') && Node::is($expr->name, 'identifier')) {
            if ($expr->name->toString() === 'withRouting' && $this->applicationBuilder($expr->var)) {
                $this->routing($expr, $context, $depth, $routers);

                return;
            }
            if ($expr->name->toString() === 'loadRoutesFrom' && $this->providerReceiver($expr) && $context['provider']) {
                $candidate = $context;
                if ($this->catalog) {
                    $candidate['provider_candidate'] = true;
                    $candidate['provider_method'] = 'loadroutesfrom';
                }
                $this->load($expr, $expr->args[0]->value ?? null, $candidate);

                return;
            }
        }
        if (Node::is($expr, 'expression.method-call') && Node::is($expr->name, 'identifier') && $expr->name->toString() === 'routes' && $this->providerReceiver($expr) && $context['provider']) {
            if ($this->catalog) {
                $context['provider_candidate'] = true;
                $context['provider_method'] = 'routes';
            }
            $callback = $this->argument($expr->args, 0, 'routes');
            if (Node::is($callback, 'expression.closure') || Node::is($callback, 'expression.arrow-function')) {
                $this->callbackGroup($callback, $context, $depth, $routers);
            } else {
                $this->notice($expr, 'Dynamic provider routes callback is unresolved.');
            }

            return;
        }
        $steps = [];
        $root = $expr;
        while (Node::is($root, 'expression.method-call') && Node::is($root->name, 'identifier')) {
            array_unshift($steps, [$root->name->toString(), $root->args, $root]);
            $root = $root->var;
        }
        if (Node::is($root, 'expression.static-call') && Node::is($root->class, 'name') && Node::is($root->name, 'identifier') && in_array($this->file->resolvedName($root->class), ['Route', 'Illuminate\\Support\\Facades\\Route'], true)) {
            array_unshift($steps, [$root->name->toString(), $root->args, $root]);
        } elseif (Node::is($root, 'expression.variable') && is_string($root->name) && in_array($root->name, $routers, true)) {
            // Typed Illuminate Router parameter, not an arbitrary variable named router.
        } else {
            if ($steps !== [] && Node::is($root, 'expression.variable') && is_string($root->name) && in_array($root->name, ['router', 'route'], true)) {
                $this->notice($expr, 'Route receiver type is not established.');
            }

            return;
        }
        $action = null;
        foreach ($steps as [$method, $args, $site]) {
            foreach ($args as $arg) {
                if (! Node::is($arg, 'arg') || $arg->unpack) {
                    $this->notice($site, 'Spread or first-class route registration arguments are unresolved.');

                    return;
                }
            }
            if (in_array($method, ['get', 'head', 'post', 'put', 'patch', 'delete', 'options', 'match', 'any', 'resource', 'apiResource'], true)) {
                if ($action !== null) {
                    $this->notice($site, 'Multiple route actions in one chain are unresolved.');

                    return;
                }
                $action = [$method, $args, $site];
            } elseif ($method === 'group') {
                $callback = $this->argument($args, 0, 'callback');
                if (Node::is($callback, 'expression.array') || count($args) > 1) {
                    $attributes = $this->value($callback);
                    if (! is_array($attributes)) {
                        $this->notice($site, 'Dynamic group attributes are unresolved.');
                        $context = $this->unknownGroup($context);
                    } else {
                        $context = $this->attributes($context, $attributes, $site);
                    }
                    $callback = $this->argument($args, 1, 'routes');
                }
                $context['sites'][] = $this->site($site);
                if (Node::is($callback, 'expression.closure')) {
                    $this->callbackGroup($callback, $context, $depth, $routers);
                } elseif (Node::is($callback, 'expression.arrow-function')) {
                    $this->expression($callback->expr, $context, $depth + 1, $routers);
                } else {
                    $this->load($site, $callback, $context);
                }

                return;
            } elseif ($method === 'can') {
                $ability = $this->value($this->argument($args, 0, 'ability'));
                $models = $this->value($this->argument($args, 1, 'models'));
                if (is_string($ability) && ($models === null || is_string($models))) {
                    $context = $this->attributes($context, ['middleware' => ['can:'.$ability.($models === null ? '' : ','.$models)]], $site);
                } else {
                    $this->notice($site, 'Dynamic route authorization is unresolved.');
                    $context['possible'] = true;
                }
            } elseif (in_array($method, ['prefix', 'name', 'as', 'domain', 'middleware', 'withoutMiddleware', 'namespace', 'controller', 'where'], true)) {
                $key = match ($method) {
                    'name', 'as' => 'name', 'withoutMiddleware' => 'excluded_middleware', 'where' => 'constraints', default => $method
                };
                $value = $this->value($this->argument($args, 0, $method === 'name' ? 'name' : $method));
                if ($method === 'where' && is_string($value)) {
                    $value = [$value => $this->value($args[1]->value ?? null)];
                }
                $context = $this->attributes($context, [$key => $value], $site);
            } elseif ($action === null || ! in_array($method, ['only', 'except', 'names', 'parameters', 'shallow'], true)) {
                $context['possible'] = true;
                $context['reasons'][] = 'Unsupported route attribute or macro: '.$method;
                $this->notice($site, 'Unsupported route attribute or macro: '.$method);
            }
        }
        if ($action === null) {
            $this->notice($expr, 'Route registration or macro could not be resolved.');

            return;
        }
        [$method, $args, $site] = $action;
        if ($method === 'resource' || $method === 'apiResource') {
            $this->resource($method, $args, $site, $context, $steps);

            return;
        }
        $uri = $this->value($this->argument($args, $method === 'match' ? 1 : 0, 'uri'));
        $handler = $this->argument($args, $method === 'match' ? 2 : 1, 'action');
        $verbs = match ($method) {
            'get' => ['GET', 'HEAD'], 'match' => $this->value($this->argument($args, 0, 'methods')), 'any' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], default => [strtoupper($method)]
        };
        $this->route($site, is_string($uri) ? $uri : null, is_array($verbs) && array_is_list($verbs) && count(array_filter($verbs, 'is_string')) === count($verbs) ? array_map('strtoupper', $verbs) : null, $handler, $context);
    }

    /** @param array<string, mixed> $context */
    private function providerRegistrations(Node $expr, array $context): void
    {
        $calls = [];
        $cursor = $expr;
        while (Node::is($cursor, 'expression.method-call')) {
            if (! $cursor->isFirstClassCallable() && Node::is($cursor->name, 'identifier') && $cursor->name->toString() === 'withProviders') {
                $calls[] = $cursor;
            }
            $cursor = $cursor->var;
        }
        foreach (array_reverse($calls) as $call) {
            $argument = $this->argument($call->args, 1, 'withBootstrapProviders');
            $enabled = $argument === null ? true : $this->value($argument);
            $this->operations[] = ['kind' => 'provider-settings', 'bootstrap_enabled' => is_bool($enabled) ? $enabled : null, 'site' => $this->site($call), 'context' => $context];
            $argument = $this->argument($call->args, 0, 'providers');
            $providers = $argument === null ? [] : $this->value($argument);
            if (! is_array($providers) || ! array_is_list($providers)) {
                $this->notice($call, 'Dynamic withProviders registration is unresolved.');

                continue;
            }
            foreach ($providers as $provider) {
                if (is_string($provider)) {
                    $this->operations[] = ['kind' => 'provider', 'class' => $provider, 'site' => $this->site($call), 'context' => $context];
                } else {
                    $this->notice($call, 'Dynamic withProviders registration is unresolved.');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $routers
     */
    private function routing(Node $node, array $context, int $depth, array $routers): void
    {
        $args = [];
        $positions = ['using', 'web', 'api', 'commands', 'channels', 'pages', 'health', 'apiPrefix', 'then'];
        foreach ($node->args as $i => $arg) {
            if (Node::is($arg, 'arg')) {
                $args[$arg->name?->toString() ?? ($positions[$i] ?? '')] = $arg->value;
            }
        }
        $using = $args['using'] ?? null;
        $then = $args['then'] ?? null;
        $usingNull = $using === null || (Node::is($using, 'expression.const-fetch') && strtolower($using->name->toString()) === 'null');
        $thenNull = $then === null || (Node::is($then, 'expression.const-fetch') && strtolower($then->name->toString()) === 'null');
        $thenCallable = Node::is($then, 'expression.closure') || Node::is($then, 'expression.arrow-function');
        $dynamic = (! $usingNull && ! Node::is($using, 'expression.closure') && ! Node::is($using, 'expression.arrow-function')) || (! $thenNull && ! $thenCallable);
        if ($dynamic) {
            $context['possible'] = true;
            $context['reasons'][] = 'Dynamic withRouting using/then callback is unresolved.';
            $this->notice($node, 'Dynamic withRouting using/then callback is unresolved.');
        }
        if ($usingNull || $thenCallable || $dynamic) {
            foreach (['web', 'api'] as $key) {
                if (! isset($args[$key])) {
                    continue;
                }
                $nested = $context;
                $nested['middleware'][] = $key;
                if ($key === 'api') {
                    $prefix = isset($args['apiPrefix']) ? $this->value($args['apiPrefix']) : 'api';
                    $nested = $this->attributes($nested, ['prefix' => $prefix], $node);
                }
                $value = $this->value($args[$key]);
                foreach (is_array($value) ? $value : [$value] as $path) {
                    $this->loadValue($node, $path, $nested);
                }
            }
            if ($thenCallable) {
                $this->callbackGroup($then, $context, $depth, $routers);
            }
        }
        if ((! $usingNull && ! $thenCallable) || $dynamic) {
            if (Node::is($using, 'expression.closure') || Node::is($using, 'expression.arrow-function')) {
                $this->callbackGroup($using, $context, $depth, $routers);
            }
        }
        foreach (['pages', 'health'] as $key) {
            if (isset($args[$key])) {
                $this->notice($node, 'Framework '.$key.' route handler is outside the project method graph.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $routers
     */
    private function callbackGroup(Node $callback, array $context, int $depth, array $routers): void
    {
        $context['sites'][] = $this->site($callback);
        foreach ($callback->params as $param) {
            if (Node::is($param->type, 'name') && $this->file->resolvedName($param->type) === 'Illuminate\\Routing\\Router' && is_string($param->var->name)) {
                $routers[] = $param->var->name;
            }
        }
        if (Node::is($callback, 'expression.closure')) {
            $this->walk($callback->stmts, $context, $depth + 1, $routers);
        } else {
            $this->expression($callback->expr, $context, $depth + 1, $routers);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function attributes(array $context, array $attributes, Node $site): array
    {
        foreach ($attributes as $key => $value) {
            $key = $key === 'as' ? 'name' : $key;
            $context['specified'][$key] = true;
            if (! array_key_exists($key, $context)) {
                $context['possible'] = true;
                $context['reasons'][] = 'Unsupported group attribute: '.$key;

                continue;
            }
            $list = in_array($key, ['middleware', 'excluded_middleware'], true);
            $map = $key === 'constraints';
            $valid = $list ? (is_string($value) || (is_array($value) && array_is_list($value) && count(array_filter($value, 'is_string')) === count($value))) : ($map ? (is_array($value) && count(array_filter($value, 'is_string')) === count($value)) : is_string($value));
            if (! $valid) {
                $context['possible'] = true;
                $context['reasons'][] = 'Unresolved route '.$key.'.';
                $this->notice($site, 'Unresolved route '.$key.'.');
                $context[$key] = null;
            } elseif (in_array($key, ['middleware', 'excluded_middleware'], true)) {
                $context[$key] = $context[$key] === null ? null : [...$context[$key], ...(is_array($value) ? $value : [$value])];
            } elseif ($key === 'prefix') {
                $context[$key] = $context[$key] === null ? null : trim($context[$key].'/'.$value, '/');
            } elseif ($key === 'name') {
                $context[$key] = $context[$key] === null ? null : $context[$key].$value;
            } elseif ($key === 'namespace') {
                $context[$key] = $context[$key] === null ? null : trim($context[$key].'\\'.$value, '\\');
            } elseif ($key === 'constraints') {
                $context[$key] = $context[$key] === null ? null : [...$context[$key], ...(is_array($value) ? $value : [])];
            } else {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    /**
     * @param  list<Node>  $args
     * @param  array<string, mixed>  $context
     * @param  list<array<mixed>>  $steps
     */
    private function resource(string $kind, array $args, Node $site, array $context, array $steps): void
    {
        $name = $this->value($this->argument($args, 0, 'name'));
        $controller = $this->value($this->argument($args, 1, 'controller'));
        $optionExpr = $this->argument($args, 2, 'options');
        $options = $optionExpr === null ? [] : $this->value($optionExpr);
        if (! is_array($options)) {
            $this->notice($site, 'Dynamic resource options are unresolved.');

            return;
        }
        foreach ($steps as [$method, $arguments]) {
            if (in_array($method, ['only', 'except', 'names', 'parameters', 'shallow'], true)) {
                $options[$method] = $method === 'shallow' ? (count($arguments) === 0 ? true : $this->value($this->argument($arguments, 0, 'shallow'))) : $this->value($this->argument($arguments, 0, $method));
                if (in_array($method, ['only', 'except'], true) && count($arguments) > 1) {
                    $options[$method] = array_map(fn ($arg) => Node::is($arg, 'arg') ? $this->value($arg->value) : null, $arguments);
                }
            }
        }
        if (! is_string($name) || ! is_string($controller)) {
            $this->notice($site, 'Dynamic resource name, controller or options are unresolved.');

            return;
        }
        $nameBase = is_string($options['names'] ?? null) ? $options['names'] : null;
        if ($nameBase !== null) {
            $options['names'] = [];
        }
        foreach (['names', 'parameters'] as $option) {
            if (array_key_exists($option, $options) && (! is_array($options[$option]) || count(array_filter($options[$option], 'is_string')) !== count($options[$option]))) {
                $context['possible'] = true;
                $context['reasons'][] = 'Resource '.$option.' customization is unresolved.';
                $this->notice($site, 'Resource '.$option.' customization is unresolved.');
                $options[$option] = [];
            }
        }
        foreach (['only', 'except'] as $option) {
            if (array_key_exists($option, $options) && is_string($options[$option])) {
                $options[$option] = [$options[$option]];
            }
        }
        if (str_contains($name, '/')) {
            $segments = explode('/', $name);
            $name = array_pop($segments);
            $context = $this->attributes($context, ['prefix' => implode('/', $segments)], $site);
        }
        $parts = explode('.', $name);
        $uri = '';
        foreach ($parts as $i => $part) {
            $uri .= ($uri === '' ? '' : '/').$part;
            if ($i < count($parts) - 1) {
                $uri .= '/{'.($options['parameters'][$part] ?? str_replace('-', '_', Str::singular($part))).'}';
            }
        }
        $last = end($parts);
        $parameter = $options['parameters'][$last] ?? str_replace('-', '_', Str::singular($last));
        $item = ! empty($options['shallow']) && count($parts) > 1 ? $last : $uri;
        $actions = ['index' => [$uri, ['GET', 'HEAD']], 'create' => [$uri.'/create', ['GET', 'HEAD']], 'store' => [$uri, ['POST']], 'show' => [$item.'/{'.$parameter.'}', ['GET', 'HEAD']], 'edit' => [$item.'/{'.$parameter.'}/edit', ['GET', 'HEAD']], 'update' => [$item.'/{'.$parameter.'}', ['PUT', 'PATCH']], 'destroy' => [$item.'/{'.$parameter.'}', ['DELETE']]];
        foreach ($actions as $action => [$path, $verbs]) {
            if (($kind === 'apiResource' && in_array($action, ['create', 'edit'], true)) || (isset($options['only']) && is_array($options['only']) && ! in_array($action, $options['only'], true)) || (isset($options['except']) && is_array($options['except']) && in_array($action, $options['except'], true))) {
                continue;
            }
            $nested = $context;
            $routeName = ! empty($options['shallow']) && count($parts) > 1 && in_array($action, ['show', 'edit', 'update', 'destroy'], true) ? $last : $name;
            $nested['name'] = $context['name'] === null ? null : $context['name'].($options['names'][$action] ?? ($nameBase ?? $routeName).'.'.$action);
            if (array_diff(array_keys($options), ['only', 'except', 'names', 'parameters', 'shallow']) !== [] || (array_key_exists('only', $options) && (! is_array($options['only']) || count(array_filter($options['only'], 'is_string')) !== count($options['only']))) || (array_key_exists('except', $options) && (! is_array($options['except']) || count(array_filter($options['except'], 'is_string')) !== count($options['except'])))) {
                $nested['possible'] = true;
                $nested['reasons'][] = 'Resource customization is not fully resolved.';
                $this->notice($site, 'Resource customization is not fully resolved.');
            }
            $this->route($site, $path, $verbs, null, $nested, ['class' => $controller, 'method' => $action], $action);
        }
    }

    /** @return list<string> */
    private function callbackTypes(?Node $type): array
    {
        if (Node::is($type, 'name')) {
            return [$this->file->resolvedName($type)];
        }
        if (Node::is($type, 'nullable-type')) {
            return $this->callbackTypes($type->type);
        }
        if (Node::is($type, 'union-type')) {
            $types = [];
            foreach ($type->types as $part) {
                array_push($types, ...$this->callbackTypes($part));
            }

            return $types;
        }

        return [];
    }

    /**
     * @param  list<string>|null  $verbs
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $handler
     */
    private function route(Node $site, ?string $uri, ?array $verbs, ?Node $action, array $context, ?array $handler = null, string $resource = ''): void
    {
        $calls = [];
        if (Node::is($action, 'expression.closure') || Node::is($action, 'expression.arrow-function')) {
            $from = '(route) '.$this->file->path.':'.$site->getStartFilePos();
            $facts = (new ImpactExtractor)->extractCallback($this->file, $action, $from);
            $calls = $facts->calls;
            foreach ($facts->notices as $notice) {
                $this->notice($action, $notice['reason']);
            }
            $parameters = [];
            foreach ($action->params as $param) {
                $parameters[] = ['name' => is_string($param->var->name) ? $param->var->name : '', 'types' => $this->callbackTypes($param->type), 'nullable' => AuthorizationSignature::allowsGuests($param), 'route_resolvable' => AuthorizationSignature::routeResolvable($param->type)];
            }
            $handler = ['callback' => $from, 'parameters' => $parameters];
        } elseif ($handler === null) {
            $value = $this->value($action);
            if (is_array($value) && count($value) === 2 && is_string($value[0] ?? null) && is_string($value[1] ?? null)) {
                $handler = ['class' => $value[0], 'method' => $value[1]];
            } elseif (is_string($value)) {
                $handler = Node::is($action, 'expression.class-const-fetch') ? ['class' => $value, 'method' => '__invoke'] : ['string' => $value];
            }
        }
        if ($uri === null || $verbs === null || $handler === null) {
            $context['possible'] = true;
            $context['reasons'][] = 'Route URI, HTTP methods or handler is unresolved.';
            $this->notice($site, 'Route URI, HTTP methods or handler is unresolved.');
        }
        $this->operations[] = ['kind' => 'route', 'site' => $this->site($site), 'context' => $context, 'uri' => $uri, 'verbs' => $verbs, 'handler' => $handler, 'calls' => $calls, 'resource' => $resource];
    }

    private function providerReceiver(Node $call): bool
    {
        return (! $this->catalog || ! $call->isFirstClassCallable()) && (Node::is($call->var, 'expression.variable') && $call->var->name === 'this'
            || $this->catalog && isset($this->boundThisCalls[$call->getStartFilePos()]));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function load(Node $site, ?Node $expr, array $context): void
    {
        $this->loadValue($site, $this->value($expr), $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function loadValue(Node $site, mixed $path, array $context): void
    {
        if (! is_string($path)) {
            $this->notice($site, 'Dynamic route source path is unresolved.');

            return;
        }
        if (! str_starts_with($path, '/')) {
            $context['possible'] = true;
            $context['reasons'][] = 'Relative include/source resolution depends on working directory or include_path.';
            $this->notice($site, 'Relative include/source resolution depends on working directory or include_path.');
        }
        $context['sites'][] = $this->site($site);
        $this->operations[] = ['kind' => 'load', 'path' => $path, 'context' => $context, 'site' => $this->site($site)];
    }

    /** @param list<Node> $args */
    private function argument(array $args, int $position, string $name): ?Node
    {
        foreach ($args as $i => $arg) {
            if (Node::is($arg, 'arg') && ! $arg->unpack && ($arg->name?->toString() === $name || ($arg->name === null && $i === $position))) {
                return $arg->value;
            }
        }

        return null;
    }

    private function applicationBuilder(Node $expr): bool
    {
        while (Node::is($expr, 'expression.method-call')) {
            $expr = $expr->var;
        }

        return Node::is($expr, 'expression.static-call') && Node::is($expr->class, 'name') && $this->file->resolvedName($expr->class) === 'Illuminate\\Foundation\\Application' && Node::is($expr->name, 'identifier') && $expr->name->toString() === 'configure';
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function unknownGroup(array $context): array
    {
        foreach (['prefix', 'name', 'domain', 'middleware', 'namespace', 'controller', 'constraints', 'excluded_middleware'] as $key) {
            $context[$key] = null;
            $context['specified'][$key] = true;
        }
        $context['possible'] = true;
        $context['reasons'][] = 'Dynamic group attributes are unresolved.';

        return $context;
    }

    private function value(?Node $expr): mixed
    {
        if (Node::is($expr, 'scalar.string') || Node::is($expr, 'scalar.int')) {
            return $expr->value;
        }
        if (Node::is($expr, 'expression.const-fetch')) {
            return match (strtolower($expr->name->toString())) {
                'true' => true, 'false' => false, default => null
            };
        }
        if (Node::is($expr, 'scalar.magic-const.dir')) {
            return dirname($this->basePath.'/'.$this->file->path);
        }
        if (Node::is($expr, 'scalar.magic-const.file')) {
            return $this->basePath.'/'.$this->file->path;
        }
        if (Node::is($expr, 'expression.binary-op.concat')) {
            $left = $this->value($expr->left);
            $right = $this->value($expr->right);

            return is_string($left) && is_string($right) ? $left.$right : null;
        }
        if (Node::is($expr, 'expression.class-const-fetch') && Node::is($expr->class, 'name') && Node::is($expr->name, 'identifier') && strtolower($expr->name->toString()) === 'class') {
            return $this->file->resolvedName($expr->class);
        }
        if (Node::is($expr, 'expression.array')) {
            $result = [];
            foreach ($expr->items as $item) {
                if ($item === null || $item->unpack) {
                    return null;
                }
                $key = $item->key === null ? null : $this->value($item->key);
                $value = $this->value($item->value);
                if ($key === null) {
                    $result[] = $value;
                } elseif (is_string($key) || is_int($key)) {
                    $result[$key] = $value;
                } else {
                    return null;
                }
            }

            return $result;
        }
        if (Node::is($expr, 'expression.func-call') && Node::is($expr->name, 'name')) {
            $name = $this->file->resolvedName($expr->name);
            $argument = isset($expr->args[0]) ? $this->value($expr->args[0]->value) : '';
            if (! is_string($argument)) {
                return null;
            }
            if ($name === 'dirname') {
                $level = isset($expr->args[1]) ? $this->value($expr->args[1]->value) : 1;

                return is_int($level) && $level >= 1 && $level <= 12 ? dirname($argument, $level) : null;
            }
            $base = match ($name) {
                'base_path' => $this->basePath, 'app_path' => $this->basePath.'/app', 'routes_path' => $this->basePath.'/routes', default => null
            };

            return $base === null ? null : $base.($argument === '' ? '' : '/'.$argument);
        }

        return null;
    }

    /**
     * @return array{path: string, line: int, offset: int}
     */
    private function site(Node $node): array
    {
        return ['path' => $this->file->path, 'line' => $node->getStartLine(), 'offset' => $node->getStartFilePos()];
    }

    private function notice(?Node $node, string $reason): void
    {
        $this->notices[] = ['path' => $this->file->path, 'line' => $node?->getStartLine() ?? 1, 'reason' => $reason];
    }
}
