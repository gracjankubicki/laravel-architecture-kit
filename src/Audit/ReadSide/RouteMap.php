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
     */
    public function __construct(public array $methods = [], public ?string $unavailable = null) {}

    /**
     * @param  iterable<Route>  $routes
     */
    public static function fromRoutes(iterable $routes): self
    {
        $methods = [];
        foreach ($routes as $route) {
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

        return new self($methods);
    }

    /** @return list<string> */
    public function verbs(string $class, string $method): array
    {
        return $this->methods[strtolower(ltrim($class, '\\').'::'.$method)] ?? [];
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
            if (! is_array($data)) {
                throw new \UnexpectedValueException('Invalid route snapshot.');
            }
            foreach ($data as $key => $verbs) {
                if (! is_string($key) || ! is_array($verbs) || ! array_is_list($verbs)) {
                    throw new \UnexpectedValueException('Invalid route entry.');
                }
                foreach ($verbs as $verb) {
                    if (! is_string($verb)) {
                        throw new \UnexpectedValueException('Invalid HTTP verb.');
                    }
                }
            }

            return new self($data);
        } catch (Throwable) {
            return new self(unavailable: 'Fresh Laravel route discovery is unavailable or exceeded its 15 second limit.');
        }
    }
}
