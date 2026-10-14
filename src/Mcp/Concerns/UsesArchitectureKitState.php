<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Concerns;

use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Doctor\ArchitectureDoctor;
use GracjanKubicki\ArchitectureKit\Doctor\ArchitectureDoctorResult;
use GracjanKubicki\ArchitectureKit\Guard\ArchitectureGuard;
use GracjanKubicki\ArchitectureKit\Guard\ArchitectureGuardResult;
use GracjanKubicki\ArchitectureKit\ProjectState;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use Illuminate\Filesystem\Filesystem;

trait UsesArchitectureKitState
{
    protected function files(): Filesystem
    {
        return app(Filesystem::class);
    }

    protected function packagePath(): string
    {
        return dirname(__DIR__, 3);
    }

    protected function projectState(): ProjectState
    {
        return ProjectState::load($this->files(), $this->packagePath(), RepositoryContext::root());
    }

    protected function doctor(ProjectState $state): ArchitectureDoctorResult
    {
        return (new ArchitectureDoctor(
            config: $state->config,
            resources: $state->resources,
            files: $this->files(),
            basePath: RepositoryContext::root(),
            console: null,
        ))->run(state: $state);
    }

    protected function audit(ProjectState $state, bool $changedOnly, ?string $baseRef): ApplicationAuditResult
    {
        return (new ApplicationAudit($this->files(), RepositoryContext::root()))->run(
            enabled: $state->enabled,
            changedOnly: $changedOnly,
            baseRef: $baseRef,
            exclude: $state->exclude,
            customRules: $state->customRules,
            scope: $state->auditScope,
            missingTestLevel: $state->missingTestLevel,
            cache: $state->graphCache,
            cacheConfiguration: $state->graphConfiguration(),
        );
    }

    protected function guard(ProjectState $state, bool $changedOnly, ?string $baseRef, bool $strict, bool $includeTarget = false): ArchitectureGuardResult
    {
        return (new ArchitectureGuard(
            files: $this->files(),
            packagePath: $this->packagePath(),
            basePath: RepositoryContext::root(),
            console: null,
        ))->run($changedOnly, $baseRef, $strict, $state, $includeTarget);
    }

    /**
     * @return array<int, array{value: string, label: string, skill: string, source: string, sum: string, rules: array<int, string>}>
     */
    protected function architectureSummaries(ProjectState $state): array
    {
        $state->assertCompatibility();

        $enabled = $state->enabled;
        $ruleSet = $state->customRules;

        return array_map(fn ($architecture): array => [
            'value' => $architecture->slug(),
            'label' => $architecture->label(),
            'skill' => $architecture->skillName(),
            'source' => $architecture->sourcePath(),
            'sum' => $state->resources->summaryFor($architecture, $enabled),
            'rules' => $ruleSet->architectureRuleBasenames($architecture->slug()),
        ], $state->catalog->ordered($enabled));
    }

    protected function guideline(ProjectState $state): string
    {
        $state->assertCompatibility();

        return $state->resources->fullGuideline($state->enabled);
    }
}
