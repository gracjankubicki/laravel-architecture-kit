<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Revision;

use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Shared bounded Git/working source reader. No checkout, include, autoload or writes. */
final class RevisionSources
{
    private string $root;

    private string $prefix;

    public function __construct(
        private readonly string $basePath,
        private readonly int $maxFiles = 3000,
        private readonly int $maxBytes = 16777216,
        private readonly int $maxFileBytes = 1048576,
    ) {
        if ($maxFiles < 1 || $maxBytes < 1 || $maxFileBytes < 1) {
            throw new InvalidArgumentException('Source budgets must be positive.');
        }
        $this->root = trim($this->git(['rev-parse', '--show-toplevel'], 8192));
        $base = realpath($basePath);
        $root = realpath($this->root);
        if ($base === false || $root === false || ($base !== $root && ! str_starts_with($base, $root.'/'))) {
            throw new InvalidArgumentException('The package must be a directory inside an available Git checkout.');
        }
        $this->root = $root;
        $this->prefix = $base === $root ? '' : substr($base, strlen($root) + 1).'/';
    }

    public function capture(string $state): SourceSnapshot
    {
        if ($state === '' || strlen($state) > 200 || str_contains($state, "\0")) {
            throw new InvalidArgumentException('Provide a Git revision or working.');
        }
        $working = $state === 'working';
        $revision = trim($this->git(['rev-parse', '--verify', '--end-of-options', ($working ? 'HEAD' : $state).'^{commit}'], 8192));
        if (! preg_match('/^[a-f0-9]{40,64}$/D', $revision)) {
            throw new RuntimeException('Git did not resolve a commit.');
        }
        $entries = $working ? $this->workingEntries() : $this->gitEntries($revision);
        ksort($entries);
        $files = $notices = $paths = [];
        $hashes = [];
        $bytes = 0;
        $attempted = 0;
        foreach ($entries as $path => $entry) {
            if (! self::allowedPath($path)) {
                continue;
            }
            $paths[] = $path;
            if ($entry['mode'] === '120000' || $entry['mode'] === '160000' || ($working && $this->hasSymlink($path))) {
                $notices[] = self::notice('E_SOURCE_LINK', $path, 'Symlinks and submodules are not read.');
                $hashes[$path] = 'excluded:link';

                continue;
            }
            if (! self::sourcePath($path)) {
                continue;
            }
            if (++$attempted > $this->maxFiles) {
                $notices[] = self::notice('E_SOURCE_LIMIT', $path, 'Source file count budget reached.');
                $hashes[$path] = 'unread:count';

                continue;
            }
            $size = $working ? @filesize($this->basePath.'/'.$path) : $entry['size'];
            $ceiling = MemoryLimit::bytes();
            if ($size === false || $size < 0) {
                $notices[] = self::notice('E_SOURCE_UNREADABLE', $path, 'Source size cannot be read.');
                $hashes[$path] = 'unread:size';

                continue;
            }
            if ($size > $this->maxFileBytes || $bytes + $size > $this->maxBytes || ($ceiling !== null && memory_get_usage(true) + $size * 4 > $ceiling * 0.8)) {
                $notices[] = self::notice('E_SOURCE_LIMIT', $path, 'Source byte or memory budget reached.');
                $hashes[$path] = 'unread:budget';

                continue;
            }
            try {
                $source = $working ? $this->readWorking($path) : $this->git(['cat-file', 'blob', $entry['id']], $this->maxFileBytes);
            } catch (RuntimeException $e) {
                $notices[] = self::notice('E_SOURCE_UNREADABLE', $path, $e->getMessage());
                $hashes[$path] = 'unread:content';

                continue;
            }
            $bytes += strlen($source);
            if ($bytes > $this->maxBytes) {
                $notices[] = self::notice('E_SOURCE_LIMIT', $path, 'Source byte budget reached during reading.');
                $hashes[$path] = 'unread:budget';

                continue;
            }
            $files[$path] = $source;
            $hashes[$path] = hash('sha256', $source);
        }
        $fingerprint = hash('sha256', serialize([$revision, $paths, $hashes, $notices]));

        return new SourceSnapshot($working ? 'working' : 'git', $revision, $fingerprint, $files, $notices, $paths);
    }

