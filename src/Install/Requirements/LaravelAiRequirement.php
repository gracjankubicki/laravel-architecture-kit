<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install\Requirements;

use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use GracjanKubicki\ArchitectureKit\LaravelAi\LaravelAiCompatibility;
use GracjanKubicki\ArchitectureKit\LaravelAi\LaravelAiCompatibilityResult;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class LaravelAiRequirement
{
    public static function projectRequiresLaravelAi(Filesystem $files, string $basePath): bool
    {
        try {
            return (new ProjectPackageInventory($files, $basePath))->package('laravel/ai')->section !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public static function resolve(Filesystem $files, string $basePath): LaravelAiCompatibilityResult
    {
        return (new LaravelAiCompatibility(
            new ProjectPackageInventory($files, $basePath),
            $files,
            $basePath,
        ))->resolve();
    }
}
