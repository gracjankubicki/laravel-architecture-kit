<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Guard;

use GracjanKubicki\ArchitectureKit\Analysis\ControlPolicy;
use GracjanKubicki\ArchitectureKit\ArchitectureCatalog;
use GracjanKubicki\ArchitectureKit\Audit\AnalysisNotice;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfigPath;
use GracjanKubicki\ArchitectureKit\Doctor\ArchitectureDoctor;
use GracjanKubicki\ArchitectureKit\Doctor\ArchitectureDoctorCheck;
use GracjanKubicki\ArchitectureKit\Doctor\ArchitectureDoctorResult;
use GracjanKubicki\ArchitectureKit\ProjectState;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\StandaloneAudit;
use GracjanKubicki\ArchitectureKit\Target\ArchitectureTarget;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Application as ConsoleApplication;

final readonly class ArchitectureGuard
{
    public function __construct(
        private Filesystem $files,
        private string $packagePath,
        private string $basePath,
        private ?ConsoleApplication $console = null,
    ) {}

    public function run(bool $changedOnly, ?string $baseRef, bool $strict, ?ProjectState $state = null, bool $includeTarget = false, ?ControlPolicy $policy = null): ArchitectureGuardResult
    {
        try {
            $policy ??= RepositoryContext::forRoot($this->basePath)?->controlPolicy() ?? $state?->controlPolicy;
            $policy ??= (new ArchitectureConfig(ArchitectureConfigPath::resolve($this->files, $this->basePath), $this->files, new ArchitectureCatalog($this->files, $this->basePath)))->controlPolicy();

            return $this->inspect($changedOnly, $baseRef, $strict, $state, $includeTarget, $policy);
        } catch (\Throwable $error) {
            $doctor = new ArchitectureDoctorResult([], [new ArchitectureDoctorCheck('config', 'blocked', 'analysis', $error->getMessage())], false);
            $audit = new ApplicationAuditResult('unavailable', [],
                notices: [new AnalysisNotice('A_ANALYSIS_FAILED', 'analysis_failed', '(project)', 1, $error->getMessage())],
                analysisStatus: 'incomplete', checksComplete: false);

            return new ArchitectureGuardResult($doctor, $audit, $strict, policy: $policy ?? ControlPolicy::Advisory);
        }
    }

    private function inspect(bool $changedOnly, ?string $baseRef, bool $strict, ?ProjectState $state = null, bool $includeTarget = false, ?ControlPolicy $policy = null): ArchitectureGuardResult
    {
        if (($context = RepositoryContext::forRoot($this->basePath)) !== null) {
            return StandaloneAudit::guard($context, $changedOnly, $baseRef, $strict, $includeTarget, $policy);
        }

        try {
            $state ??= ProjectState::load($this->files, $this->packagePath, $this->basePath);
            $config = $state->config;
            $resources = $state->resources;
        } catch (\Throwable) {
            $state = null;
            $catalog = new ArchitectureCatalog($this->files, $this->basePath);
            $config = new ArchitectureConfig(ArchitectureConfigPath::resolve($this->files, $this->basePath), $this->files, $catalog);
            $resources = new ArchitectureResources($this->packagePath, $this->basePath, $this->files, $catalog);
        }
        $doctor = (new ArchitectureDoctor($config, $resources, $this->files, $this->basePath, $this->console))->run(state: $state);
        $audit = null;

        if ($this->auditCanRun($doctor)) {
            $audit = (new ApplicationAudit($this->files, $this->basePath))->run(
                enabled: $doctor->enabled,
                changedOnly: $changedOnly,
                baseRef: $baseRef,
                exclude: $state !== null ? $state->exclude : $config->auditExcludes(),
                customRules: $state !== null ? $state->customRules : $config->customRuleSet(),
                scope: $state !== null ? $state->auditScope : $config->auditScope(),
                missingTestLevel: $state !== null ? $state->missingTestLevel : $config->missingTestLevel(),
                cache: $state !== null ? $state->graphCache : $config->graphCache(),
                cacheConfiguration: $state !== null
                    ? $state->graphConfiguration()
                    : ProjectState::graphConfigurationFor($doctor->enabled, $config->customRuleSet()),
            );
        }

        return new ArchitectureGuardResult(
            doctor: $doctor,
            audit: $audit,
            strict: $strict,
            target: $includeTarget ? (new ArchitectureTarget($this->basePath))->inspect() : null,
            blockIncompleteAnalysis: $config->blocksIncompleteAnalysis(),
            policy: $policy ?? $config->controlPolicy(),
        );
    }

    private function auditCanRun(ArchitectureDoctorResult $doctor): bool
    {
        if (! $doctor->configOk()) {
            return false;
        }

        foreach ($doctor->checks as $check) {
            if ($check->area === 'baseline' && $check->failed()) {
                return false;
            }
        }

        return true;
    }
}
