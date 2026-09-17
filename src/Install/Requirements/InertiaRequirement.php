<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install\Requirements;

use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use GracjanKubicki\ArchitectureKit\Inertia\InertiaCompatibility;
use GracjanKubicki\ArchitectureKit\Inertia\InertiaCompatibilityResult;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class InertiaRequirement
{
    public static function projectRequiresInertia(Filesystem $files, string $basePath): bool
    {
        try {
            return (new ProjectPackageInventory($files, $basePath))
                ->package('inertiajs/inertia-laravel')->section !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public static function resolve(Filesystem $files, string $basePath): InertiaCompatibilityResult
    {
        return (new InertiaCompatibility(
            new ProjectPackageInventory($files, $basePath),
        ))->resolve();
    }
}
