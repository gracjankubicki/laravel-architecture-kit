<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Install\Requirements;

use Illuminate\Filesystem\Filesystem;

final class LaravelAiRequirement
{
    public static function projectRequiresLaravelAi(Filesystem $files, string $basePath): bool
    {
        $path = $basePath.'/composer.json';

        if (! $files->exists($path)) {
            return false;
        }

        $composer = json_decode($files->get($path), true);

        if (! is_array($composer)) {
            return false;
        }

        return isset($composer['require']['laravel/ai'])
            || isset($composer['require-dev']['laravel/ai']);
    }
}
