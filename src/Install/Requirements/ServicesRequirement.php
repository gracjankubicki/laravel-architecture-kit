<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Install\Requirements;

use Illuminate\Filesystem\Filesystem;

final class ServicesRequirement
{
    public static function projectHasServices(Filesystem $files, string $basePath): bool
    {
        $path = $basePath.'/app/Services';

        if (! $files->isDirectory($path)) {
            return false;
        }

        foreach ($files->allFiles($path) as $file) {
            if ($file->getExtension() === 'php') {
                return true;
            }
        }

        return false;
    }
}
