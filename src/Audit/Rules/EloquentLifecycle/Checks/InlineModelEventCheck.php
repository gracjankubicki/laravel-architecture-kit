<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks;

use Taqie\ArchitectureKit\Audit\AuditFinding;
use Taqie\ArchitectureKit\Audit\FileCheck;
use Taqie\ArchitectureKit\Audit\FileContext;
use Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\LifecycleAst;

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
