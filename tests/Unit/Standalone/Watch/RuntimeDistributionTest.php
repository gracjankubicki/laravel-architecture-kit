<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\Watch;

use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimeBinary;
use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimeInstallation;
use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimeManifest;
use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimePlatform;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class RuntimeDistributionTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = realpath(sys_get_temp_dir()).'/kit-watch-distribution-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->home);
    }

    public function test_platform_mapping_and_missing_installation_never_download(): void
    {
        foreach ([['Darwin', 'arm64', 'macos-arm64'], ['Darwin', 'x86_64', 'macos-x64'], ['Linux', 'aarch64', 'linux-arm64'], ['Linux', 'amd64', 'linux-x64']] as [$os, $cpu, $expected]) {
            self::assertSame($expected, RuntimePlatform::identify($os, $cpu));
        }
        $installer = new RuntimeInstallation($this->home, static function (): string {
            self::fail('Status must never download.');
        });
        try {
            $installer->binary();
            self::fail('Missing runtime was accepted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('runtime install', $error->getMessage());
            self::assertDirectoryDoesNotExist($this->home);
        }
        $this->expectException(RuntimeException::class);
        RuntimePlatform::identify('Windows', 'arm64');
    }

    public function test_manifest_rejects_version_target_linkage_count_hash_and_size_before_download(): void
    {
        $platform = RuntimePlatform::current();
        foreach (['runtime_version', 'target', 'linkage', 'count', 'sha256', 'bytes-zero', 'bytes-over', 'bytes-float'] as $case) {
            $manifest = RuntimeFixture::manifest('content');
            match ($case) {
                'runtime_version' => $manifest['runtime_version'] = '999',
                'target' => $manifest['platforms'][$platform]['target'] = 'other',
                'linkage' => $manifest['platforms'][$platform]['linkage'] = 'dynamic-glibc',
                'count' => $manifest['platforms']['extra'] = [],
                'sha256' => $manifest['platforms'][$platform]['sha256'] = 'a',
                'bytes-zero' => $manifest['platforms'][$platform]['bytes'] = 0,
                'bytes-over' => $manifest['platforms'][$platform]['bytes'] = RuntimeManifest::MAX_BYTES + 1,
                'bytes-float' => $manifest['platforms'][$platform]['bytes'] = 7.1,
            };
            $calls = [];
            $installer = new RuntimeInstallation($this->home, static function (string $url) use (&$calls, $manifest): string {
                $calls[] = $url;

                return json_encode($manifest, JSON_THROW_ON_ERROR);
            });
            try {
                $installer->install();
                self::fail('Invalid manifest accepted: '.$case);
            } catch (RuntimeException) {
                self::assertCount(1, $calls, $case);
                self::assertDirectoryDoesNotExist($this->home);
            }
        }
    }

    public function test_corrupt_download_is_rejected_before_file_or_process_creation(): void
    {
        $manifest = json_encode(RuntimeFixture::manifest('expected'), JSON_THROW_ON_ERROR);
        $installer = new RuntimeInstallation($this->home, static fn (string $url): string => str_ends_with($url, 'manifest.json') ? $manifest : 'tampered');
        try {
            $installer->install();
            self::fail('Corrupt artifact accepted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('SHA-256 or size', $error->getMessage());
            self::assertDirectoryDoesNotExist($this->home);
        }
    }

    public function test_binary_boundary_symlink_and_local_hash_are_checked_before_execution(): void
    {
        mkdir($this->home, 0700, true);
        $marker = $this->home.'/executed';
        $body = "#!/bin/sh\n".'touch '.escapeshellarg($marker)."\n";
        $path = $this->home.'/binary';
        file_put_contents($path, $body);
        chmod($path, 0700);
        $manifest = new RuntimeManifest(RuntimeFixture::manifest($body));
        symlink($path, $this->home.'/alias');
        foreach ([$this->home.'/alias', $path] as $candidate) {
            if ($candidate === $path) {
                file_put_contents($path, str_repeat('x', strlen($body)));
            }
            $binary = new RuntimeBinary($candidate, $this->home, $manifest, RuntimePlatform::current());
            try {
                $binary->descriptor();
                self::fail('Unsafe artifact was executed.');
            } catch (RuntimeException) {
                self::assertFileDoesNotExist($marker);
            }
        }
    }

    public function test_real_installation_version_handshake_and_reverification_of_local_bytes(): void
    {
        $body = file_get_contents(RuntimeFixture::native());
        self::assertIsString($body);
        $manifest = json_encode(RuntimeFixture::manifest($body), JSON_THROW_ON_ERROR);
        $calls = [];
        $installer = new RuntimeInstallation($this->home, static function (string $url) use ($body, $manifest, &$calls): string {
            $calls[] = $url;

            return str_ends_with($url, 'manifest.json') ? $manifest : $body;
        });
        $result = $installer->install();
        self::assertTrue($result['ok']);
        self::assertCount(2, $calls);
        self::assertStringContainsString('/runtime-v0.1.0/', $calls[0]);
        self::assertSame('0.1.0', $installer->binary()->descriptor()['runtime_version']);
        self::assertDirectoryDoesNotExist($this->home.'/engines');
        file_put_contents($installer->binary()->path, 'corrupt');
        $this->expectException(RuntimeException::class);
        $installer->binary();
    }

    public function test_interrupted_download_process_never_becomes_ready_and_explicit_retry_succeeds(): void
    {
        $body = file_get_contents(RuntimeFixture::native());
        self::assertIsString($body);
        mkdir($this->home, 0700, true);
        $manifest = json_encode(RuntimeFixture::manifest($body), JSON_THROW_ON_ERROR);
        file_put_contents($this->home.'/manifest-fixture.json', $manifest);
        $marker = $this->home.'/downloading.marker';
        $script = '<?php require '.var_export(dirname(__DIR__, 4).'/vendor/autoload.php', true).'; ';
        $script .= '$installer = new \\GracjanKubicki\\ArchitectureKit\\Standalone\\Distribution\\RuntimeInstallation('.var_export($this->home, true).', static function(string $url): string {';
        $script .= 'if (str_ends_with($url, "manifest.json")) { return file_get_contents('.var_export($this->home.'/manifest-fixture.json', true).'); } ';
        $script .= 'file_put_contents('.var_export($marker, true).', "controlled own download"); usleep(30000000); return ""; }); $installer->install();';
        file_put_contents($this->home.'/interrupt.php', $script);
        $process = new Process([PHP_BINARY, $this->home.'/interrupt.php'], '/', timeout: 3);
        $process->start();
        try {
            $deadline = microtime(true) + 2;
            while (! is_file($marker) && $process->isRunning() && microtime(true) < $deadline) {
                usleep(1000);
            }
            self::assertFileExists($marker, $process->getErrorOutput());
            // The own installer is blocked inside local download, before it can spawn any executable.
            $process->stop(0, 9);
            $installer = new RuntimeInstallation($this->home, static fn (string $url): string => str_ends_with($url, 'manifest.json') ? $manifest : $body);
            try {
                $installer->binary();
                self::fail('Interrupted installation was considered ready.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('runtime is not installed', $error->getMessage());
            }
            self::assertTrue($installer->install()['ok']);
            self::assertSame('0.1.0', $installer->binary()->descriptor()['runtime_version']);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0, 9);
            }
        }
    }

    public function test_wrong_version_and_handshake_run_without_roots_and_leave_no_ready_installation(): void
    {
        mkdir($this->home, 0700, true);
        $log = $this->home.'/probe.log';
        $version = ['runtime_version' => '0.1.0', 'protocol' => 'architecture-kit-watch-v1', 'notify_version' => '8.2.1', 'platform' => RuntimePlatform::current(), 'target' => RuntimePlatform::TARGETS[RuntimePlatform::current()], 'linkage' => RuntimePlatform::linkage(RuntimePlatform::current()), 'capabilities' => ['events-v1', 'continuity-loss-v1']];
        foreach (['version', 'handshake'] as $case) {
            $body = "#!/bin/sh\n"."printf '%s\\n' \"\$*\" >> ".escapeshellarg($log)."\n";
            if ($case === 'version') {
                $body .= "printf '{}\\n'\n";
            } else {
                $body .= "if [ \"\$1\" = \"--version\" ]; then printf '%s\\n' ".escapeshellarg(json_encode($version))."; exit; fi\n";
                $body .= "printf '{}\\n'\n"."IFS= read -r line; printf '%s\\n' \"\$line\" >> ".escapeshellarg($log)."\n";
            }
            $manifest = json_encode(RuntimeFixture::manifest($body), JSON_THROW_ON_ERROR);
            $installer = new RuntimeInstallation($this->home, static fn (string $url): string => str_ends_with($url, 'manifest.json') ? $manifest : $body);
            try {
                $installer->install();
                self::fail('Bad probe accepted.');
            } catch (RuntimeException) {
                self::assertStringNotContainsString('"kind":"watch"', (string) file_get_contents($log));
                self::assertFileDoesNotExist($this->home.'/runtimes/0.1.0/'.RuntimePlatform::current().'/architecture-kit-runtime');
            }
        }
    }
}
