<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\Watch;

use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimePlatform;
use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchProtocol;
use PHPUnit\Framework\Assert;

final class RuntimeFixture
{
    /** @return array<string, mixed> */
    public static function manifest(string $body): array
    {
        $platforms = [];
        foreach (RuntimePlatform::TARGETS as $platform => $target) {
            // Unavailable artifacts are metadata fixtures only, never claimed as native binaries.
            $platforms[$platform] = ['file' => 'architecture-kit-runtime-'.$platform, 'target' => $target, 'bytes' => strlen($body), 'sha256' => hash('sha256', $body), 'linkage' => RuntimePlatform::linkage($platform)];
        }

        return ['schema' => 1, 'runtime_version' => WatchProtocol::VERSION, 'protocol' => WatchProtocol::PROTOCOL, 'notify_version' => '8.2.1', 'platforms' => $platforms];
    }

    public static function native(): string
    {
        $binary = getenv('ARCHITECTURE_KIT_TEST_RUNTIME');
        if (! is_string($binary) || ! is_file($binary)) {
            Assert::markTestSkipped('OPEN: set ARCHITECTURE_KIT_TEST_RUNTIME to the native built runtime; no automatic installation.');
        }

        return $binary;
    }
}
