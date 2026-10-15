<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Fixtures;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\PortableAuditRule;

final class ForbiddenWorkflowAuditRule implements PortableAuditRule
{
    public function supports(string $path, array $enabled): bool
    {
        return str_ends_with($path, 'ForbiddenWorkflow.php');
    }

    public function check(FileContext $file): array
    {
        return [
            new AuditFinding('error', 'forbidden-workflow-audit-rule', $file->path, 8, 'Forbidden workflow fixture failed.'),
        ];
    }
}
