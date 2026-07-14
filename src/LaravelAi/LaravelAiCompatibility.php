<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\LaravelAi;

use Composer\Semver\Intervals;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use GracjanKubicki\ArchitectureKit\Composer\ProjectPackage;
use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final readonly class LaravelAiCompatibility
{
    public function __construct(
        private ProjectPackageInventory $inventory,
        private Filesystem $files,
        private string $basePath,
    ) {}

    public function resolve(): LaravelAiCompatibilityResult
    {
        try {
            $package = $this->inventory->package('laravel/ai');
        } catch (Throwable $exception) {
            return $this->result(LaravelAiCompatibilityStatus::Missing, message: $exception->getMessage(), remediation: $this->installRemediation());
        }

        if ($package->section === null || $package->declaredConstraint === null) {
            return $this->fromPackage($package, LaravelAiCompatibilityStatus::Missing, 'Laravel AI is not declared directly in root composer.json.', $this->installRemediation());
        }

        if ($package->section === 'require-dev') {
            return $this->fromPackage(
                $package,
                LaravelAiCompatibilityStatus::RuntimeDependencyInRequireDev,
                'laravel/ai is an application runtime dependency and cannot be installed only in require-dev.',
                'Run composer remove --dev laravel/ai && composer require laravel/ai:"^0.8 || ^0.9".',
            );
        }

        if ($package->lockFilePresent && $package->lockedSection === 'packages-dev') {
            return $this->fromPackage(
                $package,
                LaravelAiCompatibilityStatus::StaleLock,
                'laravel/ai is declared as a runtime dependency, but composer.lock places it in packages-dev.',
                'Run composer update laravel/ai to refresh its runtime lock placement.',
            );
        }

        if ($package->lockFilePresent && $package->lockedSection === null) {
            return $this->fromPackage(
                $package,
                LaravelAiCompatibilityStatus::StaleLock,
                'laravel/ai is declared as a runtime dependency, but is missing from composer.lock.',
                'Run composer update laravel/ai to refresh the lockfile.',
            );
        }

        try {
            $parser = new VersionParser;
            $candidate = $parser->parseConstraints($package->declaredConstraint);
            $supported = $parser->parseConstraints(LaravelAiProfile::supportedUnion());
        } catch (Throwable $exception) {
            return $this->fromPackage($package, LaravelAiCompatibilityStatus::InvalidConstraint, 'Invalid laravel/ai constraint: '.$exception->getMessage(), 'Use ^0.8, ^0.9 or another fully supported constraint.');
        }

        if (! Intervals::isSubsetOf($candidate, $supported)) {
            return $this->fromPackage(
                $package,
                LaravelAiCompatibilityStatus::UnsupportedConstraint,
                'The declared laravel/ai constraint permits unsupported versions. Supported ranges: '.LaravelAiProfile::supportedUnion().'.',
                'Narrow root require.laravel/ai to ^0.8, ^0.9, ^0.8 || ^0.9, or >=0.8 <0.10 and run composer update laravel/ai.',
            );
        }

        if ($package->installedVersion === null) {
            return $this->fromPackage($package, LaravelAiCompatibilityStatus::NotInstalled, 'laravel/ai is declared but is not present in Composer installed metadata.', 'Run composer install or composer update laravel/ai.');
        }

        if (! Semver::satisfies($package->installedVersion, $package->declaredConstraint)) {
            return $this->fromPackage($package, LaravelAiCompatibilityStatus::StaleLock, 'The installed laravel/ai version does not satisfy the root constraint.', 'Run composer update laravel/ai.');
        }

        if ($package->lockedVersion !== null && $package->lockedVersion !== $package->installedVersion) {
            return $this->fromPackage($package, LaravelAiCompatibilityStatus::StaleLock, 'The installed and locked laravel/ai versions do not match.', 'Run composer install or composer update laravel/ai.');
        }

        $profile = LaravelAiProfile::forVersion($package->installedVersion);

        if ($profile === null) {
            return $this->fromPackage($package, LaravelAiCompatibilityStatus::UnsupportedVersion, 'The installed laravel/ai version has no verified Architecture Kit profile.', 'Install a supported laravel/ai 0.8.x or 0.9.x release.');
        }

        $missing = $this->missingCapabilities($profile);

        if ($missing !== []) {
            return new LaravelAiCompatibilityResult(
                status: LaravelAiCompatibilityStatus::MissingCapability,
                section: $package->section,
                declaredConstraint: $package->declaredConstraint,
                installedVersion: $package->installedVersion,
                lockedVersion: $package->lockedVersion,
                profile: $profile,
                missingCapabilities: $missing,
                message: 'The installed laravel/ai package is missing capabilities referenced by Architecture Kit: '.implode(', ', $missing).'.',
                remediation: 'Run composer install/update and verify the installed laravel/ai package contents.',
            );
        }

        return new LaravelAiCompatibilityResult(
            status: LaravelAiCompatibilityStatus::Supported,
            section: $package->section,
            declaredConstraint: $package->declaredConstraint,
            installedVersion: $package->installedVersion,
            lockedVersion: $package->lockedVersion,
            profile: $profile,
            message: 'Laravel AI resolved to '.$profile->key().'.',
        );
    }

    /** @return array<int, string> */
    private function missingCapabilities(LaravelAiProfile $profile): array
    {
        $structured = $this->basePath.'/vendor/laravel/ai/src/Responses/StructuredAgentResponse.php';
        $structuredContents = $this->files->isFile($structured) ? $this->files->get($structured) : '';
        $allSource = '';

        if (in_array('with-provider-options', $profile->requiredCapabilities(), true)) {
            $sourcePath = $this->basePath.'/vendor/laravel/ai/src';

            if ($this->files->isDirectory($sourcePath)) {
                foreach ($this->files->allFiles($sourcePath) as $file) {
                    if ($file->getExtension() === 'php') {
                        $allSource .= $this->files->get($file->getPathname());
                    }
                }
            }
        }

        $available = [
            'structured-response-to-array' => str_contains($structuredContents, 'function toArray'),
            'structured-response-array-access' => str_contains($structuredContents, 'ArrayAccess'),
            'with-provider-options' => str_contains($allSource, 'withProviderOptions'),
        ];

        return array_values(array_filter(
            $profile->requiredCapabilities(),
            fn (string $capability): bool => ! ($available[$capability] ?? false),
        ));
    }

    private function fromPackage(ProjectPackage $package, LaravelAiCompatibilityStatus $status, string $message, string $remediation): LaravelAiCompatibilityResult
    {
        return new LaravelAiCompatibilityResult(
            status: $status,
            section: $package->section,
            declaredConstraint: $package->declaredConstraint,
            installedVersion: $package->installedVersion,
            lockedVersion: $package->lockedVersion,
            message: $message,
            remediation: $remediation,
        );
    }

    private function result(LaravelAiCompatibilityStatus $status, string $message, string $remediation): LaravelAiCompatibilityResult
    {
        return new LaravelAiCompatibilityResult(status: $status, message: $message, remediation: $remediation);
    }

    private function installRemediation(): string
    {
        return 'Run composer require laravel/ai:"^0.8 || ^0.9".';
    }
}
