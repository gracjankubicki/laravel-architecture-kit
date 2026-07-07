<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\LifecycleAst;

final readonly class ProviderObserverRegistrationCheck implements FileCheck
{
    public function __construct(private LifecycleAst $lifecycle) {}

    public function findings(FileContext $file): array
    {
        if (! str_starts_with($file->path, 'app/Providers/')) {
            return [];
        }

        $findings = [];

        foreach ($this->lifecycle->observerRegistrations($file) as $registration) {
            if ($this->lifecycle->modelHasObservedBy($registration['model'])) {
                continue;
            }

            $findings[] = new AuditFinding(
                'warn',
                'eloquent-lifecycle',
                $file->path,
                $registration['line'],
                'Register observers with #[ObservedBy(...)] on the model instead of hidden Model::observe(...) provider registration.',
            );
        }

        return $findings;
    }
}
