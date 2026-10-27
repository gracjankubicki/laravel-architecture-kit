<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache;

use Closure;
use GracjanKubicki\ArchitectureKit\Standalone\GraphAdmissionRefused;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

/** Immutable data-only generations. The active pointer anchors the complete manifest. */
final readonly class GraphSegmentStore
{
    public const SCHEMA = 1;

    public const ENCODING = 'json-record-map-v1';

    public const COMPRESSED_ENCODING = 'deflate-json-record-map-v1';

    public const SEGMENT_BYTES = 16 * 1024 * 1024;

    public const MANIFEST_BYTES = 4 * 1024 * 1024;

    private ?GraphEpoch $epoch;

    public function __construct(private Filesystem $files, public string $directory, private ?int $memoryLimitBytes = null, private ?Closure $barrier = null, private ?Closure $timing = null)
    {
        $context = RepositoryContext::current();
        $this->epoch = $context === null ? null : new GraphEpoch($context);
    }

    /** Scheduling hint only. This verifies the manifest, never claims a usable decoded index.
     * @return array<string, mixed>|null */
    public function metadata(string $identity): ?array
    {
        try {
            $this->identity($identity);
            $pointer = $this->jsonFile($this->directory.'/active-'.$identity.'.json', 4096);
            $generation = $pointer['generation'] ?? null;
            if (! is_string($generation) || ! preg_match('/^[a-f0-9]{32}$/D', $generation)) {
                return null;
            }
            $raw = $this->readBytes($this->directory.'/'.$generation.'/manifest.json', self::MANIFEST_BYTES);
            if (! hash_equals(hash('sha256', $raw), $pointer['manifest_digest'] ?? '')) {
                return null;
            }
            $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || ($manifest['schema'] ?? null) !== self::SCHEMA || ($manifest['identity'] ?? null) !== $identity
                || ($manifest['generation_id'] ?? null) !== $generation || ! is_array($manifest['metadata'] ?? null)
                || ! is_array($manifest['fields'] ?? null) || ($manifest['state_digest'] ?? null) !== self::digest($manifest['fields'])) {
                return null;
            }

            return $manifest['metadata'];
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $fields
     * @param  list<string>|null  $manifestFields
     * @param  array<string, Closure>  $collectors
     * @return array{status: string, reason: ?string, state?: array<string, array<mixed>>, metadata?: array<string, mixed>, generation?: string|null, decoded?: bool}
     */
    public function read(string $identity, array $fields, ?Closure $decode = null, ?Closure $acceptMetadata = null, ?string $skipGeneration = null, ?array $manifestFields = null, ?Closure $transform = null, array $collectors = [], ?Closure $fieldReady = null, ?Closure $beforeDecode = null): array
    {
        $decoded = false;
        $generation = null;
        try {
            $this->identity($identity);
            $pointerPath = $this->directory.'/active-'.$identity.'.json';
            $this->boundary($pointerPath);
            if (! $this->files->isFile($pointerPath)) {
                return ['status' => 'miss', 'reason' => 'missing'];
            }
            $pointer = $this->jsonFile($pointerPath, 4096);
            $generation = $pointer['generation'] ?? null;
            if (! is_string($generation) || ! preg_match('/^[a-f0-9]{32}$/D', $generation)) {
                throw new RuntimeException('pointer');
            }
            if ($generation === $skipGeneration) {
                return ['status' => 'skip', 'reason' => 'rejected_generation', 'generation' => $generation, 'decoded' => false];
            }
            $root = $this->directory.'/'.$generation;
            $manifestRaw = $this->readBytes($root.'/manifest.json', self::MANIFEST_BYTES);
            if (! hash_equals(hash('sha256', $manifestRaw), $pointer['manifest_digest'] ?? '')) {
                throw new RuntimeException('manifest_digest');
            }
            $manifest = json_decode($manifestRaw, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || ($manifest['schema'] ?? null) !== self::SCHEMA || ($manifest['identity'] ?? null) !== $identity
                || ($manifest['generation_id'] ?? null) !== $generation || array_keys($manifest['fields'] ?? []) !== ($manifestFields ?? $fields)
                || ! is_array($manifest['metadata'] ?? null)) {
                throw new RuntimeException('manifest_shape');
            }
            if (! hash_equals(self::digest($manifest['fields']), $manifest['state_digest'] ?? '')) {
                throw new RuntimeException('state_digest');
            }
            if ($acceptMetadata !== null && ! $acceptMetadata($manifest['metadata'])) {
                throw new RuntimeException('changed_inputs');
            }
            if ($beforeDecode !== null) {
                $reservation = 3 * 1024 * 1024;
                foreach ($fields as $field) {
                    foreach ($manifest['fields'][$field]['segments'] ?? [] as $segment) {
                        if (! is_array($segment) || ! is_int($segment['decoded_bytes'] ?? null) || ! is_int($segment['bytes'] ?? null)
                            || $segment['decoded_bytes'] < 0 || $segment['decoded_bytes'] > self::SEGMENT_BYTES
                            || $segment['bytes'] < 0 || $segment['bytes'] > self::SEGMENT_BYTES) {
                            throw new RuntimeException('segment_shape:'.$field);
                        }
                        $reservation += $segment['decoded_bytes'] * 14 + $segment['bytes'];
                    }
                }
                $beforeDecode($reservation);
            }
            $state = [];
            $seenFiles = [];
            foreach ($fields as $field) {
                $description = $manifest['fields'][$field];
                if (! is_array($description) || array_keys($description) !== ['encoding', 'records', 'canonical_digest', 'segments']
                    || ! in_array($description['encoding'], [self::ENCODING, self::COMPRESSED_ENCODING], true) || ! is_int($description['records']) || $description['records'] < 0
                    || ! is_array($description['segments']) || ! array_is_list($description['segments'])
                    || ! hash_equals(self::digest($description['segments']), $description['canonical_digest'])) {
                    throw new RuntimeException('field:'.$field);
                }
                if ($description['encoding'] === self::COMPRESSED_ENCODING && ! function_exists('gzinflate')) {
                    throw new RuntimeException('codec_unavailable');
                }
                $values = [];
                $consumed = 0;
                foreach ($description['segments'] as $ordinal => $segment) {
                    if (! is_array($segment) || array_keys($segment) !== ['ordinal', 'file', 'bytes', 'decoded_bytes', 'records', 'digest']
                        || $segment['ordinal'] !== $ordinal || ! is_string($segment['file'])
                        || ! preg_match('/^[a-zA-Z]+-[0-9]+\.json$/D', $segment['file']) || isset($seenFiles[$segment['file']])
                        || ! is_int($segment['bytes']) || $segment['bytes'] < 2 || $segment['bytes'] > self::SEGMENT_BYTES
                        || ! is_int($segment['decoded_bytes']) || $segment['decoded_bytes'] < 2 || $segment['decoded_bytes'] > self::SEGMENT_BYTES
                        || ! is_int($segment['records']) || $segment['records'] < 1 || $segment['records'] > 8192) {
                        throw new RuntimeException('segment_shape:'.$field);
                    }
                    $seenFiles[$segment['file']] = true;
                    $this->budget($segment['decoded_bytes'] * 14 + $segment['bytes']);
                    $time = hrtime(true);
                    $raw = $this->readBytes($root.'/'.$segment['file'], $segment['bytes']);
                    $this->measure('io_boundary', $time);
                    $time = hrtime(true);
                    if (strlen($raw) !== $segment['bytes'] || ! hash_equals(hash('sha256', $raw), $segment['digest'])) {
                        throw new RuntimeException('segment_digest:'.$field);
                    }
                    $this->measure('hash', $time);
                    $time = hrtime(true);
                    $decoded = true;
                    if ($description['encoding'] === self::COMPRESSED_ENCODING) {
                        $inflated = @gzinflate($raw, $segment['decoded_bytes']);
                        if ($inflated === false) {
                            throw new RuntimeException('inflate');
                        }
                        $raw = $inflated;
                        unset($inflated);
                    }
                    if (strlen($raw) !== $segment['decoded_bytes']) {
                        throw new RuntimeException('decoded_bytes');
                    }
                    $decoded = true;
                    $pairs = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                    $this->measure('decode', $time);
                    $time = hrtime(true);
                    unset($raw);
                    if (! is_array($pairs) || count($pairs) !== $segment['records']) {
                        throw new RuntimeException('segment_records:'.$field);
                    }
                    $before = count($values);
                    if ($decode !== null) {
                        foreach ($pairs as $key => &$record) {
                            $record = $decode($key, $record);
                        }
                        unset($record);
                    }
                    if ($transform !== null) {
                        foreach ($pairs as $key => &$record) {
                            $record = $transform($field, $key, $record);
                        }
                        unset($record);
                    }
                    if (isset($collectors[$field])) {
                        foreach ($pairs as $key => $record) {
                            if ($collectors[$field]($key, $record) !== true) {
                                throw new RuntimeException('collector_record:'.$field);
                            }
                            $consumed++;
                        }
                    } else {
                        $values += $pairs;
                        if (count($values) !== $before + count($pairs)) {
                            throw new RuntimeException('duplicate_key:'.$field);
                        }
                    }
                    unset($pairs);
                    $this->measure('append', $time);
                }
                if ((isset($collectors[$field]) ? $consumed : count($values)) !== $description['records']) {
                    throw new RuntimeException('field_records:'.$field);
                }
                $state[$field] = $values;
                if ($fieldReady !== null) {
                    $fieldReady($field, $values);
                }
            }

            return ['status' => 'hit', 'reason' => null, 'state' => $state, 'metadata' => $manifest['metadata'], 'generation' => $generation, 'decoded' => $decoded];
        } catch (GraphAdmissionRefused $error) {
            throw $error;
        } catch (Throwable $error) {
            return ['decoded' => $decoded, 'generation' => is_string($generation) ? $generation : null, 'status' => in_array($error->getMessage(), ['budget', 'codec_unavailable'], true) ? 'skip' : 'error', 'reason' => $error->getMessage()];
        }
    }

    /** @param array<string, iterable<int|string, mixed>> $state
     * @param  array<string, mixed>  $metadata
     * @return array{status: string, reason: ?string, generation?: string}
     */
    public function write(string $identity, array $state, array $metadata, ?Closure $current = null): array
    {
        try {
            $this->identity($identity);
            $this->budget(4 * self::SEGMENT_BYTES + self::MANIFEST_BYTES);
            $generation = bin2hex(random_bytes(16));
            $root = $this->directory.'/'.$generation;
            $this->boundary($root);
            $this->files->ensureDirectoryExists($root, 0700);
            chmod($this->directory, 0700);
            chmod($root, 0700);
            $encoding = function_exists('gzdeflate') && function_exists('gzinflate') ? self::COMPRESSED_ENCODING : self::ENCODING;
            $descriptions = [];
            foreach ($state as $field => $records) {
                if (! preg_match('/^[a-zA-Z]+$/D', $field)) {
                    throw new RuntimeException('field_name');
                }
                $segments = [];
                $count = 0;
                $rows = [];
                $bytes = 2;
                foreach ($records as $key => $record) {
                    $this->budget(4 * self::SEGMENT_BYTES + self::MANIFEST_BYTES);
                    $count++;
                    $row = self::json((string) $key).':'.self::json($record);
                    if (strlen($row) + 2 > self::SEGMENT_BYTES) {
                        throw new RuntimeException('segment_budget');
                    }
                    if ($rows !== [] && (count($rows) >= 8192 || $bytes + strlen($row) + 1 > 1024 * 1024)) {
                        $segments[] = $this->segment($root, $field, count($segments), $rows, $encoding);
                        $rows = [];
                        $bytes = 2;
                    }
                    $rows[] = $row;
                    $bytes += strlen($row) + 1;
                }
                if ($rows !== []) {
                    $segments[] = $this->segment($root, $field, count($segments), $rows, $encoding);
                }
                $descriptions[$field] = ['encoding' => $encoding, 'records' => $count,
                    'canonical_digest' => self::digest($segments), 'segments' => $segments];
            }
            $manifest = ['schema' => self::SCHEMA, 'identity' => $identity, 'generation_id' => $generation,
                'metadata' => $metadata, 'fields' => $descriptions, 'state_digest' => self::digest($descriptions)];
            $raw = self::json($manifest);
            if (strlen($raw) > self::MANIFEST_BYTES) {
                throw new RuntimeException('manifest_budget');
            }
            $this->put($root.'/manifest.json', $raw);
            ($this->barrier ?? static function (): void {})('before_publish');
            // Serialise competing publishers. Freshness is checked while holding the lock.
            $lockPath = $this->directory.'/publish.lock';
            $this->boundary($lockPath);
            $lock = fopen($lockPath, 'c');
            if ($lock === false) {
                throw new RuntimeException('lock');
            }
            chmod($lockPath, 0600);
            try {
                if (! flock($lock, LOCK_EX)) {
                    throw new RuntimeException('lock');
                }
                if ($current !== null && ! $current()) {
                    return ['status' => 'skip', 'reason' => 'changed_inputs'];
                }
                $temporary = $root.'/active.tmp';
                $this->put($temporary, self::json(['generation' => $generation, 'manifest_digest' => hash('sha256', $raw)]));
                $pointerPath = $this->directory.'/active-'.$identity.'.json';
                $this->boundary($pointerPath);
                $publish = fn (): bool => $this->files->move($temporary, $pointerPath);
                if (! ($this->epoch === null ? $publish() : $this->epoch->publish($publish))) {
                    throw new RuntimeException('publish_io');
                }
                ($this->barrier ?? static function (): void {})('after_publish');
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }

            return ['status' => 'success', 'reason' => null, 'generation' => $generation];
        } catch (Throwable $error) {
            return ['status' => in_array($error->getMessage(), ['budget', 'segment_budget', 'manifest_budget'], true) ? 'skip' : 'error', 'reason' => $error->getMessage()];
        }
    }

    /** @param list<string> $rows
     * @return array{ordinal: int, file: string, bytes: int, decoded_bytes: int, records: int, digest: string}
     */
    private function segment(string $root, string $field, int $ordinal, array $rows, string $encoding): array
    {
        $this->budget(4 * self::SEGMENT_BYTES + self::MANIFEST_BYTES);
        $raw = '{'.implode(',', $rows).'}';
        $decodedBytes = strlen($raw);
        if ($decodedBytes > self::SEGMENT_BYTES) {
            throw new RuntimeException('segment_budget');
        }
        if ($encoding === self::COMPRESSED_ENCODING) {
            $compressed = gzdeflate($raw, 1);
            if ($compressed === false) {
                throw new RuntimeException('deflate');
            }
            $raw = $compressed;
        }
        $file = $field.'-'.$ordinal.'.json';
        $this->put($root.'/'.$file, $raw);
        ($this->barrier ?? static function (): void {})('segment', $field);

        return ['ordinal' => $ordinal, 'file' => $file, 'bytes' => strlen($raw), 'decoded_bytes' => $decodedBytes, 'records' => count($rows), 'digest' => hash('sha256', $raw)];
    }

    private function put(string $path, string $raw): void
    {
        $this->boundary($path);
        if ($this->files->put($path, $raw) !== strlen($raw)) {
            throw new RuntimeException('write_io');
        }
        chmod($path, 0600);
    }

    private function boundary(string $path): void
    {
        if (! str_starts_with($this->directory, '/') || str_contains($path, "\0") || preg_match('~(^|/)\.\.?(/|$)~', $path)
            || ! str_starts_with($path, rtrim($this->directory, '/').'/')) {
            throw new RuntimeException('unsafe_store');
        }
        $cursor = '';
        foreach (explode('/', $path) as $part) {
            $cursor .= ($cursor === '/' ? '' : '/').$part;
            if (is_link($cursor)) {
                throw new RuntimeException('unsafe_store');
            }
        }
    }

    /** Clear only this dedicated product directory, never a path supplied by its contents. */
    public function clear(): int
    {
        try {
            $this->boundary($this->directory.'/clear-check');
            if (! $this->files->isDirectory($this->directory)) {
                return 0;
            }
            $active = count($this->files->glob($this->directory.'/active-*.json'));

            return $this->files->deleteDirectory($this->directory) ? $active : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function measure(string $stage, int $start): void
    {
        if ($this->timing !== null) {
            ($this->timing)($stage, (hrtime(true) - $start) / 1e9);
        }
    }

    private function identity(string $identity): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $identity)) {
            throw new RuntimeException('identity');
        }
    }

    private function budget(int $extra): void
    {
        $limit = MemoryLimit::bytes($this->memoryLimitBytes);
        if ($limit !== null && memory_get_usage(true) + $extra > $limit) {
            throw new RuntimeException('budget');
        }
    }

    private function readBytes(string $path, int $maximum): string
    {
        $this->boundary($path);
        clearstatcache(true, $path);
        if (! $this->files->isFile($path) || $this->files->size($path) > $maximum) {
            throw new RuntimeException('missing_or_oversize');
        }
        $raw = $this->files->get($path);
        if (strlen($raw) > $maximum) {
            throw new RuntimeException('oversize');
        }

        return $raw;
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path, int $maximum): array
    {
        $value = json_decode($this->readBytes($path, $maximum), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new RuntimeException('json_shape');
        }

        return $value;
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', self::json($value));
    }
}
