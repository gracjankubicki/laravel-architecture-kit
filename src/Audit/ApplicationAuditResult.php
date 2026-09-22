<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CacheStatus;

final readonly class ApplicationAuditResult
{
    /**
     * @param  array<int, AuditFinding>  $findings
     * @param  array<int, AuditSuggestion>  $suggestions
     * @param  array<int, AnalysisNotice>  $notices
     */
    public function __construct(
        public string $scope,
        public array $findings,
        public int $suppressedInline = 0,
        public int $suppressedBaseline = 0,
        /** Appended last so existing positional construction keeps its meaning. */
        public CacheStatus $cacheStatus = CacheStatus::Disabled,
        /** Additive channels never affect the enforced error or warning counters. */
        public array $suggestions = [],
        public array $notices = [],
        public string $analysisStatus = 'not_run',
    ) {}

    /**
     * A note about the graph cache, when the run had to rebuild for a reason worth saying.
     *
     * A rebuild still answers correctly, so this never affects the result; it exists
     * because an entry rejected on every run is otherwise indistinguishable from having
     * no cache at all, and the difference is twenty seconds per command.
     */
    public function cacheNote(): ?string
    {
        return $this->cacheStatus->note();
    }

    public function errors(): int
    {
        return count(array_filter(
            $this->findings,
            fn (AuditFinding $finding): bool => $finding->severity === 'error',
        ));
    }

    public function warnings(): int
    {
        return count(array_filter(
            $this->findings,
            fn (AuditFinding $finding): bool => $finding->severity === 'warn',
        ));
    }
}
