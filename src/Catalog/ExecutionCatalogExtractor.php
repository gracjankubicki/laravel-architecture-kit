<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ExecutionExtractor;
use InvalidArgumentException;

/** Persists semantic names/types only, never ordinary method arguments or property values. */
final class ExecutionCatalogExtractor
{
    private const OPERATIONS = ['application', 'discovery', 'bus_map', 'listen', 'subscribe', 'observe', 'model_listen', 'quiet', 'event', 'dispatch', 'dispatch_options', 'model_operation', 'console_registration', 'console_closure', 'console_closure_options', 'console_candidate', 'schedule', 'schedule_options', 'schedule_group', 'authorization_candidate', 'middleware_alias', 'middleware_registration', 'middleware_global', 'middleware_group', 'middleware_controller', 'middleware_controller_options', 'auth_registration', 'exception_activation', 'exception_registration', 'exception_options', 'exception_control'];

    private bool $limited = false;

    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        if (count($php->elements) === 1 && array_filter($php->diagnostics, fn ($diagnostic) => in_array($diagnostic->code, ['source_limit', 'parse_error', 'catalog_limit'], true)) !== []) {
            return new CatalogFacts($file->path);
        }
        $this->limited = false;
        $raw = (new ExecutionExtractor)->extract($file, catalog: true);
        $classes = [];
        foreach ($raw['class_declarations'] as $class) {
            $methods = [];
            foreach ($class['methods'] as $name => $method) {
                $returns = match ($name) {
                    'subscribe' => array_map(fn ($value) => $this->nameMap($value), $method['returns']),
                    'discovereventswithin' => array_map(fn ($value) => $this->value($value), $method['returns']),
                    'middleware' => array_map(fn ($value) => in_array('Illuminate\\Routing\\Controllers\\HasMiddleware', $class['parents'], true)
                        ? $this->value($value) : $this->middlewareReturn($this->value($value)), $method['returns']),
                    'getobservableevents' => array_map($this->eventNames(...), $method['returns']),
                    'shouldqueue', 'shoulddiscoverevents', 'shouldbediscovered' => array_map(fn ($value) => is_bool($value) ? $value : null, $method['returns']),
                    default => [],
                };
                $methods[$name] = ['symbol' => $method['symbol'], 'name' => $method['name'], 'public' => $method['public'], 'abstract' => $method['abstract'],
                    'parameter' => $method['parameter'], 'parameters' => $method['parameters'], 'returns' => $returns,
                    'middleware_candidates' => array_map(fn ($value) => $this->value($value), $method['middleware_candidates'] ?? []),
                    'conditional_return' => $method['conditional_return'] || count($method['returns']) > 1, 'source' => $this->source($method['source'])];
            }
            $properties = [];
            foreach (['listen', 'subscribe', 'observers', 'observables', 'dispatchesEvents', 'policies', 'middlewareGroups', 'middleware', 'middlewareAliases', 'routeMiddleware', 'commands', 'aliases', 'queue', 'connection', 'afterCommit', 'tries', 'timeout', 'backoff', 'maxExceptions', 'failOnTimeout', 'dontReport'] as $name) {
                if (array_key_exists($name, $class['properties'])) {
                    $value = $class['properties'][$name];
                    $properties[$name] = match ($name) {
                        'queue', 'connection' => is_string($value) ? $value : null,
                        'afterCommit', 'failOnTimeout' => is_bool($value) ? $value : null,
                        'tries', 'timeout', 'maxExceptions' => is_int($value) ? $value : null,
                        'backoff' => $this->backoff($value),
                        'observables' => $this->eventNames($value),
                        'listen', 'observers', 'dispatchesEvents', 'policies', 'middlewareGroups', 'middleware', 'middlewareAliases', 'routeMiddleware', 'dontReport' => $this->nameMap($value),
                        default => $this->value($value),
                    };
                }
            }
            // Console names are needed by the shared composer; option defaults are omitted.
            foreach (['signature', 'name'] as $name) {
                if (is_string($class['properties'][$name] ?? null)) {
                    $properties[$name] = preg_split('/\s+/', trim($class['properties'][$name]))[0];
                }
            }
            $attributes = [];
            if (isset($class['attributes']['Illuminate\\Database\\Eloquent\\Attributes\\ObservedBy'])) {
                $attributes['Illuminate\\Database\\Eloquent\\Attributes\\ObservedBy'] = $this->value($class['attributes']['Illuminate\\Database\\Eloquent\\Attributes\\ObservedBy']);
            }
            if (isset($class['attributes']['Illuminate\\Database\\Eloquent\\Attributes\\UsePolicy'])) {
                $attributes['Illuminate\\Database\\Eloquent\\Attributes\\UsePolicy'] = $this->value($class['attributes']['Illuminate\\Database\\Eloquent\\Attributes\\UsePolicy']);
            }
            foreach (['Illuminate\\Console\\Attributes\\Signature', 'Illuminate\\Console\\Attributes\\Aliases'] as $attribute) {
                if (isset($class['attributes'][$attribute])) {
                    $attributes[$attribute] = $this->value($class['attributes'][$attribute]);
                    if (str_ends_with($attribute, '\\Signature')) {
                        foreach ([0, 'signature'] as $key) {
                            if (isset($attributes[$attribute][$key])) {
                                $attributes[$attribute][$key] = $this->commandName($attributes[$attribute][$key]);
                            }
                        }
                    }
                }
            }
            $classes[] = ['name' => $class['name'], 'line' => $class['line'], 'offset' => $class['offset'], 'parents' => $class['parents'], 'traits' => $class['traits'],
                'properties' => $properties, 'methods' => $methods, 'attributes' => $attributes, 'abstract' => $class['abstract'], 'kind' => $class['kind'], 'adaptations' => $class['adaptations'] ?? false];
        }
        $operations = [];
        foreach ($raw['operations'] as $op) {
            if (! in_array($op['kind'], self::OPERATIONS, true)) {
                continue;
            }
            $arguments = match ($op['kind']) {
                'bus_map' => ['map' => $this->nameMap($op['args']['map'] ?? $op['args'][0] ?? null)],
                'listen' => ['events' => $this->value($op['args']['events'] ?? $op['args'][0] ?? null), 'listener' => $this->value($op['args']['listener'] ?? $op['args'][1] ?? null)],
                'subscribe' => ['subscriber' => $this->value($op['args']['subscriber'] ?? $op['args'][0] ?? null)],
                'observe' => ['classes' => $this->value($op['args']['classes'] ?? $op['args'][0] ?? null)],
                'model_listen', 'quiet' => [0 => $this->value($op['args']['callback'] ?? $op['args'][0] ?? null)],
                'event' => ['event' => $this->value($op['args']['event'] ?? $op['args'][0] ?? null)],
                'discovery' => ['discover' => $this->value($op['args']['discover'] ?? $op['args'][0] ?? true)],
                'authorization_candidate' => $this->authorizationArguments($op),
                'middleware_alias' => ['aliases' => $this->nameMap($op['args']['aliases'] ?? $op['args'][0] ?? null)],
                'middleware_registration' => ['callback' => $this->value($op['args']['callback'] ?? $op['args'][0] ?? null)],
                'auth_registration' => ['driver' => $this->value($op['args']['driver'] ?? $op['args']['name'] ?? $op['args'][0] ?? null), 'callback' => $this->frameworkCallback($op['args']['callback'] ?? $op['args'][1] ?? null)],
                'exception_control' => $op['method'] === 'dontreportwhen'
                    ? ['callback' => $this->frameworkCallback($op['args']['dontReportWhen'] ?? $op['args'][0] ?? null)]
                    : ['classes' => $this->value($op['args']['class'] ?? $op['args']['exceptions'] ?? $op['args'][0] ?? null)],
                'exception_activation', 'exception_registration' => ['callback' => $this->frameworkCallback($op['args']['callback'] ?? $op['args']['using'] ?? $op['args']['reportUsing'] ?? $op['args']['renderUsing'] ?? $op['args'][0] ?? null)],
                'middleware_group' => $this->middlewareGroupArguments($op),
                'middleware_controller' => ['middleware' => $this->value($op['args']['middleware'] ?? $op['args'][0] ?? null), 'options' => array_intersect_key((array) $this->nameMap($op['args']['options'] ?? $op['args'][1] ?? []), ['only' => true, 'except' => true])],
                'middleware_global' => $op['method'] === 'replace'
                    ? ['search' => $this->value($op['args']['search'] ?? $op['args'][0] ?? null), 'replace' => $this->value($op['args']['replace'] ?? $op['args'][1] ?? null)]
                    : [0 => $this->value($op['args']['middleware'] ?? $op['args'][0] ?? null)],
                'console_registration' => $op['method'] === 'withschedule'
                    ? ['callback' => $this->frameworkCallback($op['args']['callback'] ?? $op['args'][0] ?? null)]
                    : ($op['method'] === 'withcommands' ? ['commands' => $this->value($op['args']['commands'] ?? $op['args'][0] ?? null)] : ($op['method'] === 'withrouting' ? ['commands' => $this->value($op['args']['commands'] ?? $op['args'][3] ?? null), 'commands_null' => $op['console_commands_null'] ?? false, 'commands_declared' => array_key_exists('commands', $op['args']) || array_key_exists(3, $op['args'])] : [])),
                'console_closure' => ['signature' => $this->commandName($op['args']['signature'] ?? $op['args'][0] ?? null), 'callback' => $this->frameworkCallback($op['args']['callback'] ?? $op['args'][1] ?? null)],
                'console_candidate' => in_array($op['method'], ['call', 'queue', 'callsilent', 'callsilently', 'command'], true)
                    ? ['command' => $this->commandName($op['args']['command'] ?? $op['args'][0] ?? null)]
                    : [0 => $this->value($op['args'][0] ?? null)],
                'schedule_group' => ['callback' => $this->frameworkCallback($op['args']['callback'] ?? $op['args'][0] ?? null)],
                'schedule' => match ($op['method']) {
                    'command' => ['command' => $this->commandName($op['args']['command'] ?? $op['args'][0] ?? null)],
                    'job' => ['job' => $this->value($op['args']['job'] ?? $op['args'][0] ?? null), 'queue' => $this->value($op['args']['queue'] ?? $op['args'][1] ?? null), 'connection' => $this->value($op['args']['connection'] ?? $op['args'][2] ?? null)],
                    'call' => ['callback' => $this->scheduleCallback($op['args']['callback'] ?? $op['args'][0] ?? null)],
                    default => [],
                },
                default => [],
            };
            if ($op['kind'] === 'console_candidate' && is_string($arguments['command'] ?? null)) {
                // A similarly named SDK method may receive a prompt rather than a command.
                // Keep only a lookup digest until the current source command catalog matches it.
                $arguments['command_hash'] = hash('sha256', $arguments['command']);
                $arguments['command'] = null;
            }
            $operations[] = ['kind' => $op['kind'], 'from' => $op['from'], 'source' => $this->source($op['source']), 'conditions' => $op['conditions'],
                'owner' => $op['owner'], 'method' => $op['method'], 'receiver' => $this->value($op['receiver']), 'args' => $arguments,
                'helper' => $op['helper'] ?? null,
                'usage' => $op['usage'] ?? 'requires_check', 'caught' => $op['caught'] ?? [],
                'value' => $op['kind'] === 'dispatch' ? $this->value($op['value']) : null, 'options' => str_starts_with($op['kind'], 'schedule') ? $this->scheduleOptions($op['options'] ?? []) : ($op['kind'] === 'middleware_controller_options' ? array_intersect_key((array) $this->value($op['options']), ['only' => true, 'except' => true]) : ($op['kind'] === 'dispatch_options' ? $this->options($op['options']) : ($op['kind'] === 'console_closure_options' ? array_intersect_key($this->value($op['options']), ['aliases' => true]) : []))),
                'dispatch_site' => isset($op['dispatch_site']) ? $this->source($op['dispatch_site']) : null,
                'schedule_site' => isset($op['schedule_site']) ? $this->source($op['schedule_site']) : null,
                'registration_site' => isset($op['registration_site']) ? $this->source($op['registration_site']) : null];
            if ($op['kind'] === 'exception_registration') {
                $callback = $arguments['callback']['symbol'] ?? null;
                $operations[array_key_last($operations)]['callback_returns_false_candidate'] = is_string($callback) && in_array(false, $raw['returns'][$callback] ?? [], true);
            }
            if ($op['kind'] === 'auth_registration') {
                $callback = $arguments['callback']['symbol'] ?? null;
                $operations[array_key_last($operations)]['factory_returns'] = array_map(fn ($value) => is_array($value) && in_array($value['type'] ?? null, ['object', 'class'], true) ? $this->value($value) : null, $raw['returns'][$callback] ?? []);
            }
            if ($op['kind'] !== 'dispatch') {
                unset($operations[array_key_last($operations)]['value']);
            }
        }
        $owner = CatalogElement::identity($file->path, 'file', $file->path);
        $diagnostics = [];
        if ($raw['limited'] || $this->limited) {
            $diagnostics[] = new CatalogDiagnostic('execution_limit', 'Execution extraction reached its AST, descriptor or memory budget.', 1, $owner);
        }
        foreach ($raw['notices'] as $notice) {
            // Generic PHP callable bodies have their own catalog analyzer.
            if (! str_contains($notice['reason'], 'Standalone function') && ! str_contains($notice['reason'], 'Anonymous execution class')) {
                $diagnostics[] = new CatalogDiagnostic('execution_analysis', $notice['reason'], max(1, $notice['line']), $owner);
            }
        }
        $relations = $classes === [] && $operations === [] ? [] : [new CatalogRelation($owner, 'execution:'.$owner, 'execution-template', 1, 1, 'conditional', ['classes' => $classes, 'operations' => $operations])];

