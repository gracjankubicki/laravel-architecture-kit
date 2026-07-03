<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Audit\Suppression;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Taqie\ArchitectureKit\Support\AuditFinding;

final class Baseline
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
    ) {}

    /**
     * @param  array<int, AuditFinding>  $findings
     */
    public function write(array $findings): void
    {
        $entries = [];

        foreach ($findings as $finding) {
            $key = $this->key($finding);
            $entries[$key] ??= [
                'rule' => $finding->rule,
                'path' => $finding->path,
                'hash' => sha1($finding->message),
                'count' => 0,
            ];
            $entries[$key]['count']++;
        }

        $path = $this->path();
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, json_encode([
            'version' => 1,
            'findings' => array_values($entries),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    /**
     * @param  array<int, AuditFinding>  $findings
     */
    public function apply(array $findings): SuppressionResult
    {
        $entries = $this->read();

        if ($entries === []) {
            return new SuppressionResult($findings, inline: 0, baseline: 0);
        }

        $remaining = [];
        $suppressed = 0;

        foreach ($findings as $finding) {
            $key = $this->key($finding);

            if (($entries[$key] ?? 0) > 0) {
                $entries[$key]--;
                $suppressed++;

                continue;
            }

            $remaining[] = $finding;
        }

        return new SuppressionResult($remaining, inline: 0, baseline: $suppressed);
    }

    public function orphanedCount(array $currentFindings): int
    {
        $entries = $this->read();
        $current = [];

        foreach ($currentFindings as $finding) {
            $current[$this->key($finding)] = true;
        }

        return count(array_filter(
            array_keys($entries),
            fn (string $key): bool => ! isset($current[$key]),
        ));
    }

    /**
     * @return array<string, int>
     */
    private function read(): array
    {
        $path = $this->path();

        if (! $this->files->exists($path)) {
            return [];
        }

        $decoded = json_decode($this->files->get($path), true);

        if (! is_array($decoded) || ($decoded['version'] ?? null) !== 1 || ! is_array($decoded['findings'] ?? null)) {
            throw new InvalidArgumentException('.architecture-kit/baseline.json is invalid or uses an unsupported version.');
        }

        $entries = [];

        foreach ($decoded['findings'] as $entry) {
            if (
                ! is_array($entry)
                || ! is_string($entry['rule'] ?? null)
                || ! is_string($entry['path'] ?? null)
                || ! is_string($entry['hash'] ?? null)
                || ! is_int($entry['count'] ?? null)
            ) {
                throw new InvalidArgumentException('.architecture-kit/baseline.json contains an invalid finding entry.');
            }

            $entries[$entry['rule'].'|'.$entry['path'].'|'.$entry['hash']] = $entry['count'];
        }

        return $entries;
    }

    private function key(AuditFinding $finding): string
    {
        return $finding->rule.'|'.$finding->path.'|'.sha1($finding->message);
    }

    private function path(): string
    {
        return $this->basePath.'/.architecture-kit/baseline.json';
    }
}
