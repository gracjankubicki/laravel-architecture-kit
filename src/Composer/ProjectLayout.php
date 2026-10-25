<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Composer;

use GracjanKubicki\ArchitectureKit\Architecture;
use InvalidArgumentException;

/** Reads Composer declarations without loading its generated autoloader. */
final readonly class ProjectLayout
{
    /** @param array<string, mixed> $composer */
    private function __construct(public string $root, public array $composer, private bool $application = false) {}

    public static function read(string $root): self
    {
        $path = $root.'/composer.json';
        if (! is_file($path)) {
            // Existing application-only callers and in-memory test fixtures.
            return new self($root, []);
        }
        if (is_link($path) || filesize($path) > 1000000) {
            throw new InvalidArgumentException('Composer declarations are unavailable or exceed 1 MiB.');
        }
        $composer = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($composer)) {
            throw new InvalidArgumentException('composer.json must contain an object.');
        }

        return new self($root, $composer, is_file($root.'/artisan'));
    }

    /** @param array<string, mixed> $composer Historical layout never consults the working tree. */
    public static function snapshot(array $composer, bool $hasArtisan): self
    {
        return new self('', $composer, $hasArtisan);
    }

    public function isPackage(): bool
    {
        if ($this->composer === [] || $this->application) {
            return false;
        }

        return ($this->composer['type'] ?? 'library') !== 'project';
    }

    public function usesLaravel(): bool
    {
        if (! $this->isPackage() || isset($this->composer['extra']['laravel'])) {
            return true;
        }
        foreach (['require', 'require-dev'] as $section) {
            foreach (array_keys($this->composer[$section] ?? []) as $package) {
                if ($package === 'laravel/framework' || str_starts_with($package, 'illuminate/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<Architecture> */
    public function defaultSelection(): array
    {
        return $this->isPackage()
            ? [Architecture::Actions, Architecture::Services, Architecture::DataObjects, Architecture::ValueObjects, Architecture::Enums, Architecture::PortsAndAdapters]
            : Architecture::defaultSelection();
    }

    /** @return list<string> Production roots, including explicit autoload files. */
    public function sources(): array
    {
        if (! $this->isPackage()) {
            return ['app'];
        }
        $autoload = $this->composer['autoload'] ?? [];
        if (! is_array($autoload)) {
            throw new InvalidArgumentException('Composer autoload must contain declarations.');
        }
        $paths = [];
        foreach (['psr-4', 'psr-0'] as $kind) {
            $mappings = $autoload[$kind] ?? [];
            if (! is_array($mappings)) {
                throw new InvalidArgumentException('Invalid Composer '.$kind.' declaration.');
            }
            foreach ($mappings as $roots) {
                foreach (is_array($roots) ? $roots : [$roots] as $path) {
                    $paths[] = self::path($path);
                }
            }
        }
        foreach (['classmap', 'files'] as $kind) {
            $entries = $autoload[$kind] ?? [];
            if (! is_array($entries)) {
                throw new InvalidArgumentException('Invalid Composer '.$kind.' list.');
            }
            foreach ($entries as $path) {
                $paths[] = self::path($path);
            }
        }
        if ($paths === []) {
            throw new InvalidArgumentException('No unambiguous package source roots. Set audit.paths explicitly.');
        }

        return array_values(array_unique($paths));
    }

    /** Own source roots for a graph, including application Composer mappings and Modules.
     * @return list<string>
     */
    public function graphSources(): array
    {
        $paths = $this->isPackage() ? [] : ['app'];
        $autoload = $this->composer['autoload'] ?? [];
        foreach (['psr-4', 'psr-0', 'classmap', 'files'] as $kind) {
            $entries = $autoload[$kind] ?? [];
            if (! is_array($entries)) {
                throw new InvalidArgumentException('Invalid Composer source declaration.');
            }
            foreach ($entries as $roots) {
                foreach (is_array($roots) ? $roots : [$roots] as $path) {
                    $paths[] = self::path($path);
                }
            }
        }
        if ($this->root !== '' && is_dir($this->root.'/Modules') && ! is_link($this->root.'/Modules')) {
            $paths[] = 'Modules';
        }

        return array_values(array_unique($paths));
    }

    /** @param array<string, mixed> $settings
     * @return array{folder: string, namespace: string} */
    public function placement(string $slug, string $defaultFolder, string $defaultNamespace, array $settings = []): array
    {
        $placement = $settings['placements'][$slug] ?? null;
        if ($placement !== null) {
            if (! is_array($placement) || ! is_string($placement['namespace'] ?? null)) {
                throw new InvalidArgumentException('Set placements.'.$slug.' to a path and namespace.');
            }

            return ['folder' => self::path($placement['path'] ?? null), 'namespace' => self::namespace($placement['namespace'])];
        }
        if (! $this->isPackage()) {
            return ['folder' => $defaultFolder, 'namespace' => $defaultNamespace];
        }
        $mappings = $this->composer['autoload']['psr-4'] ?? [];
        $candidates = [];
        if (is_array($mappings)) {
            foreach ($mappings as $prefix => $roots) {
                foreach (is_array($roots) ? $roots : [$roots] as $root) {
                    $candidates[] = ['folder' => self::path($root), 'namespace' => self::namespace($prefix)];
                }
            }
        }
        if (count($candidates) !== 1) {
            throw new InvalidArgumentException('Package placement is ambiguous. Set placements.'.$slug.'.path and placements.'.$slug.'.namespace explicitly.');
        }
        $suffix = str_starts_with($defaultFolder, 'app/') ? substr($defaultFolder, 4) : $defaultFolder;

        return ['folder' => $candidates[0]['folder'].'/'.$suffix,
            'namespace' => $candidates[0]['namespace'].'\\'.str_replace('/', '\\', $suffix)];
    }

    public static function path(mixed $path): string
    {
        if (! is_string($path) || $path === '' || str_contains($path, '\\') || str_starts_with($path, '/')
            || preg_match('/[\x00-\x1f\x7f:*?\[\]]/', $path)) {
            throw new InvalidArgumentException('Use an explicit repository-relative source path without globs.');
        }
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        $path = rtrim($path, '/');
        foreach (explode('/', $path) as $part) {
            if (in_array($part, ['', '.', '..', 'vendor', 'node_modules', '.git'], true)) {
                throw new InvalidArgumentException('Use an explicit source root inside the repository, outside vendor.');
            }
        }

        return $path;
    }

    private static function namespace(mixed $namespace): string
    {
        if (! is_string($namespace) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\?$/D', $namespace) !== 1) {
            throw new InvalidArgumentException('Set an unambiguous PHP namespace for package placement.');
        }

        return rtrim($namespace, '\\');
    }
}
