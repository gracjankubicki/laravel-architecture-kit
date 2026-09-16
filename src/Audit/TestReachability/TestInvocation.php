<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\TestReachability;

use UnexpectedValueException;

/** Source facts only. They never become architectural dependency edges. */
final readonly class TestInvocation
{
    /** @param array<int|string, string|null> $parameters */
    public function __construct(
        public string $path,
        public int $line,
        public string $kind,
        public ?string $context = null,
        public ?string $verb = null,
        public ?string $uri = null,
        public ?string $route = null,
        public array $parameters = [],
        public ?string $model = null,
        public ?string $reason = null,
        public ?string $dispatch = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        // Explicit fields avoid promoting every readonly object's property table
        // while a large cache is serialized.
        return [
            'line' => $this->line, 'kind' => $this->kind, 'context' => $this->context,
            'verb' => $this->verb, 'uri' => $this->uri, 'route' => $this->route,
            'parameters' => $this->parameters, 'model' => $this->model, 'reason' => $this->reason, 'dispatch' => $this->dispatch,
        ];
    }

    public static function fromArray(string $path, mixed $data): self
    {
        if (! is_array($data) || array_keys($data) !== ['line', 'kind', 'context', 'verb', 'uri', 'route', 'parameters', 'model', 'reason', 'dispatch']
            || ! is_int($data['line']) || $data['line'] < 1 || ! in_array($data['kind'], ['http', 'factory', 'factory-config'], true) || ! is_array($data['parameters'])) {
            throw new UnexpectedValueException('Invalid test invocation.');
        }
        foreach (['context', 'verb', 'uri', 'route', 'model', 'reason', 'dispatch'] as $key) {
            if ($data[$key] !== null && ! is_string($data[$key])) {
                throw new UnexpectedValueException('Invalid invocation field.');
            }
        }
        foreach ($data['parameters'] as $value) {
            if ($value !== null && ! is_string($value)) {
                throw new UnexpectedValueException('Invalid route parameter.');
            }
        }

        return new self($path, ...$data);
    }
}
