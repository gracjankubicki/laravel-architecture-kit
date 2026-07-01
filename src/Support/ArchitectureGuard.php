<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Application as ConsoleApplication;

final readonly class ArchitectureGuard
{
    public function __construct(
        private Filesystem $files,
        private string $packagePath,
        private string $basePath,
        private ?ConsoleApplication $console = null,
    ) {
    }

    public function run(bool $changedOnly, ?string $baseRef, bool $strict): ArchitectureGuardResult
    {
        $config = new ArchitectureConfig($this->basePath.'/config/architectures.php', $this->files);
        $resources = new ArchitectureResources($this->packagePath, $this->basePath, $this->files);
        $doctor = (new ArchitectureDoctor($config, $resources, $this->files, $this->basePath, $this->console))->run();
        $audit = null;

        if ($doctor->configOk()) {
            $audit = (new ApplicationAudit($this->files, $this->basePath))->run(
                enabled: $doctor->enabled,
                changedOnly: $changedOnly,
                baseRef: $baseRef,
            );
        }

        return new ArchitectureGuardResult(
            doctor: $doctor,
            audit: $audit,
            strict: $strict,
        );
    }
}
