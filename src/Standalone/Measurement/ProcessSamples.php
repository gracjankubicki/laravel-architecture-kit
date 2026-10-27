<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Measurement;

use Symfony\Component\Process\Process;

/** RSS totals use one observation. Known instances survive reparenting, never PID reuse. */
final class ProcessSamples
{
    public static function identity(int $pid): ?string
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $stat = @file_get_contents('/proc/'.$pid.'/stat');
            if ($stat !== false && ($end = strrpos($stat, ')')) !== false) {
                $fields = explode(' ', substr($stat, $end + 2));

                return 'linux-start-ticks:'.($fields[19] ?? 'unavailable');
            }
        }
        $process = new Process(['ps', '-p', (string) $pid, '-o', 'lstart='], timeout: 1);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) !== '' ? 'ps-lstart:'.trim($process->getOutput()) : null;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @param  array<array-key, mixed>  $known
     * @param  array<array-key, mixed>  $excluded
     * @return array<array-key, mixed>
     */
    public static function select(array $rows, int $root, array $known = [], array $excluded = []): array
    {
        $selected = [];
        foreach ($rows as $row) {
            $key = $row['pid'].':'.$row['start'];
            if ($row['pid'] === $root || isset($known[$key])) {
                $selected[$row['pid']] = $row;
            }
        }
        do {
            $before = count($selected);
            foreach ($rows as $row) {
                if (isset($selected[$row['ppid']]) && ! in_array($row['pid'], $excluded, true)) {
                    $selected[$row['pid']] = $row;
                }
            }
        } while (count($selected) !== $before);
        foreach ($excluded as $pid) {
            unset($selected[$pid]);
        }
        $instances = [];
        foreach ($selected as $row) {
            $instances[$row['pid'].':'.$row['start']] = true;
        }

        return ['rss_bytes' => $selected === [] ? null : array_sum(array_column($selected, 'rss_bytes')),
            'processes' => array_values($selected), 'known' => $instances];
    }

    /**
     * @param  array<array-key, mixed>  $known
     * @return array<array-key, mixed>
     */
    public static function observe(int $root, array $known, int $observer): array
    {
        $begin = hrtime(true);
        $process = new Process(['ps', '-axo', 'pid=,ppid=,rss=,lstart='], timeout: 1);
        $process->run();
        $rows = [];
        $excluded = [$observer];
        if ($process->isSuccessful()) {
            foreach (explode("\n", $process->getOutput()) as $line) {
                if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s+(.+)$/D', $line, $match)) {
                    $pid = (int) $match[1];
                    $ppid = (int) $match[2];
                    if ($ppid === $observer) {
                        $excluded[] = $pid;
                    }
                    $rows[] = ['pid' => $pid, 'ppid' => $ppid, 'role' => $pid === $root ? 'php-capture' : 'owned-descendant', 'rss_bytes' => (int) $match[3] * 1024,
                        'start' => PHP_OS_FAMILY === 'Linux' ? self::identity($pid) : 'ps-lstart:'.$match[4]];
                }
            }
        }
        $sample = self::select($rows, $root, $known, $excluded);
        $observerRows = array_values(array_filter($rows, static fn (array $row): bool => in_array($row['pid'], $excluded, true)));
        $observerRss = $observerRows === [] ? null : array_sum(array_column($observerRows, 'rss_bytes'));
        $sample['observer_processes'] = $observerRows;
        $sample['observer_rss_bytes'] = $observerRss;
        $sample['admission_process_count'] = count($sample['processes']) + count($observerRows);
        $sample['admission_rss_bytes'] = $sample['rss_bytes'] === null || $observerRss === null ? null : $sample['rss_bytes'] + $observerRss;
        $sample['rss_scope'] = 'owned application processes; observer tree reported separately and included in admission';

        return ['monotonic_begin_ns' => $begin, 'monotonic_end_ns' => hrtime(true), ...$sample,
            'status' => $rows === [] ? 'NOT_AVAILABLE' : 'sampled', 'interval_ns' => 50000000,
            'reason' => $rows === [] ? 'ps_unavailable' : null,
            'coverage' => ['short_lived_children' => 'NOT_AVAILABLE:may exit between samples',
                'unobserved_reparenting' => 'NOT_AVAILABLE:not discovered before reparent',
                'start_resolution' => PHP_OS_FAMILY === 'Linux' ? 'kernel_ticks' : 'NOT_AVAILABLE:ps lstart has one-second precision',
                'peak' => 'observed sample only; ps process rows are not an atomic OS snapshot']];
    }
}
