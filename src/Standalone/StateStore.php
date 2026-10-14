<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use RuntimeException;

/** Private, bounded JSON state with serialized read-modify-write and atomic publication. */
final readonly class StateStore
{
    public function __construct(public string $directory, ?string $boundary = null)
    {
        if ($boundary !== null) {
            self::assertBoundary($directory, $boundary);
        }
        if (is_link($directory)) {
            throw new RuntimeException('External state directory cannot be a symlink.');
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('External state directory is unavailable.');
        }
    }

    public static function assertBoundary(string $directory, string $boundary): void
    {
        if ($directory !== $boundary && ! str_starts_with($directory, rtrim($boundary, '/').'/')) {
            throw new RuntimeException('External state is outside the tool home.');
        }
        $root = realpath($boundary);
        if ($root === false) {
            throw new RuntimeException('Tool home is unavailable.');
        }
        $ancestor = $directory;
        while (! file_exists($ancestor) && ! is_link($ancestor)) {
            $ancestor = dirname($ancestor);
        }
        $expected = $root.substr($ancestor, strlen(rtrim($boundary, '/')));
        if (realpath($ancestor) !== $expected || is_link($ancestor)) {
            throw new RuntimeException('External state crosses a symlink or unsafe parent.');
        }
    }

    /** @return array<string, mixed> */
    public function read(string $name): array
    {
        $path = $this->path($name);
        if (! is_file($path)) {
            return [];
        }
        if (filesize($path) > 16000000) {
            throw new RuntimeException('External state exceeds its size limit.');
        }
        $value = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new RuntimeException('External state is corrupt.');
        }

        return $value;
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change
     * @return array<string, mixed>
     */
    public function update(string $name, callable $change): array
    {
        $lockPath = $this->path($name).'.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('External state lock cannot be a symlink.');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('External state lock is unavailable.');
        }
        try {
            $value = $change($this->read($name));
            $body = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($body) > 16000000) {
                throw new RuntimeException('External state exceeds its size limit.');
            }
            $temp = tempnam($this->directory, '.write-');
            if ($temp === false) {
                throw new RuntimeException('External state temporary file is unavailable.');
            }
            try {
                chmod($temp, 0600);
                if (file_put_contents($temp, $body) !== strlen($body) || ! rename($temp, $this->path($name))) {
                    throw new RuntimeException('External state could not be saved.');
                }
            } finally {
                if (is_file($temp)) {
                    unlink($temp);
                }
            }

            return $value;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function path(string $name): string
    {
        if (! preg_match('/^[a-zA-Z0-9_-]+$/D', $name)) {
            throw new RuntimeException('Invalid state identifier.');
        }

        $path = $this->directory.'/'.$name.'.json';
        if (is_link($path)) {
            throw new RuntimeException('External state cannot be a symlink.');
        }

        return $path;
    }
}
