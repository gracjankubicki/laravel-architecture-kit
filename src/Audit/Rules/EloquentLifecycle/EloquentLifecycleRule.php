<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditRule;
use GracjanKubicki\ArchitectureKit\Audit\FileCheck;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\AfterObserverMethodsCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\BeforeObserverMethodsCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\InlineModelEventCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\LifecycleFolderPurityCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\ObserverAfterCommitCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\ObserverBranchingCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\ObserverCallBudgetCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\ProviderObserverRegistrationCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\QuietSaveCheck;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\Checks\TransactionSideEffectCheck;
use Illuminate\Filesystem\Filesystem;

final readonly class EloquentLifecycleRule implements AuditRule
{
    /** @var array<int, FileCheck> */
    private array $checks;

    public function __construct(
        private Filesystem $files,
        private string $basePath,
    ) {
        $lifecycle = new LifecycleAst($this->files, $this->basePath);
        $this->checks = [
            new LifecycleFolderPurityCheck($lifecycle), new BeforeObserverMethodsCheck($lifecycle), new AfterObserverMethodsCheck($lifecycle),
            new ObserverCallBudgetCheck($lifecycle), new ObserverBranchingCheck($lifecycle), new ObserverAfterCommitCheck($lifecycle),
            new QuietSaveCheck($lifecycle), new ProviderObserverRegistrationCheck($lifecycle), new InlineModelEventCheck($lifecycle), new TransactionSideEffectCheck,
        ];
    }

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

        foreach ($this->checks as $check) {
            array_push($findings, ...$check->findings($file));
        }

        return $findings;
    }
}
