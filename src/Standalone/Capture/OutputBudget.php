<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Capture;

use RuntimeException;

/** Shared hard byte ceiling. The lock covers stat, reservation, write and flush. */
final class OutputBudget
{
    public const FINALIZATION_RESERVE = 4096;

    public const MIN_WORK_BYTES = 4096;

    private static ?self $active = null;

    private mixed $lock;

    public function __construct(public readonly string $directory, public readonly int $limit, public readonly int $reserve = self::FINALIZATION_RESERVE)
    {
        if ($limit < 1 || $reserve < 0) {
            throw new RuntimeException('capture_output_budget_invalid');
        }
        CaptureStore::privateDirectory($directory);
        $old = umask(0077);
        try {
            $this->lock = @fopen($directory.'/.quota.lock', 'c+b');
        } finally {
            umask($old);
        }
        if ($this->lock === false || is_link($directory.'/.quota.lock') || (fileperms($directory.'/.quota.lock') & 0777) !== 0600) {
            throw new RuntimeException('capture_output_lock_failed');
        }
        self::$active = $this;
    }

    public static function current(string $path): ?self
    {
        if (self::$active === null) {
            return null;
        }
        $path = preg_replace('~/+~', '/', $path);
        $prefix = rtrim(preg_replace('~/+~', '/', self::$active->directory), '/').'/';

        return str_starts_with($path, $prefix) ? self::$active : null;
    }

    public static function minimumBytes(int $admissionBytes): int
    {
        return self::FINALIZATION_RESERVE + self::MIN_WORK_BYTES + $admissionBytes;
    }

    public function used(): int
    {
        $total = 0;
        clearstatcache();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('capture_output_symlink');
            }
            if ($file->isFile()) {
                $total += $file->getSize();
            }
        }

        return $total;
    }

    /** Writes are append-only. A rejected write creates no payload bytes. */
    public function write(mixed $stream, string $bytes, bool $finalization): void
    {
        $deadline = hrtime(true) + 1000000000;
        while (! flock($this->lock, LOCK_EX | LOCK_NB)) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('capture_output_lock_timeout');
            }
            usleep(1000);
        }
        try {
            $ceiling = $finalization ? $this->limit : max(0, $this->limit - $this->reserve);
            if (strlen($bytes) > $ceiling - $this->used()) {
                throw new RuntimeException('capture_output_limit');
            }
            CaptureStore::writeUnchecked($stream, $bytes);
            // Publish physical length before releasing the interprocess lock.
            if (! fflush($stream)) {
                throw new RuntimeException('capture_write_sync');
            }
        } finally {
            flock($this->lock, LOCK_UN);
        }
    }

    public function close(): void
    {
        if (self::$active === $this) {
            self::$active = null;
        }
        fclose($this->lock);
    }
}
