<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis\Distribution;

use Closure;
use GracjanKubicki\ArchitectureKit\Analysis\EngineDescriptor;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use Illuminate\Http\Client\Factory;
use RuntimeException;

/** Installation is an explicit operation on the tool home, never a project dependency. */
final readonly class EngineInstallation
{
    public const RELEASE_URL = 'https://github.com/gracjankubicki/laravel-architecture-kit/releases/download/engine-v';

    public const MAX_BYTES = 134217728;

    /** @param (Closure(string, int): string)|null $download */
    public function __construct(private string $home, private ?Closure $download = null)
    {
        if (! str_starts_with($home, '/') || preg_match('~/\.\.?(/|$)~', $home)) {
            throw new RuntimeException('Engine home must be an absolute external directory without traversal.');
        }
        $ancestor = $home;
        while (! file_exists($ancestor) && ! is_link($ancestor) && dirname($ancestor) !== $ancestor) {
            $ancestor = dirname($ancestor);
        }
        $canonical = realpath($ancestor);
        if ($canonical === false || is_link($home)) {
            throw new RuntimeException('Engine home has an unavailable or unsafe parent.');
        }
        for ($cursor = $canonical; ; $cursor = dirname($cursor)) {
            if (file_exists($cursor.'/.git') || is_file($cursor.'/composer.json')) {
                throw new RuntimeException('Engine home must be outside repository and Composer project directories, including symlink parents.');
            }
            if (dirname($cursor) === $cursor) {
                break;
            }
        }
    }

    public function binary(?string $platform = null): string
    {
        $platform ??= EnginePlatform::current();
        $directory = $this->directory($platform);
        if (! is_dir($directory)) {
            throw new RuntimeException('Mago engine is not installed. Run architecture-kit engine install or explicitly select PHP.');
        }
        StateStore::assertBoundary($directory, $this->home);
        $metadata = (new StateStore($directory, $this->home))->read('installation');
        $path = $directory.'/architecture-kit-engine';
        $expected = $metadata['sha256'] ?? null;
        if (($metadata['version'] ?? null) !== MagoAnalysisEngine::VERSION || ($metadata['platform'] ?? null) !== $platform
            || ! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/D', $expected)
            || ! is_file($path) || is_link($path) || ! is_executable($path)
            || ! hash_equals($expected, hash_file('sha256', $path) ?: '')) {
            throw new RuntimeException('Mago engine installation is corrupt or incompatible. Reinstall the engine or explicitly select PHP.');
        }

        return $path;
    }

    /** @return array<string, mixed> */
    public function install(?string $platform = null): array
    {
        $platform ??= EnginePlatform::current();
        $directory = $this->directory($platform);
        $url = self::RELEASE_URL.MagoAnalysisEngine::VERSION.'/';
        $manifest = json_decode($this->fetch($url.'manifest.json', 65536), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['version'] ?? null) !== MagoAnalysisEngine::VERSION
            || ($manifest['mago_version'] ?? null) !== MagoAnalysisEngine::MAGO_VERSION
            || ($manifest['schema'] ?? null) !== EngineDescriptor::SCHEMA || ! is_array($manifest['platforms'] ?? null)) {
            throw new RuntimeException('The engine release manifest does not match this Architecture Kit version.');
        }
        $artifact = $manifest['platforms'][$platform] ?? null;
        $name = 'architecture-kit-engine-'.$platform;
        if (! is_array($artifact) || ($artifact['file'] ?? null) !== $name
            || ! is_string($artifact['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $artifact['sha256'])
            || ! is_int($artifact['bytes'] ?? null) || $artifact['bytes'] < 1 || $artifact['bytes'] > self::MAX_BYTES) {
            throw new RuntimeException('The release has no compatible engine artifact for '.$platform.'. Select PHP explicitly.');
        }
        $body = $this->fetch($url.$name, $artifact['bytes']);
        if (strlen($body) !== $artifact['bytes'] || ! hash_equals($artifact['sha256'], hash('sha256', $body))) {
            throw new RuntimeException('Engine download failed its SHA-256 or size check. Nothing was installed.');
        }
        new StateStore($this->home);
        $store = new StateStore($directory, $this->home);
        $metadata = $store->update('installation', function (array $old) use ($body, $artifact, $directory, $platform): array {
            $temporary = tempnam($directory, '.engine-');
            if ($temporary === false) {
                throw new RuntimeException('Engine staging file is unavailable.');
            }
            try {
                if (file_put_contents($temporary, $body) !== strlen($body) || ! chmod($temporary, 0700)) {
                    throw new RuntimeException('Engine download could not be staged.');
                }
                // Verify the actual downloaded executable before replacing an installed one.
                (new MagoAnalysisEngine($temporary))->descriptor();
                if (! rename($temporary, $directory.'/architecture-kit-engine')) {
                    throw new RuntimeException('Engine installation could not be published locally.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }

            return ['version' => MagoAnalysisEngine::VERSION, 'mago_version' => MagoAnalysisEngine::MAGO_VERSION,
                'schema' => EngineDescriptor::SCHEMA, 'platform' => $platform, 'sha256' => $artifact['sha256']];
        });

        return ['ok' => true, ...$metadata, 'binary' => $this->binary($platform)];
    }

    private function directory(string $platform): string
    {
        if (! isset(EnginePlatform::TARGETS[$platform])) {
            throw new RuntimeException('Unsupported engine distribution platform.');
        }

        return rtrim($this->home, '/').'/engines/'.MagoAnalysisEngine::VERSION.'/'.$platform;
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
                        throw new RuntimeException('Engine download exceeds its size limit.');
                    }
                },
            ])->connectTimeout(10)->timeout(60)->get($url)->throw()->body();
        }
        if (strlen($body) > $limit) {
            throw new RuntimeException('Engine download exceeds its size limit.');
        }

        return $body;
    }
}
