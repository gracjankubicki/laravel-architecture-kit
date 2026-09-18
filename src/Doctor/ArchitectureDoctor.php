<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Doctor;

use Composer\InstalledVersions;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\CustomRuleSet;
use GracjanKubicki\ArchitectureKit\Audit\RuleRegistry;
use GracjanKubicki\ArchitectureKit\Audit\Suppression\Baseline;
use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Install\ComposeServices;
use GracjanKubicki\ArchitectureKit\Install\Requirements\ArchitectureKitRuntimeRequirement;
use GracjanKubicki\ArchitectureKit\Install\Requirements\FortifyRequirement;
use GracjanKubicki\ArchitectureKit\Install\Requirements\InertiaRequirement;
use GracjanKubicki\ArchitectureKit\Install\Requirements\LaravelAiRequirement;
use GracjanKubicki\ArchitectureKit\Install\Requirements\PhpRequirement;
use GracjanKubicki\ArchitectureKit\Install\Requirements\SaloonRequirement;
use GracjanKubicki\ArchitectureKit\Install\Requirements\ServicesRequirement;
use GracjanKubicki\ArchitectureKit\Install\RuntimeResolver;
use GracjanKubicki\ArchitectureKit\ProjectState;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Resources\GeneratedFile;
use GracjanKubicki\ArchitectureKit\Support\ProjectPath;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Process\Process;
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

    public function run(bool $deep = false, ?ProjectState $state = null): ArchitectureDoctorResult
    {
        $checks = [];
        $enabled = [];
        $laravelAi = $state?->laravelAi;
        $inertia = $state?->inertia;
        $fortify = $state?->fortify;
        $canGenerate = true;

        try {
            $enabled = $state !== null ? $state->enabled : $this->config->read();
            $runtime = $state !== null ? $state->runtime : $this->config->runtime();
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
                laravelAi: $laravelAi,
                inertia: $inertia,
                fortify: $fortify,
            );
        }

        $architectureKitRuntime = (new ArchitectureKitRuntimeRequirement(new ProjectPackageInventory($this->files, $this->basePath)))->check();

        if (! $architectureKitRuntime->satisfied) {
            $checks[] = new ArchitectureDoctorCheck(
                area: 'config',
                status: 'blocked',
                path: 'composer.json',
                message: $architectureKitRuntime->message.' '.$architectureKitRuntime->remediation,
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

        if (in_array(Architecture::LaravelAi, $enabled, true)) {
            $laravelAi ??= LaravelAiRequirement::resolve($this->files, $this->basePath);

            if (! $laravelAi->supported()) {
                $checks[] = new ArchitectureDoctorCheck(
                    area: 'config',
                    status: 'blocked',
                    path: 'composer.json',
                    message: $laravelAi->message.' '.$laravelAi->remediation,
                );
                $canGenerate = false;
            }
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
            $laravelAi ??= LaravelAiRequirement::resolve($this->files, $this->basePath);
            $checks[] = new ArchitectureDoctorCheck(
                area: 'config',
                status: 'warning',
                path: 'composer.json',
                message: 'laravel/ai is declared but Architecture::LaravelAi is not enabled. Detected status: '.$laravelAi->status->value.'.',
            );
        }

        $projectRequiresInertia = InertiaRequirement::projectRequiresInertia($this->files, $this->basePath);

        if (in_array(Architecture::Inertia, $enabled, true)) {
            $inertia ??= InertiaRequirement::resolve($this->files, $this->basePath);

            if (! $inertia->supported()) {
                $checks[] = new ArchitectureDoctorCheck(
                    area: 'config',
                    status: 'blocked',
                    path: 'composer.json',
                    message: $inertia->message.' '.$inertia->remediation,
                );
                $canGenerate = false;
            }
        }

        if (! in_array(Architecture::Inertia, $enabled, true) && $projectRequiresInertia) {
            $inertia ??= InertiaRequirement::resolve($this->files, $this->basePath);
            $checks[] = new ArchitectureDoctorCheck(
                area: 'config',
                status: 'warning',
                path: 'composer.json',
                message: 'inertiajs/inertia-laravel is declared but Architecture::Inertia is not enabled. Detected status: '.$inertia->status->value.'.',
            );
        }

        $projectRequiresFortify = FortifyRequirement::projectRequiresFortify($this->files, $this->basePath);

        if (in_array(Architecture::Fortify, $enabled, true)) {
            $fortify ??= FortifyRequirement::resolve($this->files, $this->basePath);

            if (! $fortify->supported()) {
                $checks[] = new ArchitectureDoctorCheck(
                    area: 'config',
                    status: 'blocked',
                    path: 'composer.json',
                    message: $fortify->message.' '.$fortify->remediation,
                );
                $canGenerate = false;
            }
        }

        if (! in_array(Architecture::Fortify, $enabled, true) && $projectRequiresFortify) {
            $fortify ??= FortifyRequirement::resolve($this->files, $this->basePath);
            $checks[] = new ArchitectureDoctorCheck(
                area: 'config',
                status: 'warning',
                path: 'composer.json',
                message: 'laravel/fortify is declared but Architecture::Fortify is not enabled. Detected status: '.$fortify->status->value.'.',
            );
        }

        if ($canGenerate) {
            try {
                $this->resources->assertSourcesExist($enabled);
            } catch (Throwable $exception) {
                $checks[] = new ArchitectureDoctorCheck(
                    area: 'config',
                    status: 'blocked',
                    path: 'config/architectures.php',
                    message: $exception->getMessage(),
                );
                $canGenerate = false;
            }
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

        foreach ($this->customRuleChecks($enabled, $state?->customRules) as $check) {
            $checks[] = $check;
        }

        foreach ($this->baselineChecks($enabled, $deep, $state?->exclude, $state?->customRules) as $check) {
            $checks[] = $check;
        }

        $expectedSkillNames = array_map(
            fn (Architecture|string $architecture): string => $architecture instanceof Architecture
                ? $architecture->skillName()
                : 'architecture-kit-'.$architecture,
            $enabled,
        );
        $expectedSkillNames = array_values(array_unique([
            ...$expectedSkillNames,
            ...array_map(
                fn (GeneratedFile $file): string => basename(dirname($file->path)),
                $this->resources->upgradeSkills($enabled),
            ),
        ]));

        if ($canGenerate) {
            $skills = $this->resources->skills($enabled);
            $expected = [
                'guideline' => $this->resources->guideline($enabled),
            ];

            foreach ($skills as $key => $skill) {
                $expected['skill:'.$key] = $skill;
            }

            $expectedSkillNames = array_values(array_map(
                fn (GeneratedFile $file): string => basename(dirname($file->path)),
                $skills,
            ));

            foreach ($expected as $file) {
                $checks[] = new ArchitectureDoctorCheck(
                    area: 'generated',
                    status: $this->status($file),
                    path: $this->relative($file->path),
                );
            }
        }

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
            (new AgentConfigDoctor($this->files, $this->basePath, $runtime, $this->console))->checks(),
        );

        return new ArchitectureDoctorResult(
            enabled: $enabled,
            checks: $checks,
            boostInstalled: $this->boostInstalled(),
            laravelAi: $laravelAi,
            inertia: $inertia,
            fortify: $fortify,
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
        return $this->console?->has('boost:update') === true
            || class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('laravel/boost');
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
        try {
            $process = new Process($command, $this->basePath);
            $process->setTimeout(null);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, ArchitectureDoctorCheck>
     */
    private function customRuleChecks(array $enabled, ?CustomRuleSet $customRules): array
    {
        try {
            $ruleSet = $customRules ?? $this->config->customRuleSet();
            (new RuleRegistry($ruleSet->knownRuleClasses()))->customRules();

            return array_map(
                fn (string $slug): ArchitectureDoctorCheck => new ArchitectureDoctorCheck(
                    area: 'config',
                    status: 'warning',
                    path: 'config/architectures.php',
                    message: "Custom audit rules are configured for disabled architecture [{$slug}] and will not run until that architecture is enabled.",
                ),
                $ruleSet->inactiveScopedSlugs($enabled),
            );

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
     * @param  array<int, string>|null  $exclude
     * @return array<int, ArchitectureDoctorCheck>
     */
    private function baselineChecks(array $enabled, bool $deep, ?array $exclude, ?CustomRuleSet $customRules): array
    {
        if (! $this->files->exists($this->basePath.'/.architecture-kit/baseline.json')) {
            return [];
        }

        try {
            $baseline = new Baseline($this->files, $this->basePath);
            $baseline->validate();

            if (! $deep) {
                return [new ArchitectureDoctorCheck(
                    area: 'baseline',
                    status: 'current',
                    path: '.architecture-kit/baseline.json',
                )];
            }

            $audit = (new ApplicationAudit($this->files, $this->basePath))->run(
                enabled: $enabled,
                changedOnly: false,
                exclude: $exclude ?? $this->config->auditExcludes(),
                customRules: $customRules ?? $this->config->customRuleSet(),
                useBaseline: false,
                scope: $this->config->auditScope(),
                missingTestLevel: $this->config->missingTestLevel(),
            );
            $orphaned = $baseline->orphanedCount($audit->findings);
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
        return ProjectPath::relative($this->basePath, $path);
    }
}
