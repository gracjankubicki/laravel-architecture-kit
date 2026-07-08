<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Guard;

use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfigPath;
use GracjanKubicki\ArchitectureKit\Doctor\ArchitectureDoctor;
use GracjanKubicki\ArchitectureKit\Doctor\ArchitectureDoctorResult;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
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

    public function run(bool $changedOnly, ?string $baseRef, bool $strict): ArchitectureGuardResult
    {
        $config = new ArchitectureConfig(ArchitectureConfigPath::resolve($this->files, $this->basePath), $this->files);
        $resources = new ArchitectureResources($this->packagePath, $this->basePath, $this->files);
        $doctor = (new ArchitectureDoctor($config, $resources, $this->files, $this->basePath, $this->console))->run();
        $audit = null;

        if ($this->auditCanRun($doctor)) {
            $audit = (new ApplicationAudit($this->files, $this->basePath))->run(
                enabled: $doctor->enabled,
                changedOnly: $changedOnly,
                baseRef: $baseRef,
                exclude: $config->auditExcludes(),
                customRules: $config->customRuleSet(),
            );
        }

        return new ArchitectureGuardResult(
            doctor: $doctor,
            audit: $audit,
            strict: $strict,
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
