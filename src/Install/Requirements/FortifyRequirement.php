<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install\Requirements;

use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use GracjanKubicki\ArchitectureKit\Fortify\FortifyCompatibility;
use GracjanKubicki\ArchitectureKit\Fortify\FortifyCompatibilityResult;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class FortifyRequirement
{
    public static function projectRequiresFortify(Filesystem $files, string $basePath): bool
    {
        try {
            return (new ProjectPackageInventory($files, $basePath))
                ->package('laravel/fortify')->section !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public static function resolve(Filesystem $files, string $basePath): FortifyCompatibilityResult
    {
        return (new FortifyCompatibility(
            new ProjectPackageInventory($files, $basePath),
        ))->resolve();
    }
}
