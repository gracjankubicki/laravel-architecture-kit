<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use Throwable;

/** Private, bounded data facts. Never stores syntax, values, reports or route snapshots. */
final class AnalysisFactsCache
{
    private const MAX_BYTES = 3_000_000;

    private const MAX_ENTRIES = 10_000;

    /** @var array<string, true> */
    private array $withoutFunctions = [];

    /** @var array<string, 'agent'|'other'> */
    private array $relationships = [];

    private ?string $path = null;

    public function __construct(string $directory, private readonly string $boundary, ?string $identity)
    {
        if ($identity === null) {
            return; // Worker facts are transient and published only by the parent.
        }
        try {
            new StateStore($directory, $boundary);
            $path = $directory.'/facts-'.hash('sha256', $identity).'.json';
            if (is_link($path)) {
                return;
            }
            $this->path = $path;
            if (! is_file($path)) {
                return;
            }
            $size = filesize($path);
            $limit = MemoryLimit::bytes();
            if ($size === false || $size > self::MAX_BYTES || ($limit !== null && memory_get_usage(true) + $size * 16 > $limit * 0.8)) {
                return;
            }
            $raw = file_get_contents($path);
            if ($raw === false || strlen($raw) < 66 || $raw[64] !== "\n"
                || ! hash_equals(substr($raw, 0, 64), hash('sha256', substr($raw, 65)))) {
                return;
            }
            $data = json_decode(substr($raw, 65), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($data) || array_keys($data) !== ['without_functions', 'relationships']) {
                return;
            }
            foreach (['without_functions', 'relationships'] as $channel) {
                if (! is_array($data[$channel]) || count($data[$channel]) > self::MAX_ENTRIES) {
                    return;
                }
                foreach ($data[$channel] as $key => $value) {
                    if (! is_string($key) || strlen($key) !== 64 || ! ctype_xdigit($key)
                        || ($channel === 'without_functions' ? $value !== true : ! in_array($value, ['agent', 'other'], true))) {
                        return;
                    }
                }
            }
            $this->withoutFunctions = $data['without_functions'];
            $this->relationships = $data['relationships'];
        } catch (Throwable) {
            // Optional cache failures do not change the analysis decision.
        }
    }

    public function hasNoFunctions(string $path): bool
    {
        return isset($this->withoutFunctions[hash('sha256', $path)]);
    }

    public function rememberNoFunctions(string $path): void
    {
        if (count($this->withoutFunctions) < self::MAX_ENTRIES) {
            $this->withoutFunctions[hash('sha256', $path)] = true;
        }
    }

    public function relationship(string $key): ?string
    {
        return $this->relationships[hash('sha256', $key)] ?? null;
    }

    public function rememberRelationship(string $key, string $state): void
    {
        if (count($this->relationships) < self::MAX_ENTRIES && in_array($state, ['agent', 'other'], true)) {
            $this->relationships[hash('sha256', $key)] = $state;
        }
    }

    /** @return array{without_functions: array<string, true>, relationships: array<string, 'agent'|'other'>} */
    public function export(): array
    {
        return ['without_functions' => $this->withoutFunctions, 'relationships' => $this->relationships];
    }

    /** @return \Generator<int, array<string, array<string, true|'agent'|'other'>>> */
    public function chunks(): \Generator
    {
        foreach ($this->export() as $channel => $entries) {
            $chunk = [];
            foreach ($entries as $key => $value) {
                $chunk[$key] = $value;
                if (count($chunk) === 64) {
                    yield [$channel => $chunk];
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                yield [$channel => $chunk];
            }
        }
    }

    /** Merge successful facts from isolated workers, with the same bounded schema.
     * @param  array<string, mixed>  $data
     */
    public function merge(array $data): void
    {
        foreach (['without_functions', 'relationships'] as $channel) {
            foreach (is_array($data[$channel] ?? null) ? $data[$channel] : [] as $key => $value) {
                if (! is_string($key) || strlen($key) !== 64 || ! ctype_xdigit($key)) {
                    continue;
                }
                if ($channel === 'without_functions' && $value === true && count($this->withoutFunctions) < self::MAX_ENTRIES) {
                    $this->withoutFunctions[$key] = true;
                } elseif ($channel === 'relationships' && in_array($value, ['agent', 'other'], true) && count($this->relationships) < self::MAX_ENTRIES) {
                    $this->relationships[$key] = $value;
                }
            }
        }
    }

    public static function clear(string $directory, string $boundary): int
    {
        StateStore::assertBoundary($directory, $boundary);
        $removed = 0;
        foreach (glob($directory.'/facts-*.json') ?: [] as $path) {
            if (! is_link($path) && preg_match('/^facts-[a-f0-9]{64}\.json$/D', basename($path)) && unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    /** Atomic publication after the caller has checked input freshness. */
    public function save(): void
    {
        if ($this->path === null) {
            return;
        }
        $temp = false;
        try {
            StateStore::assertBoundary(dirname($this->path), $this->boundary);
            $limit = MemoryLimit::bytes();
            $estimate = (count($this->withoutFunctions) + count($this->relationships)) * 100;
            if ($estimate > self::MAX_BYTES || ($limit !== null && memory_get_usage(true) + $estimate * 3 > $limit * 0.8)) {
                return;
            }
            $body = json_encode(['without_functions' => $this->withoutFunctions, 'relationships' => $this->relationships], JSON_THROW_ON_ERROR);
            if (strlen($body) + 65 > self::MAX_BYTES || self::isSymlink($this->path)) {
                return;
            }
            $temp = tempnam(dirname($this->path), '.facts-');
            if ($temp === false || ! chmod($temp, 0600)) {
                return;
            }
            $body = hash('sha256', $body)."\n".$body;
            if (file_put_contents($temp, $body) !== strlen($body)) {
                return;
            }
            StateStore::assertBoundary(dirname($this->path), $this->boundary);
            if (self::isSymlink($this->path) || ! rename($temp, $this->path)) {
                return;
            }
            $files = array_values(array_filter(glob(dirname($this->path).'/facts-*.json') ?: [], fn (string $file): bool => $file !== $this->path));
            usort($files, static fn (string $a, string $b): int => (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0));
            foreach (array_slice($files, 3) as $file) {
                if ($file !== $this->path && ! is_link($file) && preg_match('/^facts-[a-f0-9]{64}\.json$/D', basename($file))) {
                    unlink($file);
                }
            }
        } catch (Throwable) {
            // Retry through analysis on the next invocation.
        } finally {
            if (is_string($temp) && is_file($temp)) {
                unlink($temp);
            }
        }
    }

    /** @phpstan-impure */
    private static function isSymlink(string $path): bool
    {
        // The filesystem can change between writing and publishing the entry.
        clearstatcache(true, $path);

        return is_link($path);
    }
}
