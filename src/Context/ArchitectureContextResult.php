<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Context;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CacheStatus;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectSymbol;

final readonly class ArchitectureContextResult
{
    /**
     * @param  array<int, array<string, mixed>>  $dependencies
     * @param  array<int, array<string, mixed>>  $dependents
     * @param  array<int, array<string, mixed>>  $violations
     * @param  array<int, string>  $inspect
     * @param  array<int, string>  $next
     * @param  array<int, array{path: string, coverage: string, via: string|null}>  $tests
     */
    public function __construct(
        public ProjectSymbol $subject,
        public array $dependencies,
        public array $dependents,
        public array $violations,
        public array $inspect,
        public array $next,
        public bool $truncated,
        // Appended, not inserted: an existing positional construction of this result
        // would otherwise start passing tests where inspect is expected.
        public array $tests = [],
        public CacheStatus $cacheStatus = CacheStatus::Disabled,
    ) {}

    /**
     * A note about the graph cache, when this answer cost a rebuild worth reporting.
     *
     * Context is the command an agent runs before touching a symbol, so an entry being
     * rejected on every call is the difference between an answer in under a second and
     * one in twenty, with nothing else to show for it.
     */
    public function cacheNote(): ?string
    {
        return $this->cacheStatus->note();
    }
}
