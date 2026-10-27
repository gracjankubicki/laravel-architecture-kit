<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Capture;

use Generator;
use RuntimeException;

/** Single producer, synchronous backpressure, at most one bounded record in memory. */
final class CaptureStore
{
    public const SCHEMA = 'basic-capture-v1';

    public const SEGMENT_BYTES = 16 * 1024 * 1024;

    public const MANIFEST_BYTES = 4 * 1024 * 1024;

    public const SEGMENT_RECORDS = 32;

    private mixed $stream = null;

    private mixed $hash = null;

    private int $segmentBytes = 0;

    private int $segmentRecords = 0;

    private int $count = 0;

    private int $bytes = 0;

    /** @var array<array-key, mixed> */
    private array $segments = [];

    private mixed $coverage;

    /**
     * @param  array<array-key, mixed>  $plan
     * @param  array<array-key, mixed>  $identity
     * @param  array<array-key, mixed>  $options
     */
    public function __construct(public readonly string $directory, private readonly array $plan, private readonly array $identity, private readonly array $options, private readonly \Closure $admit, private readonly ?\Closure $current = null)
    {
        self::privateDirectory($directory);
        if (scandir($directory) !== ['.', '..']) {
            throw new RuntimeException('capture_output_not_empty');
        }
        $this->coverage = hash_init('sha256');
    }

    public static function privateDirectory(string $directory): void
    {
        if (! str_starts_with($directory, '/') || str_contains($directory, "\0") || preg_match('~(^|/)\.\.?(/|$)~', $directory)) {
            throw new RuntimeException('capture_output_boundary');
        }
        $cursor = '';
        foreach (explode('/', ltrim($directory, '/')) as $part) {
            $cursor .= '/'.$part;
            if (is_link($cursor)) {
                throw new RuntimeException('capture_output_symlink');
            }
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('capture_mkdir_failed');
        }
        if ((fileperms($directory) & 0777) !== 0700) {
            throw new RuntimeException('capture_output_permissions');
        }
    }

    public static function writePrivate(string $path, string $bytes, bool $finalization = false): void
    {
        $old = umask(0077);
        try {
            $stream = @fopen($path, 'xb');
        } finally {
            umask($old);
        }
        if ($stream === false) {
            throw new RuntimeException('capture_write_open');
        }
        try {
            self::writeStream($stream, $bytes, $finalization);
            if (! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('capture_write_sync');
            }
        } finally {
            fclose($stream);
        }
    }

    public static function writeStream(mixed $stream, string $bytes, bool $finalization = false): void
    {
        $path = stream_get_meta_data($stream)['uri'] ?? '';
        $budget = OutputBudget::current($path);
        if ($budget !== null) {
            $budget->write($stream, $bytes, $finalization);
        } else {
            self::writeUnchecked($stream, $bytes);
            if (! fflush($stream)) {
                throw new RuntimeException('capture_write_sync');
            }
        }
    }

