<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use Illuminate\Support\Str;

/** Source witnesses with contextual execution conditions, separate from call graph verdicts. */
final class ExecutionLinks
{
    use AuthorizationLinks;
    use ConsoleExecutionLinks;

    /** @var array<string, mixed> */
    private array $classes;

    /** @var array<string, list<array<string, mixed>>> */
    public array $out = [];

    /** @var list<array<string, mixed>> */
    public array $notices = [];

    /** @var list<array<string, mixed>> */
    public array $registrations = [];

    /** @var array<string, array<string, mixed>> */
    public array $seeds = [];

    /** @var array<string, list<array<string, mixed>>> */
    public array $unknown = [];

    public bool $limited = false;

    private int $visits = 0;

    /** @var array<string, mixed> */
    private array $handlers = [];

    private bool $unknownHandlers = false;

    /** @param array<string, mixed> $facts
     * @param  (\Closure(array<string, mixed>, self): array<string, mixed>)|null  $consoleFacts
     * @param  (\Closure(string, ?string): list<array<string, mixed>>)|null  $sourceMethods
     */
    public function __construct(array $facts, private bool $externalBoundaries = false, ?\Closure $consoleFacts = null, private ?\Closure $sourceMethods = null)
    {
        $this->classes = $facts['classes'];
        $this->notices = $facts['notices'];
        $this->limited = $facts['limited'];
        if ($externalBoundaries) {
            foreach ($this->classes as $class) {
                if (($class['adaptations'] ?? false) && $sourceMethods === null) {
                    $this->notices[] = ['path' => $class['path'], 'line' => $class['line'], 'reason' => 'Trait adaptations are unresolved in execution paths: '.$class['name']];
                }
            }
        }
        foreach ($facts['operations'] as $op) {
            if (! $this->room()) {
                break;
            }
            if ($op['kind'] === 'bus_map') {
                $mapping = $op['args']['map'] ?? $op['args'][0] ?? null;
                if (is_array($mapping)) {
                    foreach ($mapping as $command => $handler) {
                        if (is_string($command)) {
                            $this->handlers[strtolower($command)] = $handler;
                        }
                    }
                } else {
                    $this->unknownHandlers = true;
                    $this->notice($op, 'Bus command handler mapping is unresolved.');
                }
            } elseif ($op['kind'] === 'listen') {
                $events = $op['args']['events'] ?? $op['args'][0] ?? null;
                $listener = $op['args']['listener'] ?? $op['args'][1] ?? null;
                if ($listener === null && is_array($events) && in_array($events['type'] ?? '', ['callback', 'queued_callback'], true)) {
                    $listener = $events;
                    $events = $events['events'] ?? $events['value']['events'] ?? null;
                }
                $this->register($events, $listener, $op, 'explicit');
            } elseif ($op['kind'] === 'subscribe') {
                $this->subscriber($op['args']['subscriber'] ?? $op['args'][0] ?? null, $op);
            } elseif ($op['kind'] === 'observe' && $this->isModel($op['owner'])) {
                $this->observer($op['owner'], $op['args']['classes'] ?? $op['args'][0] ?? null, $op);
            } elseif ($op['kind'] === 'model_listen' && $this->isModel($op['owner'])) {
                $this->register('eloquent.'.$op['method'].': '.$op['owner'], $op['args'][0], $op, 'model_callback');
            }
        }
        $this->classRegistrations($facts['operations']);
        $modeledModelSites = [];
        if ($externalBoundaries) {
            foreach ($facts['operations'] as $op) {
                if (in_array($op['kind'], ['model_operation', 'model_listen', 'observe', 'quiet'], true) && $this->isModel($op['owner'] ?? null)) {
                    $modeledModelSites[$op['source']['path'].':'.$op['source']['offset']] = true;
                }
            }
        }
        foreach ($facts['calls'] as $call) {
            if (! $this->room()) {
                break;
            }
            $target = $call['receiver'] === null ? null : $this->method($call['receiver'], $call['method']);
            if ($target !== null) {
                $this->edge($call['from'], $target['symbol'], 'call', $call['source'], $call['conditions']);
                if ($target['abstract']) {
                    foreach ($this->classes as $candidate) {
                        if (! $this->room()) {
                            break;
                        }
                        if (! $candidate['abstract'] && $candidate['kind'] === 'class' && $this->inherits($candidate['name'], $call['receiver'])) {
                            $implementation = $this->method($candidate['name'], $call['method']);
                            if ($implementation !== null && ! $implementation['abstract']) {
                                $this->edge($call['from'], $implementation['symbol'], 'call', $call['source'], [...$call['conditions'], 'Possible implementation of contract receiver.']);
                            }
                        }
                    }
                }
            } elseif ($call['method'] === '__construct' && isset($this->classes[strtolower($call['receiver'] ?? '')])) {
                $this->edge($call['from'], $call['receiver'], 'new', $call['source'], $call['conditions']);
            } elseif ($call['receiver'] !== null && $this->frameworkCall($call['receiver'], $call['method'])) {
                // External Laravel trait methods are represented by semantic edges.
            } elseif ($call['receiver'] === null || isset($this->classes[strtolower($call['receiver'])]) && ! $this->isModel($call['receiver'])) {
                $this->unknown[strtolower($call['from'])][] = [...$call['source'], 'reason' => 'Execution call receiver/method is unresolved: '.($call['receiver'] ?? '(unknown)').'::'.$call['method']];
            } elseif ($externalBoundaries && ! isset($this->classes[strtolower($call['receiver'])])) {
                $this->edge($call['from'], $call['receiver'].'::'.$call['method'], 'external-call', $call['source'], [...$call['conditions'], 'Receiver source is outside the analyzed project; declaration and runtime dispatch are unverified.'], ['external' => true]);
            } elseif ($externalBoundaries && ! isset($modeledModelSites[$call['source']['path'].':'.$call['source']['offset']])) {
                $this->unknown[strtolower($call['from'])][] = [...$call['source'], 'reason' => 'Execution model receiver/method is unresolved: '.$call['receiver'].'::'.$call['method']];
            }
        }
        $this->consoleLinks($consoleFacts === null ? $facts : $consoleFacts($facts, $this));
        $this->authorizationLinks($facts);
        $configured = [];
        foreach ($facts['operations'] as $op) {
            if ($op['kind'] === 'dispatch_options') {
                $configured[json_encode($op['dispatch_site'], JSON_THROW_ON_ERROR)] = $op['options'];
            }
        }
        foreach ($facts['operations'] as $op) {
            if (! $this->room()) {
                break;
            }
            if ($op['kind'] === 'alias') {
                $target = $this->callable($op['value']);
                if ($target !== null) {
                    $this->edge($op['from'], $target, 'callback', $op['source'], []);
                }
            } elseif ($op['kind'] === 'quiet' && $this->isModel($op['owner'])) {
                $target = $this->callable($op['args']['callback'] ?? $op['args'][0] ?? null);
                if ($target === null) {
                    $this->notice($op, 'withoutEvents callback is unresolved.');
                } else {
                    $this->edge($op['from'], $target, 'without-events', $op['source'], $op['conditions'], ['quiet' => true]);
                }
            } elseif ($op['kind'] === 'dispatch') {
                if ($this->isEventClass($op['owner'] ?? '')) {
                    $this->event($op['owner'], $op);
                } elseif ($this->isJobDispatch($op)) {
                    $this->seed($op, 'job-dispatch');
                    $options = $this->dispatchOptions($op);
                    $options['options'] = $configured[json_encode($op['source'], JSON_THROW_ON_ERROR)] ?? [];
                    $value = $op['value'];
                    if ($op['method'] === 'bulk' && is_array($value) && array_is_list($value)) {
                        foreach ($value as $job) {
                            $bulkOptions = $options;
                            $bulkOptions['conditions'][] = 'Independent bulk dispatch; no chain order or batch lifecycle.';
                            $this->jobs($job, $op['from'], $op, $bulkOptions);
                        }

                        continue;
                    }
                    if (isset($options['options']['chain'])) {
                        $value = ['type' => 'chain', 'jobs' => [$value, ...$this->list($options['options']['chain'])], 'options' => $options['options']];
                    }
                    $this->jobs($value, $op['from'], $op, $options);
                } elseif (isset($op['receiver']['contract_owner'])) {
                    $this->notice($op, 'Return type of custom withChain is unresolved; downstream dispatch receiver is not proved.');
                }
            } elseif ($op['kind'] === 'event') {
                $value = $op['args']['event'] ?? $op['args'][0] ?? null;
                $event = is_string($value) ? $value : (is_array($value) ? ($value['class'] ?? null) : null);
                if ($event === null) {
                    $this->notice($op, 'Dispatched event name/type is unresolved.');
                } else {
                    $this->event($event, $op);
                }
            } elseif ($op['kind'] === 'model_operation' && $this->isModel($op['owner'])) {
                $this->modelOperation($op);
            }
        }
        if ($externalBoundaries) {
            foreach ($this->out as $key => $edges) {
                $semantic = [];
                foreach ($edges as $edge) {
                    if (! in_array($edge['kind'], ['call', 'new', 'callback', 'external-call'], true)) {
                        $semantic[$edge['path'].':'.$edge['offset']] = true;
                    }
                }
                $this->out[$key] = array_values(array_filter($edges, fn ($edge) => ! ($edge['external'] ?? false) || ! isset($semantic[$edge['path'].':'.$edge['offset']])));
            }
        }
    }

