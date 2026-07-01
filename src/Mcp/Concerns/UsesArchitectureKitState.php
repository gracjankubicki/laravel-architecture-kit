<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Mcp\Concerns;

use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Support\ApplicationAudit;
use Taqie\ArchitectureKit\Support\ApplicationAuditResult;
use Taqie\ArchitectureKit\Support\ArchitectureConfig;
use Taqie\ArchitectureKit\Support\ArchitectureDoctor;
use Taqie\ArchitectureKit\Support\ArchitectureDoctorResult;
use Taqie\ArchitectureKit\Support\ArchitectureGuard;
use Taqie\ArchitectureKit\Support\ArchitectureGuardResult;
use Taqie\ArchitectureKit\Support\ArchitectureResources;

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
        return new ArchitectureConfig(config_path('architectures.php'), $this->files());
    }

    protected function resources(): ArchitectureResources
    {
        return new ArchitectureResources($this->packagePath(), base_path(), $this->files());
    }

    /**
     * @return array<int, Architecture>
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
     * @return array<int, array{value: string, label: string, skill: string, source: string}>
     */
    protected function architectureSummaries(): array
    {
        return array_map(
            fn (Architecture $architecture): array => [
                'value' => $architecture->value,
                'label' => $architecture->label(),
                'skill' => $architecture->skillName(),
                'source' => $architecture->sourcePath(),
            ],
            $this->enabled(),
        );
    }

    protected function guideline(): string
    {
        return $this->resources()->guideline($this->enabled())->contents;
    }
}