    /** Internal to OutputBudget: callers use writeStream for quota enforcement. */
    public static function writeUnchecked(mixed $stream, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $count = fwrite($stream, substr($bytes, $offset));
            if ($count === false || $count === 0) {
                throw new RuntimeException('capture_write_failed');
            }
            $offset += $count;
        }
    }

    /**
     * @param  array<array-key, mixed>  $record
     */
    public function append(array $record): void
    {
        $expected = array_keys($this->plan['files']);
        if ($record['ordinal'] !== $this->count || ($expected[$this->count] ?? null) !== $record['owner']
            || ! str_ends_with($this->plan['files'][$record['owner']], ':'.$record['sha256'])) {
            throw new RuntimeException('capture_coverage_mismatch');
        }
        CaptureRecord::decode($record, $this->options);
        $line = TypedValue::json($record)."\n";
        $length = strlen($line);
        if ($length > self::SEGMENT_BYTES) {
            throw new RuntimeException('capture_record_limit');
        }
        ($this->admit)($this->bytes + $length);
        if ($this->stream !== null && ($this->segmentBytes + $length > self::SEGMENT_BYTES || $this->segmentRecords >= self::SEGMENT_RECORDS)) {
            $this->flush();
        }
        if ($this->stream === null) {
            $path = $this->directory.'/'.sprintf('segment-%06d.ndjson', count($this->segments));
            $old = umask(0077);
            try {
                $this->stream = @fopen($path, 'xb');
            } finally {
                umask($old);
            }
            if ($this->stream === false) {
                throw new RuntimeException('capture_segment_open');
            }
            $this->hash = hash_init('sha256');
        }
        self::writeStream($this->stream, $line);
        hash_update($this->hash, $line);
        hash_update($this->coverage, TypedValue::json([$record['ordinal'], $record['owner'], $record['kind'], $record['sha256'], hash('sha256', $line)])."\n");
        $this->segmentBytes += $length;
        $this->bytes += $length;
        $this->segmentRecords++;
        $this->count++;
    }

    private function flush(): void
    {
        if ($this->stream === null) {
            return;
        }
        if (! fflush($this->stream) || ! fsync($this->stream)) {
            throw new RuntimeException('capture_segment_sync');
        }
        fclose($this->stream);
        $this->stream = null;
        $this->segments[] = ['file' => sprintf('segment-%06d.ndjson', count($this->segments)), 'bytes' => $this->segmentBytes,
            'records' => $this->segmentRecords, 'sha256' => hash_final($this->hash)];
        $this->segmentBytes = $this->segmentRecords = 0;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function commit(): array
    {
        $this->flush();
        if ($this->count !== count($this->plan['files'])) {
            throw new RuntimeException('capture_incomplete');
        }
        $manifest = ['schema' => self::SCHEMA, 'complete' => true, 'identity' => $this->identity, 'options' => $this->options,
            'source_plan' => $this->plan, 'segments' => $this->segments, 'records' => $this->count, 'bytes' => $this->bytes,
            'coverage_sha256' => hash_final($this->coverage)];
        $body = TypedValue::json($manifest);
        if (strlen($body) > self::MANIFEST_BYTES) {
            throw new RuntimeException('capture_manifest_limit');
        }
        ($this->admit)($this->bytes + strlen($body) + 65);
        self::writePrivate($this->directory.'/manifest.pending', $body);
        // Verify every file before the marker can make this a replayable dataset.
        self::validateManifest($this->directory, $manifest);
        ($this->current ?? static function (): void {})();
        if (! rename($this->directory.'/manifest.pending', $this->directory.'/manifest.json')) {
            throw new RuntimeException('capture_manifest_publish');
        }
        self::writePrivate($this->directory.'/complete.pending', hash('sha256', $body)."\n");
        if (! rename($this->directory.'/complete.pending', $this->directory.'/complete')) {
            throw new RuntimeException('capture_complete_publish');
        }

        return ['status' => 'complete', 'records' => $this->count, 'bytes' => $this->bytes, 'manifest_sha256' => hash('sha256', $body)];
    }

    public function abort(string $reason): void
    {
        if (is_file($this->directory.'/complete') && ! is_link($this->directory.'/complete')) {
            unlink($this->directory.'/complete');
        }
        if (is_resource($this->stream)) {
            fclose($this->stream);
            $this->stream = null;
        }
        // A generic code, never exception text carrying source payloads or paths.
        if (! file_exists($this->directory.'/failure.json')) {
            self::writePrivate($this->directory.'/failure.json', TypedValue::json(['status' => 'incomplete', 'reason' => $reason, 'records' => $this->count, 'bytes' => $this->bytes]), finalization: true);
        }
    }

    /**
     * @param  array<array-key, mixed>  $identity
     * @return array<array-key, mixed>
     */
    public static function manifest(string $directory, array $identity): array
    {
        self::privateDirectory($directory);
        foreach (['complete', 'manifest.json'] as $file) {
            self::safeFile($directory.'/'.$file);
        }
        if (filesize($directory.'/complete') !== 65 || filesize($directory.'/manifest.json') > self::MANIFEST_BYTES) {
            throw new RuntimeException('capture_manifest_limit');
        }
        $body = file_get_contents($directory.'/manifest.json');
        if (hash('sha256', $body) !== trim(file_get_contents($directory.'/complete'))) {
            throw new RuntimeException('capture_manifest_integrity');
        }
        $manifest = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['schema'] ?? null) !== self::SCHEMA || ($manifest['complete'] ?? null) !== true || ($manifest['identity'] ?? null) !== $identity) {
            throw new RuntimeException('capture_manifest_identity');
        }
        self::validateManifest($directory, $manifest);

        return $manifest;
    }

    private static function safeFile(string $path): void
    {
        if (! is_file($path) || is_link($path) || (fileperms($path) & 0777) !== 0600) {
            throw new RuntimeException('capture_file_boundary');
        }
    }

    /**
     * @param  array<array-key, mixed>  $manifest
     */
    private static function validateManifest(string $directory, array $manifest): void
    {
        $count = $bytes = 0;
        $coverage = hash_init('sha256');
        $owners = array_keys($manifest['source_plan']['files']);
        foreach (self::records($directory, $manifest) as $record) {
            if ($record['ordinal'] !== $count || ($owners[$count] ?? null) !== $record['owner']
                || $record['php_version'] !== $manifest['source_plan']['php_version']
                || ! str_ends_with($manifest['source_plan']['files'][$record['owner']], ':'.$record['sha256'])) {
                throw new RuntimeException('capture_replay_coverage');
            }
            CaptureRecord::decode($record, $manifest['options']);
            $line = TypedValue::json($record)."\n";
            hash_update($coverage, TypedValue::json([$record['ordinal'], $record['owner'], $record['kind'], $record['sha256'], hash('sha256', $line)])."\n");
            $bytes += strlen($line);
            $count++;
        }
        if ($count !== count($owners) || $count !== $manifest['records'] || $bytes !== $manifest['bytes'] || hash_final($coverage) !== $manifest['coverage_sha256']) {
            throw new RuntimeException('capture_replay_incomplete');
        }
    }

    /**
     * @param  array<array-key, mixed>  $manifest
     */
    private static function records(string $directory, array $manifest): Generator
    {
        foreach ($manifest['segments'] as $index => $segment) {
            if ($segment['file'] !== sprintf('segment-%06d.ndjson', $index) || ! is_int($segment['bytes']) || $segment['bytes'] < 1
                || $segment['bytes'] > self::SEGMENT_BYTES || ! is_int($segment['records']) || $segment['records'] < 1 || $segment['records'] > self::SEGMENT_RECORDS) {
                throw new RuntimeException('capture_segment_bounds');
            }
            $path = $directory.'/'.$segment['file'];
            self::safeFile($path);
            if (filesize($path) !== $segment['bytes'] || hash_file('sha256', $path) !== $segment['sha256']) {
                throw new RuntimeException('capture_segment_integrity');
            }
            $stream = fopen($path, 'rb');
            $records = 0;
            try {
                while (($line = fgets($stream, self::SEGMENT_BYTES + 1)) !== false) {
                    if (! str_ends_with($line, "\n")) {
                        throw new RuntimeException('capture_record_truncated');
                    }
                    $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (TypedValue::json($record)."\n" !== $line) {
                        throw new RuntimeException('capture_noncanonical_record');
                    }
                    $records++;
                    yield $record;
                }
            } finally {
                fclose($stream);
            }
            if ($records !== $segment['records']) {
                throw new RuntimeException('capture_segment_count');
            }
        }
    }

    /** Validates all segments before exposing any contribution. No source reader or parser. */
    /**
     * @param  array<array-key, mixed>  $identity
     */
    public static function replay(string $directory, array $identity): Generator
    {
        $manifest = self::manifest($directory, $identity);
        foreach (self::records($directory, $manifest) as $record) {
            yield ['record' => $record, 'entry' => CaptureRecord::decode($record, $manifest['options'])];
        }
    }
}
