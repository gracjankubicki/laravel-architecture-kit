<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Content identity at a control boundary, including same-stat edits. */
final class SourceFingerprint
{
    /** @param array<string, mixed> $settings */
    public static function current(string $root, array $settings = []): string
    {
        $parts = [];
        $required = [];
        foreach (['audit', 'graph'] as $section) {
            $paths = is_array($settings[$section] ?? null) ? ($settings[$section]['paths'] ?? []) : [];
            if (! is_array($paths)) {
                throw new RuntimeException('Invalid task source scope.');
            }
            foreach ($paths as $path) {
                if (! is_string($path)) {
                    throw new RuntimeException('Invalid task source scope.');
                }
                $required[] = $path;
            }
        }
        $composerPath = $root.'/composer.json';
        $composer = is_file($composerPath) ? json_decode((string) file_get_contents($composerPath), true) : [];
        if (! is_array($composer)) {
            throw new RuntimeException('Task Composer source is invalid.');
        }
        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload = $composer[$section] ?? [];
            if (! is_array($autoload) || ! is_array($autoload['files'] ?? [])) {
                throw new RuntimeException('Task Composer helper scope is invalid.');
            }
            foreach ($autoload['files'] ?? [] as $path) {
                if (! is_string($path)) {
                    throw new RuntimeException('Task Composer helper scope is invalid.');
                }
                $required[] = $path;
            }
        }
        $iterator = new RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $file, string $key, RecursiveDirectoryIterator $directory) use ($root, $required): bool {
                $name = $file->getFilename();
                if (in_array($name, ['vendor', 'node_modules', '.git', '.env'], true)) {
                    return false;
                }
                // Directory entry types let us reject logs and report assets without
                // performing a stat on every non-source file in a large repository.
                if (! $directory->hasChildren() && ! str_ends_with($name, '.php') && ! in_array($name, ['composer.json', 'composer.lock'], true)) {
                    return false;
                }
                if ($file->isLink()) {
                    return false;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $generated = false;
                foreach (['.cache', '.rector-cache', '.phpunit.cache', 'coverage', 'storage/framework/views', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/testing'] as $prefix) {
                    if ($relative === $prefix || str_starts_with($relative, $prefix.'/')) {
                        $generated = true;
                        break;
                    }
                }
                if (! $generated) {
                    return true;
                }
                foreach ($required as $path) {
                    if ($relative === $path || str_starts_with($relative, rtrim($path, '/').'/') || str_starts_with($path, $relative.'/')) {
                        return true;
                    }
                }

                return false;
            },
        ));
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile() || (! str_ends_with($file->getFilename(), '.php') && ! in_array($file->getFilename(), ['composer.json', 'composer.lock'], true))) {
                continue;
            }
            if (count($parts) >= 20000 || $file->getSize() > 8000000) {
                throw new RuntimeException('Task source fingerprint limit reached.');
            }
            $hash = hash_file('sha256', $file->getPathname());
            if ($hash === false) {
                throw new RuntimeException('Task source is unreadable.');
            }
            $parts[substr($file->getPathname(), strlen($root) + 1)] = $hash;
        }
        ksort($parts);

        return hash('sha256', serialize($parts));
    }
}
