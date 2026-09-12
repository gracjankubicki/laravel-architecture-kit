<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache;

use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Where a built graph is kept between runs.
 *
 * Every failure mode here resolves the same way: behave as though there were no cache.
 * A missing directory, an unreadable file, a truncated write and a file from another
 * version are all the same event from the caller's side, because the alternative is a
 * command that fails on something it only ever used to go faster.
 */
final readonly class ProjectGraphCache
{
    public const DIRECTORY = 'storage/framework/cache/architecture-kit';

    /**
     * How much memory restoring an entry needs, as a multiple of its size on disk.
     *
     * Measured in an isolated process: an entry of 33.8 MB peaks at 194.0 MB, which is
     * 5.73x, because the decoded arrays are still alive while the objects are built from
     * them. The ratio is against the file, so it rose when the stored form shrank: the
     * objects are unchanged and the file is smaller. Two earlier values were too tight,
     * one taken from memory in use rather than the peak, one from the larger file this
     * replaced. Being wrong here means an allocation failure, the one outcome a cache
     * must never cause.
     */
    private const MEMORY_PER_STORED_BYTE = 8;

    /**
     * Extra memory a write needs, as a multiple of the entry being written.
     *
     * Serializing on top of a restored graph took the same process from 194.0 MB to
     * 253.8 MB, an increase of 1.77x the entry. Rounded up, because the alternative to
     * skipping a write is dying during one.
     */
    private const MEMORY_PER_WRITTEN_BYTE = 3;

    /**
     * Bytes the stored form takes per symbol or edge.
     *
     * Measured at 168 on 210487 elements once paths left the stored form, against 237
     * before. The value below keeps the older, larger figure with room to spare, because
     * underestimating here is what an allocation failure is made of.
     *
     * The first version of this check sized the write from the entry it was replacing,
     * which is unavailable exactly when it matters: a changed configuration or an
     * upgraded package writes under a new fingerprint, so there is no previous file, and
     * a project whose graph grew is not bounded by what it used to be.
     */
    private const BYTES_PER_ELEMENT = 320;

    /**
     * Bytes an entry takes before any symbol or edge is counted.
     *
     * A file that declares nothing still costs its path twice, once in the signature and
     * once as the entry key, plus the surrounding structure. Measured at 84 bytes for
     * short paths and 182 for deeply nested ones; 256 covers both. Without this an
     * estimate over a project of empty files would come to zero and let a write through
     * that the budget cannot hold.
     */
    private const BYTES_PER_ENTRY = 256;

    /**
     * Hash of the stored payload, written on its own first line.
     *
     * Structural checks cannot catch every damaged entry, because a damaged entry can
     * still be well formed: an entry stripped of its symbols is indistinguishable from a
     * file that declares none, and both are legitimate. The stat signature would still
     * match, so the graph would answer as though the class had never existed. A hash over
     * the bytes decides that question without having to interpret them, and costs 6ms on
     * 35 MB against the 0.74s the read takes.
     */
    private const DIGEST = 'xxh128';

    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private string $directory = self::DIRECTORY,
        private ?int $memoryLimitBytes = null,
        private ?\Closure $memoryUsage = null,
    ) {}

    /**
     * The stored graph for this signature, together with why it was or was not used.
     *
     * An entry written under a different fingerprint is not returned: its symbols and
     * edges may have been produced by different rules, so no amount of reparsing would
     * bring it up to date.
     */
    public function read(GraphCacheSignature $signature): CacheReadResult
    {
        $path = $this->pathFor($signature);

        if (! $this->files->isFile($path)) {
            return new CacheReadResult(null, CacheStatus::Missing);
        }

        // Restoring is what costs memory, and it costs it all at once. Checking before
        // the read turns an allocation failure into a rebuild that simply takes longer.
        if (! $this->fitsInBudget($path)) {
            return new CacheReadResult(null, CacheStatus::TooLarge);
        }

        try {
            $raw = $this->files->get($path);
        } catch (Throwable) {
            return new CacheReadResult(null, CacheStatus::Corrupt);
        }

        $payload = $this->verified($raw);

        if ($payload === null) {
            return new CacheReadResult(null, CacheStatus::Corrupt);
        }

        // Objects are never restored from the file: it holds arrays, and anything else
        // in it is a file this package did not write.
        $data = @unserialize($payload, ['allowed_classes' => false]);

        if (! is_array($data)) {
            return new CacheReadResult(null, CacheStatus::Corrupt);
        }

        $cached = CachedGraph::fromArray($data);

        if ($cached === null) {
            return new CacheReadResult(null, CacheStatus::Corrupt);
        }

        // The file name carries the fingerprint, so reaching here with a mismatch means
        // the contents disagree with the name they were stored under.
        if (! $cached->signature->isCompatibleWith($signature)) {
            return new CacheReadResult(null, CacheStatus::Incompatible);
        }

        return new CacheReadResult($cached, CacheStatus::Fresh);
    }

    /**
     * Whether restoring this entry fits in what is left of the memory budget.
     */
    private function fitsInBudget(string $path): bool
    {
        $limit = MemoryLimit::bytes($this->memoryLimitBytes);

        if ($limit === null) {
            return true;
        }

        $size = @filesize($path);

        if ($size === false) {
            return false;
        }

        return $this->usage() + ($size * self::MEMORY_PER_STORED_BYTE) <= $limit;
    }

    /**
     * Whether serializing this graph fits in what is left of the budget.
     *
     * The size is estimated from the graph itself rather than from any file on disk, so
     * the check holds for a first write under a new fingerprint and for a project whose
     * graph has grown since the last run.
     */
    private function canAffordWrite(CachedGraph $graph): bool
    {
        $limit = MemoryLimit::bytes($this->memoryLimitBytes);

        if ($limit === null) {
            return true;
        }

        $estimate = count($graph->entries) * self::BYTES_PER_ENTRY;

        foreach ($graph->entries as $entry) {
            $estimate += (count($entry->symbols) + count($entry->edges)) * self::BYTES_PER_ELEMENT;
        }

        return $this->usage() + ($estimate * self::MEMORY_PER_WRITTEN_BYTE) <= $limit;
    }

    /**
     * The payload of a file whose contents still hash to what was written, or null.
     */
    private function verified(string $raw): ?string
    {
        $break = strpos($raw, "\n");

        if ($break === false) {
            return null;
        }

        $digest = substr($raw, 0, $break);
        $payload = substr($raw, $break + 1);

        return hash_equals(hash(self::DIGEST, $payload), $digest) ? $payload : null;
    }

    private function usage(): int
    {
        $usageReader = $this->memoryUsage ?? static fn (): int => memory_get_usage(true);

        return $usageReader();
    }

    /**
     * Store a graph, or leave the previous state alone if that is not possible.
     *
     * The write goes to a temporary file first. A run interrupted midway through would
     * otherwise leave a truncated entry that the next run has to detect and discard,
     * and a concurrent reader would see it.
     */
    public function write(CachedGraph $graph): bool
    {
        $path = $this->pathFor($graph->signature);

        // Storing is an optimisation for the next run, so it gives way rather than
        // pushing a run that has already grown large over the edge.
        if (! $this->canAffordWrite($graph)) {
            return false;
        }

        try {
            $this->files->ensureDirectoryExists(dirname($path));
            $temporary = $path.'.'.getmypid().'.tmp';

            $payload = serialize($graph->toArray());

            if ($this->files->put($temporary, hash(self::DIGEST, $payload)."\n".$payload) === false) {
                return false;
            }

            if (! $this->files->move($temporary, $path)) {
                $this->files->delete($temporary);

                return false;
            }

            return true;
        } catch (Throwable) {
            // A read-only or full disk makes the cache unavailable, not the command.
            return false;
        }
    }

    /**
     * Remove every stored graph. Returns how many entries were removed.
     */
    public function clear(): int
    {
        $directory = $this->absoluteDirectory();

        if (! $this->files->isDirectory($directory)) {
            return 0;
        }

        $removed = 0;

        foreach ($this->files->glob($directory.'/*.cache') ?: [] as $file) {
            if ($this->files->delete($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    public function absoluteDirectory(): string
    {
        return str_starts_with($this->directory, '/')
            ? rtrim($this->directory, '/')
            : $this->basePath.'/'.trim($this->directory, '/');
    }

    /**
     * One file per fingerprint rather than one file overwritten.
     *
     * A project that audits with tests in scope and asks for context without them would
     * otherwise invalidate its own entry on every second command, and each run would pay
     * the full build it was trying to avoid.
     */
    private function pathFor(GraphCacheSignature $signature): string
    {
        return $this->absoluteDirectory().'/graph-'.substr($signature->fingerprint, 0, 16).'.cache';
    }
}
