<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ExecutionLinks;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Reuses the execution channel over current cached local facts, without source discovery. */
final class CatalogExecutionResolver
{
    /** @var array<string, string> */
    private array $nodes = [];

    /** @var array<string, array<string, mixed>> */
    private array $classes = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $routeEvidence = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $controllerEvidence = [];

    private int $consoleCallableVisits = 0;

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    /** @param list<array<string, mixed>> $templates
     * @param  list<array<string, mixed>>  $routes
     */
    public function resolve(array $templates, array $routes = []): void
    {
        $facts = ['classes' => [], 'operations' => [], 'calls' => [], 'notices' => [], 'limited' => false];
        foreach ($templates as $template) {
            $data = $this->hydrate($template['metadata'], $template['path']);
            foreach ($data['classes'] as $class) {
                $class['path'] = $template['path'];
                $key = strtolower($class['name']);
                if (isset($facts['classes'][$key])) {
                    $class['ambiguous'] = true;
                    $this->notice($template['path'], $class['line'], 'execution_duplicate_class', 'Several source declarations define this execution class.');
                }
                $facts['classes'][$key] = $class;
            }
            foreach ($data['operations'] as $operation) {
                if (! is_string($operation['helper']) || ! $this->shadowed($operation['helper'])) {
                    $facts['operations'][] = $operation;
                }
            }
        }
        $this->classes = $facts['classes'];
        $commandNames = [];
        foreach ($facts['classes'] as $class) {
            foreach (['signature', 'name'] as $property) {
                $value = $class['properties'][$property] ?? null;
                if (is_string($value)) {
                    $commandNames[hash('sha256', $value)] = $value;
                }
            }
            $aliases = $class['properties']['aliases'] ?? [];
            foreach (is_array($aliases) ? $aliases : [] as $alias) {
                if (is_string($alias)) {
                    $commandNames[hash('sha256', $alias)] = $alias;
                }
            }
            foreach (['Illuminate\\Console\\Attributes\\Signature', 'Illuminate\\Console\\Attributes\\Aliases'] as $attribute) {
                foreach ($class['attributes'][$attribute] ?? [] as $value) {
                    if (is_string($value)) {
                        $commandNames[hash('sha256', $value)] = $value;
                    } elseif (is_array($value)) {
                        foreach ($value as $alias) {
                            if (is_string($alias)) {
                                $commandNames[hash('sha256', $alias)] = $alias;
                            }
                        }
                    }
                }
            }
        }
        foreach ($facts['operations'] as $operation) {
            if ($operation['kind'] === 'console_closure' && is_string($operation['args']['signature'] ?? null)) {
                $value = $operation['args']['signature'];
                $commandNames[hash('sha256', $value)] = $value;
            }
            if ($operation['kind'] === 'console_closure_options') {
                $aliases = $operation['options']['aliases'][0] ?? [];
                foreach (is_array($aliases) ? $aliases : [] as $alias) {
                    if (is_string($alias)) {
                        $commandNames[hash('sha256', $alias)] = $alias;
                    }
                }
            }
        }
        foreach ($facts['operations'] as &$operation) {
            if (isset($operation['args']['command_hash'])) {
                $operation['args']['command'] = $commandNames[$operation['args']['command_hash']] ?? null;
                unset($operation['args']['command_hash']);
            }
        }
        unset($operation);
        $links = new ExecutionLinks($facts, externalBoundaries: true, consoleFacts: function (array $input, ExecutionLinks $channel): array {
            foreach ($input['operations'] as &$operation) {
                if (in_array($operation['kind'], ['console_closure', 'schedule', 'schedule_options', 'schedule_group'], true) || $operation['kind'] === 'console_registration' && $operation['method'] === 'withschedule') {
                    $operation['args'] = $this->consoleReferences($operation['args'], $operation, $channel);
                    $operation['options'] = $this->consoleReferences($operation['options'], $operation, $channel);
                }
            }
            unset($operation);

            return $input;
        }, sourceMethods: $this->executionMethods(...));
        (new CatalogConsoleRegistrationResolver($this->index))->resolve($facts, $links);
        (new CatalogAuthRegistrationResolver($this->index, $this->calls))->resolve($facts, $links);
        (new CatalogExceptionResolver($this->index))->resolve($facts, $links);
        (new CatalogFormRequestResolver($this->index, $this->calls))->resolve($routes, $links);
        (new CatalogJsonResourceResolver($this->index, $this->calls))->resolve($links);
        (new CatalogMiddlewareResolver($this->index))->resolve($facts, $routes, $links);
        $routes = (new CatalogControllerMiddlewareResolver($this->index))->resolve($facts, $routes, $links);
        $routes = (new CatalogMiddlewareGroupsResolver($this->index))->resolve($facts, $routes, $links);
        $links->authorizationHttp($routes, $facts);
        foreach ($routes as $route) {
            $this->nodes['(http) '.$route['id']] = $route['id'];
            $this->controllerEvidence['(http) '.$route['id']] = $this->index->elements[$route['id']]['metadata']['controller_middleware_sources'] ?? [];
            $this->routeEvidence['(http) '.$route['id']] = $this->index->elements[$route['id']]['metadata']['middleware_group_sources'] ?? [];
        }
        foreach ($links->notices as $notice) {
            $this->notice($notice['path'], max(1, $notice['line']), 'execution_analysis', $notice['reason']);
        }
        if ($links->limited) {
            $this->notice('', 1, 'execution_composition_limit', 'Execution composition reached its traversal or memory budget.');
        }
        foreach ($links->seeds as $seed) {
            if (in_array($seed['kind'], ['console', 'schedule'], true)) {
                $kind = $seed['kind'] === 'console' ? 'console-command' : 'scheduled-task';
                $this->nodes[$seed['symbol']] = $this->resource($kind, $seed['command'] ?? $seed['symbol'], $seed['source'], $seed['symbol']);
            }
        }
        foreach ($links->authorizationChecks as $check) {
            $this->nodes[$check['symbol']] = $this->resource('authorization-check', is_string($check['ability']) ? $check['ability'] : $check['symbol'], $check['source'], $check['symbol']);
            $this->controllerEvidence[$check['symbol']] = $this->controllerEvidence[$check['from']] ?? [];
            $this->routeEvidence[$check['symbol']] = $this->routeEvidence[$check['from']] ?? [];
        }
        foreach ($links->registrations as $registration) {
            $target = $this->symbol($registration['target']);
            if ($target === null) {
                $this->notice($registration['source']['path'], $registration['source']['line'], 'execution_unresolved_target', 'Registered callable is ambiguous or absent from the PHP catalog.');

                continue;
            }
            $event = $this->resource('event', $registration['event'], $registration['source']);
            $this->edge($event, $target, 'event-registration', $registration['source'], ['basis' => $registration['basis'], 'mode' => $registration['mode'], 'timing' => $registration['timing'], 'conditions' => $registration['conditions'], 'declared_execution' => $this->declaredExecution($registration['target'])]);
            $this->role($target, 'listener', $registration['source']);
        }
        foreach ($links->out as $edges) {
            foreach ($edges as $edge) {
                if (str_starts_with($edge['to'], '(schedule) ')) {
                    $this->nodes[$edge['to']] = $this->resource('scheduled-task', $edge['to'], $edge, $edge['to']);
                } elseif (str_starts_with($edge['to'], '(event) ')) {
                    $this->nodes[$edge['to']] = $this->resource('event', $edge['event'] ?? $edge['to'], $edge, $edge['to']);
                } elseif (str_starts_with($edge['to'], '(group) ')) {
                    $kind = $edge['kind'] === 'batch-dispatch' ? 'job-batch' : 'job-chain';
                    $this->nodes[$edge['to']] = $this->resource($kind, $kind, $edge, $edge['to']);
                }
            }
        }
        $jobLifecycle = new CatalogJobLifecycleResolver($this->index);
        foreach ($links->out as $edges) {
            foreach ($edges as $edge) {
                $from = $this->nodes[$edge['from']] ?? $this->symbol($edge['from']);
                $to = $this->nodes[$edge['to']] ?? $this->symbol($edge['to']);
                if ($from === null || $to === null) {
                    $this->notice($edge['path'], $edge['line'], 'execution_unresolved_target', 'Execution endpoint is ambiguous or absent from the PHP catalog.');

                    continue;
                }
                $fileId = CatalogElement::identity($edge['path'], 'file', $edge['path']);
                $metadata = [];
                if (($this->index->elements[$fileId]['metadata']['console_file_registrations'] ?? []) !== []) {
                    $metadata['console_file_registrations'] = $this->index->elements[$fileId]['metadata']['console_file_registrations'];
                }
                foreach (['broadcast_object_verified', 'conditions', 'mode', 'timing', 'queue', 'connection', 'allow_failures', 'requires_events', 'reset_quiet', 'quiet', 'event', 'job', 'registration', 'command', 'schedule', 'stage', 'filter_index', 'authorization', 'denial_handling', 'polarity', 'operation', 'result', 'usage', 'caught', 'inline', 'ability', 'arguments'] as $key) {
                    if (array_key_exists($key, $edge)) {
                        $metadata[$key] = $edge[$key];
                    }
                }
                if (in_array($edge['kind'], ['job-handler', 'event-listener'], true)) {
                    $metadata['declared_execution'] = $this->declaredExecution($edge['job'] ?? $edge['to']);
                }
                if (($this->controllerEvidence[$edge['from']] ?? []) !== []) {
                    $metadata['controller_middleware_sources'] = $this->controllerEvidence[$edge['from']];
                }
                if (($this->routeEvidence[$edge['from']] ?? []) !== []) {
                    $metadata['middleware_group_sources'] = $this->routeEvidence[$edge['from']];
                }
                $this->edge($from, $to, $edge['kind'], $edge, $metadata);
                if ($edge['kind'] === 'console-handler') {
                    $this->role($to, 'command', $edge);
                }
                if (in_array($edge['kind'], ['authorization-policy', 'authorization-policy-before'], true)) {
                    $this->role($to, 'policy', $edge);
                }
                if ($edge['kind'] === 'job-handler') {
                    $jobLifecycle->resolve($from, $edge, $facts, $links);
                    $this->role($to, 'job', $edge);
                    if (($edge['mode'] ?? null) === 'queue-requested' && is_string($edge['queue'] ?? null)) {
                        $queue = $this->resource('queue', $edge['queue'], $edge, ($edge['connection'] ?? '(default)').':'.$edge['queue']);
                        $this->edge($from, $queue, 'dispatches-on-queue', $edge, ['connection' => $edge['connection'], 'execution_proven' => false]);
                    }
                }
            }
        }
    }