    /** @param array<string, mixed> $op */
    private function isJobDispatch(array $op): bool
    {
        $owner = $op['owner'];
        $contractOwner = $op['receiver']['contract_owner'] ?? null;
        if ($contractOwner !== null && ! $this->inherits($contractOwner, 'Illuminate\Foundation\Bus\Dispatchable') && ! $this->inherits($contractOwner, 'Illuminate\Foundation\Queue\Queueable')) {
            return false;
        }

        return $owner === null || in_array($owner, ['Illuminate\Support\Facades\Bus', 'Illuminate\Bus\Dispatcher', 'Illuminate\Contracts\Bus\Dispatcher', 'Illuminate\Contracts\Bus\QueueingDispatcher'], true) || in_array($op['receiver']['type'] ?? '', ['batch', 'chain', 'pending'], true) || $this->inherits($owner, 'Illuminate\Foundation\Bus\Dispatchable') || $this->inherits($owner, 'Illuminate\Foundation\Queue\Queueable');
    }

    private function frameworkCall(string $owner, string $method): bool
    {
        $method = strtolower($method);

        if ($this->authorizationGate($owner) && in_array($method, ['authorize', 'allows', 'denies', 'check', 'inspect', 'any', 'none', 'allowif', 'denyif', 'foruser', 'policy', 'define', 'before', 'after', 'guesspolicynamesusing'], true) || $this->inherits($owner, 'Illuminate\Foundation\Auth\Access\AuthorizesRequests') && in_array($method, ['authorize', 'authorizeforuser', 'authorizeresource'], true) || ($this->inherits($owner, 'Illuminate\Foundation\Auth\Access\Authorizable') || $this->inherits($owner, 'Illuminate\Foundation\Auth\User') || $this->inherits($owner, 'Illuminate\Contracts\Auth\Access\Authorizable')) && in_array($method, ['can', 'cannot', 'cant', 'canany'], true)) {
            return true;
        }

        return in_array($method, ['dispatch', 'dispatchsync', 'dispatchnow', 'dispatchafterresponse', 'dispatchif', 'dispatchunless', 'withchain'], true)
            && ($this->inherits($owner, 'Illuminate\Foundation\Bus\Dispatchable') || $this->inherits($owner, 'Illuminate\Foundation\Queue\Queueable') || $this->isEventClass($owner));
    }

