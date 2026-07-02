<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Application as ConsoleApplication;
use Throwable;
use Taqie\ArchitectureKit\Architecture;

final readonly class ArchitectureDoctor
{
    public function __construct(
        private ArchitectureConfig $config,
        private ArchitectureResources $resources,
        private Filesystem $files,
        private string $basePath,
        private ?ConsoleApplication $console = null,
    ) {
    }

    public function run(): ArchitectureDoctorResult
    {
        $checks = [];
        $enabled = [];

        try {
            $enabled = $this->config->read();
            $this->resources->assertSourcesExist($enabled);
            $checks[] = new ArchitectureDoctorCheck('config', 'current', 'config/architectures.php');
        } catch (Throwable $exception) {
            $checks[] = new ArchitectureDoctorCheck(
                area: 'config',
                status: 'blocked',
                path: 'config/architectures.php',
                message: $exception->getMessage(),
            );

            return new ArchitectureDoctorResult(
                enabled: [],
                checks: $checks,
                boostInstalled: $this->boostInstalled(),
            );
        }

        if (
            in_array(Architecture::ModernPhp85, $enabled, true)
            && ! PhpRequirement::projectRequiresPhp85($this->files, $this->basePath)
        ) {
            $checks[] = new ArchitectureDoctorCheck(
                area: 'config',
                status: 'blocked',
                path: 'composer.json',
                message: 'Modern PHP 8.5 is enabled but composer.json does not require PHP 8.5 or newer.',
            );
        }

        $projectRequiresLaravelAi = LaravelAiRequirement::projectRequiresLaravelAi($this->files, $this->basePath);

        if (
            in_array(Architecture::LaravelAi, $enabled, true)
            && ! $projectRequiresLaravelAi
        ) {
            $checks[] = new ArchitectureDoctorCheck(
                area: 'config',
                status: 'blocked',
                path: 'composer.json',
                message: 'Laravel AI is enabled but composer.json does not require laravel/ai.',
            );
        }

        if (in_array(Architecture::Saloon, $enabled, true)) {
            foreach (SaloonRequirement::violations($this->files, $this->basePath) as $violation) {
                $checks[] = new ArchitectureDoctorCheck(
                    area: 'config',
                    status: 'blocked',
                    path: 'composer.json',
                    message: $violation,
                );
            }
        }

        if (
            ! in_array(Architecture::LaravelAi, $enabled, true)
            && $projectRequiresLaravelAi
        ) {
            $checks[] = new ArchitectureDoctorCheck(
                area: 'config',
                status: 'warning',
                path: 'composer.json',
                message: 'laravel/ai is installed but Architecture::LaravelAi is not enabled.',
            );
        }

        if (
            ! in_array(Architecture::Services, $enabled, true)
            && ServicesRequirement::projectHasServices($this->files, $this->basePath)
        ) {
            $checks[] = new ArchitectureDoctorCheck(
                area: 'config',
                status: 'warning',
                path: 'app/Services',
                message: 'app/Services exists but Architecture::Services is not enabled. Enable Services or migrate those classes to enabled architecture patterns.',
            );
        }

        $expected = [
            'guideline' => $this->resources->guideline($enabled),
        ];

        foreach ($this->resources->skills($enabled) as $key => $skill) {
            $expected['skill:'.$key] = $skill;
        }

        foreach ($expected as $file) {
            $checks[] = new ArchitectureDoctorCheck(
                area: 'generated',
                status: $this->status($file),
                path: $this->relative($file->path),
            );
        }

        $expectedSkillNames = array_map(
            fn (Architecture $architecture): string => $architecture->skillName(),
            $enabled,
        );

        foreach ($this->resources->existingGeneratedSkillPaths() as $name => $path) {
            if (in_array($name, $expectedSkillNames, true)) {
                continue;
            }

            $checks[] = new ArchitectureDoctorCheck(
                area: 'generated',
                status: 'stale',
                path: $this->relative(dirname($path)),
            );
        }

        $checks = array_merge(
            $checks,
            (new AgentConfigDoctor($this->files, $this->basePath, $this->console))->checks(),
        );

        return new ArchitectureDoctorResult(
            enabled: $enabled,
            checks: $checks,
            boostInstalled: $this->boostInstalled(),
        );
    }

    private function status(GeneratedFile $file): string
    {
        if (! $this->files->exists($file->path)) {
            return 'missing';
        }

        if ($this->files->get($file->path) === $file->contents) {
            return 'current';
        }

        if (! $this->resources->isGenerated($file->path)) {
            return 'blocked';
        }

        return 'outdated';
    }

    private function boostInstalled(): bool
    {
        return $this->console?->has('boost:update') === true;
    }

    private function relative(string $path): string
    {
        return str_replace($this->basePath.'/', '', $path);
    }
}
