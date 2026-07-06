<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit;

final readonly class AuditFinding
{
    public function __construct(
        public string $severity,
        public string $rule,
        public string $path,
        public int $line,
        public string $message,
        public ?int $occurrence = null,
    ) {}

    public function severityRank(): int
    {
        return match ($this->severity) {
            'error' => 0,
            'warn' => 1,
            default => 2,
        };
    }
}
