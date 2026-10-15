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
     * @param  array<string, mixed>  $classification
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
        public array $classification = [],
        /** Set only after standalone input hashes match at both ends of the audit. */
        public ?string $sourceFingerprint = null,
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

    public function incomplete(): bool
    {
        return $this->analysisStatus === 'incomplete' || $this->notices !== []
            || count(array_filter($this->findings, static fn (AuditFinding $finding): bool => $finding->isIncompleteAnalysis())) > 0;
    }

    /** A failed explicitly requested route process is not a partial source result. */
    public function hasAnalysisFailure(): bool
    {
        foreach ($this->notices as $notice) {
            if ($notice->code === 'A_ROUTE_CONTEXT_UNAVAILABLE' && $notice->reason === 'runtime_routes_unavailable') {
                return true;
            }
        }

        return false;
    }

    /** Analysis-only warnings remain reported but do not enforce architecture. */
    public function architectureWarnings(): int
    {
        return count(array_filter($this->findings, static fn (AuditFinding $finding): bool => $finding->severity === 'warn' && ! $finding->isIncompleteAnalysis()));
    }

    public function warnings(): int
    {
        return count(array_filter(
            $this->findings,
            fn (AuditFinding $finding): bool => $finding->severity === 'warn',
        ));
    }
}
