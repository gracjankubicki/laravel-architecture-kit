<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Capture;

use RuntimeException;

/** No defaults for a capture experiment: every resource budget belongs to its admission. */
final class CaptureAdmission
{
    private int $started;

    /**
     * @param  array<array-key, mixed>  $policy
     * @param  array<array-key, mixed>  $command
     */
    public function __construct(public readonly array $policy, array $command, string $manifest, string $output, bool $once)
    {
        $required = ['command', 'cwd', 'source_manifest', 'source_manifest_sha256', 'php_memory_limit_bytes', 'max_wall_seconds',
            'max_output_bytes', 'min_free_disk_bytes', 'private_output', 'process_limits'];
        if (array_keys($policy) !== $required || ! $once || $policy['command'] !== $command || $policy['cwd'] !== getcwd()
            || $policy['source_manifest'] !== $manifest || $policy['private_output'] !== $output
            || ! is_file($manifest) || is_link($manifest) || filesize($manifest) > CaptureStore::MANIFEST_BYTES
            || $policy['source_manifest_sha256'] !== hash_file('sha256', $manifest)
            || $policy['php_memory_limit_bytes'] !== 1073741824 || self::memoryLimit() !== 1073741824) {
            throw new RuntimeException('capture_admission_identity');
        }
        foreach (['max_wall_seconds', 'max_output_bytes', 'min_free_disk_bytes'] as $key) {
            if (! is_int($policy[$key]) || $policy[$key] < 1) {
                throw new RuntimeException('capture_admission_missing_limit');
            }
        }
        $limits = $policy['process_limits'];
        if (! is_array($limits) || array_keys($limits) !== ['max_processes', 'max_tree_rss_bytes']
            || ! is_int($limits['max_processes']) || $limits['max_processes'] < 1
            || ! is_int($limits['max_tree_rss_bytes']) || $limits['max_tree_rss_bytes'] < 1
            || ! function_exists('pcntl_alarm') || ! function_exists('pcntl_async_signals') || ! function_exists('posix_kill')) {
            throw new RuntimeException('capture_admission_process_limits');
        }
        CaptureStore::privateDirectory($output);
        if (scandir($output) !== ['.', '..']) {
            throw new RuntimeException('capture_once_output_not_empty');
        }
        $this->started = hrtime(true);
        $this->check(0);
    }

    private static function memoryLimit(): int
    {
        $value = trim(ini_get('memory_limit'));
        $factor = match (strtolower(substr($value, -1))) {
            'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1
        };

        return (int) $value * $factor;
    }

    public function check(int $outputBytes): void
    {
        if ((hrtime(true) - $this->started) / 1e9 >= $this->policy['max_wall_seconds']) {
            throw new RuntimeException('capture_wall_limit');
        }
        $onDisk = $dataset = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->policy['private_output'], \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('capture_output_symlink');
            }
            if ($file->isFile()) {
                $onDisk += $file->getSize();
                if (str_starts_with($file->getPathname(), $this->policy['private_output'].'/dataset/')) {
                    $dataset += $file->getSize();
                }
            }
        }
        $outputBytes = $onDisk - $dataset + max($dataset, $outputBytes);
        $free = disk_free_space($this->policy['private_output']);
        if ($free === false || $free < $this->policy['min_free_disk_bytes'] || $outputBytes > $this->policy['max_output_bytes']) {
            throw new RuntimeException('capture_disk_limit');
        }
    }
}
