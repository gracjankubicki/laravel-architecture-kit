<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Inertia;

use Composer\Semver\Intervals;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use GracjanKubicki\ArchitectureKit\Composer\ProjectPackage;
use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use Throwable;

final readonly class InertiaCompatibility
{
    public const SUPPORTED_CONSTRAINT = '>=3.0.0 <4.0.0';

    public function __construct(private ProjectPackageInventory $inventory) {}

    public function resolve(): InertiaCompatibilityResult
    {
        try {
            $package = $this->inventory->package('inertiajs/inertia-laravel');
        } catch (Throwable $exception) {
            return $this->result(
                InertiaCompatibilityStatus::Missing,
                $exception->getMessage(),
                $this->installRemediation(),
            );
        }

        if ($package->section === null || $package->declaredConstraint === null) {
            return $this->fromPackage(
                $package,
                InertiaCompatibilityStatus::Missing,
                'Inertia Laravel is not declared directly in root composer.json.',
                $this->installRemediation(),
            );
        }

        if ($package->section === 'require-dev') {
            return $this->fromPackage(
                $package,
                InertiaCompatibilityStatus::RuntimeDependencyInRequireDev,
                'inertiajs/inertia-laravel is an application runtime dependency and cannot be installed only in require-dev.',
                'Run composer remove --dev inertiajs/inertia-laravel && composer require inertiajs/inertia-laravel:"^3.0".',
            );
        }

        if ($package->lockFilePresent && $package->lockedSection === 'packages-dev') {
            return $this->fromPackage(
                $package,
                InertiaCompatibilityStatus::StaleLock,
                'inertiajs/inertia-laravel is declared as a runtime dependency, but composer.lock places it in packages-dev.',
                'Run composer update inertiajs/inertia-laravel to refresh its runtime lock placement.',
            );
        }

        if ($package->lockFilePresent && $package->lockedSection === null) {
            return $this->fromPackage(
                $package,
                InertiaCompatibilityStatus::StaleLock,
                'inertiajs/inertia-laravel is declared as a runtime dependency, but is missing from composer.lock.',
                'Run composer update inertiajs/inertia-laravel to refresh the lockfile.',
            );
        }

        try {
            $parser = new VersionParser;
            $candidate = $parser->parseConstraints($package->declaredConstraint);
            $supported = $parser->parseConstraints(self::SUPPORTED_CONSTRAINT);
        } catch (Throwable $exception) {
            return $this->fromPackage(
                $package,
                InertiaCompatibilityStatus::InvalidConstraint,
                'Invalid inertiajs/inertia-laravel constraint: '.$exception->getMessage(),
                'Use ^3.0 or another constraint contained within >=3.0 <4.0.',
            );
        }

        if (! Intervals::isSubsetOf($candidate, $supported)) {
            return $this->fromPackage(
                $package,
                InertiaCompatibilityStatus::UnsupportedConstraint,
                'The declared inertiajs/inertia-laravel constraint permits unsupported versions. Supported range: '.self::SUPPORTED_CONSTRAINT.'.',
                'Narrow root require.inertiajs/inertia-laravel to ^3.0 or another constraint contained within >=3.0 <4.0, then run composer update inertiajs/inertia-laravel.',
            );
        }

        if ($package->installedVersion === null) {
            return $this->fromPackage(
                $package,
                InertiaCompatibilityStatus::NotInstalled,
                'inertiajs/inertia-laravel is declared but is not present in Composer installed metadata.',
                'Run composer install or composer update inertiajs/inertia-laravel.',
            );
        }

        try {
            if (! Semver::satisfies($package->installedVersion, $package->declaredConstraint)) {
                return $this->fromPackage(
                    $package,
                    InertiaCompatibilityStatus::StaleLock,
                    'The installed inertiajs/inertia-laravel version does not satisfy the root constraint.',
                    'Run composer update inertiajs/inertia-laravel.',
                );
            }

            if ($package->lockedVersion !== null) {
                Semver::satisfies($package->lockedVersion, self::SUPPORTED_CONSTRAINT);

                if ($package->lockedVersion !== $package->installedVersion) {
                    return $this->fromPackage(
                        $package,
                        InertiaCompatibilityStatus::StaleLock,
                        'The installed and locked inertiajs/inertia-laravel versions do not match.',
                        'Run composer install or composer update inertiajs/inertia-laravel.',
                    );
                }
            }

            if (! Semver::satisfies($package->installedVersion, self::SUPPORTED_CONSTRAINT)) {
                return $this->fromPackage(
                    $package,
                    InertiaCompatibilityStatus::UnsupportedVersion,
                    'The installed inertiajs/inertia-laravel version has no verified Architecture Kit profile.',
                    'Install a supported inertiajs/inertia-laravel 3.x release.',
                );
            }
        } catch (Throwable $exception) {
            return $this->fromPackage(
                $package,
                InertiaCompatibilityStatus::InvalidVersion,
                'Invalid inertiajs/inertia-laravel installed or locked version metadata: '.$exception->getMessage(),
                'Run composer install or composer update inertiajs/inertia-laravel to rebuild Composer metadata.',
            );
        }

        return new InertiaCompatibilityResult(
            status: InertiaCompatibilityStatus::Supported,
            section: $package->section,
            declaredConstraint: $package->declaredConstraint,
            installedVersion: $package->installedVersion,
            lockedVersion: $package->lockedVersion,
            profile: 'inertia@3',
            message: 'Inertia Laravel resolved to inertia@3.',
        );
    }

    private function fromPackage(
        ProjectPackage $package,
        InertiaCompatibilityStatus $status,
        string $message,
        string $remediation,
    ): InertiaCompatibilityResult {
        return new InertiaCompatibilityResult(
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
        InertiaCompatibilityStatus $status,
        string $message,
        string $remediation,
    ): InertiaCompatibilityResult {
        return new InertiaCompatibilityResult(
            status: $status,
            message: $message,
            remediation: $remediation,
        );
    }

    private function installRemediation(): string
    {
        return 'Run composer require inertiajs/inertia-laravel:"^3.0".';
    }
}
