<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install\Requirements;

use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use Throwable;

final readonly class ArchitectureKitRuntimeRequirement
{
    public const PACKAGE = 'gracjankubicki/laravel-architecture-kit';

    public function __construct(private ProjectPackageInventory $inventory) {}

    public function check(): ArchitectureKitRuntimeRequirementResult
    {
        try {
            $package = $this->inventory->package(self::PACKAGE);
        } catch (Throwable $exception) {
            return new ArchitectureKitRuntimeRequirementResult(false, null, $exception->getMessage(), $this->installCommand());
        }

        if ($package->section === 'require' && $package->lockFilePresent && $package->lockedSection === 'packages-dev') {
            return new ArchitectureKitRuntimeRequirementResult(
                false,
                'require',
                'Architecture Kit is declared as a runtime dependency, but composer.lock places it in packages-dev.',
                'Run composer update '.self::PACKAGE.' to refresh its runtime lock placement.',
            );
        }

        if ($package->section === 'require' && $package->lockFilePresent && $package->lockedSection === null) {
            return new ArchitectureKitRuntimeRequirementResult(
                false,
                'require',
                'Architecture Kit is declared as a runtime dependency, but is missing from composer.lock.',
                'Run composer update '.self::PACKAGE.' to refresh the lockfile.',
            );
        }

        if ($package->section === 'require') {
            return new ArchitectureKitRuntimeRequirementResult(true, 'require', 'Architecture Kit is a root runtime dependency.', '');
        }

        if ($package->section === 'require-dev') {
            return new ArchitectureKitRuntimeRequirementResult(
                false,
                'require-dev',
                'Architecture Kit is installed only in require-dev, but config/architectures.php references runtime enum classes.',
                'Run composer remove --dev '.self::PACKAGE.' && composer require '.self::PACKAGE.'.',
            );
        }

        return new ArchitectureKitRuntimeRequirementResult(
            false,
            null,
            'Architecture Kit must be declared directly in the root composer.json require section.',
            $this->installCommand(),
        );
    }

    private function installCommand(): string
    {
        return 'Run composer require '.self::PACKAGE.'.';
    }
}
