<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Concerns;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfigPath;
use GracjanKubicki\ArchitectureKit\Doctor\ArchitectureDoctor;
use GracjanKubicki\ArchitectureKit\Doctor\ArchitectureDoctorResult;
use GracjanKubicki\ArchitectureKit\Guard\ArchitectureGuard;
use GracjanKubicki\ArchitectureKit\Guard\ArchitectureGuardResult;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
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

    protected function config(): ArchitectureConfig
    {
        return new ArchitectureConfig(ArchitectureConfigPath::resolve($this->files(), base_path()), $this->files());
    }

    protected function resources(): ArchitectureResources
    {
        return new ArchitectureResources($this->packagePath(), base_path(), $this->files());
    }

    /**
     * @return array<int, Architecture|string>
     */
    protected function enabled(): array
    {
        return $this->config()->read();
    }

    protected function doctor(): ArchitectureDoctorResult
    {
        return (new ArchitectureDoctor(
            config: $this->config(),
            resources: $this->resources(),
            files: $this->files(),
            basePath: base_path(),
            console: null,
        ))->run();
    }

    protected function audit(bool $changedOnly, ?string $baseRef): ApplicationAuditResult
    {
        return (new ApplicationAudit($this->files(), base_path()))->run(
            enabled: $this->enabled(),
            changedOnly: $changedOnly,
            baseRef: $baseRef,
            exclude: $this->config()->auditExcludes(),
            customRules: $this->config()->customRuleSet(),
        );
    }

    protected function guard(bool $changedOnly, ?string $baseRef, bool $strict): ArchitectureGuardResult
    {
        return (new ArchitectureGuard(
            files: $this->files(),
            packagePath: $this->packagePath(),
            basePath: base_path(),
            console: null,
        ))->run($changedOnly, $baseRef, $strict);
    }

    /**
     * @return array<int, array{value: string, label: string, skill: string, source: string, sum: string, rules: array<int, string>}>
     */
    protected function architectureSummaries(): array
    {
        $enabled = $this->enabled();
        $ruleSet = $this->config()->customRuleSet();

        return array_map(fn ($architecture): array => [
            'value' => $architecture->slug(),
            'label' => $architecture->label(),
            'skill' => $architecture->skillName(),
            'source' => $architecture->sourcePath(),
            'sum' => $this->resources()->summaryFor($architecture, $enabled),
            'rules' => $ruleSet->architectureRuleBasenames($architecture->slug()),
        ], $this->resources()->ordered($enabled));
    }

    protected function guideline(): string
    {
        return $this->resources()->fullGuideline($this->enabled());
    }
}
