<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class RouteMap
{
    /**
     * @param  array<string, list<string>>  $methods
     * @param  list<RouteEntry>|null  $entries
     * @param  array<string, mixed>|null  $context
     */
    public function __construct(
        public array $methods = [],
        public ?string $unavailable = null,
        public ?array $entries = null,
        public ?array $context = null,
    ) {}

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
        return array_values(array_filter(
            $this->entries ?? [],
            fn (RouteEntry $entry): bool => $entry->class !== null
                && $entry->method !== null
                && strcasecmp($entry->class, ltrim($class, '\\')) === 0
                && strcasecmp($entry->method, $method) === 0,
        ));
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'version' => 2,
            'entries' => array_map(fn (RouteEntry $entry): array => $entry->toArray(), $this->entries ?? []),
            'context' => $this->context ?? [
                'status' => 'unavailable',
                'providers' => [],
                'middleware' => [],
                'middlewareGroups' => [],
                'middlewareAliases' => [],
                'packageVersions' => [],
                'unavailable' => 'Laravel framework context was not supplied with this route map.',
            ],
        ];
    }

    public static function fromSnapshot(mixed $data): self
    {
        if (! is_array($data) || ! in_array($data['version'] ?? null, [1, 2], true) || ! is_array($data['entries'] ?? null) || ! array_is_list($data['entries'])) {
            throw new \UnexpectedValueException('Invalid route snapshot.');
        }
        if (($data['version'] ?? null) === 2 && ! self::validContext($data['context'] ?? null)) {
            throw new \UnexpectedValueException('Invalid route snapshot context.');
        }
        $entries = [];
        $methods = [];
        foreach ($data['entries'] as $value) {
            $entry = RouteEntry::fromArray($value);
            $entries[] = $entry;
            if ($entry->class !== null && $entry->method !== null) {
                $key = strtolower($entry->class.'::'.$entry->method);
                $methods[$key] = array_values(array_unique([...($methods[$key] ?? []), ...$entry->verbs]));
                sort($methods[$key]);
            }
        }
        ksort($methods);

        return new self($methods, entries: $entries, context: ($data['version'] ?? null) === 2 ? $data['context'] : null);
    }

    public static function fresh(string $basePath): self
    {
        if (! is_file($basePath.'/bootstrap/app.php') || ! is_file($basePath.'/vendor/autoload.php')) {
            return new self(unavailable: 'Laravel bootstrap is unavailable; pass an explicit RouteMap to the programmatic audit.');
        }
        try {
            // A separate boot sees edited routes in a long-lived MCP process. The
            // cache override affects this child only, without deleting project files.
            $process = new Process([PHP_BINARY, __DIR__.'/route-snapshot.php', $basePath], $basePath, [
                'APP_ROUTES_CACHE' => $basePath.'/bootstrap/cache/architecture-kit-'.bin2hex(random_bytes(12)).'.php',
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

        return ! isset($context['unavailable']) || is_string($context['unavailable']);
    }
}
