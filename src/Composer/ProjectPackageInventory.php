<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Composer;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final readonly class ProjectPackageInventory
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
    ) {}

    public function package(string $name): ProjectPackage
    {
        $composer = $this->composerJson();
        $section = null;
        $constraint = null;

        foreach (['require', 'require-dev'] as $candidate) {
            if (isset($composer[$candidate][$name]) && is_string($composer[$candidate][$name])) {
                $section = $candidate;
                $constraint = $composer[$candidate][$name];
                break;
            }
        }

        return new ProjectPackage(
            name: $name,
            section: $section,
            declaredConstraint: $constraint,
            installedVersion: $this->installedVersion($name),
            lockedVersion: $this->lockedVersion($name),
        );
    }

    /** @return array<string, mixed> */
    private function composerJson(): array
    {
        $path = $this->basePath.'/composer.json';

        if (! $this->files->isFile($path)) {
            throw new RuntimeException('composer.json is missing or invalid.');
        }

        $composer = json_decode($this->files->get($path), true);

        if (! is_array($composer)) {
            throw new RuntimeException('composer.json is missing or invalid.');
        }

        return $composer;
    }

    private function installedVersion(string $name): ?string
    {
        $phpPath = $this->basePath.'/vendor/composer/installed.php';

        if ($this->files->isFile($phpPath)) {
            $installed = require $phpPath;
            $version = is_array($installed) ? ($installed['versions'][$name]['pretty_version'] ?? $installed['versions'][$name]['version'] ?? null) : null;

            if (is_string($version)) {
                return $this->normalizeVersion($version);
            }
        }

        $jsonPath = $this->basePath.'/vendor/composer/installed.json';

        if (! $this->files->isFile($jsonPath)) {
            return null;
        }

        $decoded = json_decode($this->files->get($jsonPath), true);
        $packages = is_array($decoded) && isset($decoded['packages']) && is_array($decoded['packages'])
            ? $decoded['packages']
            : $decoded;

        if (! is_array($packages)) {
            return null;
        }

        foreach ($packages as $package) {
            if (is_array($package) && ($package['name'] ?? null) === $name && is_string($package['version'] ?? null)) {
                return $this->normalizeVersion($package['version']);
            }
        }

        return null;
    }

    private function lockedVersion(string $name): ?string
    {
        $path = $this->basePath.'/composer.lock';

        if (! $this->files->isFile($path)) {
            return null;
        }

        $lock = json_decode($this->files->get($path), true);

        if (! is_array($lock)) {
            throw new RuntimeException('composer.lock is invalid. Run composer update to rebuild it.');
        }

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (($lock[$section] ?? []) as $package) {
                if (is_array($package) && ($package['name'] ?? null) === $name && is_string($package['version'] ?? null)) {
                    return $this->normalizeVersion($package['version']);
                }
            }
        }

        return null;
    }

    private function normalizeVersion(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }
}
