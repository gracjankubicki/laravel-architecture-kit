<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit;

interface FileCheck
{
    /**
     * @return array<int, AuditFinding>
     */
    public function findings(FileContext $file): array;
}
