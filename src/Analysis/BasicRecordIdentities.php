<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use InvalidArgumentException;

/** Materializes stable PHP identities from typed native tuples, without source extraction. */
final class BasicRecordIdentities
{
    /** @var array<int, string> */
    private array $resolved = [];

    /** @var array<int, true> */
    private array $resolving = [];

    /** @param array<array-key, mixed> $identities */
    public function __construct(private readonly SourceInput $source, private readonly array $identities, private readonly ?BasicRecordStrings $strings = null)
    {
        if (! array_is_list($identities) || count($identities) > 50000) {
            throw new InvalidArgumentException('Invalid Basic identity table.');
        }
        // Validate all entries, including identities not reached by another record.
        foreach (array_keys($identities) as $index) {
            $this->identity($index);
        }
    }

    public function materialize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 16 || is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Basic records must contain bounded plain values.');
        }
        if (! is_array($value)) {
            return is_string($value) ? ($this->strings?->share($value) ?? $value) : $value;
        }
        if (array_key_exists('$identity', $value)) {
            if (array_keys($value) !== ['$identity'] || ! is_int($value['$identity'])) {
                throw new InvalidArgumentException('Invalid Basic identity reference.');
            }

            return $this->identity($value['$identity']);
        }
        $result = [];
        foreach ($value as $key => $item) {
            $key = is_string($key) ? ($this->strings?->share($key) ?? $key) : $key;
            // Signature and invocation parameters are plain source values, not
            // identity-bearing records. A route parameter may literally be
            // named "$identity" and must keep its string value.
            if ($key === 'parameters') {
                $result[$key] = $item;

                continue;
            }
            $result[$key] = $this->materialize($item, $depth + 1);
        }

        return $result;
    }

    private function identity(int $index): string
    {
        if (isset($this->resolved[$index])) {
            return $this->resolved[$index];
        }
        if (! array_key_exists($index, $this->identities) || isset($this->resolving[$index]) || count($this->resolving) >= 16) {
            throw new InvalidArgumentException('Missing, cyclic or overly deep Basic identity reference.');
        }
        $tuple = $this->identities[$index];
        if (! is_array($tuple) || ! array_is_list($tuple) || ! is_string($tuple[0] ?? null)) {
            throw new InvalidArgumentException('Invalid Basic identity tuple.');
        }
        $this->resolving[$index] = true;
        try {
            $identity = match ($tuple[0]) {
                'element' => $this->element($tuple),
                'resource' => $this->resource($tuple),
                'dispatch' => $this->dispatch($tuple),
                'value' => $this->value($tuple),
                'container' => $this->value($tuple),
                'include' => $this->include($tuple),
                default => throw new InvalidArgumentException('Unknown Basic identity kind.'),
            };

            return $this->resolved[$index] = $identity;
        } finally {
            unset($this->resolving[$index]);
        }
    }

    /** @param list<mixed> $tuple */
    private function element(array $tuple): string
    {
        if (count($tuple) !== 4 || ! is_string($tuple[1]) || $tuple[1] === '' || ! is_string($tuple[2]) || $tuple[2] === ''
            || ! is_int($tuple[3]) || $tuple[3] < 0 || $tuple[3] > strlen($this->source->contents)) {
            throw new InvalidArgumentException('Invalid Basic element identity.');
        }

        return CatalogElement::identity($this->source->path, $tuple[1], $tuple[2], $tuple[3]);
    }

    /** @param list<mixed> $tuple */
    private function resource(array $tuple): string
    {
        if (count($tuple) !== 3 || ! is_string($tuple[1]) || $tuple[1] === '' || ! is_string($tuple[2]) || $tuple[2] === '') {
            throw new InvalidArgumentException('Invalid Basic resource identity.');
        }

        return CatalogElement::resourceIdentity($tuple[1], $tuple[2]);
    }

    /** @param list<mixed> $tuple */
    private function dispatch(array $tuple): string
    {
        if (count($tuple) !== 4 || ! is_string($tuple[1]) || $tuple[1] === '' || ! is_string($tuple[2]) || $tuple[2] === '' || ! is_bool($tuple[3])) {
            throw new InvalidArgumentException('Invalid Basic dispatch identity.');
        }

        return 'dispatch:'.hash('xxh128', serialize([$tuple[1], $tuple[2], $tuple[3]]));
    }

    /** @param list<mixed> $tuple */
    private function value(array $tuple): string
    {
        if (count($tuple) !== 2 || ! is_array($tuple[1])) {
            throw new InvalidArgumentException('Invalid Basic value identity.');
        }

        return $tuple[0].':'.hash('xxh128', serialize($this->materialize($tuple[1])));
    }

    /** @param list<mixed> $tuple */
    private function include(array $tuple): string
    {
        if (count($tuple) !== 2 || ! is_array($tuple[1]) || ! array_is_list($tuple[1]) || $tuple[1] === []) {
            throw new InvalidArgumentException('Invalid Basic include identity.');
        }
        foreach ($tuple[1] as $path) {
            if (! is_string($path) || $path === '' || str_contains($path, "\0")) {
                throw new InvalidArgumentException('Invalid Basic include candidate.');
            }
        }

        return 'include:'.hash('xxh128', serialize($tuple[1]));
    }
}