    public function isFresh(SourceSnapshot $snapshot): bool
    {
        if ($snapshot->state !== 'working') {
            return true;
        }
        try {
            return hash_equals($snapshot->fingerprint, $this->capture('working')->fingerprint);
        } catch (RuntimeException|InvalidArgumentException) {
            return false;
        }
    }

    /** @return array<string, array{mode: string, id: string, size: int}> */
    private function gitEntries(string $revision): array
    {
        $entries = [];
        $listing = $this->git(['ls-tree', '--full-tree', '-r', '-l', '-z', $revision], 4194304);
        foreach (explode("\0", $listing) as $row) {
            if ($row === '') {
                continue;
            }
            if (! preg_match('/^(\d{6}) (blob|commit) ([a-f0-9]+) +([0-9]+|-)\t(.*)$/sD', $row, $match)) {
                throw new RuntimeException('Unsupported Git tree entry.');
            }
            $path = $match[5];
            if (! str_starts_with($path, $this->prefix)) {
                continue;
            }
            $entries[substr($path, strlen($this->prefix))] = ['mode' => $match[1], 'id' => $match[3], 'size' => (int) $match[4]];
        }

        return $entries;
    }

    /** @return array<string, array{mode: string, id: string, size: int}> */
    private function workingEntries(): array
    {
        $entries = [];
        $listing = $this->git(['ls-files', '-z', '--cached', '--others', '--exclude-standard', '--full-name'], 4194304);
        foreach (explode("\0", $listing) as $fullPath) {
            if ($fullPath === '' || ! str_starts_with($fullPath, $this->prefix)) {
                continue;
            }
            $path = substr($fullPath, strlen($this->prefix));
            // Deleted tracked files are absent from the working state.
            if (! file_exists($this->basePath.'/'.$path) && ! is_link($this->basePath.'/'.$path)) {
                continue;
            }
            $entries[$path] = ['mode' => '100644', 'id' => '', 'size' => 0];
        }

        return $entries;
    }

    private function readWorking(string $path): string
    {
        if ($this->hasSymlink($path)) {
            throw new RuntimeException('Source became a symlink during reading.');
        }
        $real = realpath($this->basePath.'/'.$path);
        $base = realpath($this->basePath);
        if ($real === false || $base === false || ! str_starts_with($real, $base.'/') || ! is_file($real)) {
            throw new RuntimeException('Source is not a readable package file.');
        }
        $handle = @fopen($real, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Source cannot be opened.');
        }
        try {
            $source = stream_get_contents($handle, $this->maxFileBytes + 1);
        } finally {
            fclose($handle);
        }
        if ($source === false || strlen($source) > $this->maxFileBytes || $this->hasSymlink($path)) {
            throw new RuntimeException('Source exceeds the byte budget or changed during reading.');
        }

        return $source;
    }

    /** @phpstan-impure Filesystem links can change between reads. */
    private function hasSymlink(string $path): bool
    {
        $current = $this->basePath;
        foreach (explode('/', $path) as $part) {
            $current .= '/'.$part;
            if (is_link($current)) {
                return true;
            }
        }

        return false;
    }

    private static function allowedPath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! preg_match('~(?:^|/)(?:\.\.|\.git|vendor|node_modules|\.env[^/]*)(?:/|$)~', $path);
    }

    private static function sourcePath(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['php', 'json', 'yaml', 'yml', 'sql'], true);
    }

    /** @return array{code: string, path: string, message: string} */
    private static function notice(string $code, string $path, string $message): array
    {
        return compact('code', 'path', 'message');
    }

    /** @param list<string> $args */
    private function git(array $args, int $outputLimit): string
    {
        $process = new Process(['git', '-C', $this->basePath, ...$args], timeout: 15);
        $bytes = 0;
        $over = false;
        try {
            $process->run(static function (string $type, string $buffer) use ($process, $outputLimit, &$bytes, &$over): void {
                $bytes += strlen($buffer);
                if ($bytes > $outputLimit + 8192) {
                    $over = true;
                    $process->stop(0);
                }
            });
        } catch (\Throwable $e) {
            throw new RuntimeException('Git source read failed: '.$e->getMessage(), previous: $e);
        }
        if ($over || strlen($process->getOutput()) > $outputLimit) {
            throw new RuntimeException('Git source output budget exceeded.');
        }
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Git repository or revision is unavailable: '.trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }
}
