<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Doctor;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Application as ConsoleApplication;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Audit\ApplicationAudit;
use Taqie\ArchitectureKit\Audit\RuleRegistry;
use Taqie\ArchitectureKit\Audit\Suppression\Baseline;
use Taqie\ArchitectureKit\Config\ArchitectureConfig;
use Taqie\ArchitectureKit\Install\ComposeServices;
use Taqie\ArchitectureKit\Install\Requirements\LaravelAiRequirement;
use Taqie\ArchitectureKit\Install\Requirements\PhpRequirement;
use Taqie\ArchitectureKit\Install\Requirements\SaloonRequirement;
use Taqie\ArchitectureKit\Install\Requirements\ServicesRequirement;
use Taqie\ArchitectureKit\Install\RuntimeResolver;
use Taqie\ArchitectureKit\Resources\ArchitectureResources;
use Taqie\ArchitectureKit\Resources\GeneratedFile;
use Throwable;

final readonly class ArchitectureDoctor
{
    public function __construct(
        private ArchitectureConfig $config,
        private ArchitectureResources $resources,
        private Filesystem $files,
        private string $basePath,
        private ?ConsoleApplication $console = null,
    ) {}

    public function run(): ArchitectureDoctorResult
    {
        $checks = [];
        $enabled = [];

        try {
            $enabled = $this->config->read();
            $runtime = $this->config->runtime();
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

        if (
            in_array(Architecture::PortsAndAdapters, $enabled, true)
            && ! in_array(Architecture::DataObjects, $enabled, true)
        ) {
            $checks[] = new ArchitectureDoctorCheck(
                area: 'config',
                status: 'warning',
                path: 'config/architectures.php',
                message: 'Ports And Adapters works best with Data Objects enabled because Port boundaries should use typed project-owned payloads instead of raw arrays.',
            );
        }

        foreach ($this->runtimeChecks($runtime) as $check) {
            $checks[] = $check;
        }

        foreach ($this->boostSkillChecks($enabled) as $check) {
            $checks[] = $check;
        }

        foreach ($this->customRuleChecks() as $check) {
            $checks[] = $check;
        }

        foreach ($this->baselineChecks($enabled) as $check) {
            $checks[] = $check;
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
            fn (Architecture|string $architecture): string => $architecture instanceof Architecture
                ? $architecture->skillName()
                : 'architecture-kit-'.$architecture,
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

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, ArchitectureDoctorCheck>
     */
    private function boostSkillChecks(array $enabled): array
    {
        if (
            ! in_array(Architecture::LaravelBestPractices, $enabled, true)
            || ! $this->files->isDirectory($this->basePath.'/.ai/skills/laravel-best-practices')
        ) {
            return [];
        }

        return [
            new ArchitectureDoctorCheck(
                area: 'boost',
                status: 'warning',
                path: '.ai/skills/laravel-best-practices',
                message: 'Generic Laravel Boost laravel-best-practices skill is present. Disable or remove it so architecture-kit-laravel-best-practices is the authoritative Laravel baseline.',
            ),
        ];
    }

    /**
     * @param  array{driver: string, service: string|null, php: string, command: array<int, string>|null}  $runtime
     * @return array<int, ArchitectureDoctorCheck>
     */
    private function runtimeChecks(array $runtime): array
    {
        $checks = [];
        $compose = new ComposeServices($this->files, $this->basePath);

        if ($runtime['driver'] === 'docker' && $compose->composePath() === null) {
            $checks[] = new ArchitectureDoctorCheck(
                area: 'runtime',
                status: 'warning',
                path: 'compose.yaml',
                message: 'Runtime driver is docker but no compose.yaml, compose.yml, docker-compose.yml, or docker-compose.yaml file was found.',
            );
        }

        if ($runtime['driver'] === 'sail' && ! $this->files->exists($this->basePath.'/vendor/bin/sail')) {
            $checks[] = new ArchitectureDoctorCheck(
                area: 'runtime',
                status: 'warning',
                path: 'vendor/bin/sail',
                message: 'Runtime driver is sail but vendor/bin/sail was not found.',
            );
        }

        $resolver = new RuntimeResolver($runtime);

        if (! $this->commandSucceeds($resolver->gitCommand())) {
            $checks[] = new ArchitectureDoctorCheck(
                area: 'runtime',
                status: 'warning',
                path: 'git',
                message: 'git is unavailable from the configured Architecture Kit runtime. Changed-file hooks may fall back to a full app scan.',
            );
        }

        return $checks;
    }

    /**
     * @param  array<int, string>  $command
     */
    private function commandSucceeds(array $command): bool
    {
        $output = [];
        $status = 1;
        @exec(implode(' ', array_map(escapeshellarg(...), $command)).' 2>/dev/null', $output, $status);

        return $status === 0;
    }

    /**
     * @return array<int, ArchitectureDoctorCheck>
     */
    private function customRuleChecks(): array
    {
        try {
            (new RuleRegistry($this->config->customRules()))->customRules();

            return [];
        } catch (Throwable $exception) {
            return [
                new ArchitectureDoctorCheck(
                    area: 'config',
                    status: 'blocked',
                    path: 'config/architectures.php',
                    message: $exception->getMessage(),
                ),
            ];
        }
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, ArchitectureDoctorCheck>
     */
    private function baselineChecks(array $enabled): array
    {
        if (! $this->files->exists($this->basePath.'/.architecture-kit/baseline.json')) {
            return [];
        }

        try {
            $audit = (new ApplicationAudit($this->files, $this->basePath))->run(
                enabled: $enabled,
                changedOnly: false,
                exclude: $this->config->auditExcludes(),
                customRules: $this->config->customRules(),
                useBaseline: false,
            );
            $orphaned = (new Baseline($this->files, $this->basePath))->orphanedCount($audit->findings);
        } catch (Throwable $exception) {
            return [
                new ArchitectureDoctorCheck(
                    area: 'baseline',
                    status: 'blocked',
                    path: '.architecture-kit/baseline.json',
                    message: $exception->getMessage(),
                ),
            ];
        }

        if ($orphaned === 0) {
            return [
                new ArchitectureDoctorCheck(
                    area: 'baseline',
                    status: 'current',
                    path: '.architecture-kit/baseline.json',
                ),
            ];
        }

        return [
            new ArchitectureDoctorCheck(
                area: 'baseline',
                status: 'warning',
                path: '.architecture-kit/baseline.json',
                message: "{$orphaned} baseline entr".($orphaned === 1 ? 'y is' : 'ies are').' orphaned. Run php artisan architecture-kit:audit --update-baseline.',
            ),
        ];
    }

    private function relative(string $path): string
    {
        return str_replace($this->basePath.'/', '', $path);
    }
}
