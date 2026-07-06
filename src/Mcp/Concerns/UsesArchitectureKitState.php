<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Mcp\Concerns;

use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Audit\ApplicationAudit;
use Taqie\ArchitectureKit\Audit\ApplicationAuditResult;
use Taqie\ArchitectureKit\Config\ArchitectureConfig;
use Taqie\ArchitectureKit\Config\ArchitectureConfigPath;
use Taqie\ArchitectureKit\Doctor\ArchitectureDoctor;
use Taqie\ArchitectureKit\Doctor\ArchitectureDoctorResult;
use Taqie\ArchitectureKit\Guard\ArchitectureGuard;
use Taqie\ArchitectureKit\Guard\ArchitectureGuardResult;
use Taqie\ArchitectureKit\Resources\ArchitectureResources;

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
            customRules: $this->config()->customRules(),
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
     * @return array<int, array{value: string, label: string, skill: string, source: string, sum: string}>
     */
    protected function architectureSummaries(): array
    {
        $enabled = $this->enabled();

        return array_map(fn ($architecture): array => [
            'value' => $architecture->slug(),
            'label' => $architecture->label(),
            'skill' => $architecture->skillName(),
            'source' => $architecture->sourcePath(),
            'sum' => $this->resources()->summaryFor($architecture, $enabled),
        ], $this->resources()->ordered($enabled));
    }

    protected function guideline(): string
    {
        return $this->resources()->fullGuideline($this->enabled());
    }
}
