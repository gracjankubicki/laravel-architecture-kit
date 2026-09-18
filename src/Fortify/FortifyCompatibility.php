<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Fortify;

use Composer\Semver\Intervals;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use GracjanKubicki\ArchitectureKit\Composer\ProjectPackage;
use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use Throwable;

final readonly class FortifyCompatibility
{
    public const SUPPORTED_CONSTRAINT = '>=1.0.0 <2.0.0';

    public function __construct(private ProjectPackageInventory $inventory) {}

    public function resolve(): FortifyCompatibilityResult
    {
        try {
            $package = $this->inventory->package('laravel/fortify');
        } catch (Throwable $exception) {
            return $this->result(
                FortifyCompatibilityStatus::Missing,
                $exception->getMessage(),
                $this->installRemediation(),
            );
        }

        if ($package->section === null || $package->declaredConstraint === null) {
            return $this->fromPackage(
                $package,
                FortifyCompatibilityStatus::Missing,
                'Laravel Fortify is not declared directly in root composer.json.',
                $this->installRemediation(),
            );
        }

        if ($package->section === 'require-dev') {
            return $this->fromPackage(
                $package,
                FortifyCompatibilityStatus::RuntimeDependencyInRequireDev,
                'laravel/fortify is an application runtime dependency and cannot be installed only in require-dev.',
                'Run composer remove --dev laravel/fortify && composer require laravel/fortify:"^1.0".',
            );
        }

        if ($package->lockFilePresent && $package->lockedSection === 'packages-dev') {
            return $this->fromPackage(
                $package,
                FortifyCompatibilityStatus::StaleLock,
                'laravel/fortify is declared as a runtime dependency, but composer.lock places it in packages-dev.',
                'Run composer update laravel/fortify to refresh its runtime lock placement.',
            );
        }

        if ($package->lockFilePresent && $package->lockedSection === null) {
            return $this->fromPackage(
                $package,
                FortifyCompatibilityStatus::StaleLock,
                'laravel/fortify is declared as a runtime dependency, but is missing from composer.lock.',
                'Run composer update laravel/fortify to refresh the lockfile.',
            );
        }

        try {
            $parser = new VersionParser;
            $candidate = $parser->parseConstraints($package->declaredConstraint);
            $supported = $parser->parseConstraints(self::SUPPORTED_CONSTRAINT);
        } catch (Throwable $exception) {
            return $this->fromPackage(
                $package,
                FortifyCompatibilityStatus::InvalidConstraint,
                'Invalid laravel/fortify constraint: '.$exception->getMessage(),
                'Use ^1.0 or another constraint contained within >=1.0 <2.0.',
            );
        }

        if (! Intervals::isSubsetOf($candidate, $supported)) {
            return $this->fromPackage(
                $package,
                FortifyCompatibilityStatus::UnsupportedConstraint,
                'The declared laravel/fortify constraint permits unsupported versions. Supported range: '.self::SUPPORTED_CONSTRAINT.'.',
                'Narrow root require.laravel/fortify to ^1.0 or another constraint contained within >=1.0 <2.0, then run composer update laravel/fortify.',
            );
        }

        if ($package->installedVersion === null) {
            return $this->fromPackage(
                $package,
                FortifyCompatibilityStatus::NotInstalled,
                'laravel/fortify is declared but is not present in Composer installed metadata.',
                'Run composer install or composer update laravel/fortify.',
            );
        }

        try {
            if (! Semver::satisfies($package->installedVersion, $package->declaredConstraint)) {
                return $this->fromPackage(
                    $package,
                    FortifyCompatibilityStatus::StaleLock,
                    'The installed laravel/fortify version does not satisfy the root constraint.',
                    'Run composer update laravel/fortify.',
                );
            }

            if ($package->lockedVersion !== null) {
                Semver::satisfies($package->lockedVersion, self::SUPPORTED_CONSTRAINT);

                if ($package->lockedVersion !== $package->installedVersion) {
                    return $this->fromPackage(
                        $package,
                        FortifyCompatibilityStatus::StaleLock,
                        'The installed and locked laravel/fortify versions do not match.',
                        'Run composer install or composer update laravel/fortify.',
                    );
                }
            }

            if (! Semver::satisfies($package->installedVersion, self::SUPPORTED_CONSTRAINT)) {
                return $this->fromPackage(
                    $package,
                    FortifyCompatibilityStatus::UnsupportedVersion,
                    'The installed laravel/fortify version has no verified Architecture Kit profile.',
                    'Install a supported laravel/fortify 1.x release.',
                );
            }
        } catch (Throwable $exception) {
            return $this->fromPackage(
                $package,
                FortifyCompatibilityStatus::InvalidVersion,
                'Invalid laravel/fortify installed or locked version metadata: '.$exception->getMessage(),
                'Run composer install or composer update laravel/fortify to rebuild Composer metadata.',
            );
        }

        return new FortifyCompatibilityResult(
            status: FortifyCompatibilityStatus::Supported,
            section: $package->section,
            declaredConstraint: $package->declaredConstraint,
            installedVersion: $package->installedVersion,
            lockedVersion: $package->lockedVersion,
            profile: 'fortify@1',
            message: 'Laravel Fortify resolved to fortify@1.',
        );
    }

    private function fromPackage(
        ProjectPackage $package,
        FortifyCompatibilityStatus $status,
        string $message,
        string $remediation,
    ): FortifyCompatibilityResult {
        return new FortifyCompatibilityResult(
            status: $status,
            section: $package->section,
            declaredConstraint: $package->declaredConstraint,
            installedVersion: $package->installedVersion,
            lockedVersion: $package->lockedVersion,
            message: $message,
            remediation: $remediation,
        );
    }

    private function result(
        FortifyCompatibilityStatus $status,
        string $message,
        string $remediation,
    ): FortifyCompatibilityResult {
        return new FortifyCompatibilityResult(
            status: $status,
            message: $message,
            remediation: $remediation,
        );
    }

    private function installRemediation(): string
    {
        return 'Run composer require laravel/fortify:"^1.0".';
    }
}
