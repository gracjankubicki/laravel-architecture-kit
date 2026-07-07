<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\LifecycleAst;

final readonly class ObserverAfterCommitCheck implements FileCheck
{
    public function __construct(private LifecycleAst $lifecycle) {}

    public function findings(FileContext $file): array
    {
        if (! $this->lifecycle->isObserverPath($file->path)) {
            return [];
        }

        $hasBeforeMethod = false;

        foreach ($this->lifecycle->lifecycleMethods($file) as $method) {
            $hasBeforeMethod = $hasBeforeMethod || in_array($method->name->toString(), LifecycleAst::BEFORE_METHODS, true);
        }

        $afterCommitLine = $this->lifecycle->afterCommitObserverLine($file);

        if (! $hasBeforeMethod || $afterCommitLine === null) {
            return [];
        }

        return [
            new AuditFinding(
                'warn',
                'eloquent-lifecycle',
                $file->path,
                $afterCommitLine,
                'Do not delay the whole observer with ShouldHandleEventsAfterCommit/$afterCommit when it has before-save methods; apply after-commit at the event/listener/job dispatch level.',
            ),
        ];
    }
}
