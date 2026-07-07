<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Suppression;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;

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
