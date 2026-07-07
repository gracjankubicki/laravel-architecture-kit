<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\LifecycleAst;

final readonly class QuietSaveCheck implements FileCheck
{
    public function __construct(private LifecycleAst $lifecycle) {}

    public function findings(FileContext $file): array
    {
        if (str_starts_with($file->path, 'tests/') || str_contains($file->path, '/Tests/')) {
            return [];
        }

        $line = $this->lifecycle->firstQuietSaveLine($file);

        if ($line === null) {
            return [];
        }

        return [
            new AuditFinding(
                'warn',
                'eloquent-lifecycle',
                $file->path,
                $line,
                'Quiet saves/withoutEvents suggest observers carry behavior callers need to opt out of; move that behavior to an explicit Action or named event.',
            ),
        ];
    }
}
