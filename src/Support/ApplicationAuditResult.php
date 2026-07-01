<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

final readonly class ApplicationAuditResult
{
    /**
     * @param  array<int, AuditFinding>  $findings
     */
    public function __construct(
        public string $scope,
        public array $findings,
    ) {
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
