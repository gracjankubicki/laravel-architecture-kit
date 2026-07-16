<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Planning;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\ArchitectureCatalog;
use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfigPath;
use GracjanKubicki\ArchitectureKit\EnabledArchitecture;
use GracjanKubicki\ArchitectureKit\Install\Requirements\ArchitectureKitRuntimeRequirement;
use GracjanKubicki\ArchitectureKit\Install\Requirements\LaravelAiRequirement;
use GracjanKubicki\ArchitectureKit\Install\Requirements\PhpRequirement;
use GracjanKubicki\ArchitectureKit\Install\Requirements\SaloonRequirement;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResourceManifest;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Resources\GeneratedFile;
use GracjanKubicki\ArchitectureKit\Resources\ManagedResourceDeployment;
use GracjanKubicki\ArchitectureKit\Resources\ManagedResourcePlan;
use Illuminate\Filesystem\Filesystem;

final readonly class ArchitecturePlanner
{
    public function __construct(
        private Filesystem $files,
        private string $packagePath,
        private string $basePath,
    ) {}

    public function plan(): ArchitecturePlan
    {
        $catalog = new ArchitectureCatalog($this->files, $this->basePath);
        $configPath = ArchitectureConfigPath::resolve($this->files, $this->basePath);
        $config = new ArchitectureConfig($configPath, $this->files, $catalog);
        $configured = $this->files->exists($configPath);
        $enabled = $configured ? $config->read() : $this->detected($catalog);
        $recommendations = $this->recommendations($catalog, $enabled, $configured);

        return new ArchitecturePlan(
            configured: $configured,
            recommendations: $recommendations,
            requirements: $this->requirements($enabled),
            changes: $this->changes($catalog, $config, $enabled),
        );
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, array{name: string, satisfied: bool, message: string, remediation: string}>
     */
    private function requirements(array $enabled): array
    {
        $runtime = (new ArchitectureKitRuntimeRequirement(
            new ProjectPackageInventory($this->files, $this->basePath),
        ))->check();

        $requirements = [[
            'name' => 'architecture-kit-runtime',
            'satisfied' => $runtime->satisfied,
            'message' => $runtime->message,
            'remediation' => $runtime->remediation,
        ]];

        if (in_array(Architecture::Saloon, $enabled, true)) {
            $violations = SaloonRequirement::violations($this->files, $this->basePath);
            $requirements[] = [
                'name' => 'saloon',
                'satisfied' => $violations === [],
                'message' => $violations === [] ? 'Saloon requirements are satisfied.' : implode(' ', $violations),
                'remediation' => $violations === [] ? '' : 'Install saloonphp/saloon ^4.0, saloonphp/laravel-plugin, and saloonphp/rate-limit-plugin.',
            ];
        }

        if (in_array(Architecture::ModernPhp85, $enabled, true)) {
            $satisfied = PhpRequirement::projectRequiresPhp85($this->files, $this->basePath);
            $requirements[] = [
                'name' => 'modern-php-85',
                'satisfied' => $satisfied,
                'message' => $satisfied ? 'composer.json requires PHP 8.5 or newer.' : 'composer.json does not require PHP 8.5 or newer.',
                'remediation' => $satisfied ? '' : 'Update composer.json require.php to a PHP 8.5+ constraint.',
            ];
        }

        if (in_array(Architecture::LaravelAi, $enabled, true)) {
            $laravelAi = LaravelAiRequirement::resolve($this->files, $this->basePath);
            $requirements[] = [
                'name' => 'laravel-ai',
                'satisfied' => $laravelAi->supported(),
                'message' => $laravelAi->message,
                'remediation' => $laravelAi->remediation,
            ];
        }

        return $requirements;
    }

    /** @return array<int, Architecture|string> */
    private function detected(ArchitectureCatalog $catalog): array
    {
        $detected = [];

        foreach ($catalog->known() as $architecture) {
            if ($this->evidence($architecture) !== []) {
                $detected[] = $architecture->value;
            }
        }

        return $detected;
    }

    /** @param array<int, Architecture|string> $enabled @return array<int, ArchitectureRecommendation> */
    private function recommendations(ArchitectureCatalog $catalog, array $enabled, bool $configured): array
    {
        return array_map(function (EnabledArchitecture $architecture) use ($configured): ArchitectureRecommendation {
            $evidence = $configured
                ? ['config/architectures.php']
                : $this->evidence($architecture);

            return new ArchitectureRecommendation(
                slug: $architecture->slug(),
                label: $architecture->label(),
                confidence: 'high',
                evidence: $evidence,
                configured: $configured,
            );
        }, $catalog->ordered($enabled));
    }

    /** @return array<int, string> */
    private function evidence(EnabledArchitecture $architecture): array
    {
        if (is_string($architecture->value)) {
            $path = '.architecture-kit/architectures/'.$architecture->value.'/guideline.md';

            return $this->files->isFile($this->basePath.'/'.$path) ? [$path] : [];
        }

        if ($architecture->value === Architecture::Saloon) {
            return $this->packageEvidence('saloonphp/saloon');
        }

        if ($architecture->value === Architecture::LaravelAi) {
            return $this->packageEvidence('laravel/ai');
        }

        if ($architecture->value === Architecture::ModernPhp85) {
            return PhpRequirement::projectRequiresPhp85($this->files, $this->basePath)
                ? ['composer.json:require.php']
                : [];
        }

        if ($architecture->value === Architecture::LaravelBestPractices) {
            return $this->packageEvidence('laravel/framework');
        }

        if ($architecture->value === Architecture::Enums) {
            return $this->enumEvidence();
        }

        $placement = $architecture->defaultPlacement();

        if ($placement === null) {
            return [];
        }

        $evidence = [];

        foreach (array_map('trim', explode(',', $placement)) as $relativeDirectory) {
            $directory = $this->basePath.'/'.$relativeDirectory;

            if (! $this->files->isDirectory($directory)) {
                continue;
            }

            foreach ($this->files->allFiles($directory) as $file) {
                if ($file->getExtension() === 'php') {
                    $evidence[] = str_replace($this->basePath.'/', '', $file->getPathname());
                }
            }
        }

        sort($evidence);

        return $evidence;
    }

    /** @return array<int, string> */
    private function packageEvidence(string $package): array
    {
        try {
            $section = (new ProjectPackageInventory($this->files, $this->basePath))->package($package)->section;
        } catch (\Throwable) {
            return [];
        }

        return $section === null ? [] : ["composer.json:{$section}.{$package}"];
    }

    /** @return array<int, string> */
    private function enumEvidence(): array
    {
        $app = $this->basePath.'/app';

        if (! $this->files->isDirectory($app)) {
            return [];
        }

        $evidence = [];

        foreach ($this->files->allFiles($app) as $file) {
            if ($file->getExtension() === 'php' && preg_match('/\benum\s+[A-Za-z_]/', $this->files->get($file->getPathname())) === 1) {
                $evidence[] = str_replace($this->basePath.'/', '', $file->getPathname());
            }
        }

        sort($evidence);

        return $evidence;
    }

    /** @param array<int, Architecture|string> $enabled */
    private function changes(ArchitectureCatalog $catalog, ArchitectureConfig $config, array $enabled): ManagedResourcePlan
    {
        if ($enabled === []) {
            return new ManagedResourcePlan;
        }

        $laravelAi = in_array(Architecture::LaravelAi, $enabled, true)
            ? LaravelAiRequirement::resolve($this->files, $this->basePath)
            : null;

        if ($laravelAi !== null && ! $laravelAi->supported()) {
            return new ManagedResourcePlan(blocked: ['requirements:laravel-ai']);
        }

        $resources = new ArchitectureResources($this->packagePath, $this->basePath, $this->files, $catalog, $laravelAi);
        $expected = [
            'config' => new GeneratedFile(
                path: $this->basePath.'/config/architectures.php',
                contents: $config->render($enabled, $config->runtime()),
            ),
            ...(new ArchitectureResourceManifest($resources))->expected($enabled),
        ];
        $deployment = new ManagedResourceDeployment($this->files, $resources);

        return $this->relativePlan($deployment->plan(
            $expected,
            (new ArchitectureResourceManifest($resources))->stale($enabled),
            ['config'],
        ));
    }

    private function relativePlan(ManagedResourcePlan $plan): ManagedResourcePlan
    {
        return new ManagedResourcePlan(
            create: array_map($this->relative(...), $plan->create),
            update: array_map($this->relative(...), $plan->update),
            remove: array_map($this->relative(...), $plan->remove),
            blocked: array_map($this->relative(...), $plan->blocked),
        );
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, $this->basePath.'/')
            ? substr($path, strlen($this->basePath) + 1)
            : $path;
    }
}