    /** @param array<string|int, mixed> $values
     * @param  array<string, mixed>  $op
     * @return array<string|int, mixed>
     */
    private function consoleReferences(array $values, array $op, ExecutionLinks $links, int $depth = 0): array
    {
        if ($depth > 12 || ++$this->consoleCallableVisits > 20000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->notice($op['source']['path'], $op['source']['line'], 'console_callable_limit', 'Console callback descriptors reached their depth, operation or memory limit.');

            return [];
        }
        if ($depth === 0 && in_array($op['kind'], ['console_closure', 'schedule_group'], true) && array_key_exists('callback', $values)) {
            $callback = $values['callback'];
            if ($callback !== null && ($callback['type'] ?? null) !== 'callback' && ! ($callback['first_class'] ?? false)) {
                $this->notice($op['source']['path'], $op['source']['line'], 'console_callable_contract', 'Console command/group registration requires a Closure callback.');
                $values['callback'] = null;
            }
        }
        if (in_array($values['method'] ?? null, ['before', 'after', 'then', 'onsuccess', 'onfailure', 'thenwithoutput', 'onsuccesswithoutput', 'onfailurewithoutput'], true)) {
            $callback = $values['value'] ?? null;
            if ($callback !== null && ($callback['type'] ?? null) !== 'callback' && ! ($callback['first_class'] ?? false)) {
                $this->notice($op['source']['path'], $op['source']['line'], 'console_callable_contract', 'Schedule lifecycle registration requires a Closure callback.');
                $values['value'] = null;
                $values['invalid_contract'] = true;
            }
        }
        foreach ($values as &$value) {
            if (! is_array($value)) {
                continue;
            }
            if (($value['type'] ?? null) === 'reference' && is_string($value['symbol'] ?? null) && ! str_contains($value['symbol'], '::') && ! isset($value['function_names'])) {
                $functionIds = array_values(array_filter($this->index->names[strtolower($value['symbol'])] ?? [], fn ($id) => $this->index->elements[$id]['kind'] === 'function'));
                if ($functionIds !== []) {
                    $value['function_names'] = [$value['symbol']];
                } elseif ($op['kind'] === 'schedule' && $op['method'] === 'call' && count($this->index->namedTypes($value['symbol'])) === 1) {
                    $value['symbol'] .= '::__invoke';
                    $value['callable_form'] = 'invokable';
                } else {
                    $this->notice($op['source']['path'], $op['source']['line'], 'console_callable_unresolved', 'Console callable function or invokable class source is absent or ambiguous.');
                    $value = null;

                    continue;
                }
            }
            if (($value['type'] ?? null) === 'reference' && isset($value['function_names'])) {
                $targets = [];
                foreach ($value['function_names'] as $name) {
                    $targets = array_values(array_filter($this->index->names[strtolower($name)] ?? [], fn ($id) => $this->index->elements[$id]['kind'] === 'function'));
                    if ($targets !== []) {
                        break;
                    }
                }
                if (count($targets) === 1) {
                    $value['symbol'] = $this->index->elements[$targets[0]]['name'];
                } else {
                    $this->notice($op['source']['path'], $op['source']['line'], 'console_callable_unresolved', 'Console callback function source is absent or ambiguous.');
                    $value = null;
                }
            } elseif (($value['type'] ?? null) === 'reference' && is_string($value['symbol'] ?? null) && str_contains($value['symbol'], '::')) {
                [$owner, $name] = explode('::', $value['symbol'], 2);
                $failed = false;
                $method = $this->consoleMethod($owner, strtolower($name), $op, $failed);
                $targets = $method === null ? [] : ($this->index->names[strtolower($method['symbol'])] ?? []);
                $metadata = $method ?? [];
                $creator = $value['creator_class'] ?? (str_contains($op['from'], '::') ? explode('::', $op['from'], 2)[0] : '');
                $declaring = $method['scope'] ?? '';
                $visibility = $metadata['visibility'] ?? null;
                $access = $visibility === 'public' || ($value['first_class'] ?? false) && ($visibility === 'private' && strcasecmp($creator, $declaring) === 0
                    || $visibility === 'protected' && $links->inherits($creator, $declaring));
                if (count($targets) !== 1 || ($metadata['abstract'] ?? true) || ! $access
                    || in_array($value['callable_form'] ?? null, ['static-first-class', 'static-string', 'static-array'], true) && ! ($metadata['static'] ?? false)) {
                    $this->notice($op['source']['path'], $op['source']['line'], 'console_callable_unresolved', 'Console callback method source or callable access is unresolved.');
                    $value = null;
                } else {
                    $value['symbol'] = $method['symbol'];
                }
            } else {
                $value = $this->consoleReferences($value, $op, $links, $depth + 1);
            }
        }
        unset($value);

        return $values;
    }

