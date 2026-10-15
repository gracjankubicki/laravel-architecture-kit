<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Fixtures;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\PortableAuditRule;

final class InvalidFindingAuditRule implements PortableAuditRule
{
    public function supports(string $path, array $enabled): bool
    {
        return str_ends_with($path, 'InvalidFinding.php');
    }

    public function check(FileContext $file): array
    {
        return [new AuditFinding('notice', 'invalid finding', $file->path, 0, '')];
    }
}
