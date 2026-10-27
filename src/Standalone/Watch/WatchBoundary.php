<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Watch;

use RuntimeException;

/** Explicit source/configuration roots supplied by the existing scope policy. Never discovers sources. */
final readonly class WatchBoundary
{
    /** @param non-empty-list<string> $roots */
    public function __construct(public array $roots)
    {
        if ($roots === [] || ! array_is_list($roots)) {
            throw new RuntimeException('unsafe_path: watch roots must be a non-empty list.');
        }
        foreach ($roots as $root) {
            self::absolute($root);
            self::noSymlinks($root);
            if ($root === '/') {
                throw new RuntimeException('unsafe_path: whole filesystem observation is forbidden.');
            }
        }
    }

    public static function absolute(string $path): void
    {
        if ($path === '' || strlen($path) > WatchProtocol::PATH_BYTES || ! str_starts_with($path, '/') || str_contains($path, "\0")
            || str_contains($path, '//') || preg_match('~/(?:\.|\.\.)(?:/|$)~D', $path)
            || $path !== '/' && str_ends_with($path, '/')) {
            throw new RuntimeException('unsafe_path: invalid absolute byte path.');
        }
    }

    public static function noSymlinks(string $path): void
    {
        clearstatcache(true);
        // realpath of the deepest existing ancestor detects symlink aliases anywhere above missing roots.
        $ancestor = $path;
        while (! file_exists($ancestor) && ! is_link($ancestor)) {
            $ancestor = dirname($ancestor);
        }
        if (is_link($ancestor) || realpath($ancestor) !== $ancestor) {
            throw new RuntimeException('unsafe_symlink: use the canonical permitted source identity.');
        }
    }

    public function accepts(string $path): bool
    {
        self::absolute($path);
        foreach ($this->roots as $root) {
            // Ancestor changes matter for missing/replaced roots, but never authorize reading the ancestor.
            if ($path === $root || str_starts_with($path, $root.'/') || str_starts_with($root, $path.'/')) {
                self::noSymlinks($path);

                return true;
            }
        }

        return false;
    }
}
