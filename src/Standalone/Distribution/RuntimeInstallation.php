<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Distribution;

use Closure;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchProtocol;
use Illuminate\Http\Client\Factory;
use RuntimeException;

/** Explicit installation from the fixed release endpoint, independent of the analysis backend. */
final readonly class RuntimeInstallation
{
    public const RELEASE_URL = 'https://github.com/gracjankubicki/laravel-architecture-kit/releases/download/runtime-v';

    /** @param (Closure(string, int): string)|null $download */
    public function __construct(private string $home, private ?Closure $download = null)
    {
        if (! str_starts_with($home, '/') || preg_match('~/\.\.?(/|$)~', $home)) {
            throw new RuntimeException('Runtime home must be an absolute external directory without traversal.');
        }
        $ancestor = $home;
        while (! file_exists($ancestor) && ! is_link($ancestor) && dirname($ancestor) !== $ancestor) {
            $ancestor = dirname($ancestor);
        }
        $canonical = realpath($ancestor);
        if ($canonical === false || is_link($home)) {
            throw new RuntimeException('Runtime home has an unavailable or unsafe parent.');
        }
        for ($cursor = $canonical; ; $cursor = dirname($cursor)) {
            if (file_exists($cursor.'/.git') || is_file($cursor.'/composer.json')) {
                throw new RuntimeException('Runtime home must be outside repository and Composer project directories, including symlink parents.');
            }
            if (dirname($cursor) === $cursor) {
                break;
            }
        }
    }

    public function binary(?string $platform = null): RuntimeBinary
    {
        $platform ??= RuntimePlatform::current();
        $directory = $this->directory($platform);
        if (! is_dir($directory)) {
            throw new RuntimeException('native_watch_unavailable: runtime is not installed; run runtime install explicitly or use background reconciliation.');
        }
        StateStore::assertBoundary($directory, $this->home);
        $metadata = (new StateStore($directory, $this->home))->read('installation');
        if (($metadata['platform'] ?? null) !== $platform || ! is_array($metadata['manifest'] ?? null)) {
            throw new RuntimeException('Runtime installation metadata is incompatible.');
        }
        $binary = new RuntimeBinary($directory.'/architecture-kit-runtime', $this->home, new RuntimeManifest($metadata['manifest']), $platform);
        $binary->verify();

        return $binary;
    }

    /** @return array<string, mixed> */
    public function install(?string $platform = null): array
    {
        $platform ??= RuntimePlatform::current();
        $directory = $this->directory($platform);
        $url = self::RELEASE_URL.WatchProtocol::VERSION.'/';
        $value = json_decode($this->fetch($url.'manifest.json', 65536), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new RuntimeException('Runtime manifest must be an object.');
        }
        $manifest = new RuntimeManifest($value);
        $artifact = $manifest->artifact($platform);
        $body = $this->fetch($url.$artifact['file'], $artifact['bytes']);
        if (strlen($body) !== $artifact['bytes'] || ! hash_equals($artifact['sha256'], hash('sha256', $body))) {
            throw new RuntimeException('Runtime download failed its SHA-256 or size check. Nothing was installed.');
        }
        new StateStore($this->home);
        $store = new StateStore($directory, $this->home);
        $store->update('installation', function (array $old) use ($body, $directory, $platform, $manifest): array {
            $temporary = tempnam($directory, '.runtime-');
            if ($temporary === false) {
                throw new RuntimeException('Runtime staging file is unavailable.');
            }
            try {
                if (file_put_contents($temporary, $body) !== strlen($body) || ! chmod($temporary, 0700)) {
                    throw new RuntimeException('Runtime download could not be staged.');
                }
                $binary = new RuntimeBinary($temporary, $this->home, $manifest, $platform);
                $binary->descriptor();
                // Root-free protocol probe: no repository paths are ever supplied during installation.
                RuntimeProbe::handshake($binary);
                if (is_link($directory.'/architecture-kit-runtime') || ! rename($temporary, $directory.'/architecture-kit-runtime')) {
                    throw new RuntimeException('Runtime installation could not be published locally.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }

            return ['platform' => $platform, 'manifest' => $manifest->value];
        });

        return ['ok' => true, 'runtime_version' => WatchProtocol::VERSION, 'platform' => $platform, 'binary' => $this->binary($platform)->path];
    }

    private function directory(string $platform): string
    {
        RuntimePlatform::linkage($platform);

        return rtrim($this->home, '/').'/runtimes/'.WatchProtocol::VERSION.'/'.$platform;
    }

    private function fetch(string $url, int $limit): string
    {
        if ($this->download !== null) {
            $body = ($this->download)($url, $limit);
        } else {
            $body = (new Factory)->withOptions([
                'allow_redirects' => ['max' => 5, 'protocols' => ['https']],
                'progress' => static function (float $total, float $received) use ($limit): void {
                    if ($total > $limit || $received > $limit) {
                        throw new RuntimeException('Runtime download exceeds its size limit.');
                    }
                },
            ])->connectTimeout(10)->timeout(60)->get($url)->throw()->body();
        }
        if (strlen($body) > $limit) {
            throw new RuntimeException('Runtime download exceeds its size limit.');
        }

        return $body;
    }
}
