<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

interface FileCheck
{
    /**
     * @return array<int, AuditFinding>
     */
    public function findings(FileContext $file): array;
}
