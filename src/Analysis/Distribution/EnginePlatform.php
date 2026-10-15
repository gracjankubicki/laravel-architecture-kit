<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis\Distribution;

use RuntimeException;

final class EnginePlatform
{
    /** Static musl Linux executables also run on glibc without depending on its version. */
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
            'darwin', 'macos' => 'macos',
            'linux' => 'linux',
            default => throw new RuntimeException('The Mago engine supports macOS and Linux only. Select PHP explicitly on this system.'),
        };
        $cpu = match (strtolower($architecture)) {
            'aarch64', 'arm64' => 'arm64',
            'x86_64', 'amd64' => 'x64',
            default => throw new RuntimeException('Unsupported Mago engine CPU: '.$architecture.'. Select PHP explicitly.'),
        };

        return $os.'-'.$cpu;
    }
}
