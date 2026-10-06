<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use Illuminate\Support\Str;

/** Authorization witnesses. Conditions describe Laravel dispatch, never access decisions. */
trait AuthorizationLinks
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $authorizationPolicies = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $authorizationAbilities = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $authorizationHooks = ['before' => [], 'after' => []];

    private bool $authorizationDynamicPolicy = false;

    /** @var list<array<string, mixed>> */
    public array $authorizationChecks = [];

    private function authorizationGate(?string $owner): bool
    {
        return in_array($owner, ['Illuminate\Support\Facades\Gate', 'Illuminate\Contracts\Auth\Access\Gate', 'Illuminate\Auth\Access\Gate'], true);
    }

    /** @param array<string, mixed> $facts */
    private function authorizationLinks(array $facts): void
    {
        foreach ($this->classes as $class) {
            if ($this->inherits($class['name'], 'Illuminate\Foundation\Support\Providers\AuthServiceProvider')) {
                foreach (is_array($class['properties']['policies'] ?? null) ? $class['properties']['policies'] : [] as $model => $policy) {
                    if (is_string($model)) {
                        $this->authorizationPolicies[strtolower($model)][] = ['value' => $policy, 'source' => ['path' => $class['path'], 'line' => $class['line'], 'offset' => $class['offset']], 'conditions' => ['Provider must be active.']];
                    }
                }
            }
        }
        foreach ($facts['operations'] as $op) {
            if (! $this->room()) {
                break;
            }
            if ($op['kind'] !== 'authorization_candidate' || ! $this->authorizationGate($op['owner'])) {
                continue;
            }
            $args = $op['args'];
            if ($op['method'] === 'policy') {
                $model = $args['class'] ?? $args[0] ?? null;
                $policy = $args['policy'] ?? $args[1] ?? null;
                if (is_string($model) && is_string($policy)) {
                    $this->authorizationPolicies[strtolower($model)][] = ['value' => $policy, ...$op];
                } else {
                    $this->authorizationDynamicPolicy = true;
                    $this->notice($op, 'Dynamic policy registration is unresolved.');
                }
            } elseif ($op['method'] === 'define') {
                $ability = $args['ability'] ?? $args[0] ?? null;
                if (is_string($ability)) {
                    $this->authorizationAbilities[$ability][] = ['value' => $args['callback'] ?? $args[1] ?? null, ...$op];
                } else {
                    $this->notice($op, 'Dynamic ability registration is unresolved.');
                }
            } elseif (in_array($op['method'], ['before', 'after'], true)) {
                $this->authorizationHooks[$op['method']][] = ['value' => $args['callback'] ?? $args[0] ?? null, ...$op];
            } elseif ($op['method'] === 'guesspolicynamesusing') {
                $this->authorizationDynamicPolicy = true;
                $this->notice($op, 'Custom policy name guessing is unresolved.');
            }
        }
        foreach ($facts['operations'] as $op) {
            if (! $this->room()) {
                break;
            }
            if ($op['kind'] !== 'authorization_candidate') {
                continue;
            }
            $owner = $op['owner'];
            $gate = $this->authorizationGate($owner);
            $controller = $owner !== null && $this->inherits($owner, 'Illuminate\Foundation\Auth\Access\AuthorizesRequests');
            $user = $owner !== null && ($this->inherits($owner, 'Illuminate\Foundation\Auth\Access\Authorizable') || $this->inherits($owner, 'Illuminate\Contracts\Auth\Access\Authorizable') || $this->inherits($owner, 'Illuminate\Foundation\Auth\User'));
            if (! $gate && $owner !== null && $this->method($owner, $op['method']) !== null) {
                continue; // A real project implementation overrides the framework contract.
            }
            if ($gate && in_array($op['method'], ['allowif', 'denyif'], true)) {
                $this->authorizationCheck($op, null, null, true);
            } elseif ($gate && in_array($op['method'], ['authorize', 'allows', 'denies', 'check', 'inspect', 'any', 'none'], true) || $controller && in_array($op['method'], ['authorize', 'authorizeforuser'], true) || $user && in_array($op['method'], ['can', 'cannot', 'cant', 'canany'], true)) {
                $shift = $op['method'] === 'authorizeforuser' ? 1 : 0;
                $abilities = $op['args']['abilities'] ?? $op['args']['ability'] ?? $op['args'][$shift] ?? null;
                $arguments = array_key_exists('arguments', $op['args']) ? $op['args']['arguments'] : (array_key_exists($shift + 1, $op['args']) ? $op['args'][$shift + 1] : []);
                if ($controller && ! is_string($abilities) && ! (is_array($abilities) && array_is_list($abilities))) {
                    $map = ['index' => 'viewAny', 'show' => 'view', 'create' => 'create', 'store' => 'create', 'edit' => 'update', 'update' => 'update', 'destroy' => 'delete'];
                    $arguments = [$abilities];
                    $abilities = $this->method($owner, 'resourceAbilityMap') === null ? ($map[explode('::', $op['from'])[1] ?? ''] ?? null) : null;
                }
                $this->authorizationCheck($op, $abilities, $arguments);
            }
        }
    }

    /** Attach route-specific checks to route roots, never to a shared handler.
     * @param  list<array<string, mixed>>  $routes
     * @param  array<string, mixed>  $facts
     */
    public function authorizationHttp(array $routes, array $facts): void
    {
        foreach ($routes as $route) {
            if (! $this->room()) {
                break;
            }
            $handler = $route['handler'];
            $target = isset($handler['callback']) ? '(route) '.$route['source']['path'].':'.$route['source']['offset'] : null;
            if (isset($handler['class'], $handler['method'])) {
                $target = $this->method($handler['class'], $handler['method'])['symbol'] ?? null;
            }
            if ($target === null) {
                continue;
            }
            $root = '(http) '.$route['id'];
            $parameters = $handler['parameters'] ?? [];
            if (isset($handler['class'], $handler['method'])) {
                $parameters = $this->method($handler['class'], $handler['method'])['parameters'] ?? [];
            }
            $op = ['from' => $root, 'source' => $route['source'], 'conditions' => [...$route['reasons'], 'Route registration and middleware must be active.'], 'method' => 'middleware', 'args' => [], 'usage' => 'throws_on_denial'];
            foreach ($parameters as $parameter) {
                if (! ($parameter['route_resolvable'] ?? false)) {
                    if ($parameter['types'] !== []) {
                        $this->authorizationUnknown($op, $root, 'Route parameter is not a resolvable named class type: '.$parameter['name']);
                    }

                    continue;
                }
                foreach ($parameter['types'] as $type) {
                    if ($this->inherits($type, 'Illuminate\Foundation\Http\FormRequest')) {
                        $authorize = $this->method($type, 'authorize');
                        if ($authorize !== null) {
                            $this->edge($root, $authorize['symbol'], 'authorization-form-request', $route['source'], [...$op['conditions'], 'Form Request is resolved for this handler parameter; false denies and exceptions may be handled by the application.'], ['authorization' => true, 'registration' => $authorize['source']]);
                        }
                    }
                }
            }
            if (! is_array($route['middleware']) || ! is_array($route['excluded_middleware'])) {
                $this->authorizationUnknown($op, $root, 'Dynamic route middleware prevents complete authorization analysis.');
            }
            $middleware = array_values(array_diff($route['middleware'] ?? [], $route['excluded_middleware'] ?? []));
            foreach ($facts['operations'] as $candidate) {
                if (! $this->room()) {
                    break;
                }
                if ($candidate['kind'] !== 'authorization_candidate' || $candidate['method'] !== 'authorizeresource' || ($candidate['from'] ?? null) !== (isset($handler['class']) ? ($this->method($handler['class'], '__construct')['symbol'] ?? null) : null) || ! $this->inherits($candidate['owner'], 'Illuminate\Foundation\Auth\Access\AuthorizesRequests') || $this->method($candidate['owner'], 'authorizeResource') !== null) {
                    continue;
                }
                $map = ['index' => 'viewAny', 'show' => 'view', 'create' => 'create', 'store' => 'create', 'edit' => 'update', 'update' => 'update', 'destroy' => 'delete'];
                if ($this->method($candidate['owner'], 'resourceAbilityMap') !== null || $this->method($candidate['owner'], 'resourceMethodsWithoutModels') !== null) {
                    $this->authorizationUnknown($op, $root, 'Custom authorizeResource mapping is unresolved.');

                    continue;
                }
                $action = $handler['method'] ?? '';
                $model = $candidate['args']['model'] ?? $candidate['args'][0] ?? null;
                $parameter = $candidate['args']['parameter'] ?? $candidate['args'][1] ?? null;
                $options = $candidate['args']['options'] ?? $candidate['args'][2] ?? [];
                $models = is_array($model) && array_is_list($model) ? $model : [$model];
                if (count(array_filter($models, 'is_string')) !== count($models) || ! isset($map[$action]) || ! is_array($options)) {
                    $this->authorizationUnknown($op, $root, 'authorizeResource model, parameter or options require verification.');

                    continue;
                }
                if (in_array($action, (array) ($options['except'] ?? []), true)) {
                    continue;
                }
                if ($parameter === null) {
                    $parameter = implode(',', array_map(fn ($value) => Str::snake(class_basename($value)), $models));
                } elseif (is_array($parameter) && array_is_list($parameter) && count(array_filter($parameter, 'is_string')) === count($parameter)) {
                    $parameter = implode(',', $parameter);
                }
                if (! is_string($parameter)) {
                    $this->authorizationUnknown($op, $root, 'authorizeResource parameter is dynamic.');

                    continue;
                }
                $op['conditions'] = array_values(array_unique([...$op['conditions'], ...$candidate['conditions'], 'Controller constructor authorization registration must execute.']));
                $middleware[] = 'can:'.$map[$action].','.(in_array($action, ['index', 'create', 'store'], true) ? implode(',', $models) : $parameter);
            }
            foreach ($middleware as $entry) {
                if (! $this->room()) {
                    break;
                }
                if (str_starts_with($entry, 'can:') || str_starts_with($entry, 'Illuminate\Auth\Middleware\Authorize:')) {
                    $arguments = explode(',', explode(':', $entry, 2)[1]);
                    $ability = array_shift($arguments);
                    $models = [];
                    $unresolved = false;
                    foreach ($arguments as $argument) {
                        $model = null;
                        if (str_contains($argument, '\\')) {
                            $model = $argument;
                        } else {
                            foreach ($parameters as $parameter) {
                                if ($parameter['name'] === $argument && ($parameter['route_resolvable'] ?? false) && count($parameter['types']) === 1) {
                                    $model = ['type' => 'object', 'class' => $parameter['types'][0]];
                                }
                            }
                        }
                        if ($model === null) {
                            $this->authorizationUnknown($op, $root, 'Route authorization argument binding is unresolved: '.$argument);
                            $unresolved = true;
                        }
                        $models[] = $model;
                    }
                    if (! $unresolved) {
                        $this->authorizationCheck($op, $ability, $models);
                    }
                } else {
                    $class = explode(':', $entry, 2)[0];
                    $aliases = [];
                    foreach ($facts['operations'] as $registration) {
                        if (! $this->room()) {
                            break;
                        }
                        if ($registration['kind'] === 'middleware_alias') {
                            $mapping = $registration['args']['aliases'] ?? $registration['args'][0] ?? [];
                            if (is_array($mapping) && isset($mapping[$class])) {
                                $aliases[] = $mapping[$class];
                            }
                        }
                    }
                    foreach ($this->classes as $kernel) {
                        if ($this->inherits($kernel['name'], 'Illuminate\Foundation\Http\Kernel')) {
                            foreach (['middlewareAliases', 'routeMiddleware'] as $property) {
                                if (isset($kernel['properties'][$property][$class])) {
                                    $aliases[] = $kernel['properties'][$property][$class];
                                }
                            }
                        }
                    }
                    if ($aliases !== []) {
                        if (count(array_filter($aliases, 'is_string')) !== count($aliases) || count(array_unique($aliases)) !== 1) {
                            $this->authorizationUnknown($op, $root, 'Middleware alias has ambiguous or dynamic registration.');

                            continue;
                        }
                        $class = $aliases[0];
                    }
                    $method = $this->method($class, 'handle');
                    if ($method !== null) {
                        $this->edge($root, $method['symbol'], 'http-middleware', $route['source'], $op['conditions'], ['registration' => $method['source']]);
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $op */
    private function authorizationCheck(array $op, mixed $abilities, mixed $arguments, bool $inline = false): void
    {
        $check = '(authorization) '.$op['source']['path'].':'.$op['source']['offset'].':'.count($this->authorizationChecks);
        $throws = in_array($op['method'], ['authorize', 'authorizeforuser', 'allowif', 'denyif', 'middleware'], true);
        $caught = $op['caught'] ?? [];
        $handled = array_intersect($caught, ['Illuminate\Auth\Access\AuthorizationException', 'Exception', 'Throwable']) !== [];
        $meta = ['authorization' => true, 'denial_handling' => $handled ? 'recognized_catch' : ($caught !== [] ? 'catch_requires_check' : 'no_local_catch'), 'polarity' => in_array($op['method'], ['cannot', 'cant', 'denies', 'none', 'denyif'], true) ? 'inverted' : 'allow', 'operation' => $op['method'], 'result' => $throws ? 'throws_on_denial' : ($op['method'] === 'inspect' ? 'response' : 'boolean'), 'usage' => $op['usage'] ?? 'requires_check', 'caught' => $op['caught'] ?? [], 'inline' => $inline, 'ability' => $abilities, 'arguments' => $arguments];
        $this->authorizationChecks[] = ['symbol' => $check, 'from' => $op['from'], 'source' => $op['source'], ...$meta];
        $this->edge($op['from'], $check, 'authorization-check', $op['source'], $op['conditions'], $meta);
        if ($inline) {
            $value = $op['args']['condition'] ?? $op['args'][0] ?? null;
            if (is_array($value) && ($value['type'] ?? '') === 'callback') {
                $this->authorizationCallback($check, $value, $op, ['Inline callback must be eligible for the resolved user.'], 'authorization-inline');
            } elseif ($value === null) {
                $this->authorizationUnknown($op, $check, 'Inline authorization argument is unresolved.');
            }

            return;
        }
        if ($arguments === null) {
            $this->authorizationUnknown($op, $check, 'Authorization arguments are dynamic; policy versus ability selection is unresolved.');

            return;
        }
        $list = is_array($abilities) && array_is_list($abilities) ? $abilities : [$abilities];
        foreach ($list as $index => $ability) {
            if (! $this->room()) {
                break;
            }
            if (! is_string($ability)) {
                $this->authorizationUnknown($op, $check, 'Authorization ability is dynamic or inferred from unsupported source.');

                continue;
            }
            $conditions = ['Authorization registration must be active; runtime user and arguments are not evaluated.'];
            if ($index > 0) {
                $conditions[] = in_array($op['method'], ['any', 'none', 'canany'], true) ? 'Previous abilities did not allow; evaluation may stop earlier.' : 'Previous abilities allowed; evaluation may stop earlier.';
            }
            foreach ($this->authorizationHooks['before'] as $hookIndex => $hook) {
                $this->authorizationCallback($check, $hook['value'], $op, [...$conditions, 'Source before registration '.($hookIndex + 1).'; runtime provider order is unverified and all earlier before results must be null.'], 'authorization-before', $hook);
            }
            $bodyConditions = [...$conditions, 'All global before callbacks must return null.'];
            $first = is_array($arguments) && array_is_list($arguments) ? ($arguments[0] ?? null) : $arguments;
            $model = is_string($first) ? $first : (is_array($first) ? ($first['class'] ?? null) : null);
            $policy = $model === null ? null : $this->authorizationPolicy($model, $op, $check);
            $method = $policy === null ? null : $this->method($policy, str_contains($ability, '-') ? Str::camel($ability) : $ability);
            if ($method !== null && $method['public']) {
                $before = $this->method($policy, 'before');
                if ($before === null && $this->authorizationMethodUncertain($policy, 'before')) {
                    $this->authorizationUnknown($op, $check, 'Policy before dispatch may be inherited from unread or ambiguous source.');
                }
                if ($before !== null) {
                    $this->edge($check, $before['symbol'], 'authorization-policy-before', $op['source'], [...$bodyConditions, $this->authorizationEligibility($before), 'Policy before participates only when the requested ability method is callable.'], ['authorization' => true, 'ability' => $ability, 'registration' => $before['source']]);
                }
                $this->edge($check, $method['symbol'], 'authorization-policy', $op['source'], [...$bodyConditions, $this->authorizationEligibility($method), 'Policy before must return null or be ineligible.'], ['authorization' => true, 'ability' => $ability, 'registration' => $method['source']]);
            } elseif ($policy !== null && ($this->authorizationMethodUncertain($policy, str_contains($ability, '-') ? Str::camel($ability) : $ability)) || $policy === null && $model !== null && $this->authorizationDynamicPolicy) {
                $this->authorizationUnknown($op, $check, 'Policy precedence is unresolved; ability fallback cannot be assumed.');
            } else {
                $definitions = $this->authorizationAbilities[$ability] ?? [];
                if (count($definitions) !== 1) {
                    $this->authorizationUnknown($op, $check, 'Ability callback is absent, external, or has ambiguous registrations: '.$ability);
                } else {
                    $this->authorizationCallback($check, $definitions[0]['value'], $op, $bodyConditions, 'authorization-ability', $definitions[0]);
                }
            }
            foreach ($this->authorizationHooks['after'] as $hookIndex => $hook) {
                $this->authorizationCallback($check, $hook['value'], $op, [...$conditions, 'Global after callback order '.($hookIndex + 1).'; runs after raw result, including non-null before result, unless evaluation threw. Overrides only a null result.'], 'authorization-after', $hook);
            }
        }
    }

    /** A missing method is definite only when its full source hierarchy is known.
     * @param  array<string, bool>  $seen
     */
    private function authorizationMethodUncertain(string $owner, string $method, array $seen = []): bool
    {
        $key = strtolower($owner);
        if (isset($seen[$key]) || count($seen) >= 32 || ! isset($this->classes[$key])) {
            return true;
        }
        $seen[$key] = true;
        $class = $this->classes[$key];
        if (($class['ambiguous'] ?? false) || ($class['adaptations'] ?? false) || isset($class['methods']['__call'])) {
            return true;
        }
        if (isset($class['methods'][strtolower($method)])) {
            return false;
        }
        foreach ([...$class['parents'], ...$class['traits']] as $ancestor) {
            if ($this->authorizationMethodUncertain($ancestor, $method, $seen)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $method */
    private function authorizationEligibility(array $method): string
    {
        return ($method['parameters'][0]['nullable'] ?? false) ? 'Authenticated users and guests are eligible by the first parameter.' : 'A resolved non-null user is required by the callback signature.';
    }

    /** @param array<string, mixed> $op */
    private function authorizationPolicy(string $model, array $op, string $check): ?string
    {
        if ($this->classes[strtolower($model)]['ambiguous'] ?? false) {
            $this->authorizationUnknown($op, $check, 'Model declaration is ambiguous.');

            return '(unresolved-policy)';
        }
        $explicit = $this->authorizationPolicies[strtolower($model)] ?? [];
        if ($explicit !== []) {
            if (count($explicit) === 1 && ! $this->authorizationDynamicPolicy) {
                return is_string($explicit[0]['value']) ? $explicit[0]['value'] : null;
            }
            $this->authorizationUnknown($op, $check, 'Policy registrations are ambiguous or dynamic.');

            return '(unresolved-policy)';
        }
        if ($this->authorizationDynamicPolicy) {
            return null;
        }
        if (! isset($this->classes[strtolower($model)])) {
            $this->authorizationUnknown($op, $check, 'Authorization model source is outside the analyzed project.');

            return '(unresolved-policy)';
        }
        $attributes = $this->classes[strtolower($model)]['attributes']['Illuminate\Database\Eloquent\Attributes\UsePolicy'] ?? [];
        if (is_string($attributes['class'] ?? $attributes[0] ?? null)) {
            return $attributes['class'] ?? $attributes[0];
        }
        $parts = explode('\\', $model);
        $basename = array_pop($parts);
        $candidates = [];
        for ($i = 1; $i <= count($parts); $i++) {
            $candidates[] = implode('\\', array_slice($parts, 0, $i)).'\\Policies\\'.$basename.'Policy';
        }
        if (in_array('Models', $parts, true)) {
            $namespace = implode('\\', $parts).'\\';
            $candidates[] = str_replace('\\Models\\', '\\Policies\\', $namespace).$basename.'Policy';
            $candidates[] = str_replace('\\Models\\', '\\Models\\Policies\\', $namespace).$basename.'Policy';
        }
        $found = array_values(array_unique(array_filter($candidates, fn ($candidate) => isset($this->classes[strtolower($candidate)]))));
        if (count($found) === 1) {
            return $found[0];
        }
        if (count($found) > 1) {
            $this->authorizationUnknown($op, $check, 'Several policy convention candidates exist; selection requires verification.');

            return '(unresolved-policy)';
        }
        foreach ($this->authorizationPolicies as $expected => $registrations) {
            if ($this->inherits($model, $expected)) {
                foreach ($registrations as $registration) {
                    $found[] = $registration['value'];
                }
            }
        }
        if ($found !== [] && count(array_unique($found)) !== 1) {
            $this->authorizationUnknown($op, $check, 'Several inherited policy registrations are possible.');

            return '(unresolved-policy)';
        }
        if (count(array_unique($found)) === 1 && is_string($found[0])) {
            return $found[0];
        }

        return null;
    }

    /** @param array<string, mixed> $op
     * @param  list<string>  $conditions
     * @param  array<string, mixed>|null  $registration
     */
    private function authorizationCallback(string $check, mixed $value, array $op, array $conditions, string $kind, ?array $registration = null): void
    {
        if (is_string($value) && ! str_contains($value, '@')) {
            $value .= '@__invoke';
        }
        $target = $this->authorizationCallable($value);
        if ($target === null) {
            $this->authorizationUnknown($op, $check, 'Authorization callback declaration is unresolved or external.');

            return;
        }
        $eligibility = is_array($value) && ($value['type'] ?? '') === 'callback' ? (($value['guest'] ?? false) ? 'Callback signature allows guests.' : 'Callback requires a non-null user.') : $this->authorizationEligibility($this->method(explode('::', $target)[0], explode('::', $target)[1] ?? '__invoke') ?? []);
        $this->edge($check, $target, $kind, $op['source'], [...$conditions, ...($registration['conditions'] ?? []), $eligibility], ['authorization' => true, 'registration' => is_array($value) && isset($value['source']) ? $value['source'] : ($registration['source'] ?? null)]);
    }

    private function authorizationCallable(mixed $value): ?string
    {
        if (is_array($value) && ($value['type'] ?? '') === 'callback') {
            return $value['symbol'];
        }
        if (is_array($value) && ($value['type'] ?? '') === 'reference') {
            [$owner, $method] = array_pad(explode('::', $value['symbol'], 2), 2, '__invoke');
        } elseif (is_array($value) && array_is_list($value) && count($value) === 2 && is_string($value[1])) {
            $owner = is_string($value[0]) ? $value[0] : ($value[0]['class'] ?? null);
            $method = $value[1];
        } elseif (is_string($value)) {
            [$owner, $method] = array_pad(explode('@', $value, 2), 2, '__invoke');
        } else {
            return null;
        }
        $declaration = $owner === null ? null : $this->method($owner, $method);

        return $declaration !== null && $declaration['public'] && ! $declaration['abstract'] ? $declaration['symbol'] : null;
    }

    /** @param array<string, mixed> $op */
    private function authorizationUnknown(array $op, string $check, string $reason): void
    {
        $notice = [...$op['source'], 'reason' => $reason];
        $this->unknown[strtolower($check)][] = $notice;
        $this->notices[] = $notice;
    }
}
