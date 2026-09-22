<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use UnexpectedValueException;

final readonly class RouteEntry
{
    /** @param list<string> $verbs
     * @param  array<string, string>  $constraints
     * @param  list<string>  $middleware
     * @param  list<string>  $excludedMiddleware
     * @param  array<string, string>  $bindings
     */
    public function __construct(
        public array $verbs,
        public string $uri,
        public ?string $name = null,
        public ?string $domain = null,
        public array $constraints = [],
        public ?string $class = null,
        public ?string $method = null,
        public ?string $unresolved = null,
        public array $middleware = [],
        public array $excludedMiddleware = [],
        public array $bindings = [],
    ) {}

    public static function fromRoute(Route $route): self
    {
        $class = $method = null;
        if ($route->getControllerClass() !== null && is_string($route->getAction('uses'))) {
            [$class, $method] = Str::parseCallback($route->getAction('uses'), '__invoke');
        }

        return new self(
            $route->methods(),
            $route->uri(),
            $route->getName(),
            $route->getDomain(),
            $route->wheres,
            $class,
            $method,
            $class === null ? 'Closure or unresolved route handler.' : null,
            array_values(array_filter($route->middleware(), 'is_string')),
            array_values(array_filter($route->excludedMiddleware(), 'is_string')),
            array_filter($route->bindingFields(), 'is_string'),
        );
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return [
            'id' => $this->identity(),
            'uri' => $this->uri,
            'name' => $this->name,
            'domain' => $this->domain,
            'verbs' => $this->verbs,
            'middleware' => $this->middleware,
            'excluded_middleware' => $this->excludedMiddleware,
            'bindings' => $this->bindings,
        ];
    }

    public function identity(): string
    {
        $data = [
            $this->name,
            $this->domain,
            $this->uri,
            $this->verbs,
            $this->constraints,
            $this->class === null ? null : strtolower(ltrim($this->class, '\\')),
            $this->method === null ? null : strtolower($this->method),
            $this->middleware,
            $this->excludedMiddleware,
            $this->bindings,
        ];

        return 'route:'.substr(hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)
            || ! in_array(array_keys($data), [
                ['verbs', 'uri', 'name', 'domain', 'constraints', 'class', 'method', 'unresolved'],
                ['verbs', 'uri', 'name', 'domain', 'constraints', 'class', 'method', 'unresolved', 'middleware', 'excludedMiddleware'],
                ['verbs', 'uri', 'name', 'domain', 'constraints', 'class', 'method', 'unresolved', 'middleware', 'excludedMiddleware', 'bindings'],
            ], true)
            || ! is_array($data['verbs']) || ! array_is_list($data['verbs']) || ! is_string($data['uri']) || ! is_array($data['constraints'])) {
            throw new UnexpectedValueException('Invalid route entry.');
        }
        foreach ($data['verbs'] as $verb) {
            if (! is_string($verb)) {
                throw new UnexpectedValueException('Invalid route verb.');
            }
        }
        foreach ($data['constraints'] as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new UnexpectedValueException('Invalid route constraint.');
            }
        }
        foreach (['name', 'domain', 'class', 'method', 'unresolved'] as $key) {
            if ($data[$key] !== null && ! is_string($data[$key])) {
                throw new UnexpectedValueException('Invalid route field.');
            }
        }

        $data['middleware'] ??= [];
        $data['excludedMiddleware'] ??= [];
        $data['bindings'] ??= [];
        foreach (['middleware', 'excludedMiddleware'] as $key) {
            if (! is_array($data[$key]) || ! array_is_list($data[$key]) || array_filter($data[$key], fn (mixed $value): bool => ! is_string($value)) !== []) {
                throw new UnexpectedValueException('Invalid route middleware.');
            }
        }
        if (! is_array($data['bindings']) || array_filter($data['bindings'], fn (mixed $key, mixed $value): bool => ! is_string($key) || ! is_string($value), ARRAY_FILTER_USE_BOTH) !== []) {
            throw new UnexpectedValueException('Invalid route bindings.');
        }

        return new self(...$data);
    }
}
