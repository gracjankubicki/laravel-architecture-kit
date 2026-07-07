<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\LifecycleAst;

final readonly class ObserverCallBudgetCheck implements FileCheck
{
    public function __construct(private LifecycleAst $lifecycle) {}

    public function findings(FileContext $file): array
    {
        if (! $this->lifecycle->isObserverPath($file->path)) {
            return [];
        }

        $findings = [];

        foreach ($this->lifecycle->lifecycleMethods($file) as $method) {
            $calls = $this->lifecycle->significantCalls($method);

            if (count($calls) <= 1) {
                continue;
            }

            $findings[] = new AuditFinding(
                'warn',
                'eloquent-lifecycle',
                $file->path,
                $calls[1],
                'Observer methods should call exactly one lifecycle handler or dispatch exactly one named event; do not orchestrate several calls in the observer.',
            );
        }

        return $findings;
    }
}
