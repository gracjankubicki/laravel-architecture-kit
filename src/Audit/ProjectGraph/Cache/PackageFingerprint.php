<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A fingerprint of the code that produces the graph.
 *
 * An upgrade can change what a symbol or an edge means without changing anything in the
 * consumer's project, and a cache written by the previous version would then keep
 * answering in the old shape.
 *
 * It reads every source in the package rather than a list of the ones that look
 * relevant. The first version listed five files and was already wrong: the builder
 * resolves names through `FileContext` and walks trees through `PhpAst`, and neither was
 * on it. Any list of that kind has to be maintained by whoever changes the graph next,
 * and a missed entry fails silently, which is the worst way for this to fail. Hashing
 * everything costs 32ms on 189 files against the 19.5s build it exists to avoid, and it
 * cannot drift.
 */
final class PackageFingerprint
{
    private static ?string $cached = null;

    public static function current(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        return self::$cached = self::of(dirname(__DIR__, 3));
    }

    /**
     * The fingerprint of one directory of sources. Exposed so a test can prove that
     * editing a source really does change it.
     */
    public static function of(string $directory): string
    {
        $parts = [];

        foreach (self::sources($directory) as $path) {
            $contents = @file_get_contents($path);

            // An unreadable source is not an error worth failing a command over, but it
            // must not silently produce the same fingerprint as a readable one.
            $parts[] = substr($path, strlen($directory)).':'.($contents === false ? 'unreadable' : sha1($contents));
        }

        return sha1(implode('|', $parts));
    }

    /**
     * Every PHP source under the directory, in a stable order.
     *
     * The order has to be fixed or the same code would fingerprint differently depending
     * on how the filesystem happened to enumerate it.
     *
     * @return array<int, string>
     */
    private static function sources(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        sort($found, SORT_STRING);

        return $found;
    }

    /** Test seam: the fingerprint is stable for a process, so it has to be resettable. */
    public static function forget(): void
    {
        self::$cached = null;
    }
}
