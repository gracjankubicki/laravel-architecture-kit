<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache;

/**
 * What the cached graph was built from, so a stale entry can be recognised.
 *
 * A cache that answers with a graph the project no longer has is worse than no cache at
 * all: the audit would report findings for code that is gone and stay silent about code
 * just added, and nothing in the output would say so. Correctness is the whole job here;
 * the speed follows from it.
 *
 * File state is `mtime` and size rather than a content hash. Measured on an application
 * with 11566 files, stat costs 0.02s against 1.58s for hashing, and the cost is paid on
 * every run including the ones that hit the cache. The gap it leaves is narrow and named:
 * content changed while both `mtime` and size stayed identical. Git, editors and agents
 * writing a file all move `mtime`; `rsync -a`, an archive unpacked with its timestamps and
 * `touch -r` do not, which is what the clear command is for.
 */
final readonly class GraphCacheSignature
{
    /** Bumped when the stored shape changes in a way older entries cannot satisfy. */
    public const FORMAT = 1;

    /**
     * @param  string  $fingerprint  Configuration and package state the graph depends on.
     * @param  array<string, string>  $files  Project-relative path to its stat signature.
     */
    public function __construct(
        public string $fingerprint,
        public array $files,
    ) {}

    /**
     * @param  array<int, string>  $configuration  Parts that change the shape of the graph.
     * @param  array<string, string>  $files
     */
    public static function create(array $configuration, array $files): self
    {
        ksort($files);

        return new self(sha1(implode('|', [self::FORMAT, ...$configuration])), $files);
    }

    public static function stat(int $modifiedAt, int $size): string
    {
        return $modifiedAt.':'.$size;
    }

    /**
     * Whether the stored entry was built under the same configuration and package.
     *
     * A mismatch cannot be repaired by reparsing a few files, because every symbol and
     * edge in the entry may have been produced by different rules.
     */
    public function isCompatibleWith(self $previous): bool
    {
        return hash_equals($this->fingerprint, $previous->fingerprint);
    }

    /** @return array{fingerprint: string, files: array<string, string>} */
    public function toArray(): array
    {
        return ['fingerprint' => $this->fingerprint, 'files' => $this->files];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $fingerprint = $data['fingerprint'] ?? null;
        $files = $data['files'] ?? null;

        if (! is_string($fingerprint) || ! is_array($files)) {
            return null;
        }

        $signatures = [];

        foreach ($files as $path => $stat) {
            if (! is_string($path) || ! is_string($stat)) {
                return null;
            }

            $signatures[$path] = $stat;
        }

        return new self($fingerprint, $signatures);
    }
}