    /** @return list<array<string, mixed>> */
    private function executionMethods(string $owner, ?string $name): array
    {
        $names = $this->executionMethodNames($owner);
        if ($name !== null) {
            $names = in_array($name, $names, true) ? [$name] : [];
        }
        $class = $this->classes[strtolower($owner)] ?? null;
        if ($class === null) {
            return [];
        }
        $op = ['source' => ['path' => $class['path'], 'line' => $class['line']]];
        $methods = [];
        foreach ($names as $candidate) {
            $failed = false;
            $method = $this->consoleMethod($owner, $candidate, $op, $failed, executionSelection: true);
            if ($failed) {
                $this->notice($class['path'], $class['line'], 'execution_method_unresolved', 'Source execution method selection is ambiguous or incomplete: '.$candidate);
            } elseif ($method !== null) {
                [$declaring, $declaredName] = explode('::', $method['symbol'], 2);
                $declaration = $this->classes[strtolower($declaring)]['methods'][strtolower($declaredName)] ?? null;
                $methods[] = [...$method, 'name' => $candidate, 'public' => ($method['visibility'] ?? null) === 'public',
                    'parameters' => $declaration['parameters'] ?? []];
            }
        }

        return $methods;
    }

    /** @param list<string> $seen
     * @return list<string>
     */
    private function executionMethodNames(string $owner, array $seen = []): array
    {
        $key = strtolower($owner);
        $class = $this->classes[$key] ?? null;
        if ($class === null) {
            return [];
        }
        if (in_array($key, $seen, true) || count($seen) >= 32) {
            $this->notice($class['path'], $class['line'], 'execution_method_limit', 'Source execution method inventory reached a cycle or depth limit.');

            return [];
        }
        $names = array_keys($class['methods']);
        foreach ([...$class['traits'], ...$class['parents']] as $parent) {
            array_push($names, ...$this->executionMethodNames($parent, [...$seen, $key]));
        }
        foreach ($this->index->namedTypes($owner) as $id) {
            foreach ($this->index->elements[$id]['metadata']['trait_rules'] ?? [] as $rule) {
                if (is_string($rule['alias'])) {
                    $names[] = $rule['alias'];
                }
            }
        }
        if (count($names) > 1000) {
            $this->notice($class['path'], $class['line'], 'execution_method_limit', 'Source execution method inventory reached its name budget.');
        }

        return array_slice(array_values(array_unique($names)), 0, 1000);
    }

