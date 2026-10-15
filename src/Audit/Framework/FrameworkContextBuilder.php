<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Framework;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceClass;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceIndex;

/** Collects framework registrations from active application providers without loading their classes. */
final class FrameworkContextBuilder
{
    private int $nodes = 0;

    /** @var array<string, true> */
    private array $active = [];

    /** @var array<string, string> */
    private array $gatePolicies = [];

    /** @var array<string, array{class: string, method: string}|FrameworkValue> */
    private array $gateAbilities = [];

    /** @var list<array{class: string, method: string}|FrameworkValue> */
    private array $gateBefore = [];

    /** @var list<array{class: string, method: string}|FrameworkValue> */
    private array $gateAfter = [];

    /** @var list<FrameworkValue> */
    private array $inertiaShares = [];

    /** @var array<string, array{class: string, method: string}|FrameworkValue> */
    private array $fortifyActions = [];

    /** @var array<string, FrameworkValue> */
    private array $fortifyViews = [];

    /** @var array<string, FrameworkValue> */
    private array $fortifyCallbacks = [];

    /** @var list<array{class: string, method: string}> */
    private array $fortifyPipeline = [];

    /** @var array<string, string> */
    private array $bindings = [];

    /** @var array<string, FrameworkValue> */
    private array $bindingCandidates = [];

    /** @var array<string, true> */
    private array $origins = [];

    private ?string $unavailable = null;

    private string $unavailableReason = 'unresolved_call';

    private int $methods = 0;

    private ?string $defaultAuthGuard = null;

    /** @var array<string, array{model: ?string, custom: bool}> */
    private array $authGuards = [];

    /** @var list<string> */
    private array $routeAuthGuards = [];

    private bool $providersBuilt = false;

    public function __construct(
        private SourceIndex $sources,
        private readonly ?RouteMap $routes = null,
        private ?RouteEntry $route = null,
    ) {}

    /** Fork provider facts; route state is collected only on the fork. */
    public function forRoute(SourceIndex $sources, ?RouteEntry $route): self
    {
        if ($this->route !== null || ! $this->providersBuilt) {
            throw new \LogicException('Only a built provider context can be forked.');
        }
        $builder = clone $this;
        $builder->sources = $sources;
        $builder->route = $route;

        return $builder;
    }

    private function markUnavailable(string $detail, string $reason = 'unresolved_call'): void
    {
        if ($this->unavailable === null) {
            $this->unavailable = $detail;
            $this->unavailableReason = $reason;
        }
    }

