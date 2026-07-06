<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Suppression;

use Taqie\ArchitectureKit\Audit\AuditFinding;

final readonly class SuppressionResult
{
    /**
     * @param  array<int, AuditFinding>  $findings
     */
    public function __construct(
        public array $findings,
        public int $inline,
        public int $baseline,
    ) {}
}
