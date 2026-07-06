<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Install\Requirements;

use Composer\Semver\Semver;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final readonly class SaloonRequirement
{
    /**
     * @var array<string, string>
     */
    public const REQUIRED_PACKAGES = [
        'saloonphp/saloon' => '^4.0',
        'saloonphp/laravel-plugin' => '^4.0',
        'saloonphp/rate-limit-plugin' => '^4.0',
    ];

    /**
     * @return array<int, string>
     */
    public static function violations(Filesystem $files, string $basePath): array
    {
        $composer = self::composer($files, $basePath);

        if ($composer === null) {
            return ['composer.json is missing or invalid.'];
        }

        $violations = [];
        $saloon = self::packageConstraint($composer, 'saloonphp/saloon');

        if ($saloon === null) {
            $violations[] = 'composer.json does not require saloonphp/saloon ^4.0.';
        } elseif (! self::allowsSaloonFourOnly($saloon)) {
            $violations[] = 'saloonphp/saloon must require ^4.0 and must not allow Saloon 3; Saloon 4 fixes security issues in v3.';
        }

        foreach (['saloonphp/laravel-plugin', 'saloonphp/rate-limit-plugin'] as $package) {
            if (self::packageConstraint($composer, $package) === null) {
                $violations[] = "composer.json does not require {$package}.";
            }
        }

        return $violations;
    }

    public static function projectRequiresSaloon(Filesystem $files, string $basePath): bool
    {
        return self::packageConstraint(self::composer($files, $basePath), 'saloonphp/saloon') !== null;
    }

    /**
     * @return array<int, string>
     */
    public static function missingInstallPackages(Filesystem $files, string $basePath): array
    {
        $composer = self::composer($files, $basePath);

        if ($composer === null) {
            return [];
        }

        $packages = [];

        foreach (self::REQUIRED_PACKAGES as $package => $constraint) {
            if (self::packageConstraint($composer, $package) === null) {
                $packages[] = $package.':'.$constraint;
            }
        }

        return $packages;
    }

    /**
     * @param  array<string, mixed>|null  $composer
     */
    private static function packageConstraint(?array $composer, string $package): ?string
    {
        if ($composer === null) {
            return null;
        }

        foreach (['require', 'require-dev'] as $section) {
            if (isset($composer[$section][$package]) && is_string($composer[$section][$package])) {
                return $composer[$section][$package];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function composer(Filesystem $files, string $basePath): ?array
    {
        $path = $basePath.'/composer.json';

        if (! $files->exists($path)) {
            return null;
        }

        $composer = json_decode($files->get($path), true);

        return is_array($composer) ? $composer : null;
    }

    private static function allowsSaloonFourOnly(string $constraint): bool
    {
        try {
            return Semver::satisfies('4.0.0', $constraint)
                && ! Semver::satisfies('3.9.9', $constraint);
        } catch (Throwable) {
            return false;
        }
    }
}
