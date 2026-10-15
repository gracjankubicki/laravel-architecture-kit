<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\LaravelAi;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\AnalysisFactsCache;

/** Audit-local successful relationship facts. No syntax or limit failures are retained. */
final class AiRelationshipCache
{
    /** @var array<string, 'agent'|'other'> */
    private array $entries = [];

    public function __construct(private readonly ?AnalysisFactsCache $facts = null) {}

    public function get(string $key): ?string
    {
        return $this->entries[$key] ?? $this->facts?->relationship($key);
    }

    public function put(string $key, string $state): void
    {
        if (count($this->entries) < 10_000 && in_array($state, ['agent', 'other'], true)) {
            $this->entries[$key] = $state;
            $this->facts?->rememberRelationship($key, $state);
        }
    }
}
