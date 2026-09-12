<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Support;

final class ProjectPath
{
    public static function relative(string $basePath, string $absolutePath): string
    {
        $base = self::normalize($basePath);
        $path = self::normalize($absolutePath);

        $base = rtrim($base, '/');

        if ($base !== '' && ($path === $base || str_starts_with($path, $base.'/'))) {
            return ltrim(substr($path, strlen($base)), '/');
        }

        return ltrim($path, '/');
    }

    private static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return self::resolveTraversal($path);
    }

    /**
     * Collapses `.` and `..` lexically so a caller-supplied path is compared against
     * the base in the same shape the audit would see. Without it `app/../routes/api.php`
     * reads as an application file even though the audit never loads it.
     */
    private static function resolveTraversal(string $path): string
    {
        if (! str_contains($path, '.')) {
            return $path;
        }

        $leading = str_starts_with($path, '/') ? '/' : '';
        $trailing = str_ends_with($path, '/') && rtrim($path, '/') !== '' ? '/' : '';
        $resolved = [];

        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment !== '..') {
                $resolved[] = $segment;

                continue;
            }

            $last = end($resolved);

            // A `..` that would escape the root is kept, otherwise an absolute path
            // outside the base would silently collapse into a relative one.
            if ($last === false || $last === '..') {
                $resolved[] = '..';

                continue;
            }

            array_pop($resolved);
        }

        return $leading.implode('/', $resolved).($resolved === [] ? '' : $trailing);
    }
}
