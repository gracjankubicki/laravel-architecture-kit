<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Support;

use GracjanKubicki\ArchitectureKit\Analysis\Distribution\EngineInstallation;
use GracjanKubicki\ArchitectureKit\Analysis\Distribution\EnginePlatform;
use GracjanKubicki\ArchitectureKit\Analysis\EngineDescriptor;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use RuntimeException;

/** Local test binary only. No network, user installation or production fallback. */
final class EngineFixture
{
    public static function selected(): string
    {
        return getenv('ARCHITECTURE_KIT_SUITE_ENGINE') === 'mago' ? 'mago' : 'php';
    }

    public static function install(string $home): void
    {
        $binary = getenv('ARCHITECTURE_KIT_TEST_ENGINE');
        if (! is_string($binary) || $binary === '' || ! is_file($binary)) {
            throw new RuntimeException('The Mago suite requires ARCHITECTURE_KIT_TEST_ENGINE.');
        }
        $bytes = file_get_contents($binary);
        if ($bytes === false) {
            throw new RuntimeException('Cannot read the test engine.');
        }
        $platform = EnginePlatform::current();
        $manifest = json_encode(['version' => MagoAnalysisEngine::VERSION,
            'mago_version' => MagoAnalysisEngine::MAGO_VERSION, 'schema' => EngineDescriptor::SCHEMA,
            'platforms' => [$platform => ['file' => 'architecture-kit-engine-'.$platform,
                'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)]]], JSON_THROW_ON_ERROR);
        (new EngineInstallation($home, static fn (string $url): string => str_ends_with($url, 'manifest.json') ? $manifest : $bytes))->install();
    }
}
