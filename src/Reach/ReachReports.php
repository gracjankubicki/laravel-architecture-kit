<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Reach;

use DirectoryIterator;
use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Immutable report bodies. Continuation reads metadata, never project PHP bodies. */
final readonly class ReachReports
{
    private const DIRECTORY = 'storage/framework/cache/architecture-kit/reach';

    private const MAX_BYTES = 16000000;

    public function __construct(private Filesystem $files, private string $base) {}

    /** @param list<string> $exclude
     * @return array<string, list<int>>
     */
    public function signature(array $exclude): array
    {
        $states = [];
        $queue = [''];
        $entries = 0;
        for ($i = 0; $i < count($queue); $i++) {
            $relative = $queue[$i];
            $directory = $this->base.($relative === '' ? '' : '/'.$relative);
            foreach (new DirectoryIterator($directory) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }
                $child = $entry->getFilename();
                if (++$entries > 20000 || ! $this->room(65536)) {
                    throw new InvalidArgumentException('Source metadata traversal/memory limit reached. Narrow the project or increase memory.');
                }
                $path = $relative === '' ? $child : $relative.'/'.$child;
                // No source contents are read here. Ignore forbidden trees and report storage.
                if ($path === self::DIRECTORY || in_array($child, ['vendor', 'node_modules', '.git', '.env'], true) || is_link($directory.'/'.$child)) {
                    continue;
                }
                if (is_dir($directory.'/'.$child)) {
                    $queue[] = $path;
                } elseif (str_ends_with(strtolower($path), '.php') || $path === 'composer.json') {
                    if (Str::is($exclude, $path) && ! RoleClassifier::isTestPath($path)) {
                        continue;
                    }
                    $states[$path] = DiscoverySettings::stat($this->base.'/'.$path);
                }
            }
        }
        foreach (['config/architectures.php', 'composer.json'] as $required) {
            if (! DiscoverySettings::safe($this->base, $required)) {
                throw new InvalidArgumentException('Unsafe configuration source path.');
            }
            $states[$required] = DiscoverySettings::stat($this->base.'/'.$required);
        }
        ksort($states);

        return $states;
    }

    /** @param array<string, mixed> $report
     * @param  array<string, list<int>>  $signature
     * @param  list<string>  $exclude
     */
    public function save(array $report, array $signature, array $exclude): string
    {
        $this->directory();
        $estimate = $this->estimate([$report, $signature, $exclude]);
        if ($estimate > self::MAX_BYTES || ! $this->room($estimate * 3 + 65536)) {
            throw new InvalidArgumentException('Report persistence memory limit reached. Increase memory and rerun reach.');
        }
        $body = json_encode(['report' => $report, 'signature' => $signature, 'exclude' => $exclude], JSON_THROW_ON_ERROR);
        if (strlen($body) + 65 > self::MAX_BYTES) {
            throw new InvalidArgumentException('Report persistence size limit reached. Narrow scope/depth and rerun reach.');
        }
        $body = hash('sha256', $body)."\n".$body;
        $id = bin2hex(random_bytes(16));
        $path = $this->base.'/'.self::DIRECTORY.'/'.$id.'.json';
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new InvalidArgumentException('Report storage unavailable.');
        }
        try {
            if (fwrite($handle, $body) !== strlen($body)) {
                throw new InvalidArgumentException('Report storage write incomplete.');
            }
        } finally {
            fclose($handle);
        }

        return $id;
    }

    /** @return array<string, mixed> */
    public function load(string $id): array
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new InvalidArgumentException('Invalid report ID. Start a new reach analysis.');
        }
        $this->directory();
        $path = self::DIRECTORY.'/'.$id.'.json';
        if (! DiscoverySettings::safe($this->base, $path) || ! $this->files->isFile($this->base.'/'.$path)) {
            throw new InvalidArgumentException('Report unavailable. Start a new reach analysis.');
        }
        $size = $this->files->size($this->base.'/'.$path);
        if ($size > self::MAX_BYTES || ! $this->room($size * 8 + 65536)) {
            throw new InvalidArgumentException('Report read size/memory limit reached. Increase memory or start a narrower analysis.');
        }
        $body = $this->files->get($this->base.'/'.$path);
        $json = substr($body, 65);
        if (substr($body, 0, 65) !== hash('sha256', $json)."\n") {
            throw new InvalidArgumentException('Report corrupt. Start a new reach analysis.');
        }
        $saved = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        if (! is_array($saved) || ! is_array($saved['report'] ?? null) || ! is_array($saved['signature'] ?? null) || ! is_array($saved['exclude'] ?? null) || count(array_filter($saved['exclude'], 'is_string')) !== count($saved['exclude'])) {
            throw new InvalidArgumentException('Report corrupt. Start a new reach analysis.');
        }
        if (! ($saved['report']['reach']['fresh'] ?? false) || $this->signature($saved['exclude']) !== $saved['signature']) {
            throw new InvalidArgumentException('Project sources changed. Start a new reach analysis; previous pages are stale.');
        }

        return $saved['report'];
    }

    private function directory(): void
    {
        if (! DiscoverySettings::safe($this->base, self::DIRECTORY)) {
            throw new InvalidArgumentException('Unsafe report storage path.');
        }
        $this->files->ensureDirectoryExists($this->base.'/'.self::DIRECTORY);
    }

    /** @param array<mixed> $values */
    private function estimate(array $values): int
    {
        $bytes = 0;
        foreach ($values as $key => $value) {
            $bytes += strlen((string) $key) + 32;
            $bytes += is_array($value) ? $this->estimate($value) : (is_string($value) ? strlen($value) * 2 : 16);
            if ($bytes > self::MAX_BYTES) {
                return $bytes;
            }
        }

        return $bytes;
    }

    private function room(int $bytes): bool
    {
        $limit = MemoryLimit::bytes();

        return $limit === null || memory_get_usage(true) + $bytes < $limit * 0.8;
    }
}
