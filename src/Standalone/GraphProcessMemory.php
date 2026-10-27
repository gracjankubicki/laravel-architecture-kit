<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use Symfony\Component\Process\Process;

/** Read-only OS accounting for one owned process and its parser/observer descendants. */
final class GraphProcessMemory
{
    /** @return array{rss_bytes: ?int, processes: list<array{pid: int, ppid: int, rss_bytes: int}>, reason: ?string} */
    public static function tree(int $root): array
    {
        return self::forest([$root]);
    }

    /** One OS sample for all owned trees, deduplicated by PID.
     * @param list<int> $roots
     * @return array{rss_bytes: ?int, processes: list<array{pid: int, ppid: int, rss_bytes: int}>, reason: ?string} */
    public static function forest(array $roots): array
    {
        try {
            $process = new Process(['ps', '-axo', 'pid=,ppid=,rss='], timeout: 1);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new \RuntimeException('process_rss_unavailable');
            }
            $rows = [];
            foreach (explode("\n", trim($process->getOutput())) as $line) {
                if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s*$/D', $line, $match)) {
                    $rows[(int) $match[1]] = ['pid' => (int) $match[1], 'ppid' => (int) $match[2], 'rss_bytes' => (int) $match[3] * 1024];
                }
            }
            foreach ($roots as $root) {
                if (! isset($rows[$root]) || $rows[$root]['rss_bytes'] <= 0) {
                    throw new \RuntimeException('process_root_rss_unavailable');
                }
            }
            $owned = array_fill_keys($roots, true);
            for ($depth = 0; $depth < 8; $depth++) {
                foreach ($rows as $pid => $row) {
                    if (isset($owned[$row['ppid']])) {
                        $owned[$pid] = true;
                    }
                }
            }
            $selected = array_values(array_intersect_key($rows, $owned));

            return ['rss_bytes' => array_sum(array_column($selected, 'rss_bytes')), 'processes' => $selected, 'reason' => null];
        } catch (\Throwable $error) {
            return ['rss_bytes' => null, 'processes' => [], 'reason' => $error->getMessage()];
        }
    }
}
