<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Analysis\Distribution;

use GracjanKubicki\ArchitectureKit\Analysis\Distribution\EngineInstallation;
use GracjanKubicki\ArchitectureKit\Analysis\Distribution\EnginePlatform;
use GracjanKubicki\ArchitectureKit\Analysis\EngineDescriptor;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EngineDistributionTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/kit-engine-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->home);
    }

    public function test_platforms_cover_both_cpu_families_and_static_linux_for_glibc_and_musl(): void
    {
        self::assertSame('macos-arm64', EnginePlatform::identify('Darwin', 'arm64'));
        self::assertSame('macos-x64', EnginePlatform::identify('Darwin', 'x86_64'));
        self::assertSame('linux-arm64', EnginePlatform::identify('Linux', 'aarch64'));
        self::assertSame('linux-x64', EnginePlatform::identify('Linux', 'amd64'));
        self::assertStringEndsWith('-musl', EnginePlatform::TARGETS['linux-arm64']);
        self::assertStringEndsWith('-musl', EnginePlatform::TARGETS['linux-x64']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('macOS and Linux only');
        EnginePlatform::identify('Windows', 'AMD64');
    }

    public function test_missing_engine_does_not_install_or_fall_back_implicitly(): void
    {
        try {
            (new EngineInstallation($this->home))->binary();
            self::fail('Missing binary must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('engine install', $error->getMessage());
            self::assertDirectoryDoesNotExist($this->home);
        }
    }

    public function test_download_integrity_is_checked_before_any_executable_is_written(): void
    {
        $platform = EnginePlatform::current();
        $manifest = $this->manifest($platform, 'expected');
        $installer = new EngineInstallation($this->home, static fn (string $url): string => str_ends_with($url, 'manifest.json') ? $manifest : 'tampered');
        try {
            $installer->install();
            self::fail('Corrupt download must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('SHA-256 or size check', $error->getMessage());
            self::assertDirectoryDoesNotExist($this->home);
        }
    }

    public function test_incompatible_manifest_fails_without_downloading_the_binary(): void
    {
        $calls = [];
        $installer = new EngineInstallation($this->home, static function (string $url) use (&$calls): string {
            $calls[] = $url;

            return '{"version":"999","platforms":{}}';
        });
        try {
            $installer->install();
            self::fail('Version mismatch must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('does not match', $error->getMessage());
            self::assertCount(1, $calls);
            self::assertDirectoryDoesNotExist($this->home);
        }
    }

    public function test_real_engine_is_installed_externally_and_subsequent_corruption_is_rejected(): void
    {
        $binary = getenv('ARCHITECTURE_KIT_TEST_ENGINE');
        if (! is_string($binary) || $binary === '') {
            self::markTestSkipped('Set ARCHITECTURE_KIT_TEST_ENGINE to the built engine binary.');
        }
        $bytes = file_get_contents($binary);
        self::assertIsString($bytes);
        $platform = EnginePlatform::current();
        $manifest = $this->manifest($platform, $bytes);
        $installer = new EngineInstallation($this->home, static fn (string $url): string => str_ends_with($url, 'manifest.json') ? $manifest : $bytes);
        $result = $installer->install();
        self::assertTrue($result['ok']);
        self::assertSame(hash('sha256', $bytes), $result['sha256']);
        self::assertSame($result['binary'], $installer->binary());
        self::assertSame('mago', (new MagoAnalysisEngine($result['binary']))->descriptor()->engine);
        file_put_contents($result['binary'], 'corrupt');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('corrupt or incompatible');
        $installer->binary();
    }

    private function manifest(string $platform, string $body): string
    {
        return json_encode(['version' => MagoAnalysisEngine::VERSION, 'mago_version' => MagoAnalysisEngine::MAGO_VERSION,
            'schema' => EngineDescriptor::SCHEMA, 'platforms' => [$platform => ['file' => 'architecture-kit-engine-'.$platform,
                'bytes' => strlen($body), 'sha256' => hash('sha256', $body)]]], JSON_THROW_ON_ERROR);
    }
}
