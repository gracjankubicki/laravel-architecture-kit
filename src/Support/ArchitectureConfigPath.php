<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

use Illuminate\Filesystem\Filesystem;

final readonly class ArchitectureConfigPath
{
    public static function resolve(Filesystem $files, string $basePath): string
    {
        $path = $basePath.'/config/architectures.php';

        if ($files->exists($path)) {
            return $path;
        }

        if (! str_contains($basePath, '/vendor/orchestra/testbench-core/laravel')) {
            return $path;
        }

        if (! function_exists('Orchestra\\Testbench\\workbench_path')) {
            return $path;
        }

        $workbenchPath = \Orchestra\Testbench\workbench_path('config/architectures.php');

        return $files->exists($workbenchPath) ? $workbenchPath : $path;
    }
}
