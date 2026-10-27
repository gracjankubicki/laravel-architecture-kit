<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Distribution;

use RuntimeException;

final class RuntimePlatform
{
    public const TARGETS = [
        'macos-arm64' => 'aarch64-apple-darwin',
        'macos-x64' => 'x86_64-apple-darwin',
        'linux-arm64' => 'aarch64-unknown-linux-musl',
        'linux-x64' => 'x86_64-unknown-linux-musl',
    ];

    public static function current(): string
    {
        return self::identify(PHP_OS_FAMILY, php_uname('m'));
    }

    public static function identify(string $system, string $architecture): string
    {
        $os = match (strtolower($system)) {
            'darwin', 'macos' => 'macos', 'linux' => 'linux',
            default => throw new RuntimeException('native_watch_unavailable: unsupported OS; use background reconciliation.'),
        };
        $cpu = match (strtolower($architecture)) {
            'aarch64', 'arm64' => 'arm64', 'x86_64', 'amd64' => 'x64',
            default => throw new RuntimeException('native_watch_unavailable: unsupported CPU; use background reconciliation.'),
        };

        return $os.'-'.$cpu;
    }

    public static function linkage(string $platform): string
    {
        if (! isset(self::TARGETS[$platform])) {
            throw new RuntimeException('native_watch_unavailable: unsupported distribution platform.');
        }

        return str_starts_with($platform, 'linux-') ? 'static-musl' : 'system';
    }
}
