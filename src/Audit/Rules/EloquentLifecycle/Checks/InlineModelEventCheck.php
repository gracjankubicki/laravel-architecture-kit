<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\LifecycleAst;

final readonly class InlineModelEventCheck implements FileCheck
{
    public function __construct(private LifecycleAst $lifecycle) {}

    public function findings(FileContext $file): array
    {
        if (! str_starts_with($file->path, 'app/Models/')) {
            return [];
        }

        if ($this->lifecycle->containsTraitDeclaration($file)) {
            return [];
        }

        $line = $this->lifecycle->inlineModelEventLine($file);

        if ($line === null) {
            return [];
        }

        return [
            new AuditFinding(
                'error',
                'eloquent-lifecycle',
                $file->path,
                $line,
                'Concrete models must not register inline lifecycle closures; use #[ObservedBy] + observer/handler or $dispatchesEvents.',
            ),
        ];
    }
}