    private function isEventClass(string $class): bool
    {
        return $this->inherits($class, 'Illuminate\Foundation\Events\Dispatchable');
    }

    private function isModel(?string $class): bool
    {
        return $class !== null && $this->inherits($class, 'Illuminate\Database\Eloquent\Model');
    }

    /** @param array<string, bool> $seen */
    public function inherits(string $name, string $target, array $seen = []): bool
    {
        if ($name === 'Illuminate\Contracts\Queue\ShouldQueueAfterCommit' && $target === 'Illuminate\Contracts\Queue\ShouldQueue') {
            return true;
        }
        if (strcasecmp($name, $target) === 0) {
            return true;
        }
        $key = strtolower($name);
        if (isset($seen[$key]) || count($seen) >= 32) {
            return false;
        }
        $seen[$key] = true;
        $class = $this->classes[$key] ?? null;
        foreach ([...($class['parents'] ?? []), ...($class['traits'] ?? [])] as $ancestor) {
            if ($this->inherits($ancestor, $target, $seen)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, bool> $seen
     * @return array<string, mixed>|null */
    public function method(string $name, string $method, array $seen = []): ?array
    {
        if ($this->externalBoundaries && $this->sourceMethods !== null) {
            return ($this->sourceMethods)($name, strtolower($method))[0] ?? null;
        }
        $key = strtolower($name);
        if (isset($seen[$key]) || count($seen) >= 32) {
            return null;
        }
        $seen[$key] = true;
        $class = $this->classes[$key] ?? null;
        if ($class['ambiguous'] ?? false) {
            return null;
        }
        if (isset($class['methods'][strtolower($method)])) {
            return $class['methods'][strtolower($method)];
        }
        if ($this->externalBoundaries && ($class['adaptations'] ?? false)) {
            return null;
        }
        foreach ([...($class['traits'] ?? []), ...($class['parents'] ?? [])] as $ancestor) {
            $found = $this->method($ancestor, $method, $seen);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<string, bool> $seen
     * @return list<array<string, mixed>> */
    public function methods(string $name, array $seen = []): array
    {
        if ($this->externalBoundaries && $this->sourceMethods !== null) {
            return ($this->sourceMethods)($name, null);
        }
        $key = strtolower($name);
        if (isset($seen[$key]) || count($seen) >= 32) {
            return [];
        }
        $seen[$key] = true;
        $class = $this->classes[$key] ?? null;
        $methods = $class['methods'] ?? [];
        if ($this->externalBoundaries && ($class['adaptations'] ?? false)) {
            return array_values($methods);
        }
        foreach ([...($class['traits'] ?? []), ...($class['parents'] ?? [])] as $ancestor) {
            foreach ($this->methods($ancestor, $seen) as $method) {
                $methods[strtolower($method['name'])] ??= $method;
            }
        }

        return array_values($methods);
    }

    /** @param list<array<string, mixed>> $operations */
    private function classRegistrations(array $operations): void
    {
        $discovery = array_filter($operations, fn ($op) => $op['kind'] === 'application') !== [] ? ['app/Listeners'] : [];
        foreach ($operations as $op) {
            if ($op['kind'] === 'discovery') {
                $value = $op['args']['discover'] ?? $op['args'][0] ?? true;
                if ($value === false) {
                    $discovery = [];
                } elseif (is_array($value)) {
                    $discovery = array_values(array_filter($value, 'is_string'));
                } elseif ($value !== true) {
                    $this->notice($op, 'Event discovery configuration is unresolved.');
                }
            }
        }
        foreach ($this->classes as $class) {
            if (! $this->room()) {
                break;
            }
            $op = ['source' => ['path' => $class['path'], 'line' => $class['line'], 'offset' => 0], 'conditions' => ['Source registration does not prove provider activation.'], 'from' => $class['name']];
            if ($this->inherits($class['name'], 'Illuminate\Foundation\Support\Providers\EventServiceProvider')) {
                foreach ($class['properties']['listen'] ?? [] as $event => $listeners) {
                    foreach ($this->list($listeners) as $listener) {
                        $this->register($event, $listener, $op, 'provider_listen');
                    }
                }
                foreach ($this->list($class['properties']['subscribe'] ?? []) as $subscriber) {
                    $this->subscriber($subscriber, $op);
                }
                foreach ($class['properties']['observers'] ?? [] as $model => $observers) {
                    $this->observer($model, $observers, $op);
                }
                $override = $this->method($class['name'], 'shouldDiscoverEvents');
                if (($override['returns'][0] ?? null) === true && ! $override['conditional_return']) {
                    $paths = $this->method($class['name'], 'discoverEventsWithin')['returns'][0] ?? ['app/Listeners'];
                    array_push($discovery, ...array_filter((array) $paths, 'is_string'));
                } elseif ($override !== null && ($override['returns'][0] ?? null) !== false) {
                    $this->notice($op, 'Provider event discovery condition is unresolved.');
                }
            }
            if ($this->isModel($class['name'])) {
                foreach ($this->attributes($class['name'])['Illuminate\Database\Eloquent\Attributes\ObservedBy'] ?? [] as $observers) {
                    $this->observer($class['name'], $observers, $op);
                }
            }
        }
        foreach ($this->classes as $class) {
            if (! $this->room()) {
                break;
            }
            $within = false;
            foreach ($discovery as $path) {
                $within = $within || Str::is(rtrim($path, '/').'/*', $class['path']);
            }
            if (! $within || $class['abstract'] || $class['kind'] !== 'class') {
                continue;
            }
            $op = ['source' => ['path' => $class['path'], 'line' => $class['line'], 'offset' => 0], 'conditions' => ['Event discovery registration must be active.'], 'from' => $class['name']];
            if ($this->inherits($class['name'], 'Illuminate\Contracts\Events\ShouldBeDiscovered')) {
                $enabled = $this->method($class['name'], 'shouldBeDiscovered')['returns'][0] ?? null;
                if ($enabled === false) {
                    $op['conditions'][] = 'Laravel 13 ShouldBeDiscovered disables this listener; Laravel 12 may still discover it.';
                } elseif ($enabled !== true) {
                    $op['conditions'][] = 'ShouldBeDiscovered condition is unresolved and version-dependent.';
                }
            }
            foreach ($this->methods($class['name']) as $method) {
                if ($method['public'] && ! $method['abstract'] && (str_starts_with(strtolower($method['name']), 'handle') || strtolower($method['name']) === '__invoke')) {
                    $this->register($method['parameter'], ['type' => 'reference', 'symbol' => $method['symbol'], 'class' => $class['name']], [...$op, 'source' => $method['source']], 'discovery');
                }
            }
        }
    }

    /** @param array<string, mixed> $op */
    private function subscriber(mixed $subscriber, array $op): void
    {
        $class = is_string($subscriber) ? $subscriber : ($subscriber['class'] ?? null);
        $method = $class === null ? null : $this->method($class, 'subscribe');
        if ($method === null) {
            $this->notice($op, 'Subscriber class/mapping is unresolved.');

            return;
        }
        foreach ($method['returns'] as $mapping) {
            if (! is_array($mapping)) {
                $this->notice($op, 'Dynamic subscriber returned mapping is unresolved.');

                continue;
            }
            foreach ($mapping as $event => $listeners) {
                foreach ($this->list($listeners) as $listener) {
                    $callable = is_string($listener) && $this->method($class, $listener) !== null ? ['type' => 'reference', 'symbol' => $this->method($class, $listener)['symbol'], 'class' => $class] : $listener;
                    $this->register($event, $callable, [...$op, 'source' => $method['source']], 'subscriber');
                }
            }
        }
    }

    /** @param array<string, mixed> $op */
    private function observer(string $model, mixed $observers, array $op): void
    {
        $events = null;
        if ($this->externalBoundaries) {
            $events = ['retrieved', 'creating', 'created', 'updating', 'updated', 'saving', 'saved', 'restoring', 'restored', 'replicating', 'trashed', 'deleting', 'deleted', 'forcedeleting', 'forcedeleted'];
            $getter = $this->method($model, 'getObservableEvents');
            if ($getter !== null) {
                $events = [];
                if ($getter['conditional_return']) {
                    $op['conditions'][] = 'Custom model observable event list selects a source return branch.';
                }
                foreach ($getter['returns'] as $names) {
                    if (! is_array($names)) {
                        $this->notice($op, 'Custom model observable event list is unresolved.');

                        return;
                    }
                    array_push($events, ...array_map('strtolower', $names));
                }
                if ($getter['returns'] === []) {
                    $this->notice($op, 'Custom model observable event list is unresolved.');

                    return;
                }
            } else {
                $properties = $this->properties($model);
                if (array_key_exists('observables', $properties)) {
                    if (! is_array($properties['observables'])) {
                        $this->notice($op, 'Additional model observable event names are unresolved.');
                    } else {
                        array_push($events, ...array_map('strtolower', $properties['observables']));
                    }
                }
            }
        }
        foreach ($this->list($observers) as $observer) {
            $name = is_string($observer) ? $observer : ($observer['class'] ?? null);
            if ($name === null) {
                $this->notice($op, 'Observer class is unresolved.');

                continue;
            }
            foreach ($this->methods($name) as $method) {
                if ($method['public'] && ($events === null || in_array(strtolower($method['name']), $events, true))) {
                    $this->register('eloquent.'.strtolower($method['name']).': '.$model, ['type' => 'reference', 'symbol' => $method['symbol'], 'class' => $name], $op, 'observer');
                }
            }
        }
    }

    /** @param array<string, mixed> $op */
    private function register(mixed $events, mixed $listener, array $op, string $basis): void
    {
        $target = $this->callable($listener);
        if ($target === null) {
            $this->notice($op, 'Event listener callable is unresolved.');

            return;
        }
        $owner = $this->callableOwner($listener) ?? explode('::', $target)[0];
        $queued = ($listener['type'] ?? null) === 'queued_callback' || $this->inherits($owner, 'Illuminate\Contracts\Queue\ShouldQueue');
        $conditions = $op['conditions'];
        $shouldQueue = $this->method($owner, 'shouldQueue');
        if ($queued && $shouldQueue !== null && ! $shouldQueue['conditional_return'] && ($shouldQueue['returns'][0] ?? null) === false) {
            return;
        }
        if ($queued && $shouldQueue !== null) {
            $conditions[] = 'Listener shouldQueue condition is not evaluated.';
        }
        foreach ($this->list($events) as $event) {
            if (! is_string($event)) {
                $this->notice($op, 'Registered event name/type is unresolved.');

                continue;
            }
            $properties = $this->properties($owner);
            $afterCommit = $this->inherits($owner, 'Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit') || $this->inherits($owner, 'Illuminate\Contracts\Queue\ShouldQueueAfterCommit') || ($properties['afterCommit'] ?? false) === true || is_array($listener) && isset($listener['options']['aftercommit']);
            $this->registrations[] = ['event' => $event, 'target' => $target, 'source' => $op['source'], 'basis' => $basis, 'mode' => $queued ? 'queue-requested' : 'synchronous', 'timing' => $afterCommit ? 'after-commit' : 'immediate', 'conditions' => $conditions];
        }
    }

    private function callableOwner(mixed $value): ?string
    {
        if (is_string($value)) {
            return explode('@', $value)[0];
        }
        if (! is_array($value)) {
            return null;
        }
        if (($value['type'] ?? '') === 'queued_callback') {
            return $this->callableOwner($value['value']);
        }
        if (array_is_list($value) && count($value) === 2) {
            return is_string($value[0]) ? $value[0] : ($value[0]['class'] ?? null);
        }

        return $value['class'] ?? (isset($value['symbol']) ? explode('::', $value['symbol'])[0] : null);
    }

    private function callable(mixed $value): ?string
    {
        if (is_array($value) && ($value['type'] ?? '') === 'queued_callback') {
            return $this->callable($value['value']);
        }
        if (is_array($value) && in_array($value['type'] ?? '', ['callback', 'reference'], true)) {
            if (($value['type'] ?? '') === 'reference' && str_contains($value['symbol'], '::')) {
                [$owner, $method] = explode('::', $value['symbol'], 2);

                return $this->method($owner, $method)['symbol'] ?? null;
            }

            return $value['symbol'];
        }
        if (is_array($value) && array_is_list($value) && count($value) === 2 && is_string($value[1])) {
            $owner = is_string($value[0]) ? $value[0] : ($value[0]['class'] ?? null);

            return $owner === null ? null : ($this->method($owner, $value[1])['symbol'] ?? null);
        }
        if (is_string($value)) {
            [$owner, $method] = array_pad(explode('@', $value, 2), 2, 'handle');

            return $this->method($owner, $method)['symbol'] ?? $this->method($owner, '__invoke')['symbol'] ?? null;
        }

        return null;
    }

    /** @param array<string, mixed> $op */
    private function event(string $event, array $op, bool $model = false, ?string $from = null): void
    {
        $from ??= $op['from'];
        $payload = $op['args']['event'] ?? $op['args'][0] ?? null;
        $broadcastObject = ! $model && is_array($payload) && ($payload['type'] ?? null) === 'object';
        $node = '(event) '.$event.'@'.$op['source']['path'].':'.$op['source']['offset'].($model ? ':model' : '');
        $conditions = [...$op['conditions'], 'Listener propagation may stop on false or a non-null until result.'];
        if ($model) {
            $conditions[] = 'Model operation and earlier lifecycle listeners must permit this event.';
        }
        $afterCommit = $this->inherits($event, 'Illuminate\Contracts\Events\ShouldDispatchAfterCommit');
        if ($afterCommit) {
            $this->edge($from, $node, $model ? 'model-event' : 'event-dispatch', $op['source'], [...$conditions, 'Active transaction commits; deferred event starts after withoutEvents restores the dispatcher.'], [...($this->externalBoundaries ? ['broadcast_object_verified' => $broadcastObject] : []), 'requires_events' => $model, 'event' => $event, 'mode' => 'synchronous', 'timing' => 'after-commit', 'reset_quiet' => true]);
        }
        $this->edge($from, $node, $model ? 'model-event' : 'event-dispatch', $op['source'], $afterCommit ? [...$conditions, 'Without an active transaction the event is dispatched immediately in the current event context.'] : $conditions, [...($this->externalBoundaries ? ['broadcast_object_verified' => $broadcastObject] : []), 'requires_events' => $model, 'event' => $event, 'mode' => 'synchronous', 'timing' => 'immediate']);
        foreach ($this->registrations as $registration) {
            if (! $this->room()) {
                break;
            }
            $pattern = $registration['event'];
            if ($pattern !== $event && ! Str::is($pattern, $event) && ! (isset($this->classes[strtolower($pattern)]) && $this->classes[strtolower($pattern)]['kind'] === 'interface' && $this->inherits($event, $pattern))) {
                continue;
            }
            $this->edge($node, $registration['target'], 'event-listener', $registration['source'], [...$conditions, ...$registration['conditions']], ['event' => $event, 'registration' => $registration['basis'], 'mode' => $registration['mode'], 'timing' => $registration['timing'], 'reset_quiet' => $registration['mode'] === 'queue-requested' || $registration['timing'] === 'after-commit']);
        }
        if (! $model) {
            $this->seed($op, 'event-dispatch');
        }
    }

    /** @param array<string, mixed> $op */
    private function modelOperation(array $op): void
    {
        if (str_ends_with($op['method'], 'quietly')) {
            return;
        }
        $builder = ($op['receiver']['type'] ?? '') === 'builder' || ($op['receiver']['type'] ?? '') === 'class';
        if ($builder && in_array($op['method'], ['update', 'delete', 'increment', 'decrement', 'restore', 'forcedelete'], true)) {
            return;
        }
        $events = match ($op['method']) {
            'save' => ['saving', 'creating', 'created', 'updating', 'updated', 'saved'],
            'create' => ['saving', 'creating', 'created', 'saved'],
            'firstorcreate', 'updateorcreate' => ['retrieved', 'saving', 'creating', 'created', 'updating', 'updated', 'saved'],
            'update', 'increment', 'decrement' => ['saving', 'updating', 'updated', 'saved'],
            'delete', 'destroy' => ['deleting', 'deleted', ...($this->inherits($op['owner'], 'Illuminate\Database\Eloquent\SoftDeletes') ? ['trashed'] : [])],
            'restore' => ['restoring', 'saving', 'updating', 'updated', 'saved', 'restored'],
            'forcedelete' => ['forcedeleting', 'deleting', 'deleted', 'forcedeleted'],
            'replicate' => ['replicating'],
            'get', 'find', 'findorfail', 'first' => ['retrieved'],
            default => [],
        };
        if ($events === []) {
            return;
        }
        $this->seed($op, 'model-operation');
        $properties = $this->properties($op['owner']);
        foreach ($events as $event) {
            $conditional = $op;
            $conditional['conditions'][] = 'Lifecycle '.$event.' depends on model existence, dirty data, operation outcome and listener responses.';
            $custom = $properties['dispatchesEvents'][$event] ?? null;
            if (is_string($custom)) {
                $this->event($custom, $conditional, true);
                $conditional['conditions'][] = 'Standard model event runs only when custom dispatchesEvents listeners allow fallback.';
            }
            $this->event('eloquent.'.$event.': '.$op['owner'], $conditional, true);
        }
    }

    /** @param array<string, bool> $seen
     * @return array<string, mixed>
     */
    private function properties(string $name, array $seen = []): array
    {
        $key = strtolower($name);
        if (isset($seen[$key]) || count($seen) >= 32) {
            return [];
        }
        $seen[$key] = true;
        $class = $this->classes[$key] ?? [];
        $properties = [];
        foreach ([...($class['parents'] ?? []), ...($class['traits'] ?? [])] as $ancestor) {
            $properties = [...$properties, ...$this->properties($ancestor, $seen)];
        }

        return [...$properties, ...($class['properties'] ?? [])];
    }

    /** @param array<string, bool> $seen
     * @return array<string, mixed>
     */
    private function attributes(string $name, array $seen = []): array
    {
        $key = strtolower($name);
        if (isset($seen[$key]) || count($seen) >= 32) {
            return [];
        }
        $seen[$key] = true;
        $class = $this->classes[$key] ?? [];
        $attributes = $class['attributes'] ?? [];
        foreach ($class['parents'] ?? [] as $parent) {
            foreach ($this->attributes($parent, $seen) as $attribute => $values) {
                $attributes[$attribute] = [...($attributes[$attribute] ?? []), ...$values];
            }
        }

        return $attributes;
    }

    /** @param array<string, mixed> $op
     * @return array<string, mixed>
     */
    private function dispatchOptions(array $op): array
    {
        $mode = in_array($op['method'], ['dispatchsync', 'dispatchnow', 'dispatchafterresponse'], true) ? 'synchronous' : 'from-job-contract';

        return ['mode' => $mode, 'timing' => $op['method'] === 'dispatchafterresponse' ? 'after-response' : 'immediate', 'conditions' => $op['conditions'], 'options' => []];
    }

    /** @param array<string, mixed> $op
     * @param  array<string, mixed>  $options
     */
    private function jobs(mixed $value, string $from, array $op, array $options, int $depth = 0): void
    {
        if ($depth > 12 || ! $this->room()) {
            $this->limited = true;

            return;
        }
        if (is_array($value) && array_is_list($value)) {
            $value = ['type' => 'chain', 'jobs' => $value, 'options' => []];
        }
        $type = is_array($value) ? ($value['type'] ?? null) : null;
        if (is_array($value)) {
            foreach ($value['options'] ?? [] as $name => $option) {
                if (in_array($name, ['before', 'progress', 'then', 'catch', 'finally'], true)) {
                    $options['options'][$name] = [...($options['options'][$name] ?? []), ...$option];
                } else {
                    $options['options'][$name] = $option;
                }
            }
        }
        if ($type === 'pending') {
            $inner = $value['value'];
            if (is_array($inner)) {
                foreach (['before', 'progress', 'then', 'catch', 'finally'] as $callbackOption) {
                    if (isset($options['options'][$callbackOption], $inner['options'][$callbackOption])) {
                        unset($inner['options'][$callbackOption]);
                    }
                }
            }
            $this->jobs($inner, $from, $op, $options, $depth + 1);

            return;
        }
        if (in_array($type, ['batch', 'chain'], true)) {
            if ($type === 'batch' && $op['method'] === 'dispatchafterresponse') {
                $options['mode'] = 'queue-requested';
            }
            $jobs = $value['jobs'] ?? null;
            if (! is_array($jobs) || ! array_is_list($jobs)) {
                $this->notice($op, 'Dynamic chain/batch job collection is unresolved.');

                return;
            }
            if (isset($value['first'])) {
                array_unshift($jobs, $value['first']);
            }
            $group = '(group) '.$op['source']['path'].':'.$op['source']['offset'].':'.$type.':'.hash('xxh128', serialize([$from, $value, $options['conditions']]));
            $conditions = $options['conditions'];
            $allowFailures = $options['options']['allowfailures'] ?? false;
            $this->edge($from, $group, $type.'-dispatch', $op['source'], $conditions, ['mode' => $type === 'batch' ? 'queue-requested' : $options['mode'], 'timing' => $options['timing'], 'allow_failures' => $allowFailures]);
            foreach ($jobs as $position => $job) {
                $next = $options;
                foreach (['before', 'progress', 'then', 'catch', 'finally', 'allowfailures'] as $localOption) {
                    unset($next['options'][$localOption]);
                }
                if ($type === 'chain' && is_array($job) && ($job['type'] ?? '') === 'batch' && ! ($job['options']['allowfailures'] ?? false)) {
                    $next['options']['catch'] = $options['options']['catch'] ?? [];
                    if ($next['options']['catch'] !== []) {
                        $next['conditions'][] = 'Parent chain catch is forwarded only when the nested batch does not allow failures.';
                    }
                }
                $next['conditions'] = [...$next['conditions'], $type === 'chain' ? 'Chain item '.$position.' requires success of preceding ordinary jobs; preceding batches resume via finally only when not cancelled.' : 'Independent batch branch '.$position.'; no order relative to other branches.'];
                if ($type === 'batch') {
                    $next['conditions'][] = 'Batch cancellation and job middleware may prevent execution; allowFailures='.json_encode($allowFailures).'.';
                    $next['mode'] = 'queue-requested';
                    $next['batch'] = true;
                }
                $this->jobs($job, $group, $op, $next, $depth + 1);
            }
            foreach (['before', 'progress', 'then', 'catch', 'finally'] as $callback) {
                if (! isset($options['options'][$callback])) {
                    continue;
                }
                foreach ($options['options'][$callback] as $callbackValue) {
                    $target = $this->callable($callbackValue);
                    if ($target === null) {
                        $this->notice($op, 'Dynamic '.$type.' '.$callback.' callback is unresolved.');

                        continue;
                    }
                    $condition = match ($callback) {
                        'before' => 'After batch is stored, before jobs are added.',
                        'progress' => 'After a batch job succeeds.',
                        'then' => 'After batch success; allowFailures and failed/retried jobs affect completion.',
                        'catch' => $type === 'batch' ? 'On the first batch failure.' : 'On chain failure.',
                        'finally' => 'After batch completion; cancellation and retries affect completion.',
                    };
                    $this->edge($group, $target, $type.'-'.$callback, $op['source'], [...$conditions, $condition], ['mode' => $callback === 'before' ? 'synchronous' : 'worker-callback', 'timing' => $callback, 'allow_failures' => $allowFailures, 'reset_quiet' => $callback !== 'before']);
                }
            }

            return;
        }
        $callback = $type === 'callback' || $type === 'reference' ? $this->callable($value) : null;
        $job = is_string($value) ? $value : (is_array($value) ? ($value['class'] ?? null) : null);
        $handler = $job === null ? null : ($this->handlers[strtolower($job)] ?? $job);
        $target = $callback ?? (! is_string($handler) ? null : ($this->method($handler, 'handle')['symbol'] ?? $this->method($handler, '__invoke')['symbol'] ?? null));
        if ($target === null) {
            $this->notice($op, 'Dispatched job/closure handler is unresolved.');

            return;
        }
        $mode = $options['mode'];
        if ($mode === 'from-job-contract') {
            $mode = $callback !== null || $this->inherits($job ?? '', 'Illuminate\Contracts\Queue\ShouldQueue') ? 'queue-requested' : 'synchronous';
        }
        $timing = isset($options['options']['aftercommit']) ? 'after-commit' : (isset($options['options']['afterresponse']) ? 'after-response' : $options['timing']);
        if ($timing === 'after-response' && ! ($options['batch'] ?? false)) {
            $mode = 'synchronous';
        }
        $properties = $job === null ? [] : $this->properties($job);
        if ($timing === 'immediate' && ($this->inherits($job ?? '', 'Illuminate\Contracts\Queue\ShouldQueueAfterCommit') || ($properties['afterCommit'] ?? false) === true) && ! isset($options['options']['beforecommit'])) {
            $timing = 'after-commit';
        }
        $connection = array_key_exists('onconnection', $options['options']) ? $options['options']['onconnection'] : ($properties['connection'] ?? null);
        $conditions = $options['conditions'];
        if ($this->unknownHandlers) {
            $conditions[] = 'Unresolved Bus command mapping may override the handler.';
        }
        if ($mode === 'queue-requested') {
            $conditions[] = 'Queue connection may execute synchronously; worker, middleware, uniqueness and retries are not evaluated.';
        }
        $this->edge($from, $target, 'job-handler', $op['source'], $conditions, ['job' => $job, 'mode' => $mode, 'timing' => $timing, 'queue' => $options['options']['onqueue'] ?? $properties['queue'] ?? null, 'connection' => $connection, 'reset_quiet' => $mode === 'queue-requested' && $connection !== 'sync' || $timing !== 'immediate']);
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [$value];
    }

    /** @param array<string, mixed> $op */
    private function seed(array $op, string $kind): void
    {
        if (str_starts_with($op['from'], '(callback)')) {
            return;
        }
        $this->seeds[strtolower($op['from'])] = ['symbol' => $op['from'], 'kind' => $kind, 'source' => $op['source']];
    }

    /** @param array<string, mixed> $source
     * @param  list<string>  $conditions
     * @param  array<string, mixed>  $meta
     */
    private function edge(string $from, string $to, string $kind, array $source, array $conditions, array $meta = []): void
    {
        if (! $this->room()) {
            return;
        }
        $this->out[strtolower($from)][] = ['from' => $from, 'to' => $to, 'kind' => $kind, ...$source, 'conditions' => array_values(array_unique($conditions)), 'certainty' => $conditions === [] ? 'declared' : 'possible', ...$meta];
    }

    /** @phpstan-impure */
    private function room(): bool
    {
        if (++$this->visits > 100000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $op */
    private function notice(array $op, string $reason): void
    {
        $this->notices[] = [...$op['source'], 'reason' => $reason];
    }
}