    public function build(): FrameworkContext
    {
        $routeContext = $this->routes?->context;
        if ($this->routes !== null && $routeContext === null) {
            $routeContext = ['status' => FrameworkContext::UNAVAILABLE, 'providers' => []];
            $this->unavailable = 'Laravel framework context is unavailable in the supplied route map.';
        }
        if (($routeContext['status'] ?? null) === FrameworkContext::UNAVAILABLE) {
            $this->markUnavailable(is_string($routeContext['unavailable'] ?? null)
                ? $routeContext['unavailable']
                : 'Laravel framework context is unavailable.', 'unresolved_call');
        }
        if ($routeContext !== null) {
            $missing = array_values(array_filter(
                ['providers', 'middleware', 'middlewareGroups', 'middlewareAliases', 'packageVersions'],
                fn (string $key): bool => ! array_key_exists($key, $routeContext) || ! is_array($routeContext[$key]),
            ));
            if ($missing !== []) {
                $this->markUnavailable('Laravel framework context is incomplete; missing '.implode(', ', $missing).'.', 'unresolved_call');
            }
            $this->readAuthContext($routeContext['auth'] ?? null);
        }
        $providers = $routeContext['providers'] ?? [];
        if (! is_array($providers)) {
            return FrameworkContext::unavailable('Active Laravel providers are unavailable.');
        }

        foreach ($this->providersBuilt ? [] : $providers as $provider) {
            if (! is_string($provider) || ! $this->sources->inScope($provider)) {
                continue;
            }
            if ($this->sources->get($provider) === null) {
                $this->markUnavailable($this->sources->unavailableReason($provider) ?? 'Provider source is unavailable for '.$provider.'.', $this->sources->unavailableCode($provider));

                continue;
            }
            foreach (['register', 'boot'] as $method) {
                if ($this->sources->method($provider, $method) !== null) {
                    $this->visit($provider, $method, 0);
                }
            }
        }
        $this->providersBuilt = true;
        foreach ($this->routeMiddleware() as $middleware) {
            if ($this->sources->method($middleware, 'share') !== null) {
                $this->visit($middleware, 'share', 0);
            } elseif (($reason = $this->sources->unavailableReason($middleware)) !== null) {
                $this->markUnavailable($reason, $this->sources->unavailableCode($middleware));
            }
        }
        $this->routeAuthGuards = $this->routeAuthGuards();

        $status = $this->unavailable !== null
            ? FrameworkContext::UNAVAILABLE
            : (($this->gatePolicies !== [] || $this->gateAbilities !== [] || $this->inertiaShares !== [] || $this->fortifyActions !== [] || $this->fortifyViews !== [] || $this->fortifyCallbacks !== [] || $this->fortifyPipeline !== [] || $this->bindings !== [] || $this->bindingCandidates !== [] || $this->authGuards !== []) ? FrameworkContext::KNOWN : FrameworkContext::EMPTY);

        return new FrameworkContext(
            status: $status,
            providers: array_values(array_filter($providers, 'is_string')),
            gatePolicies: $this->gatePolicies,
            gateAbilities: $this->gateAbilities,
            gateBefore: $this->gateBefore,
            gateAfter: $this->gateAfter,
            inertiaShares: $this->inertiaShares,
            fortifyActions: $this->fortifyActions,
            fortifyViews: $this->fortifyViews,
            fortifyCallbacks: $this->fortifyCallbacks,
            fortifyPipeline: $this->fortifyPipeline,
            bindings: $this->bindings,
            origins: array_keys($this->origins),
            unavailable: $this->unavailable,
            unavailableReason: $this->unavailableReason,
            defaultAuthGuard: $this->defaultAuthGuard,
            authGuards: $this->authGuards,
            routeAuthGuards: $this->routeAuthGuards,
            bindingCandidates: $this->bindingCandidates,
            aliases: $routeContext['aliases'] ?? [],
        );
    }

    private function readAuthContext(mixed $auth): void
    {
        if (! is_array($auth)) {
            return;
        }
        $this->defaultAuthGuard = is_string($auth['default'] ?? null) ? $auth['default'] : null;
        foreach (($auth['guards'] ?? []) as $name => $guard) {
            if (! is_string($name) || ! is_array($guard)) {
                continue;
            }
            $model = is_string($guard['model'] ?? null) ? $guard['model'] : null;
            $this->authGuards[$name] = [
                'model' => $model,
                'custom' => ($guard['custom'] ?? false) === true || $model === null,
            ];
        }
    }

    /** @return list<string> */
    private function routeAuthGuards(): array
    {
        $guards = [];
        foreach (($this->route->middleware ?? []) as $middleware) {
            $parts = explode(':', $middleware, 2);
            if (strtolower($parts[0]) !== 'auth') {
                continue;
            }
            $names = ($parts[1] ?? '') === '' ? [$this->defaultAuthGuard] : explode(',', $parts[1]);
            foreach ($names as $guard) {
                if (is_string($guard) && $guard !== '') {
                    $guards[$guard] = true;
                }
            }
        }

        return array_keys($guards);
    }

    private function visit(string $class, string $method, int $depth): void
    {
        $key = strtolower($class.'::'.$method);
        if ($depth >= 12 || isset($this->active[$key]) || ++$this->methods > 128) {
            $this->markUnavailable('Provider registration cycle or method limit at '.$class.'::'.$method.'.', 'analysis_limit');

            return;
        }
        $found = $this->sources->method($class, $method);
        if ($found === null || $found[1]->stmts === null) {
            return;
        }
        [$source, $node] = $found;
        $this->origins[$source->file->path] = true;
        $this->active[$key] = true;
        $variables = ['this' => FrameworkValue::type($class)];
        foreach ($node->params as $param) {
            if (is_string($param->var->name)) {
                $variables[$param->var->name] = $this->type($source, $param->type);
            }
        }
        $this->walk($node->stmts, $source, $class, $method, $depth, $variables);
        unset($this->active[$key]);
    }

