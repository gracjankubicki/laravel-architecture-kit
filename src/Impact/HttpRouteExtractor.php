<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

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
        $ast = $file->ast();
        if ($ast === null) {
            $this->notice(null, 'Unparseable HTTP source.');
        } else {
            $stack = $ast;
            $count = 0;
            while ($stack !== []) {
                $node = array_pop($stack);
                if (! $node instanceof Node) {
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
                    if ($value instanceof Node) {
                        $stack[] = $value;
                    } elseif (is_array($value)) {
                        foreach ($value as $child) {
                            if ($child instanceof Node) {
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
            if ($node instanceof Stmt\Expression) {
                $this->expression($node->expr, $context, $depth, $routers);
            } elseif ($node instanceof Stmt\Return_ && $node->expr !== null) {
                if ($this->file->path === 'bootstrap/providers.php' && $node->expr instanceof Expr\Array_) {
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
            } elseif ($node instanceof Stmt\Namespace_) {
                $this->walk($node->stmts, $context, $depth + 1, $routers);
            } elseif ($node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
                $nested = $context;
                if ($node instanceof Stmt\Class_ && $node->extends instanceof Node\Name) {
                    $nested['provider'] = in_array($this->file->resolvedName($node->extends), ['Illuminate\\Support\\ServiceProvider', 'Illuminate\\Foundation\\Support\\Providers\\RouteServiceProvider'], true);
                }
                if ($this->catalog && $node instanceof Stmt\ClassLike) {
                    $nested['provider'] = true;
                    $nested['provider_owner'] = isset($node->namespacedName) ? $node->namespacedName->toString() : '(anonymous) '.$this->file->path.':'.$node->getStartFilePos();
                }
                if ($this->catalog && $node instanceof Stmt\ClassMethod && isset($nested['provider_owner'])) {
                    $nested['provider_scope'] = $nested['provider_owner'].'::'.$node->name->toString();
                }
                if ($this->catalog && $node instanceof Stmt\Function_) {
                    $nested['provider_scope'] = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
                }
                $nested['possible'] = true;
                $nested['reasons'][] = 'Provider/function activation is not established by source inspection.';
                $typed = $routers;
                foreach ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ ? $node->params : [] as $param) {
                    if ($param->type instanceof Node\Name && $this->file->resolvedName($param->type) === 'Illuminate\\Routing\\Router' && is_string($param->var->name)) {
                        $typed[] = $param->var->name;
                    }
                }
                $this->walk($node->stmts ?? [], $nested, $depth + 1, $typed);
            } elseif ($node instanceof Stmt\If_ || $node instanceof Stmt\ElseIf_ || $node instanceof Stmt\Else_ || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\For_ || $node instanceof Stmt\While_ || $node instanceof Stmt\Switch_ || $node instanceof Stmt\Case_ || $node instanceof Stmt\TryCatch || $node instanceof Stmt\Catch_ || $node instanceof Stmt\Finally_) {
                $nested = $context;
                $nested['possible'] = true;
                $nested['reasons'][] = 'Conditional or repeated registration is not evaluated.';
                foreach ($node->getSubNodeNames() as $key) {
                    $value = $node->$key;
                    if (is_array($value)) {
                        $this->walk(array_values(array_filter($value, fn ($v) => $v instanceof Stmt)), $nested, $depth + 1, $routers);
                    } elseif ($value instanceof Stmt) {
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
    private function expression(Expr $expr, array $context, int $depth, array $routers): void
    {
        if ($depth > 12 || ++$this->visits > 20000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;
            $this->limitReasons[ImpactExtractor::sourceLimit(0) !== null ? 'memory' : 'structure'] = true;
            $this->notice($expr, 'HTTP expression/depth/memory limit reached.');

            return;
        }
        if ($expr instanceof Expr\Ternary || $expr instanceof Expr\BinaryOp\BooleanAnd || $expr instanceof Expr\BinaryOp\BooleanOr || $expr instanceof Expr\BinaryOp\LogicalAnd || $expr instanceof Expr\BinaryOp\LogicalOr || $expr instanceof Expr\BinaryOp\Coalesce) {
            $nested = $context;
            $nested['possible'] = true;
            $nested['reasons'][] = 'Conditional expression registration is not evaluated.';
            $this->notice($expr, 'Conditional expression registration is not evaluated.');
            if ($expr instanceof Expr\Ternary) {
                $this->expression($expr->cond, $context, $depth + 1, $routers);
                if ($expr->if !== null) {
                    $this->expression($expr->if, $nested, $depth + 1, $routers);
                }
                $this->expression($expr->else, $nested, $depth + 1, $routers);
            } else {
                $this->expression($expr->left, $context, $depth + 1, $routers);
                $this->expression($expr->right, $nested, $depth + 1, $routers);
            }

            return;
        }
        if ($expr instanceof Expr\Assign) {
            $this->expression($expr->expr, $context, $depth + 1, $routers);

            return;
        }
        if ($this->catalog && ($expr instanceof Expr\Closure || $expr instanceof Expr\ArrowFunction)) {
            $nested = $context;
            $nested['provider_scope'] = '(closure) '.$this->file->path.':'.$expr->getStartFilePos();
            $nested['provider'] = $context['provider'] && ! $expr->static;
            $nested['possible'] = true;
            $nested['reasons'][] = 'Callback declaration does not establish its invocation.';
            $this->callbackGroup($expr, $nested, $depth, $routers);

            return;
        }
        if ($this->catalog && $expr instanceof Expr\FuncCall && ($expr->name instanceof Expr\Closure || $expr->name instanceof Expr\ArrowFunction)) {
            $this->expression($expr->name, $context, $depth + 1, $routers);

            return;
        }
        if ($this->catalog && $expr instanceof Expr\MethodCall && $this->applicationBuilder($expr)) {
            $this->providerRegistrations($expr, $context);
        }
        $builder = $expr;
        while ($builder instanceof Expr\MethodCall) {
            if ($builder->name instanceof Node\Identifier && $builder->name->toString() === 'withRouting' && $this->applicationBuilder($builder->var)) {
                $this->routing($builder, $context, $depth, $routers);

                return;
            }
            $builder = $builder->var;
        }
        if ($expr instanceof Expr\Include_) {
            $this->load($expr, $expr->expr, $context);

            return;
        }
        if ($expr instanceof Expr\MethodCall && $expr->name instanceof Node\Identifier) {
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
        if ($expr instanceof Expr\MethodCall && $expr->name instanceof Node\Identifier && $expr->name->toString() === 'routes' && $this->providerReceiver($expr) && $context['provider']) {
            if ($this->catalog) {
                $context['provider_candidate'] = true;
                $context['provider_method'] = 'routes';
            }
            $callback = $this->argument($expr->args, 0, 'routes');
            if ($callback instanceof Expr\Closure || $callback instanceof Expr\ArrowFunction) {
                $this->callbackGroup($callback, $context, $depth, $routers);
            } else {
                $this->notice($expr, 'Dynamic provider routes callback is unresolved.');
            }

            return;
        }
        $steps = [];
        $root = $expr;
        while ($root instanceof Expr\MethodCall && $root->name instanceof Node\Identifier) {
            array_unshift($steps, [$root->name->toString(), $root->args, $root]);
            $root = $root->var;
        }
        if ($root instanceof Expr\StaticCall && $root->class instanceof Node\Name && $root->name instanceof Node\Identifier && in_array($this->file->resolvedName($root->class), ['Route', 'Illuminate\\Support\\Facades\\Route'], true)) {
            array_unshift($steps, [$root->name->toString(), $root->args, $root]);
        } elseif ($root instanceof Expr\Variable && is_string($root->name) && in_array($root->name, $routers, true)) {
            // Typed Illuminate Router parameter, not an arbitrary variable named router.
        } else {
            if ($steps !== [] && $root instanceof Expr\Variable && is_string($root->name) && in_array($root->name, ['router', 'route'], true)) {
                $this->notice($expr, 'Route receiver type is not established.');
            }

            return;
        }
        $action = null;
        foreach ($steps as [$method, $args, $site]) {
            foreach ($args as $arg) {
                if (! $arg instanceof Node\Arg || $arg->unpack) {
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
                if ($callback instanceof Expr\Array_ || count($args) > 1) {
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
                if ($callback instanceof Expr\Closure) {
                    $this->callbackGroup($callback, $context, $depth, $routers);
                } elseif ($callback instanceof Expr\ArrowFunction) {
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
    private function providerRegistrations(Expr\MethodCall $expr, array $context): void
    {
        $calls = [];
        $cursor = $expr;
        while ($cursor instanceof Expr\MethodCall) {
            if (! $cursor->isFirstClassCallable() && $cursor->name instanceof Node\Identifier && $cursor->name->toString() === 'withProviders') {
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
    private function routing(Expr\MethodCall $node, array $context, int $depth, array $routers): void
    {
        $args = [];
        $positions = ['using', 'web', 'api', 'commands', 'channels', 'pages', 'health', 'apiPrefix', 'then'];
        foreach ($node->args as $i => $arg) {
            if ($arg instanceof Node\Arg) {
                $args[$arg->name?->toString() ?? ($positions[$i] ?? '')] = $arg->value;
            }
        }
        $using = $args['using'] ?? null;
        $then = $args['then'] ?? null;
        $usingNull = $using === null || ($using instanceof Expr\ConstFetch && strtolower($using->name->toString()) === 'null');
        $thenNull = $then === null || ($then instanceof Expr\ConstFetch && strtolower($then->name->toString()) === 'null');
        $thenCallable = $then instanceof Expr\Closure || $then instanceof Expr\ArrowFunction;
        $dynamic = (! $usingNull && ! $using instanceof Expr\Closure && ! $using instanceof Expr\ArrowFunction) || (! $thenNull && ! $thenCallable);
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
            if ($using instanceof Expr\Closure || $using instanceof Expr\ArrowFunction) {
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
    private function callbackGroup(Expr\Closure|Expr\ArrowFunction $callback, array $context, int $depth, array $routers): void
    {
        $context['sites'][] = $this->site($callback);
        foreach ($callback->params as $param) {
            if ($param->type instanceof Node\Name && $this->file->resolvedName($param->type) === 'Illuminate\\Routing\\Router' && is_string($param->var->name)) {
                $routers[] = $param->var->name;
            }
        }
        if ($callback instanceof Expr\Closure) {
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
     * @param  list<Node\Arg|Node\VariadicPlaceholder>  $args
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
                    $options[$method] = array_map(fn ($arg) => $arg instanceof Node\Arg ? $this->value($arg->value) : null, $arguments);
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
        if ($type instanceof Node\Name) {
            return [$this->file->resolvedName($type)];
        }
        if ($type instanceof Node\NullableType) {
            return $this->callbackTypes($type->type);
        }
        if ($type instanceof Node\UnionType) {
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
    private function route(Node $site, ?string $uri, ?array $verbs, ?Expr $action, array $context, ?array $handler = null, string $resource = ''): void
    {
        $calls = [];
        if ($action instanceof Expr\Closure || $action instanceof Expr\ArrowFunction) {
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
                $handler = $action instanceof Expr\ClassConstFetch ? ['class' => $value, 'method' => '__invoke'] : ['string' => $value];
            }
        }
        if ($uri === null || $verbs === null || $handler === null) {
            $context['possible'] = true;
            $context['reasons'][] = 'Route URI, HTTP methods or handler is unresolved.';
            $this->notice($site, 'Route URI, HTTP methods or handler is unresolved.');
        }
        $this->operations[] = ['kind' => 'route', 'site' => $this->site($site), 'context' => $context, 'uri' => $uri, 'verbs' => $verbs, 'handler' => $handler, 'calls' => $calls, 'resource' => $resource];
    }

    private function providerReceiver(Expr\MethodCall $call): bool
    {
        return (! $this->catalog || ! $call->isFirstClassCallable()) && ($call->var instanceof Expr\Variable && $call->var->name === 'this'
            || $this->catalog && isset($this->boundThisCalls[$call->getStartFilePos()]));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function load(Node $site, ?Expr $expr, array $context): void
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

    /** @param list<Node\Arg|Node\VariadicPlaceholder> $args */
    private function argument(array $args, int $position, string $name): ?Expr
    {
        foreach ($args as $i => $arg) {
            if ($arg instanceof Node\Arg && ! $arg->unpack && ($arg->name?->toString() === $name || ($arg->name === null && $i === $position))) {
                return $arg->value;
            }
        }

        return null;
    }

    private function applicationBuilder(Expr $expr): bool
    {
        while ($expr instanceof Expr\MethodCall) {
            $expr = $expr->var;
        }

        return $expr instanceof Expr\StaticCall && $expr->class instanceof Node\Name && $this->file->resolvedName($expr->class) === 'Illuminate\\Foundation\\Application' && $expr->name instanceof Node\Identifier && $expr->name->toString() === 'configure';
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

    private function value(?Expr $expr): mixed
    {
        if ($expr instanceof Node\Scalar\String_ || $expr instanceof Node\Scalar\Int_) {
            return $expr->value;
        }
        if ($expr instanceof Expr\ConstFetch) {
            return match (strtolower($expr->name->toString())) {
                'true' => true, 'false' => false, default => null
            };
        }
        if ($expr instanceof Node\Scalar\MagicConst\Dir) {
            return dirname($this->basePath.'/'.$this->file->path);
        }
        if ($expr instanceof Node\Scalar\MagicConst\File) {
            return $this->basePath.'/'.$this->file->path;
        }
        if ($expr instanceof Expr\BinaryOp\Concat) {
            $left = $this->value($expr->left);
            $right = $this->value($expr->right);

            return is_string($left) && is_string($right) ? $left.$right : null;
        }
        if ($expr instanceof Expr\ClassConstFetch && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier && strtolower($expr->name->toString()) === 'class') {
            return $this->file->resolvedName($expr->class);
        }
        if ($expr instanceof Expr\Array_) {
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
        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name) {
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
