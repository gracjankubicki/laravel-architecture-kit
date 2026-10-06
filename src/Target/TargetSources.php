<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Target;

use FilesystemIterator;
use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Revision\SnapshotInputs;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/** Bounded working sources also support projects without Git. No source is executed. */
final readonly class TargetSources
{
    public function __construct(private string $base) {}

    /** @phpstan-impure Symlinks may change while source bytes are read. */
    private function safe(string $path): bool
    {
        clearstatcache();

        return DiscoverySettings::safe($this->base, $path);
    }

    /** @param list<string> $directories
     * @param list<string> $extra */
    public function capture(array $directories, array $extra = []): SourceSnapshot
    {
        $paths = [];
        $notices = [];
        $entries = 0;
        foreach (array_unique(['app', 'bootstrap', 'config', 'routes', 'database', ...$directories]) as $directory) {
            if (! SnapshotInputs::safe($directory) || ! DiscoverySettings::safe($this->base, $directory)) {
                $notices[] = ['code' => 'E_TARGET_SOURCE', 'path' => $directory, 'message' => 'Unsafe target analysis directory.'];

                continue;
            }
            if (! is_dir($this->base.'/'.$directory)) {
                if (in_array($directory, $directories, true)) {
                    $notices[] = ['code' => 'E_TARGET_SCOPE', 'path' => $directory, 'message' => 'Declared analysis directory is absent; no coverage is claimed.'];
                }

                continue;
            }
            try {
                foreach (new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($this->base.'/'.$directory, FilesystemIterator::SKIP_DOTS), function ($file) use (&$notices): bool {
                    $relative = substr($file->getPathname(), strlen($this->base) + 1);
                    if ($file->isLink()) {
                        $notices[] = ['code' => 'E_TARGET_SOURCE', 'path' => $relative, 'message' => 'Symlink source omitted; target coverage is incomplete.'];

                        return false;
                    }

                    return SnapshotInputs::safe($relative);
                })) as $file) {
                    if (++$entries > 10000) {
                        $notices[] = ['code' => 'E_TARGET_SOURCE', 'path' => $directory, 'message' => 'Target source listing budget reached.'];
                        break 2;
                    }
                    $path = substr($file->getPathname(), strlen($this->base) + 1);
                    if (! SnapshotInputs::safe($path) || ! $this->safe($path)) {
                        $notices[] = ['code' => 'E_TARGET_SOURCE', 'path' => $path, 'message' => 'Unsafe target source omitted.'];

                        continue;
                    }
                    if ($file->isFile() && in_array(strtolower($file->getExtension()), ['php', 'json', 'sql'], true)) {
                        $paths[$path] = true;
                    }
                }
            } catch (Throwable) {
                $notices[] = ['code' => 'E_TARGET_SOURCE', 'path' => $directory, 'message' => 'Target directory cannot be listed.'];
            }
        }
        foreach (['composer.json', TargetDefinition::PATH, '.architecture-kit/baseline.json', ...$extra] as $path) {
            if (SnapshotInputs::safe($path) && (file_exists($this->base.'/'.$path) || is_link($this->base.'/'.$path))) {
                $paths[$path] = true;
            }
        }
        ksort($paths);
        $sources = $stats = [];
        $bytes = $count = 0;
        foreach ($paths as $path => $_) {
            if (! $this->safe($path)) {
                $notices[] = ['code' => 'E_TARGET_SOURCE', 'path' => $path, 'message' => 'Target source is a symlink.'];

                continue;
            }
            clearstatcache(true, $this->base.'/'.$path);
            $stat = @stat($this->base.'/'.$path);
            $stats[$path] = $stat === false ? null : [$stat['mtime'], $stat['size']];
            $ceiling = MemoryLimit::bytes();
            if ($stat === false || ++$count > 3000 || $stat['size'] > 1000000 || $bytes + $stat['size'] > 16777216 || ($ceiling !== null && memory_get_usage(true) + $stat['size'] * 4 > $ceiling * 0.8)) {
                $notices[] = ['code' => 'E_TARGET_SOURCE', 'path' => $path, 'message' => 'Target source unavailable or exceeds byte/count/memory budget.'];

                continue;
            }
            $handle = @fopen($this->base.'/'.$path, 'rb');
            if ($handle === false) {
                $notices[] = ['code' => 'E_TARGET_SOURCE', 'path' => $path, 'message' => 'Target source cannot be read.'];

                continue;
            }
            try {
                $source = stream_get_contents($handle, 1000001);
            } finally {
                fclose($handle);
            }
            if ($source === false || strlen($source) > 1000000 || ! $this->safe($path)) {
                $notices[] = ['code' => 'E_TARGET_SOURCE', 'path' => $path, 'message' => 'Target source changed or exceeded budget during reading.'];

                continue;
            }
            $sources[$path] = $source;
            $bytes += strlen($source);
        }

        return new SourceSnapshot('working', 'working', hash('sha256', serialize([$stats, array_map(static fn (string $source): string => hash('sha256', $source), $sources), $notices])), $sources, $notices, array_keys($paths));
    }
}
