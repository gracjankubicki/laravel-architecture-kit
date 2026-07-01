<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class PhpRequirement
{
    public static function projectRequiresPhp85(Filesystem $files, string $basePath): bool
    {
        $path = $basePath.'/composer.json';

        if (! $files->exists($path)) {
            return false;
        }

        $composer = json_decode($files->get($path), true);

        if (! is_array($composer)) {
            return false;
        }

        $php = $composer['require']['php'] ?? null;

        return is_string($php) && self::constraintRequiresPhp85($php);
    }

    public static function constraintRequiresPhp85(string $constraint): bool
    {
        try {
            $parser = new VersionParser();

            return Intervals::isSubsetOf(
                $parser->parseConstraints($constraint),
                $parser->parseConstraints('>=8.5.0.0-dev'),
            );
        } catch (Throwable) {
            return false;
        } finally {
            Intervals::clear();
        }
    }
}
