<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\PublicApi;

use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use InvalidArgumentException;
use JsonException;

/** Composer's production declarations only. Does not include generated autoloaders. */
final class AutoloadSurface
{
    /** @var array<string, list<array{kind: string, prefix: string, root: string}>> */
    public array $files = [];

    /** @var list<array{code: string, path: string, message: string}> */
    public array $notices = [];

    /** @var array<string, mixed> */
    public array $composer = [];

    /** @param list<string> $publicPaths Optional literal package-relative boundaries. */
    public function __construct(SourceSnapshot $snapshot, array $publicPaths = [])
    {
        foreach ($publicPaths as $path) {
            if (self::path($path) === null || strpbrk($path, '*?[') !== false) {
                throw new InvalidArgumentException('Public areas must be literal package-relative paths.');
            }
        }
        if (! isset($snapshot->files['composer.json'])) {
            $this->notice('composer.json', 'Production Composer declarations are unavailable.');

            return;
        }
        try {
            $composer = json_decode($snapshot->files['composer.json'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->notice('composer.json', 'Composer JSON cannot be parsed.');

            return;
        }
        if (! is_array($composer) || ! is_array($composer['autoload'] ?? null)) {
            $this->notice('composer.json', 'No recognized production autoload declaration.');

            return;
        }
        $this->composer = $composer;
        $autoload = $composer['autoload'];
        foreach (array_keys($autoload) as $kind) {
            if (! in_array($kind, ['psr-4', 'psr-0', 'classmap', 'files', 'exclude-from-classmap'], true)) {
                $this->notice('composer.json', 'Unsupported autoload form: '.$kind);
            }
        }
        $excludes = $this->patterns($autoload['exclude-from-classmap'] ?? [], 'exclude-from-classmap');
        foreach (['psr-4', 'psr-0'] as $kind) {
            if (! isset($autoload[$kind])) {
                continue;
            }
            if (! is_array($autoload[$kind])) {
                $this->notice('composer.json', 'Invalid '.$kind.' map.');

                continue;
            }
            foreach ($autoload[$kind] as $prefix => $roots) {
                if (! is_string($prefix) || ($kind === 'psr-4' && $prefix !== '' && ! str_ends_with($prefix, '\\'))) {
                    $this->notice('composer.json', 'Invalid '.$kind.' namespace prefix.');

                    continue;
                }
                foreach (is_array($roots) ? $roots : [$roots] as $root) {
                    $normalized = is_string($root) ? self::path($root) : null;
                    if ($normalized === null || strpbrk($normalized, '*?[') !== false) {
                        $this->notice('composer.json', 'Unsupported '.$kind.' root.');

                        continue;
                    }
                    $this->select($snapshot, $publicPaths, ['kind' => $kind, 'prefix' => $prefix, 'root' => $normalized], []);
                }
            }
        }
        foreach ($this->patterns($autoload['classmap'] ?? [], 'classmap') as $root) {
            $this->select($snapshot, $publicPaths, ['kind' => 'classmap', 'prefix' => '', 'root' => $root], $excludes);
        }
        foreach ($this->patterns($autoload['files'] ?? [], 'files') as $path) {
            if (strpbrk($path, '*?[') !== false) {
                $this->notice($path, 'Autoload files must name literal files.');

                continue;
            }
            if (! isset($snapshot->files[$path])) {
                $this->notice($path, 'Declared autoload file is unavailable.');
            } elseif (self::inAreas($path, $publicPaths)) {
                $this->files[$path][] = ['kind' => 'files', 'prefix' => '', 'root' => $path];
            }
        }
        ksort($this->files);
    }

    public function allowsClass(string $path, string $name): bool
    {
        foreach ($this->files[$path] ?? [] as $mapping) {
            if (in_array($mapping['kind'], ['classmap', 'files'], true)) {
                return true;
            }
            $prefix = $mapping['prefix'];
            if (! str_starts_with($name, $prefix)) {
                continue;
            }
            if ($mapping['kind'] === 'psr-4') {
                $relative = str_replace('\\', '/', substr($name, strlen($prefix))).'.php';
            } else {
                $position = strrpos($name, '\\');
                $relative = $position === false ? str_replace('_', '/', $name).'.php'
                    : str_replace('\\', '/', substr($name, 0, $position + 1)).str_replace('_', '/', substr($name, $position + 1)).'.php';
            }
            if ($path === ($mapping['root'] === '' ? '' : $mapping['root'].'/').$relative) {
                return true;
            }
        }

        return false;
    }

    public function allowsStandalone(string $path): bool
    {
        // Composer executes only files entries; PSR/classmap do not load functions alone.
        return in_array('files', array_column($this->files[$path] ?? [], 'kind'), true);
    }

    /** @param list<string> $areas
     * @param array{kind: string, prefix: string, root: string} $mapping
     * @param list<string> $excludes */
    private function select(SourceSnapshot $snapshot, array $areas, array $mapping, array $excludes): void
    {
        $matched = false;
        foreach ($snapshot->paths as $path) {
            if (! str_ends_with(strtolower($path), '.php') || ! self::under($path, $mapping['root'])) {
                continue;
            }
            $matched = true;
            if (! isset($snapshot->files[$path])) {
                $this->notice($path, 'Declared production source is unavailable.');

                continue;
            }
            if (! self::inAreas($path, $areas) || self::matchesAny($path, $excludes)) {
                continue;
            }
            $this->files[$path][] = $mapping;
        }
        if (! $matched && $mapping['root'] !== '') {
            $this->notice($mapping['root'], 'No readable PHP source under a declared production root.');
        }
    }

    /** @return list<string> */
    private function patterns(mixed $input, string $kind): array
    {
        if (! is_array($input) || ! array_is_list($input)) {
            $this->notice('composer.json', 'Invalid '.$kind.' list.');

            return [];
        }
        $patterns = [];
        foreach ($input as $pattern) {
            // Composer permits a leading slash in exclusion patterns only.
            $raw = is_string($pattern) && $kind === 'exclude-from-classmap' ? ltrim($pattern, '/') : $pattern;
            $path = is_string($raw) ? self::path($raw) : null;
            if ($path === null) {
                $this->notice('composer.json', 'Unsupported '.$kind.' path.');
            } else {
                $patterns[] = $path;
            }
        }

        return $patterns;
    }

    private static function path(string $path): ?string
    {
        if (strlen($path) > 300 || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('~(?:^|/)\.\.(?:/|$)|^[A-Za-z]:~', $path)) {
            return null;
        }
        $path = trim($path, '/');
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return $path === '.' ? '' : $path;
    }

    /** Composer classmap directories/globs also match nested files. */
    private static function under(string $path, string $pattern): bool
    {
        if ($pattern === '') {
            return true;
        }
        $parts = explode('/', $path);
        for ($length = count($parts); $length > 0; $length--) {
            if (self::glob(array_slice($parts, 0, $length), explode('/', $pattern))) {
                return true;
            }
        }

        return false;
    }

    /** Bounded dynamic programming supports ** without regex backtracking.
     * @param list<string> $path
     * @param list<string> $pattern */
    private static function glob(array $path, array $pattern): bool
    {
        $matched = [0 => true];
        foreach ($pattern as $part) {
            $next = [];
            foreach (array_keys($matched) as $position) {
                if ($part === '**') {
                    for ($i = $position; $i <= count($path); $i++) {
                        $next[$i] = true;
                    }
                } elseif (isset($path[$position]) && fnmatch($part, $path[$position], FNM_NOESCAPE)) {
                    $next[$position + 1] = true;
                }
            }
            $matched = $next;
        }

        return isset($matched[count($path)]);
    }

    /** @param list<string> $areas */
    private static function inAreas(string $path, array $areas): bool
    {
        return $areas === [] || self::matchesAny($path, array_map(static fn (string $area): string => self::path($area) ?? '@invalid', $areas));
    }

    /** @param list<string> $patterns */
    private static function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::under($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function notice(string $path, string $message): void
    {
        $this->notices[] = ['code' => 'E_AUTOLOAD_UNRESOLVED', 'path' => $path, 'message' => $message];
    }
}
