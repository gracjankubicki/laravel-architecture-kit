<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Audit\AnalysisNotice;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class RouteMap
{
    /** @var array<string, list<RouteEntry>> */
    private array $entriesByHandler;

    /** @var array<string, array<string, string>> */
    private array $methodsByClass;

    /**
     * @param  array<string, list<string>>  $methods
     * @param  list<RouteEntry>|null  $entries
     * @param  array<string, mixed>|null  $context
     * @param  list<AnalysisNotice>  $notices
     */
    public function __construct(
        public array $methods = [],
        public ?string $unavailable = null,
        public ?array $entries = null,
        public ?array $context = null,
        public array $notices = [],
    ) {
        $entriesByHandler = $methodsByClass = [];
        if ($entries !== null) {
            foreach ($entries as $entry) {
                if ($entry->class === null || $entry->method === null) {
                    continue;
                }
                $class = strtolower(ltrim($entry->class, '\\'));
                $method = strtolower($entry->method);
                $entriesByHandler[$class.'::'.$method][] = $entry;
                $methodsByClass[$class][$method] = $entry->method;
            }
        } else {
            foreach ($methods as $callback => $_verbs) {
                $separator = strpos($callback, '::');
                if ($separator === false) {
                    continue;
                }
                $class = substr($callback, 0, $separator);
                $method = substr($callback, $separator + 2);
                $methodsByClass[$class][strtolower($method)] = $method;
            }
        }
        $this->entriesByHandler = $entriesByHandler;
        $this->methodsByClass = $methodsByClass;
    }

    /**
     * @param  iterable<Route>  $routes
     * @param  array<string, mixed>|null  $context
     */
    public static function fromRoutes(iterable $routes, ?array $context = null): self
    {
        $methods = [];
        $entries = [];
        foreach ($routes as $route) {
            $entries[] = RouteEntry::fromRoute($route);
            if ($route->getControllerClass() === null) {
                continue;
            }
            // `controller` can contain only the class for an invokable route; `uses`
            // is the normalized callback Laravel actually dispatches.
            [$class, $method] = Str::parseCallback($route->getAction('uses'), '__invoke');
            $key = strtolower(ltrim($class, '\\').'::'.$method);
            $methods[$key] = array_values(array_unique([...($methods[$key] ?? []), ...$route->methods()]));
            sort($methods[$key]);
        }
        ksort($methods);

        return new self($methods, entries: $entries, context: $context);
    }

    /** @return list<string> */
    public function verbs(string $class, string $method): array
    {
        return $this->methods[strtolower(ltrim($class, '\\').'::'.$method)] ?? [];
    }

    /** @return list<RouteEntry> */
    public function entriesFor(string $class, string $method): array
    {
        return $this->entriesByHandler[strtolower(ltrim($class, '\\').'::'.$method)] ?? [];
    }

    /**
     * Return one analysis context per distinct route registration.
     *
     * @return list<array{method: string, verbs: list<string>, route: ?RouteEntry}>
     */
    public function contextsFor(string $class, string $method): array
    {
        if ($this->entries !== null) {
            $contexts = [];
            foreach ($this->entriesFor($class, $method) as $entry) {
                $contexts[$entry->identity()] ??= ['method' => $entry->method ?? $method, 'verbs' => $entry->verbs, 'route' => $entry];
            }

            return array_values($contexts);
        }

        $verbs = $this->verbs($class, $method);

        return $verbs === [] ? [] : [['method' => $method, 'verbs' => $verbs, 'route' => null]];
    }

    /**
     * Registered method names keyed by their case-insensitive identity.
     *
     * @return array<string, string>
     */
    public function methodsFor(string $class): array
    {
        return $this->methodsByClass[strtolower(ltrim($class, '\\'))] ?? [];
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'version' => 3,
            'entries' => array_map(fn (RouteEntry $entry): array => $entry->toArray(), $this->entries ?? []),
            'context' => $this->context ?? [
                'status' => 'unavailable',
                'providers' => [],
                'middleware' => [],
                'middlewareGroups' => [],
                'middlewareAliases' => [],
                'packageVersions' => [],
                'auth' => null,
                'unavailable' => 'Laravel framework context was not supplied with this route map.',
            ],
            ...($this->notices === [] ? [] : ['analysis_notices' => array_map(fn (AnalysisNotice $notice): array => $notice->toArray(), $this->notices)]),
        ];
    }

    public static function fromSnapshot(mixed $data): self
    {
        if (! is_array($data) || ! in_array($data['version'] ?? null, [1, 2, 3], true) || ! is_array($data['entries'] ?? null) || ! array_is_list($data['entries'])) {
            throw new \UnexpectedValueException('Invalid route snapshot.');
        }
        if (($data['version'] ?? null) >= 2 && ! self::validContext($data['context'] ?? null)) {
            throw new \UnexpectedValueException('Invalid route snapshot context.');
        }
        $entries = [];
        $methods = [];
        foreach ($data['entries'] as $value) {
            $entry = RouteEntry::fromArray($value);
            $entries[] = $entry;
            if ($entry->class !== null && $entry->method !== null) {
                $key = strtolower(ltrim($entry->class, '\\').'::'.$entry->method);
                $methods[$key] = array_values(array_unique([...($methods[$key] ?? []), ...$entry->verbs]));
                sort($methods[$key]);
            }
        }
        ksort($methods);

        $notices = [];
        if (isset($data['analysis_notices'])) {
            if (! is_array($data['analysis_notices']) || ! array_is_list($data['analysis_notices'])) {
                throw new \UnexpectedValueException('Invalid route analysis notices.');
            }
            foreach ($data['analysis_notices'] as $notice) {
                if (! is_array($notice) || ! is_int($notice['line'] ?? null)
                    || count(array_filter(['code', 'reason', 'path', 'message'], fn (string $key): bool => is_string($notice[$key] ?? null))) !== 4
                    || ! is_array($notice['trace'] ?? null) || ! array_is_list($notice['trace'])
                    || array_filter($notice['trace'], fn (mixed $value): bool => ! is_string($value)) !== []
                    || (($notice['route'] ?? null) !== null && ! is_array($notice['route']))) {
                    throw new \UnexpectedValueException('Invalid route analysis notice.');
                }
                $notices[] = new AnalysisNotice($notice['code'], $notice['reason'], $notice['path'], $notice['line'], $notice['message'], $notice['trace'], $notice['route'] ?? null);
            }
        }

        return new self($methods, entries: $entries, context: ($data['version'] ?? null) >= 2 ? $data['context'] : null, notices: $notices);
    }

    public static function fresh(string $basePath): self
    {
        $context = RepositoryContext::forRoot($basePath);
        if ($context !== null && ! $context->execute) {
            return new self(unavailable: 'Runtime route discovery requires explicit --allow-execution; source relationships remain available through the catalog.');
        }

        if (! is_file($basePath.'/bootstrap/app.php') || ! is_file($basePath.'/vendor/autoload.php')) {
            return new self(unavailable: 'Laravel bootstrap is unavailable; pass an explicit RouteMap to the programmatic audit.');
        }
        try {
            // A separate boot sees edited routes in a long-lived MCP process. The
            // cache override affects this child only, without deleting project files.
            $process = new Process([PHP_BINARY, __DIR__.'/route-snapshot.php', $basePath], $basePath, [
                'APP_ROUTES_CACHE' => ($context === null ? $basePath.'/bootstrap/cache' : $context->storage).'/architecture-kit-'.bin2hex(random_bytes(12)).'.php',
            ]);
            $process->setTimeout(15);
            $process->run();
            $output = $process->getOutput();
            $marker = strrpos($output, 'ARCHITECTURE_KIT_ROUTES=');
            if (! $process->isSuccessful() || $marker === false) {
                return new self(unavailable: 'Fresh Laravel route discovery failed; inspect application boot separately.');
            }
            $data = json_decode(substr($output, $marker + strlen('ARCHITECTURE_KIT_ROUTES=')), true, flags: JSON_THROW_ON_ERROR);

            return self::fromSnapshot($data);

        } catch (Throwable) {
            return new self(unavailable: 'Fresh Laravel route discovery is unavailable or exceeded its 15 second limit.');
        }
    }

    private static function validContext(mixed $context): bool
    {
        if (! is_array($context) || ! in_array($context['status'] ?? null, ['known', 'empty', 'unavailable'], true)) {
            return false;
        }
        foreach (['providers', 'middleware', 'middlewareGroups', 'middlewareAliases', 'packageVersions'] as $key) {
            if (! is_array($context[$key] ?? null)) {
                return false;
            }
        }

        if (! array_is_list($context['providers']) || ! array_is_list($context['middleware'])) {
            return false;
        }
        if (array_filter([...$context['providers'], ...$context['middleware']], fn (mixed $value): bool => ! is_string($value)) !== []) {
            return false;
        }
        foreach ($context['middlewareGroups'] as $name => $middleware) {
            if (! is_string($name) || ! is_array($middleware) || ! array_is_list($middleware) || array_filter($middleware, fn (mixed $value): bool => ! is_string($value)) !== []) {
                return false;
            }
        }
        foreach ($context['middlewareAliases'] as $name => $middleware) {
            if (! is_string($name) || ! is_string($middleware)) {
                return false;
            }
        }
        foreach ($context['packageVersions'] as $package => $version) {
            if (! is_string($package) || ($version !== null && ! is_string($version))) {
                return false;
            }
        }
        if (array_key_exists('auth', $context) && ! self::validAuthContext($context['auth'])) {
            return false;
        }
        if (isset($context['aliases']) && (! is_array($context['aliases'])
            || array_filter($context['aliases'], fn (mixed $target, mixed $alias): bool => ! is_string($alias) || ! is_string($target), ARRAY_FILTER_USE_BOTH) !== [])) {
            return false;
        }

        return ! isset($context['unavailable']) || is_string($context['unavailable']);
    }

    private static function validAuthContext(mixed $auth): bool
    {
        if ($auth === null) {
            return true;
        }
        if (! is_array($auth)
            || (($auth['default'] ?? null) !== null && ! is_string($auth['default']))
            || ! is_array($auth['guards'] ?? null)) {
            return false;
        }
        foreach ($auth['guards'] as $name => $guard) {
            if (! is_string($name)
                || ! is_array($guard)
                || ! is_string($guard['driver'] ?? null)
                || (($guard['provider'] ?? null) !== null && ! is_string($guard['provider']))
                || (($guard['model'] ?? null) !== null && ! is_string($guard['model']))
                || ! is_bool($guard['custom'] ?? false)) {
                return false;
            }
        }

        return true;
    }
}
