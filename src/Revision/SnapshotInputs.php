<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Revision;

/** Read-only virtual project inputs; no filesystem fallback for missing historic files. */
final readonly class SnapshotInputs
{
    public function __construct(public SourceSnapshot $snapshot) {}

    public function read(string $path): ?string
    {
        return self::safe($path) ? ($this->snapshot->files[$path] ?? null) : null;
    }

    /** @return array<int>|null */
    public function stat(string $path): ?array
    {
        $source = $this->read($path);
        if ($source !== null) {
            return [0, strlen($source)];
        }
        if ($this->listing([$path]) !== []) {
            return [0, 0];
        }

        return null;
    }

    /** @param list<string> $directories
     * @return list<string> */
    public function listing(array $directories): array
    {
        $paths = [];
        foreach (array_keys($this->snapshot->files) as $path) {
            if (! self::safe($path) || ! str_ends_with(strtolower($path), '.php')) {
                continue;
            }
            foreach ($directories as $directory) {
                if (self::safe($directory) && str_starts_with($path, rtrim($directory, '/').'/')) {
                    $paths[] = $path;
                    break;
                }
            }
        }
        sort($paths);

        return $paths;
    }

    public static function safe(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, "\0")
            && ! str_contains($path, ':') && ! str_contains($path, '\\')
            && ! str_starts_with($path, 'bootstrap/cache/')
            && ! preg_match('~(^|/)(\.|\.\.|vendor|node_modules|\.git)(/|$)~', $path);
    }
}