    /** Select a concrete source body while retaining the visibility of a trait alias.
     * @param  array<string, mixed>  $op
     * @param  list<string>  $seen
     * @return array<string, mixed>|null
     */
    private function consoleMethod(string $owner, string $name, array $op, bool &$failed, array $seen = [], bool $executionSelection = false): ?array
    {
        $key = strtolower($owner);
        if (in_array($key, $seen, true) || count($seen) >= 32 || ++$this->consoleCallableVisits > 20000 || ImpactExtractor::sourceLimit(0) !== null) {
            $failed = true;
            $this->notice($op['source']['path'], $op['source']['line'], 'console_callable_limit', 'Console method selection reached a cycle, depth, operation or memory limit.');

            return null;
        }
        $ids = $this->index->namedTypes($owner);
        $class = $this->classes[$key] ?? null;
        if (count($ids) !== 1 || $class === null || ($class['ambiguous'] ?? false)) {
            $failed = true;

            return null;
        }
        if (isset($class['methods'][$name])) {
            $method = $class['methods'][$name];
            $members = $this->index->names[strtolower($method['symbol'])] ?? [];
            if (count($members) !== 1) {
                $failed = true;

                return null;
            }

            return [...$method, ...$this->index->elements[$members[0]]['metadata'], 'scope' => $owner];
        }
        $traits = [];
        foreach ($class['traits'] as $trait) {
            $traitIds = $this->index->namedTypes($trait);
            // Laravel 12/13 queue traits do not supply these execution hooks.
            // Source shadows remain ordinary traits and still participate in selection.
            if ($traitIds === [] && $executionSelection
                && in_array($trait, ['Illuminate\\Foundation\\Bus\\Dispatchable', 'Illuminate\\Foundation\\Queue\\Queueable', 'Illuminate\\Bus\\Queueable'], true)
                && in_array($name, ['handle', '__invoke', 'failed', 'middleware', 'subscribe', 'getobservableevents', 'shouldqueue', 'shoulddiscoverevents', 'shouldbediscovered'], true)) {
                continue;
            }
            if (count($traitIds) !== 1) {
                $failed = true;

                return null;
            }
            $traits[] = $traitIds[0];
        }
        $selection = CatalogTraitSelection::candidates($this->index, $ids[0], $name, $traits);
        if ($selection === null) {
            $failed = true;

            return null;
        }
        $candidates = [];
        foreach ($selection as [$trait, $traitName]) {
            $traitOwner = $this->index->elements[$trait]['name'];
            $method = $this->consoleMethod($traitOwner, $traitName, $op, $failed, [...$seen, $key], $executionSelection);
            if ($method !== null) {
                foreach ($this->index->elements[$ids[0]]['metadata']['trait_rules'] ?? [] as $rule) {
                    if ($rule['method'] === $traitName && ($rule['alias'] ?? $traitName) === $name
                        && ($rule['trait'] === null || strcasecmp($rule['trait'], $traitOwner) === 0) && $rule['visibility'] !== null) {
                        $method['visibility'] = $rule['visibility'];
                    }
                }
                $method['scope'] = $owner;
                $candidates[$method['symbol']] = $method;
            }
        }
        if ($failed || count($candidates) > 1) {
            $failed = true;

            return null;
        }
        if ($candidates !== []) {
            return array_values($candidates)[0];
        }
        foreach ($class['parents'] as $parent) {
            // Framework declarations outside the source graph cannot provide a callback body.
            if ($this->index->namedTypes($parent) === []) {
                continue;
            }
            $method = $this->consoleMethod($parent, $name, $op, $failed, [...$seen, $key], $executionSelection);
            if ($failed || $method !== null) {
                return $failed ? null : $method;
            }
        }

        return null;
    }

