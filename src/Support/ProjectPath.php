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

        return preg_replace('#/+#', '/', $path) ?? $path;
    }
}
