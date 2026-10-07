<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

/** Console relations share the bounded execution graph and its callable resolver. */
trait ConsoleExecutionLinks
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $consoleCommands = [];

    /** @param array<string, mixed> $facts */
    private function consoleLinks(array $facts): void
    {
        $registrationCallbacks = [];
        // Only registered scheduling callbacks/groups activate their nested declarations.
        for ($round = 0; $round < 12; $round++) {
            $before = count($registrationCallbacks);
            foreach ($facts['operations'] as $registration) {
                if (! $this->room()) {
                    return;
                }
                if (str_starts_with($registration['from'], '(callback)') && ! isset($registrationCallbacks[$registration['from']])) {
                    continue;
                }
                if ($registration['kind'] === 'schedule_group' || $registration['kind'] === 'console_registration' && $registration['method'] === 'withschedule') {
                    $callback = $this->callable($registration['args']['callback'] ?? $registration['args']['events'] ?? $registration['args'][0] ?? null);
                    if ($callback !== null) {
                        $registrationCallbacks[$callback] = true;
                    }
                }
            }
            if (count($registrationCallbacks) === $before) {
                break;
            }
            if ($round === 11) {
                $this->limited = true;
            }
        }
        $directories = [];
        $registered = [];
        $closureOptions = [];
        $scheduleOptions = [];
        foreach ($facts['operations'] as $op) {
            if (! $this->room()) {
                return;
            }
            if ($op['kind'] === 'console_closure_options') {
                $closureOptions[serialize($op['registration_site'])] = $op['options'];
            } elseif ($op['kind'] === 'schedule_options') {
                $key = serialize($op['schedule_site']);
                $prior = $scheduleOptions[$key] ?? [];
                $callbacks = [];
                foreach ([...($prior['callbacks'] ?? []), ...($op['options']['callbacks'] ?? [])] as $callback) {
                    $callbacks[serialize($callback)] = $callback;
                }
                $attributeCalls = [];
                foreach ([...($prior['attribute_calls'] ?? []), ...($op['options']['attribute_calls'] ?? [])] as $attributeCall) {
                    $attributeCalls[serialize($attributeCall)] = $attributeCall;
                }
                $scheduleOptions[$key] = [...$prior, ...$op['options'], 'callbacks' => array_values($callbacks), 'attribute_calls' => array_values($attributeCalls)];
            } elseif ($op['kind'] === 'application') {
                $directories[] = ['path' => 'app/Console/Commands', 'op' => $op];
            } elseif ($op['kind'] === 'console_registration' && $op['method'] === 'withcommands') {
                $this->consoleRegistration(($op['args']['commands'] ?? $op['args'][0] ?? []) ?: ['app/Console/Commands'], $op, $directories, $registered);
            } elseif ($op['kind'] === 'console_candidate' && $this->consoleRegistrar($op['owner'])) {
                if (in_array($op['method'], ['commands', 'addcommands', 'registercommand', 'load', 'addcommandpaths'], true)) {
                    $this->consoleRegistration($op['args'][0] ?? null, $op, $directories, $registered);
                }
            }
        }
        foreach ($this->classes as $class) {
            if (! $this->room()) {
                return;
            }
            if ($this->inherits($class['name'], 'Illuminate\Foundation\Console\Kernel')) {
                $op = ['from' => $class['name'], 'source' => ['path' => $class['path'], 'line' => $class['line'], 'offset' => 0], 'conditions' => []];
                $this->consoleRegistration($this->properties($class['name'])['commands'] ?? [], $op, $directories, $registered);
                $directories[] = ['path' => 'app/Console/Commands', 'op' => $op];
            }
        }
        foreach ($this->classes as $class) {
            if (! $this->room()) {
                return;
            }
            if ($class['abstract'] || $class['kind'] !== 'class' || ! $this->consoleCommand($class['name'])) {
                continue;
            }
            $sources = $registered[strtolower($class['name'])] ?? [];
            foreach ($directories as $directory) {
                if (str_starts_with($class['path'], rtrim($directory['path'], '/').'/')) {
                    $sources[] = $directory['op'];
                }
            }
            foreach ($sources as $op) {
                $handler = $this->consoleHandler($class['name']);
                if ($handler === null) {
                    $this->notice($op, 'Registered console command handle/__invoke is unresolved: '.$class['name']);

                    continue;
                }
                $properties = $this->properties($class['name']);
                $attributes = $class['attributes'];
                $signatureAttribute = $attributes['Illuminate\Console\Attributes\Signature'] ?? [];
                $aliasAttribute = $attributes['Illuminate\Console\Attributes\Aliases'] ?? [];
                $signature = $signatureAttribute['signature'] ?? $signatureAttribute[0] ?? $properties['signature'] ?? $properties['name'] ?? null;
                $aliases = $aliasAttribute['aliases'] ?? $aliasAttribute[0] ?? $signatureAttribute['aliases'] ?? $signatureAttribute[1] ?? $properties['aliases'] ?? [];
                if ($signatureAttribute !== [] || $aliasAttribute !== []) {
                    $op['conditions'][] = 'Console Signature/Aliases attributes require Laravel 13; framework version is not established by source registration.';
                }
                $this->registerConsole($signature, $handler, $op, $class['name']);
                foreach (is_array($aliases) ? $aliases : [] as $alias) {
                    $this->registerConsole($alias, $handler, $op, $class['name']);
                }
            }
        }
        foreach ($facts['operations'] as $op) {
            if (! $this->room()) {
                return;
            }
            if ($op['kind'] === 'console_closure' && (! str_starts_with($op['from'], '(callback)') || isset($registrationCallbacks[$op['from']]))) {
                $handler = $this->callable($op['args']['callback'] ?? $op['args'][1] ?? null);
                if ($handler === null) {
                    $this->notice($op, 'Console closure callback is unresolved.');

                    continue;
                }
                $this->registerConsole($op['args']['signature'] ?? $op['args'][0] ?? null, $handler, $op, null);
                foreach ((array) ($closureOptions[serialize($op['source'])]['aliases'][0] ?? []) as $alias) {
                    $this->registerConsole($alias, $handler, $op, null);
                }
            }
        }
        foreach ($registered as $name => $registrations) {
            if (! isset($this->classes[$name])) {
                foreach ($registrations as $registration) {
                    $this->notice($registration, 'Registered console class source is missing: '.$name);
                }
            }
        }
        foreach ($this->consoleCommands as $name => $commands) {
            if (count(array_unique(array_map(fn (array $command): string => ($command['class'] ?? '').'::'.$command['handler'], $commands))) > 1) {
                foreach ($commands as $command) {
                    $this->notice($command['op'], 'Console registration conflict for '.$name.'; runtime order is unresolved.');
                }
            }
        }
        foreach ($facts['operations'] as $op) {
            if (! $this->room()) {
                return;
            }
            if ($this->externalBoundaries && $op['kind'] === 'console_candidate' && $op['owner'] === 'Illuminate\Support\Facades\Artisan' && in_array($op['method'], ['callsilent', 'callsilently'], true)) {
                $this->notice($op, 'Artisan facade has no standard callSilent/callSilently API; source extension or intended command receiver needs checking.');

                continue;
            }
            if ($op['kind'] === 'console_candidate' && $this->consoleCaller($op)) {
                $this->seed($op, 'artisan-call');
                $this->consoleCall($op['args']['command'] ?? $op['args'][0] ?? null, $op['from'], $op, $op['method'] === 'queue' ? 'queue-requested' : 'synchronous', $op['conditions']);
            } elseif ($op['kind'] === 'schedule') {
                if (str_starts_with($op['from'], '(callback)') && ! isset($registrationCallbacks[$op['from']])) {
                    $this->notice($op, 'Scheduling declaration in an unactivated callback; registration execution is not proved.');

                    continue;
                }
                $this->scheduleLinks($op, $scheduleOptions[serialize($op['source'])] ?? $op['options']);
            }
        }
    }

    private function consoleCommand(string $owner): bool
    {
        return $owner === 'Illuminate\Foundation\Console\ClosureCommand' || $this->inherits($owner, 'Illuminate\Console\Command');
    }

    private function consoleRegistrar(string $owner): bool
    {
        return $owner === 'Illuminate\Support\Facades\Artisan' || $this->inherits($owner, 'Illuminate\Foundation\Console\Kernel') || $this->inherits($owner, 'Illuminate\Support\ServiceProvider');
    }

    /** @param array<string, mixed> $op */
    private function consoleCaller(array $op): bool
    {
        return $op['owner'] === 'Illuminate\Support\Facades\Artisan' && in_array($op['method'], ['call', 'queue'], true)
            || $this->consoleCommand($op['owner']) && in_array($op['method'], ['call', 'callsilent', 'callsilently'], true) && $this->method($op['owner'], $op['method']) === null;
    }

    private function consoleHandler(string $owner): ?string
    {
        $method = $this->method($owner, 'handle') ?? $this->method($owner, '__invoke');

        return $method !== null && ! $method['abstract'] && (! $this->externalBoundaries || $method['public']) ? $method['symbol'] : null;
    }

    /** @param array<string, mixed> $op
     * @param  list<array<string, mixed>>  $directories
     * @param  array<string, list<array<string, mixed>>>  $registered
     */
    private function consoleRegistration(mixed $values, array $op, array &$directories, array &$registered): void
    {
        foreach (is_array($values) && array_is_list($values) ? $values : [$values] as $value) {
            if (! $this->room()) {
                return;
            }
            $name = is_string($value) ? $value : ($value['class'] ?? null);
            if (! is_string($name)) {
                $this->notice($op, 'Dynamic console registration is unresolved.');
            } elseif (str_contains($name, '\\')) {
                $registered[strtolower($name)][] = $op;
            } elseif (! str_ends_with($name, '.php')) {
                $directories[] = ['path' => $name, 'op' => $op];
            }
        }
    }

    /** @param array<string, mixed> $op */
    private function registerConsole(mixed $signature, string $handler, array $op, ?string $class): void
    {
        $name = is_string($signature) ? preg_split('/\s+/', trim($signature))[0] : null;
        if (! is_string($name) || $name === '') {
            $this->notice($op, 'Console command name/signature is unresolved.');

            return;
        }
        $root = '(console) '.$name.'@'.$op['source']['path'].':'.$op['source']['offset'].':'.$handler.($class !== null ? ':'.$class : '');
        $conditions = [...$op['conditions'], 'Source registration requires runtime console activation and command selection.'];
        foreach ($this->consoleCommands[$name] ?? [] as $existing) {
            if ($existing['handler'] === $handler && $existing['class'] === $class && $existing['op']['source'] === $op['source']) {
                return;
            }
        }
        $this->consoleCommands[$name][] = ['handler' => $handler, 'class' => $class, 'op' => $op];
        $this->edge($root, $handler, 'console-handler', $op['source'], $conditions, ['command' => $name, 'mode' => 'console-entry', 'timing' => 'command-selected']);
        $this->seeds[strtolower($root)] = ['symbol' => $root, 'kind' => 'console', 'source' => $op['source'], 'command' => $name, 'runtime_class' => $class];
    }

    /** @param array<string, mixed> $op
     * @param  list<string>  $conditions
     */
    private function consoleCall(mixed $value, string $from, array $op, string $mode, array $conditions): void
    {
        $command = is_string($value) ? $value : ($value['class'] ?? null);
        if (! is_string($command)) {
            $this->notice($op, 'Dynamic Artisan command selector is unresolved.');

            return;
        }
        $handler = $this->consoleCommand($command) ? $this->consoleHandler($command) : null;
        $name = preg_split('/\s+/', trim($command))[0];
        $commands = $handler === null ? ($this->consoleCommands[$name] ?? []) : [['handler' => $handler]];
        if ($commands === []) {
            $this->notice($op, 'Artisan command registration/handler is unresolved: '.$name);

            return;
        }
        foreach ($commands as $registration) {
            $extra = [...$conditions, 'Console registration, arguments, container resolution and command execution may fail.'];
            if (count(array_unique(array_column($commands, 'handler'))) > 1) {
                $extra[] = 'Conflicting registration; selected handler depends on runtime order.';
            }
            if ($mode === 'queue-requested') {
                $extra[] = 'Queued Artisan command requires queue execution; connection may be synchronous.';
            }
            $this->edge($from, $registration['handler'], 'artisan-command', $op['source'], $extra, ['command' => $name, 'mode' => $mode, 'timing' => 'command-invocation', 'reset_quiet' => $mode !== 'synchronous']);
        }
    }

    private function scheduleCallable(mixed $value): ?string
    {
        if (is_array($value) && ($value['type'] ?? '') === 'object') {
            return $this->method($value['class'], '__invoke')['symbol'] ?? null;
        }
        if (is_string($value) && ! str_contains($value, '@') && ! str_contains($value, '::')) {
            return $this->method($value, '__invoke')['symbol'] ?? null;
        }
        if (is_string($value) && str_contains($value, '::')) {
            [$owner, $method] = explode('::', $value, 2);

            return $this->method($owner, $method)['symbol'] ?? null;
        }

        return $this->callable($value);
    }

    /** @param array<string, mixed> $op
     * @param  array<string, mixed>  $options
     */
    private function scheduleLinks(array $op, array $options): void
    {
        $root = '(schedule) '.$op['source']['path'].':'.$op['source']['offset'];
        $this->seeds[strtolower($root)] = ['symbol' => $root, 'kind' => 'schedule', 'source' => $op['source'], 'schedule' => $options];
        if ($this->externalBoundaries && array_filter($options['callbacks'] ?? [], fn ($callback) => $callback['invalid_contract'] ?? false) !== []) {
            $this->notice($op, 'Schedule lifecycle callback violates its Closure contract; a valid scheduled execution path is not established.');

            return;
        }
        if ($op['method'] !== 'command' && isset($options['runinbackground'])) {
            $this->notice($op, 'CallbackEvent runInBackground is invalid; scheduling declaration throws.');

            return;
        }
        if ($op['method'] === 'call') {
            $name = $op['options']['name']['description'] ?? $op['options']['name'][0] ?? $op['options']['description']['description'] ?? $op['options']['description'][0] ?? null;
            if ($name === null && (isset($op['options']['withoutoverlapping']) || isset($op['options']['ononeserver']))) {
                $this->notice($op, 'CallbackEvent mutex requires a name before withoutOverlapping/onOneServer; scheduling declaration is invalid.');

                return;
            }
            foreach ($options['attribute_calls'] ?? [] as $attributeCall) {
                if (in_array($attributeCall['method'], ['name', 'description'], true)) {
                    $name = $attributeCall['args']['description'] ?? $attributeCall['args'][0] ?? null;
                } elseif ($name === null) {
                    $this->notice(['source' => $attributeCall['source']], 'CallbackEvent mutex requires a name before withoutOverlapping/onOneServer; scheduling declaration is invalid.');

                    return;
                }
            }
        }
        $conditions = [...$op['conditions'], 'Scheduler registration must be active; task must be due and environment, maintenance and pause gates must pass.'];
        foreach ($options as $attribute => $arguments) {
            if ($attribute !== 'callbacks' && is_array($arguments) && in_array(null, $arguments, true)) {
                $this->notice($op, 'Dynamic schedule '.$attribute.' configuration is unresolved.');
            }
        }
        $blocked = false;
        // Laravel evaluates all when filters before skip rejects, each with short-circuit.
        $callbacks = $options['callbacks'] ?? [];
        foreach (['when', 'skip'] as $filter) {
            foreach ($callbacks as $index => $callback) {
                if ($callback['method'] !== $filter || $blocked) {
                    continue;
                }
                $value = $callback['value'];
                if (is_bool($value)) {
                    $blocked = $filter === 'when' ? ! $value : $value;
                } else {
                    $target = $this->scheduleCallable($value);
                    if ($target === null) {
                        $this->notice(['source' => $callback['source']], 'Schedule '.$filter.' filter is unresolved.');
                    } else {
                        $this->edge($root, $target, 'schedule-'.$filter, $callback['source'], $conditions, ['stage' => 'filter', 'mode' => 'synchronous', 'timing' => 'before-task', 'filter_index' => $index, 'schedule' => $options]);
                    }
                }
                $conditions[] = $filter === 'when' ? 'All preceding when filters must return truthy.' : 'All preceding skip rejects must return falsy.';
            }
        }
        if ($blocked) {
            return;
        }
        $conditions[] = 'Single-server and overlapping mutex gates must permit the task.';
        $task = $root.':task';
        $this->edge($root, $task, 'schedule-task', $op['source'], $conditions, ['schedule' => $options, 'mode' => isset($options['runinbackground']) ? 'background-process' : 'scheduler', 'timing' => 'when-due']);
        $taskConditions = [...$conditions, 'Before callbacks must complete without throwing; overlapping mutex must be acquired.'];
        $value = $op['args'][$op['method'] === 'job' ? 'job' : ($op['method'] === 'command' ? 'command' : 'callback')] ?? $op['args'][0] ?? null;
        if ($op['method'] === 'command') {
            $this->consoleCall($value, $task, $op, isset($options['runinbackground']) ? 'background-process' : 'subprocess', $taskConditions);
        } elseif ($op['method'] === 'job') {
            $this->jobs($value, $task, $op, ['mode' => 'from-job-contract', 'timing' => 'when-due', 'conditions' => $taskConditions, 'options' => array_filter(['onqueue' => $op['args']['queue'] ?? $op['args'][1] ?? null, 'onconnection' => $op['args']['connection'] ?? $op['args'][2] ?? null], fn ($value) => $value !== null)]);
        } elseif ($op['method'] === 'call') {
            $target = $this->scheduleCallable($value);
            if ($target === null) {
                $this->notice($op, 'Schedule callback is unresolved.');
            } else {
                $this->edge($task, $target, 'schedule-call', $op['source'], $taskConditions, ['mode' => 'synchronous', 'timing' => 'when-due']);
            }
        } else {
            $this->notice($op, 'Scheduled shell command has no proved PHP execution relation.');
        }
        $afterConditions = [...$taskConditions, 'Task completion is required; preceding after callbacks must complete without throwing.'];
        foreach ($callbacks as $callback) {
            $method = $callback['method'];
            if (in_array($method, ['when', 'skip'], true)) {
                continue;
            }
            $target = $this->scheduleCallable($callback['value']);
            if ($target === null) {
                $this->notice(['source' => $callback['source']], 'Schedule '.$method.' callback is unresolved.');

                continue;
            }
            $condition = match ($method) {
                'before' => 'Before-task callback order requires preceding before callbacks to complete.',
                'onsuccess', 'onsuccesswithoutput' => 'Task exit code must be zero. For a queued job this is dispatch callback success, not Job::handle success.',
                'onfailure', 'onfailurewithoutput' => 'Task exit code must be nonzero. Worker failures do not set the dispatch callback exit code.',
                default => 'Task completion and preceding after callbacks must permit execution; a thrown before callback prevents it.',
            };
            $this->edge($task, $target, 'schedule-'.$method, $callback['source'], [...($method === 'before' ? $conditions : $afterConditions), $condition], ['stage' => $method === 'before' ? 'before-task' : 'after-task', 'mode' => isset($options['runinbackground']) && $method !== 'before' ? 'background-finish' : 'synchronous', 'timing' => $method, 'schedule' => $options]);
        }
    }
}