    private function symbol(string $symbol): ?string
    {
        if (str_starts_with($symbol, '(callback) ')) {
            $symbol = '(closure) '.substr($symbol, 11);
        }
        if (str_starts_with($symbol, '(file) ')) {
            $path = substr($symbol, 7);
            $id = CatalogElement::identity($path, 'file', $path);

            return isset($this->index->elements[$id]) ? $id : null;
        }
        $ids = array_values(array_filter($this->index->names[strtolower($symbol)] ?? [], fn ($id) => in_array($this->index->elements[$id]['kind'], ['method', 'function', 'closure', 'class', 'interface', 'trait', 'enum'], true)));

        return count($ids) === 1 ? $ids[0] : null;
    }

    /** @return array<string, mixed> */
    private function declaredExecution(string $symbol): array
    {
        $name = strtolower(explode('::', $symbol)[0]);
        $result = [];
        $seen = [];
        while (isset($this->classes[$name]) && ! isset($seen[$name]) && count($seen) < 32) {
            $seen[$name] = true;
            $class = $this->classes[$name];
            if ($class['ambiguous'] ?? false) {
                break;
            }
            foreach (['queue', 'connection', 'afterCommit', 'tries', 'timeout', 'backoff', 'maxExceptions', 'failOnTimeout'] as $key) {
                if (! array_key_exists($key, $result) && array_key_exists($key, $class['properties'])) {
                    $result[$key] = ['value' => $class['properties'][$key], 'source' => ['path' => $class['path'], 'line' => $class['line'], 'offset' => $class['offset']], 'basis' => 'class-property'];
                }
            }
            // The source extractor lists the superclass first, followed by interfaces.
            $name = strtolower($class['parents'][0] ?? '');
        }

        return $result;
    }