    /** @param list<Node> $nodes
     * @param  array<string, FrameworkValue|null>  $variables
     */
    private function walk(array $nodes, SourceClass $source, string $runtimeClass, string $runtimeMethod, int $depth, array &$variables): void
    {
        foreach ($nodes as $node) {
            if (++$this->nodes > 20_000) {
                $this->markUnavailable('Framework registration AST node budget exceeded.', 'analysis_limit');

                return;
            }
            if (Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function')) {
                continue;
            }
            if (Node::is($node, 'expression.assign') && Node::is($node->var, 'expression.variable') && is_string($node->var->name)) {
                $variables[$node->var->name] = $this->value($node->expr, $source, $variables);
            }
            if (Node::is($node, 'expression.method-call') && Node::is($node->var, 'expression.variable') && $node->var->name === 'this' && Node::is($node->name, 'identifier')) {
                $helper = $node->name->toString();
                if ($this->sources->method($runtimeClass, $helper) !== null) {
                    $this->visit($runtimeClass, $helper, $depth + 1);
                }
            }
            if (Node::is($node, 'expression.static-call') && Node::is($node->class, 'name') && Node::is($node->name, 'identifier')) {
                $class = $source->file->resolvedName($node->class);
                $method = strtolower($node->name->toString());
                $args = array_map(fn (Node $arg): FrameworkValue => $this->value($arg->value, $source, $variables), $node->getArgs());
                $this->registration($class, $method, $args);
            }
            if (Node::is($node, 'expression.method-call') && Node::is($node->name, 'identifier') && $this->isContainerCall($node)) {
                $args = array_map(fn (Node $arg): FrameworkValue => $this->value($arg->value, $source, $variables), $node->getArgs());
                $contract = ($args[0] ?? null)?->literal;
                $implementation = ($args[1] ?? null)?->literal;
                if (in_array(strtolower($node->name->toString()), ['bind', 'singleton', 'scoped'], true)) {
                    $callback = count($args) === 1 ? $args[0] : ($args[1] ?? null);
                    if ($callback?->callback !== null) {
                        $contracts = $contract !== null ? [$contract] : (new ContainerBindingResolver)->contracts($callback);
                        $candidate = (new ContainerBindingResolver)->resolve($callback);
                        foreach ($contracts as $abstract) {
                            $previous = $this->bindingCandidates[$abstract] ?? null;
                            if ($previous !== null && $previous != $candidate) {
                                $candidate = (FrameworkValue::union([$previous, $candidate]) ?? FrameworkValue::unknown())->markAmbiguous();
                                $this->markUnavailable('Container binding registration is ambiguous for '.$abstract.'.', 'unresolved_call');
                            }
                            if (isset($this->bindings[$abstract]) && $candidate->typeNames() !== [$this->bindings[$abstract]]) {
                                $candidate = $candidate->markAmbiguous();
                                $this->markUnavailable('Container binding registration is ambiguous for '.$abstract.'.', 'unresolved_call');
                            }
                            $this->bindingCandidates[$abstract] = $candidate;
                            if (! $candidate->isUnknown() && ! $candidate->isAmbiguous() && ! $candidate->nullable) {
                                $this->registerUnique($this->bindings, $abstract, $candidate->typeNames()[0], 'Container binding');
                                $implementation = $candidate->typeNames()[0];
                            }
                        }
                    }
                }
                if (in_array(strtolower($node->name->toString()), ['bind', 'singleton', 'scoped', 'instance'], true) && $contract !== null && $implementation !== null) {
                    if (isset($this->bindingCandidates[$contract]) && $this->bindingCandidates[$contract]->typeNames() !== [$implementation]) {
                        $this->bindingCandidates[$contract] = $this->bindingCandidates[$contract]->markAmbiguous();
                        $this->markUnavailable('Container binding registration is ambiguous for '.$contract.'.', 'unresolved_call');
                    }
                    $this->registerUnique($this->bindings, $contract, $implementation, 'Container binding');
                } elseif (in_array(strtolower($node->name->toString()), ['bind', 'singleton', 'scoped', 'instance'], true) && $contract !== null && str_starts_with($contract, 'Laravel\\Fortify\\Contracts\\')) {
                    $this->markUnavailable('Fortify container binding is dynamic for '.$contract.'.', 'unresolved_call');
                }
            }
            if (Node::is($node, 'statement.class-like') || Node::is($node, 'statement.function')) {
                continue;
            }
            if ($runtimeMethod === 'share' && Node::is($node, 'statement.return') && $node->expr !== null) {
                $this->inertiaShares[] = $this->value($node->expr, $source, $variables);
            }
            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->$name;
                $children = Node::is($child, 'node') ? [$child] : (is_array($child) ? array_values(array_filter($child, fn ($item): bool => Node::is($item, 'node'))) : []);
                $this->walk($children, $source, $runtimeClass, $runtimeMethod, $depth, $variables);
            }
        }
    }

    /** @param list<FrameworkValue|null> $args */
    private function registration(string $class, string $method, array $args): void
    {
        if ($class === 'Illuminate\Support\Facades\Gate') {
            if ($method === 'policy' && $args[0]?->literal !== null && $args[1]?->literal !== null) {
                $this->registerUnique($this->gatePolicies, $args[0]->literal, $args[1]->literal, 'Gate policy');
            } elseif ($method === 'define' && $args[0]?->literal !== null && ($target = $this->target($args[1] ?? null)) !== null) {
                $this->registerUnique($this->gateAbilities, $args[0]->literal, $target, 'Gate ability');
            } elseif ($method === 'before' && ($target = $this->target($args[0] ?? null)) !== null) {
                $this->gateBefore[] = $target;
            } elseif ($method === 'after' && ($target = $this->target($args[0] ?? null)) !== null) {
                $this->gateAfter[] = $target;
            }

            return;
        }

        if (in_array($class, ['Inertia\Inertia', 'Inertia\ResponseFactory'], true) && $method === 'share') {
            $shared = ($args[0] ?? null)?->type === '@array' ? $args[0] : ($args[1] ?? null);
            if ($shared !== null) {
                $this->inertiaShares[] = $shared;
            }

            return;
        }

        if ($class !== 'Laravel\Fortify\Fortify') {
            return;
        }
        $actionMap = [
            'createusersusing' => ['key' => 'createUsers', 'method' => 'create'],
            'resetuserpasswordsusing' => ['key' => 'resetUserPasswords', 'method' => 'reset'],
            'updateuserprofileinformationusing' => ['key' => 'updateUserProfileInformation', 'method' => 'update'],
            'updateuserpasswordsusing' => ['key' => 'updateUserPasswords', 'method' => 'update'],
            'enabletwofactorauthenticationusing' => ['key' => 'enableTwoFactorAuthentication', 'method' => 'enable'],
            'disabletwofactorauthenticationusing' => ['key' => 'disableTwoFactorAuthentication', 'method' => 'disable'],
            'confirmtwofactorauthenticationusing' => ['key' => 'confirmTwoFactorAuthentication', 'method' => 'confirm'],
        ];
        if (isset($actionMap[$method]) && ($target = $this->target($args[0] ?? null)) !== null) {
            if (is_array($target) && $target['method'] === '__invoke') {
                $target['method'] = $actionMap[$method]['method'];
            }
            $this->registerUnique($this->fortifyActions, $actionMap[$method]['key'], $target, 'Fortify action');

            return;
        }
        if (isset($actionMap[$method])) {
            $this->markUnavailable('Fortify action registration '.$method.' is dynamic.', 'unresolved_call');

            return;
        }
        if (str_ends_with($method, 'view') && ($args[0] ?? null)?->callback !== null) {
            $this->registerUnique($this->fortifyViews, $method, $args[0], 'Fortify view');

            return;
        }
        if (str_ends_with($method, 'view')) {
            $this->markUnavailable('Fortify view registration '.$method.' is dynamic.', 'unresolved_call');

            return;
        }
        if ($method === 'authenticateusing' && ($args[0] ?? null)?->callback !== null) {
            $this->registerUnique($this->fortifyCallbacks, $method, $args[0], 'Fortify authentication callback');

            return;
        }
        if ($method === 'authenticateusing') {
            $this->markUnavailable('Fortify authentication callback is dynamic.', 'unresolved_call');

            return;
        }
        if ($method === 'authenticatethrough' && ($args[0] ?? null)?->callback !== null) {
            $pipeline = $this->callbackReturn($args[0]);
            if ($pipeline?->type !== '@array') {
                $this->markUnavailable('Fortify authentication pipeline is dynamic.', 'unresolved_call');

                return;
            }
            if ($pipeline->isUnknown()) {
                $this->markUnavailable('Fortify authentication pipeline contains dynamic stages.', 'unresolved_call');
            }
            foreach ($pipeline->items as $stage) {
                $class = $stage?->literal;
                if ($class === null) {
                    $this->markUnavailable('Fortify authentication pipeline contains a dynamic stage.', 'unresolved_call');

                    return;
                }
                if (! $this->sources->inScope($class)) {
                    continue;
                }
                $entry = $this->sources->method($class, '__invoke') !== null ? '__invoke' : 'handle';
                if ($this->sources->method($class, $entry) === null) {
                    $unavailable = $this->sources->unavailableReason($class);
                    $this->markUnavailable($unavailable ?? 'Fortify authentication pipeline stage '.$class.' has no resolvable entrypoint.',
                        $unavailable === null ? 'unresolved_call' : $this->sources->unavailableCode($class));

                    return;
                }
                $this->fortifyPipeline[] = ['class' => $class, 'method' => $entry];
            }

            return;
        }
        if ($method === 'authenticatethrough') {
            $this->markUnavailable('Fortify authentication pipeline is dynamic.', 'unresolved_call');
        }
    }

    /** @param array<string, mixed> $registrations */
    private function registerUnique(array &$registrations, string $key, mixed $value, string $kind): void
    {
        if (array_key_exists($key, $registrations) && $registrations[$key] != $value) {
            $this->markUnavailable($kind.' registration is ambiguous for '.$key.'.', 'unresolved_call');

            return;
        }
        $registrations[$key] = $value;
    }

    private function callbackReturn(FrameworkValue $callback): ?FrameworkValue
    {
        if ($callback->callback === null || $callback->callbackSource === null) {
            return null;
        }
        if (Node::is($callback->callback, 'expression.arrow-function')) {
            return $this->value($callback->callback->expr, $callback->callbackSource, $callback->captures);
        }
        foreach ($callback->callback->stmts as $statement) {
            if (Node::is($statement, 'statement.return') && $statement->expr !== null) {
                return $this->value($statement->expr, $callback->callbackSource, $callback->captures);
            }
        }

        return null;
    }

    /** @return array{class: string, method: string}|FrameworkValue|null */
    private function target(?FrameworkValue $value): array|FrameworkValue|null
    {
        if ($value?->callback !== null) {
            return $value;
        }
        if ($value?->type === '@array' && isset($value->items[0], $value->items[1]) && $value->items[0]->literal !== null && $value->items[1]->literal !== null) {
            return ['class' => $value->items[0]->literal, 'method' => $value->items[1]->literal];
        }
        if ($value?->literal !== null) {
            return ['class' => $value->literal, 'method' => '__invoke'];
        }

        return null;
    }

    /** @param array<string, FrameworkValue|null> $variables */
    private function value(Node $expr, SourceClass $source, array $variables): FrameworkValue
    {
        if (Node::is($expr, 'expression.class-const-fetch') && Node::is($expr->class, 'name') && Node::is($expr->name, 'identifier') && strtolower($expr->name->toString()) === 'class') {
            $class = $source->file->resolvedName($expr->class);

            return new FrameworkValue(type: $class, literal: $class);
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
                $key = Node::is($item->key, 'scalar.string') ? $item->key->value : (Node::is($item->key, 'scalar.int') ? $item->key->value : $index);
                $items[$key] = $this->value($item->value, $source, $variables);
            }

            return FrameworkValue::array($items);
        }
        if (Node::is($expr, 'expression.closure') || Node::is($expr, 'expression.arrow-function')) {
            return FrameworkValue::callback($source, $expr, $variables);
        }
        if (Node::is($expr, 'expression.variable') && is_string($expr->name)) {
            return $variables[$expr->name] ?? FrameworkValue::unknown();
        }
        if (Node::is($expr, 'expression.func-call') && Node::is($expr->name, 'name') && strtolower($expr->name->toString()) === 'array_merge') {
            $items = [];
            $unknown = false;
            foreach ($expr->getArgs() as $arg) {
                $value = $this->value($arg->value, $source, $variables);
                if ($value->type === '@array') {
                    foreach ($value->items as $key => $item) {
                        if (is_int($key)) {
                            $items[] = $item;
                        } else {
                            $items[$key] = $item;
                        }
                    }
                }
                if ($value->type !== '@array' || $value->isUnknown()) {
                    $unknown = true;
                }
            }

            return new FrameworkValue(type: '@array', items: $items, unknown: $unknown);
        }
        if (Node::is($expr, 'expression.static-call') && Node::is($expr->class, 'name') && strtolower($expr->class->toString()) === 'parent' && Node::is($expr->name, 'identifier') && strtolower($expr->name->toString()) === 'share') {
            return FrameworkValue::array([]);
        }

        return FrameworkValue::unknown();
    }

    private function type(SourceClass $source, Node|string|null $type): ?FrameworkValue
    {
        if (Node::is($type, 'nullable-type')) {
            return $this->type($source, $type->type);
        }
        if (Node::is($type, 'name')) {
            return FrameworkValue::type($source->file->resolvedName($type));
        }
        if (Node::is($type, 'identifier') && ! in_array(strtolower($type->toString()), ['mixed', 'object', 'callable', 'iterable'], true)) {
            return FrameworkValue::scalar();
        }

        return null;
    }

    private function isContainerCall(Node $call): bool
    {
        if (Node::is($call->var, 'expression.property-fetch') && Node::is($call->var->var, 'expression.variable') && $call->var->var->name === 'this' && Node::is($call->var->name, 'identifier')) {
            return in_array($call->var->name->toString(), ['app', 'container'], true);
        }

        return false;
    }

    /** @return list<string> */
    private function routeMiddleware(): array
    {
        if ($this->route === null) {
            return [];
        }
        $context = $this->routes->context;
        $groups = is_array($context['middlewareGroups'] ?? null) ? $context['middlewareGroups'] : [];
        $aliases = is_array($context['middlewareAliases'] ?? null) ? $context['middlewareAliases'] : [];
        $global = is_array($context['middleware'] ?? null) ? $context['middleware'] : [];
        $excluded = array_map(fn (string $value): string => explode(':', $value, 2)[0], $this->route->excludedMiddleware);
        $resolved = [];
        $queue = [...$global, ...$this->route->middleware, ...$this->controllerMiddleware()];
        $seen = [];
        while ($queue !== []) {
            $name = array_shift($queue);
            if (! is_string($name)) {
                continue;
            }
            $base = explode(':', $name, 2)[0];
            if (isset($seen[$base]) || in_array($base, $excluded, true)) {
                continue;
            }
            $seen[$base] = true;
            if (isset($groups[$base]) && is_array($groups[$base])) {
                array_push($queue, ...$groups[$base]);

                continue;
            }
            $class = $aliases[$base] ?? $base;
            if (is_string($class) && $this->sources->inScope($class)) {
                if ($this->sources->get($class) !== null) {
                    $resolved[] = $class;
                } else {
                    $this->markUnavailable($this->sources->unavailableReason($class) ?? 'Route middleware source is unavailable for '.$class.'.', $this->sources->unavailableCode($class));
                }
            } elseif (is_string($class) && str_starts_with(ltrim($class, '\\'), 'App\\')) {
                $this->markUnavailable('Route middleware source is unavailable for '.$class.'.', 'source_unavailable');
            } elseif (! isset($aliases[$base]) && ! str_contains($base, '\\')) {
                $this->markUnavailable('Route middleware alias or group is unavailable for '.$base.'.', 'unresolved_call');
            } elseif (is_string($class) && ! $this->isKnownVendorMiddleware($class)) {
                $this->markUnavailable('Route middleware source is unavailable for '.$class.'.', 'source_unavailable');
            }
        }

        return $resolved;
    }

    private function isKnownVendorMiddleware(string $class): bool
    {
        $class = ltrim($class, '\\');

        return str_starts_with($class, 'Illuminate\\')
            || str_starts_with($class, 'Laravel\\Fortify\\')
            || str_starts_with($class, 'Inertia\\')
            || $class === 'Laravel\\Mcp\\Server\\Middleware\\AddWwwAuthenticateHeader';
    }

    /** @return list<string> */
    private function controllerMiddleware(): array
    {
        if ($this->route?->class === null || $this->route->method === null) {
            return [];
        }
        $found = $this->sources->method($this->route->class, 'middleware');
        if ($found === null || $found[1]->stmts === null) {
            return [];
        }
        [$source, $method] = $found;
        $this->origins[$source->file->path] = true;
        foreach ($method->stmts as $statement) {
            if (Node::is($statement, 'statement.return') && $statement->expr !== null) {
                return $this->middlewareDefinitions($statement->expr, $source, $this->route->method);
            }
        }
        $this->markUnavailable('Controller middleware declaration is dynamic for '.$this->route->class.'::middleware.', 'unresolved_call');

        return [];
    }

    /** @return list<string> */
    private function middlewareDefinitions(Node $expr, SourceClass $source, string $routeMethod): array
    {
        if (Node::is($expr, 'scalar.string')) {
            return [$expr->value];
        }
        if (Node::is($expr, 'expression.array')) {
            $result = [];
            foreach ($expr->items as $item) {
                if ($item !== null) {
                    array_push($result, ...$this->middlewareDefinitions($item->value, $source, $routeMethod));
                }
            }

            return $result;
        }
        if (Node::is($expr, 'expression.new') && Node::is($expr->class, 'name') && str_ends_with($source->file->resolvedName($expr->class), '\\Middleware')) {
            $arguments = [];
            foreach ($expr->getArgs() as $index => $argument) {
                $arguments[$argument->name?->toString() ?? $index] = $argument->value;
            }
            $name = $arguments['middleware'] ?? $arguments[0] ?? null;
            $only = $this->literalStrings($arguments['only'] ?? $arguments[1] ?? null);
            $except = $this->literalStrings($arguments['except'] ?? $arguments[2] ?? null);
            if (! Node::is($name, 'scalar.string') || $only === null || $except === null) {
                $this->markUnavailable('Controller middleware assignment is dynamic for '.$this->route?->class.'::'.$routeMethod.'.', 'unresolved_call');

                return [];
            }
            if (($only === [] || in_array($routeMethod, $only, true)) && ! in_array($routeMethod, $except, true)) {
                return [$name->value];
            }

            return [];
        }

        $this->markUnavailable('Controller middleware assignment is dynamic for '.$this->route?->class.'::'.$routeMethod.'.', 'unresolved_call');

        return [];
    }

    /** @return list<string>|null */
    private function literalStrings(Node|string|null $value): ?array
    {
        if ($value === null) {
            return [];
        }
        if (Node::is($value, 'scalar.string')) {
            return [$value->value];
        }
        if (Node::is($value, 'expression.array')) {
            $strings = [];
            foreach ($value->items as $item) {
                if ($item === null || ! Node::is($item->value, 'scalar.string')) {
                    return null;
                }
                $strings[] = $item->value->value;
            }

            return $strings;
        }

        return null;
    }
}
