<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

interface AuditRule
{
    /**
     * @param  array<int, mixed>  $enabled
     */
    public function supports(string $path, array $enabled): bool;

    /**
     * @return array<int, AuditFinding>
     */
    public function check(FileContext $file): array;
}