    /** @param array<string, mixed> $source */
    private function resource(string $kind, string $name, array $source, ?string $occurrence = null): string
    {
        $id = $occurrence === null ? CatalogElement::resourceIdentity($kind, $name) : CatalogElement::identity($source['path'], $kind, $occurrence, $source['offset']);
        if (! isset($this->index->elements[$id])) {
            $element = new CatalogElement($id, $name, $kind, max(1, $source['line']), max(1, $source['line']), max(0, $source['offset']), metadata: ['logical_resource' => true, 'execution_proven' => false]);
            $this->index->elements[$id] = [...$element->toArray(), 'path' => $source['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => []];
            $this->index->names[strtolower($name)][] = $id;
        }
        $this->index->elements[$id]['sources'][] = ['path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['line']];

        return $id;
    }

    /** @param array<string, mixed> $source
     * @param  array<string, mixed>  $metadata
     */
    private function edge(string $from, string $to, string $kind, array $source, array $metadata): void
    {
        $this->index->addRelation(['from' => $from, 'to' => $to, 'kind' => $kind, 'line' => max(1, $source['line']), 'end_line' => max(1, $source['line']),
            'resolution' => 'conditional', 'metadata' => [...$metadata, 'execution_proven' => false], 'path' => $source['path'], 'knowledge' => 'static']);
    }

    /** @param array<string, mixed> $source */
    private function role(string $method, string $role, array $source): void
    {
        $id = $this->index->elements[$method]['parent'] ?? null;
        if ($id !== null && isset($this->index->elements[$id]) && $this->index->elements[$id]['kind'] === 'class') {
            $this->index->elements[$id]['roles'] = array_values(array_unique([...$this->index->elements[$id]['roles'], $role]));
            $this->index->elements[$id]['role_evidence'][] = ['role' => $role, 'basis' => 'framework-registration', 'path' => $source['path'], 'line' => $source['line']];
        }
    }

    private function hydrate(mixed $value, string $path): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (($value['type'] ?? null) === 'queued_callback' && is_string($value['helper'] ?? null) && $this->shadowed($value['helper'])) {
            return null;
        }
        if (array_keys($value) === ['line', 'offset']) {
            return [...$value, 'path' => $path];
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->hydrate($item, $path);
        }

        return $value;
    }

    private function shadowed(string $helper): bool
    {
        return array_filter($this->index->names[strtolower($helper)] ?? [], fn ($id) => $this->index->elements[$id]['kind'] === 'function') !== [];
    }

    private function notice(string $path, int $line, string $code, string $message): void
    {
        $this->index->diagnostics[] = ['code' => $code, 'message' => $message, 'path' => $path, 'line' => $line, 'subject' => null];
    }
}
