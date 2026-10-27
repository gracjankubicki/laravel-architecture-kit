<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog\Incremental;

/** Per-operation plain version references, including absent names and cache entries. */
final class SemanticReads
{
    /** @var array<string, string> */
    public array $versions = [];

    /** @var array<string, list<string>> */
    public array $lookups = [];

    /** @var array<string, list<string>|null> */
    public array $cacheReads = [];

    /** @var array<string, list<string>> */
    public array $cacheWrites = [];

    public function __construct(private readonly SemanticVersions $state) {}

    public function read(string $key): void
    {
        $this->versions[$key] = $this->state->version($key);
        if (str_starts_with($key, 'name:')) {
            $this->lookups[$key] = $this->state->candidates(substr($key, 5));
        }
    }

    /** @param list<string>|null $value */
    public function cacheRead(string $key, ?array $value): void
    {
        // A recursive call may observe a write from this same operation.
        if (! array_key_exists($key, $this->cacheReads) && ! array_key_exists($key, $this->cacheWrites)) {
            $this->cacheReads[$key] = $value;
        }
    }

    /** @param list<string> $value */
    public function cacheWrite(string $key, array $value): void
    {
        $this->cacheWrites[$key] = $value;
    }
}
