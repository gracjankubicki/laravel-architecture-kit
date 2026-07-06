<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle;

use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Audit\AuditFinding;
use Taqie\ArchitectureKit\Audit\AuditRule;
use Taqie\ArchitectureKit\Audit\FileCheck;
use Taqie\ArchitectureKit\Audit\FileContext;
use Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\AfterObserverMethodsCheck;
use Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\BeforeObserverMethodsCheck;
use Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\InlineModelEventCheck;
use Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\LifecycleFolderPurityCheck;
use Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\ObserverAfterCommitCheck;
use Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\ObserverBranchingCheck;
use Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\ObserverCallBudgetCheck;
use Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\ProviderObserverRegistrationCheck;
use Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\QuietSaveCheck;
use Taqie\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\TransactionSideEffectCheck;

final readonly class EloquentLifecycleRule implements AuditRule
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
    ) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function supports(string $path, array $enabled): bool
    {
        return in_array(Architecture::EloquentLifecycle, $enabled, true);
    }

    /**
     * @return array<int, AuditFinding>
     */
    public function check(FileContext $file): array
    {
        $findings = [];

        foreach ($this->checks() as $check) {
            array_push($findings, ...$check->findings($file));
        }

        return $findings;
    }

    /**
     * @return array<int, FileCheck>
     */
    private function checks(): array
    {
        $lifecycle = new LifecycleAst($this->files, $this->basePath);

        return [
            new LifecycleFolderPurityCheck($lifecycle),
            new BeforeObserverMethodsCheck($lifecycle),
            new AfterObserverMethodsCheck($lifecycle),
            new ObserverCallBudgetCheck($lifecycle),
            new ObserverBranchingCheck($lifecycle),
            new ObserverAfterCommitCheck($lifecycle),
            new QuietSaveCheck($lifecycle),
            new ProviderObserverRegistrationCheck($lifecycle),
            new InlineModelEventCheck($lifecycle),
            new TransactionSideEffectCheck,
        ];
    }
}
