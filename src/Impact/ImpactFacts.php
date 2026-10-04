<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use UnexpectedValueException;

/** Static facts for impact only. Never added to DependencyEdge. */
final readonly class ImpactFacts
{
    /**
     * @param  array<string, array<string, mixed>>  $classes
     * @param  list<array<string, mixed>>  $calls
     * @param  list<array<string, mixed>>  $notices
     */
    public function __construct(public string $path, public array $classes, public array $calls, public array $notices) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['classes' => $this->classes, 'calls' => $this->calls, 'notices' => $this->notices];
    }

    public static function fromArray(string $path, mixed $data): self
    {
        if (! is_array($data) || ! is_array($data['classes'] ?? null) || ! is_array($data['calls'] ?? null) || ! is_array($data['notices'] ?? null)) {
            throw new UnexpectedValueException('Invalid impact facts.');
        }
        // Validate the complete shape, not just the envelope. Cached input is untrusted.
        foreach ($data['classes'] as $name => $class) {
            if (! is_string($name) || ! is_array($class) || ! is_string($class['kind'] ?? null) || ! is_int($class['line'] ?? null)
                || ! is_bool($class['final'] ?? null) || ! is_array($class['parents'] ?? null) || ! is_array($class['traits'] ?? null)
                || ! is_bool($class['adaptations'] ?? null) || ! is_array($class['properties'] ?? null) || ! is_array($class['methods'] ?? null)) {
                throw new UnexpectedValueException('Invalid impact class.');
            }
            foreach ([...$class['parents'], ...$class['traits'], ...array_values($class['properties'])] as $value) {
                if (! is_string($value)) {
                    throw new UnexpectedValueException('Invalid impact type.');
                }
            }
            foreach ($class['methods'] as $method => $declaration) {
                if (! is_string($method) || ! is_array($declaration) || ! is_string($declaration['name'] ?? null) || ! is_int($declaration['line'] ?? null) || ! is_bool($declaration['final'] ?? null) || ! MethodSignature::valid($declaration['signature'] ?? null)) {
                    throw new UnexpectedValueException('Invalid impact method.');
                }
            }
        }
        foreach ($data['calls'] as $call) {
            if (! is_array($call) || ! is_string($call['from'] ?? null) || ! is_int($call['line'] ?? null) || ! is_string($call['kind'] ?? null)
                || ! array_key_exists('receiver', $call) || (! is_string($call['receiver']) && $call['receiver'] !== null)
                || ! array_key_exists('method', $call) || (! is_string($call['method']) && $call['method'] !== null) || ! is_bool($call['exact'] ?? null) || ! MethodSignature::validSite($call['site'] ?? null)) {
                throw new UnexpectedValueException('Invalid impact call.');
            }
        }
        foreach ($data['notices'] as $notice) {
            if (! is_array($notice) || ! is_int($notice['line'] ?? null) || ! is_string($notice['reason'] ?? null)) {
                throw new UnexpectedValueException('Invalid impact notice.');
            }
        }

        return new self($path, $data['classes'], array_values($data['calls']), array_values($data['notices']));
    }

    public function cacheable(): bool
    {
        foreach ($this->notices as $notice) {
            if ($notice['reason'] === 'Impact memory limit reached during extraction.') {
                return false;
            }
        }

        return true;
    }

    public function estimatedBytes(): int
    {
        return self::size($this->toArray());
    }

    private static function size(mixed $value): int
    {
        if (! is_array($value)) {
            return 128 + (is_string($value) ? strlen($value) : 0);
        }
        $size = 256;
        foreach ($value as $key => $item) {
            $size += 128 + strlen((string) $key) + self::size($item);
        }

        return $size;
    }
}