        return new CatalogFacts($file->path, relations: $relations, diagnostics: $diagnostics);
    }

    /** @param array<string, mixed> $source
     * @return array{line: int, offset: int}
     */
    private function source(array $source): array
    {
        return ['line' => max(1, $source['line']), 'offset' => max(0, $source['offset'])];
    }

    /** @phpstan-impure */
    private function value(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 8) {
            $this->limited = true;

            return null;
        }
        if (! is_array($value)) {
            return is_string($value) || is_int($value) || is_bool($value) || $value === null ? $value : null;
        }
        if (($value['type'] ?? null) === 'catalog-array') {
            $items = $value['items'];
            if (! array_is_list($items) && is_string($items['type'] ?? null)) {
                // A literal PHP array cannot impersonate an analyzer callable/object descriptor.
                return ['type' => 'literal-array'];
            }

            return array_map(fn ($item) => $this->value($item, $depth + 1), $items);
        }
        if (is_string($value['type'] ?? null)) {
            $type = $value['type'];
            $result = ['type' => $type];
            foreach (match ($type) {
                'object', 'class', 'builder' => ['class'],
                'callback' => ['symbol', 'guest', 'events', 'source'],
                'reference' => ['symbol', 'class', 'callable_form', 'function_names', 'first_class', 'creator_class'],
                'controller_middleware' => ['middleware', 'only', 'except', 'source'],
                'queued_callback' => ['value', 'helper'],
                'pending' => ['value'],
                'chain', 'batch' => ['jobs', 'contract_owner', 'first', 'source'],
                default => [],
            } as $key) {
                if (array_key_exists($key, $value)) {
                    $result[$key] = $key === 'source' ? $this->source($value[$key]) : $this->value($value[$key], $depth + 1);
                }
            }
            if (isset($value['options'])) {
                $result['options'] = $this->options($value['options'], $depth + 1);
            }

            return $result;
        }
        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = $this->value($item, $depth + 1);
        }

        return $result;
    }

    /** @phpstan-impure */
    private function nameMap(mixed $value): mixed
    {
        if (is_array($value) && ($value['type'] ?? null) === 'catalog-array') {
            $result = [];
            foreach ($value['items'] as $key => $item) {
                $result[$key] = $this->value($item);
            }

            return $result;
        }

        return $this->value($value);
    }

    /** @return list<string>|null */
    private function eventNames(mixed $value): ?array
    {
        $value = $this->nameMap($value);
        if (is_array($value) && count($value) > 128) {
            $this->limited = true;

            return null;
        }
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        foreach ($value as $name) {
            if (is_string($name) && strlen($name) > 128) {
                $this->limited = true;

                return null;
            }
            if (! is_string($name) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) !== 1) {
                return null;
            }
        }

        return $value;
    }

    private function middlewareReturn(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_match('/^[\\\\a-zA-Z_][\\\\a-zA-Z0-9_]*$/D', $value) === 1 ? $value : null;
        }
        if (! is_array($value)) {
            return null;
        }
        if (array_is_list($value)) {
            return array_map($this->middlewareReturn(...), $value);
        }

        return in_array($value['type'] ?? null, ['object', 'class', 'callback', 'reference', 'controller_middleware'], true) ? $value : null;
    }

    /** @return int|list<int>|null */
    private function backoff(mixed $value): int|array|null
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_array($value) && ($value['type'] ?? null) === 'catalog-array') {
            $value = $value['items'];
        }

        return is_array($value) && array_is_list($value) && count(array_filter($value, 'is_int')) === count($value) ? $value : null;
    }

    /** @param array<string, mixed> $op
     * @return array<string, mixed>
     */
    private function middlewareGroupArguments(array $op): array
    {
        $args = $op['args'];
        if (in_array($op['method'], ['web', 'api'], true)) {
            $result = ['group' => $op['method']];
            foreach (['append', 'prepend', 'remove', 'replace'] as $position => $key) {
                $result[$key] = $key === 'replace' ? $this->nameMap($args[$key] ?? $args[$position] ?? []) : $this->value($args[$key] ?? $args[$position] ?? []);
            }

            return $result;
        }

        return ['group' => $this->value($args['group'] ?? $args['name'] ?? $args[0] ?? null),
            'middleware' => $this->value($args['middleware'] ?? $args['search'] ?? $args[1] ?? null),
            'replace' => $this->value($args['replace'] ?? $args[2] ?? null)];
    }

    private function scheduleCallback(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*@[a-zA-Z_][a-zA-Z0-9_]*$/D', $value)) {
            return ['type' => 'reference', 'symbol' => str_replace('@', '::', ltrim($value, '\\')), 'callable_form' => 'container-string'];
        }

        return $this->frameworkCallback($value);
    }

    private function frameworkCallback(mixed $value): mixed
    {
        if (is_string($value) && strlen($value) <= 500 && preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*(?:::[a-zA-Z_][a-zA-Z0-9_]*)?$/D', $value)) {
            return ['type' => 'reference', 'symbol' => ltrim($value, '\\'), 'callable_form' => str_contains($value, '::') ? 'static-string' : 'function'];
        }
        if (is_array($value) && ($value['type'] ?? null) === 'catalog-array') {
            $items = $value['items'] ?? [];
            if (array_is_list($items) && count($items) === 2 && is_string($items[1]) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $items[1])) {
                $class = is_string($items[0]) ? $items[0] : (is_array($items[0]) && ($items[0]['type'] ?? null) === 'object' ? ($items[0]['class'] ?? null) : null);
                if (is_string($class)) {
                    return ['type' => 'reference', 'symbol' => $class.'::'.$items[1], 'callable_form' => is_string($items[0]) ? 'static-array' : 'object-array'];
                }
            }

            return null;
        }
        if (is_array($value) && ($value['type'] ?? null) === 'object' && is_string($value['class'] ?? null)) {
            return ['type' => 'reference', 'symbol' => $value['class'].'::__invoke', 'callable_form' => 'invokable'];
        }

        return is_array($value) && in_array($value['type'] ?? null, ['callback', 'reference'], true) ? $this->value($value) : null;
    }

    private function commandName(mixed $value): mixed
    {
        return is_string($value) ? preg_split('/\s+/', trim($value))[0] : $this->value($value);
    }

    /** @param array<string, mixed> $op
     * @return array<string|int, mixed>
     */
    private function authorizationArguments(array $op): array
    {
        $args = $op['args'];

        return match ($op['method']) {
            'policy' => ['class' => $this->value($args['class'] ?? $args[0] ?? null), 'policy' => $this->value($args['policy'] ?? $args[1] ?? null)],
            'define' => ['ability' => $this->value($args['ability'] ?? $args[0] ?? null), 'callback' => $this->frameworkCallback($args['callback'] ?? $args[1] ?? null)],
            'before', 'after', 'guesspolicynamesusing' => ['callback' => $this->frameworkCallback($args['callback'] ?? $args[0] ?? null)],
            'allowif', 'denyif' => ['condition' => is_bool($args['condition'] ?? $args[0] ?? null) ? ($args['condition'] ?? $args[0]) : $this->authorizationModels($args['condition'] ?? $args[0] ?? null)],
            'foruser' => [],
            'authorizeresource' => ['model' => $this->authorizationModels($args['model'] ?? $args[0] ?? null), 'parameter' => $this->value($args['parameter'] ?? $args[1] ?? null), 'options' => array_intersect_key((array) $this->nameMap($args['options'] ?? $args[2] ?? []), ['only' => true, 'except' => true])],
            default => ['ability' => $this->value($args['abilities'] ?? $args['ability'] ?? $args[$op['method'] === 'authorizeforuser' ? 1 : 0] ?? null),
                'arguments' => $this->authorizationModels($args['arguments'] ?? $args[$op['method'] === 'authorizeforuser' ? 2 : 1] ?? [])],
        };
    }

    /** Only model/callable descriptors and class selectors, never scalar authorization payloads. */
    private function authorizationModels(mixed $value): mixed
    {
        if (is_array($value) && ($value['type'] ?? null) === 'catalog-array') {
            return array_map(fn ($item) => $this->authorizationModels($item), $value['items']);
        }
        if (is_array($value) && in_array($value['type'] ?? null, ['object', 'class', 'reference', 'callback'], true)) {
            return $this->value($value);
        }
        if (is_array($value) && array_is_list($value)) {
            return array_map(fn ($item) => $this->authorizationModels($item), $value);
        }

        return is_string($value) && str_contains($value, '\\') && preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/D', $value) === 1 ? $value : null;
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function scheduleOptions(array $options): array
    {
        $result = [];
        foreach ($options as $name => $arguments) {
            if ($name === 'callbacks') {
                $result[$name] = array_map(fn ($callback) => ['method' => $callback['method'], 'value' => is_bool($callback['value']) ? $callback['value'] : $this->frameworkCallback($callback['value']), 'source' => $this->source($callback['source'])], $arguments);
            } elseif ($name === 'attribute_calls') {
                $result[$name] = array_map(fn ($call) => ['method' => $call['method'], 'args' => in_array($call['method'], ['name', 'description'], true) ? [0 => '(declared)'] : $this->value($call['args']), 'source' => $this->source($call['source'])], $arguments);
            } elseif (in_array($name, ['name', 'description'], true)) {
                $result[$name] = [0 => '(declared)'];
            } elseif (in_array($name, ['cron', 'timezone', 'withoutoverlapping', 'ononeserver', 'runinbackground', 'environments', 'eveninmaintenancemode', 'between', 'unlessbetween', 'days', 'weekdays', 'weekends', 'mondays', 'tuesdays', 'wednesdays', 'thursdays', 'fridays', 'saturdays', 'sundays', 'at', 'dailyat', 'weeklyon', 'monthlyon', 'twicemonthly', 'daysofmonth', 'quarterlyon', 'yearlyon', 'lastdayofmonth', 'hourlyat', 'everyminute', 'everytwominutes', 'everythreeminutes', 'everyfourminutes', 'everyfiveminutes', 'everytenminutes', 'everyfifteenminutes', 'everythirtyminutes', 'hourly', 'everyoddhour', 'everytwohours', 'everythreehours', 'everyfourhours', 'everysixhours', 'daily', 'twicedaily', 'twicedailyat', 'weekly', 'monthly', 'quarterly', 'yearly', 'everysecond', 'everytwoseconds', 'everyfiveseconds', 'everytenseconds', 'everyfifteenseconds', 'everytwentyseconds', 'everythirtyseconds'], true)) {
                $result[$name] = $this->value($arguments);
            } else {
                // Keep the unsupported operation visible without retaining its payload.
                $result[$name] = [null];
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    private function options(array $options, int $depth = 0): array
    {
        $result = [];
        foreach (['onqueue', 'onconnection', 'aftercommit', 'beforecommit', 'afterresponse', 'allowfailures', 'delay', 'chain', 'before', 'progress', 'then', 'catch', 'finally'] as $key) {
            if (array_key_exists($key, $options)) {
                $result[$key] = match ($key) {
                    'delay' => is_int($options[$key]) ? $options[$key] : null,
                    'onqueue', 'onconnection' => is_string($options[$key]) ? $options[$key] : null,
                    'aftercommit', 'beforecommit', 'afterresponse', 'allowfailures' => is_bool($options[$key]) ? $options[$key] : null,
                    default => $this->value($options[$key], $depth + 1),
                };
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $metadata */
    public static function validate(array $metadata): void
    {
        foreach (['classes', 'operations'] as $key) {
            if (! is_array($metadata[$key] ?? null) || ! array_is_list($metadata[$key])) {
                throw new InvalidArgumentException('Invalid execution template list.');
            }
        }
        foreach ($metadata['classes'] as $class) {
            if (! is_array($class) || ! is_string($class['name'] ?? null) || ! is_int($class['line'] ?? null) || ! is_int($class['offset'] ?? null)
                || ! is_bool($class['abstract'] ?? null) || ! is_bool($class['adaptations'] ?? null) || ! in_array($class['kind'] ?? null, ['class', 'interface', 'trait', 'enum'], true)) {
                throw new InvalidArgumentException('Invalid execution class.');
            }
            foreach (['parents', 'traits'] as $key) {
                if (! self::strings($class[$key] ?? null)) {
                    throw new InvalidArgumentException('Invalid execution ancestry.');
                }
            }
            foreach (['properties', 'methods', 'attributes'] as $key) {
                if (! is_array($class[$key] ?? null)) {
                    throw new InvalidArgumentException('Invalid execution class members.');
                }
            }
            foreach ($class['methods'] as $method) {
                if (! is_array($method) || ! is_string($method['name'] ?? null) || ! is_string($method['symbol'] ?? null)
                    || ! is_bool($method['public'] ?? null) || ! is_bool($method['abstract'] ?? null) || ! is_bool($method['conditional_return'] ?? null)
                    || ! is_array($method['middleware_candidates'] ?? null) || ! array_is_list($method['middleware_candidates'])
                    || ! self::strings($method['parameter'] ?? null) || ! is_array($method['parameters'] ?? null) || ! is_array($method['returns'] ?? null)) {
                    throw new InvalidArgumentException('Invalid execution method.');
                }
                foreach ($method['middleware_candidates'] as $candidate) {
                    self::validateMiddlewareCandidate($candidate);
                }
                self::validateSource($method['source'] ?? null);
            }
        }
        foreach ($metadata['operations'] as $op) {
            if (! is_array($op) || ! in_array($op['kind'] ?? null, self::OPERATIONS, true) || ! is_string($op['from'] ?? null)
                || ! is_string($op['method'] ?? null) || ! self::strings($op['conditions'] ?? null) || ! is_array($op['args'] ?? null) || ! is_array($op['options'] ?? null)
                || ! array_key_exists('owner', $op) || $op['owner'] !== null && ! is_string($op['owner'])
                || ! array_key_exists('helper', $op) || $op['helper'] !== null && ! is_string($op['helper'])) {
                throw new InvalidArgumentException('Invalid execution operation.');
            }
            if (! is_string($op['usage'] ?? null) || ! self::strings($op['caught'] ?? null)) {
                throw new InvalidArgumentException('Invalid authorization usage context.');
            }
            if ($op['kind'] === 'console_candidate' && (is_string($op['args']['command'] ?? null)
                || array_key_exists('command_hash', $op['args']) && (! is_string($op['args']['command_hash'])
                    || preg_match('/^[a-f0-9]{64}$/D', $op['args']['command_hash']) !== 1 || ($op['args']['command'] ?? null) !== null))) {
                throw new InvalidArgumentException('Invalid private console candidate selector.');
            }
            self::validateSource($op['source'] ?? null);
            self::validateCallableEvidence($op['args']);
            self::validateCallableEvidence($op['options']);
            if ($op['kind'] === 'console_registration' && $op['method'] === 'withrouting' && (! is_bool($op['args']['commands_declared'] ?? null) || ! is_bool($op['args']['commands_null'] ?? null))) {
                throw new InvalidArgumentException('Invalid console route file declaration evidence.');
            }
            if ($op['kind'] === 'exception_options') {
                if ($op['method'] !== 'stop' || ! is_array($op['registration_site'] ?? null)) {
                    throw new InvalidArgumentException('Invalid exception reporting option target.');
                }
                self::validateSource($op['registration_site']);
            }
            if (in_array($op['kind'], ['exception_activation', 'exception_registration', 'exception_control', 'auth_registration', 'console_closure', 'console_registration', 'schedule', 'schedule_group'], true)) {
                $callback = $op['args']['callback'] ?? null;
                if ($callback !== null) {
                    if (! is_array($callback) || ! in_array($callback['type'] ?? null, ['callback', 'reference'], true) || ! is_string($callback['symbol'] ?? null)) {
                        throw new InvalidArgumentException('Invalid exception callable descriptor.');
                    }
                    if (array_key_exists('first_class', $callback) && ! is_bool($callback['first_class'])) {
                        throw new InvalidArgumentException('Invalid first-class callable evidence.');
                    }
                    if (array_key_exists('creator_class', $callback) && ! is_string($callback['creator_class'])) {
                        throw new InvalidArgumentException('Invalid callable creator scope.');
                    }
                    if (array_key_exists('function_names', $callback) && ! self::strings($callback['function_names'])) {
                        throw new InvalidArgumentException('Invalid exception callable function candidates.');
                    }
                }
            }
            if ($op['kind'] === 'exception_registration' && ! is_bool($op['callback_returns_false_candidate'] ?? null)) {
                throw new InvalidArgumentException('Invalid exception callback return evidence.');
            }
            if ($op['kind'] === 'auth_registration') {
                if (! is_array($op['factory_returns'] ?? null) || ! array_is_list($op['factory_returns'])) {
                    throw new InvalidArgumentException('Invalid authentication factory results.');
                }
                foreach ($op['factory_returns'] as $value) {
                    if ($value !== null && (! is_array($value) || ! in_array($value['type'] ?? null, ['object', 'class'], true) || ! is_string($value['class'] ?? null))) {
                        throw new InvalidArgumentException('Invalid authentication factory result descriptor.');
                    }
                }
            }
            foreach (['dispatch_site', 'schedule_site', 'registration_site'] as $site) {
                if (($op[$site] ?? null) !== null) {
                    self::validateSource($op[$site]);
                }
            }
        }
    }

    private static function validateCallableEvidence(mixed $value, int $depth = 0): void
    {
        if ($depth > 16) {
            throw new InvalidArgumentException('Callable evidence nesting exceeds its budget.');
        }
        if (! is_array($value)) {
            return;
        }
        if (in_array($value['type'] ?? null, ['callback', 'reference'], true)) {
            if (! is_string($value['symbol'] ?? null)
                || array_key_exists('first_class', $value) && ! is_bool($value['first_class'])
                || array_key_exists('creator_class', $value) && ! is_string($value['creator_class'])
                || array_key_exists('callable_form', $value) && ! is_string($value['callable_form'])
                || array_key_exists('function_names', $value) && ! self::strings($value['function_names'])) {
                throw new InvalidArgumentException('Invalid callable evidence.');
            }
        }
        foreach ($value as $item) {
            self::validateCallableEvidence($item, $depth + 1);
        }
    }

    private static function validateMiddlewareCandidate(mixed $value, int $depth = 0): void
    {
        if ($depth > 8) {
            throw new InvalidArgumentException('Middleware candidate nesting exceeds its budget.');
        }
        if ($value === null || is_string($value)) {
            return;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('Invalid middleware return candidate.');
        }
        if (array_is_list($value)) {
            if (count($value) > 1000) {
                throw new InvalidArgumentException('Middleware candidate list exceeds its budget.');
            }
            foreach ($value as $item) {
                self::validateMiddlewareCandidate($item, $depth + 1);
            }

            return;
        }
        if (in_array($value['type'] ?? null, ['callback', 'reference'], true) && is_string($value['symbol'] ?? null)) {
            self::validateCallableEvidence($value);

            return;
        }
        if (($value['type'] ?? null) === 'controller_middleware' && array_key_exists('middleware', $value)) {
            self::validateMiddlewareCandidate($value['middleware'], $depth + 1);
            self::validateSource($value['source'] ?? null);
            foreach (['only', 'except'] as $key) {
                if (array_key_exists($key, $value)) {
                    CatalogFacts::validateValues($value[$key], $depth + 1);
                }
            }

            return;
        }

        throw new InvalidArgumentException('Invalid middleware return candidate descriptor.');
    }

    private static function strings(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && count(array_filter($value, 'is_string')) === count($value);
    }

    private static function validateSource(mixed $source): void
    {
        if (! is_array($source) || array_keys($source) !== ['line', 'offset'] || ! is_int($source['line']) || $source['line'] < 1 || ! is_int($source['offset']) || $source['offset'] < 0) {
            throw new InvalidArgumentException('Invalid execution source witness.');
        }
    }
}
