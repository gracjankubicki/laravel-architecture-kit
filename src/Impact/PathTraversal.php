<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

/** Enumerates bounded simple paths, preserving separate quiet contexts per path. */
final class PathTraversal
{
    /** @param list<string> $starts
     * @param  list<string>  $targets
     * @param  array<string, list<array<string, mixed>>>  $out
     * @param  array<string, list<array<string, mixed>>>  $unknown
     * @return array<string, mixed>
     */
    public function find(array $starts, array $targets, array $out, array $unknown, int $limit, int $depth): array
    {
        $targetSet = array_fill_keys(array_map('strtolower', $targets), true);
        $queue = [];
        $limited = false;
        foreach (array_unique($starts) as $start) {
            if (count($queue) >= 1000 || ImpactExtractor::sourceLimit(0) !== null) {
                $limited = true;
                break;
            }
            $queue[] = [$start, $start, false, [], []];
        }
        $paths = $boundaries = $notices = [];
        $visits = 0;
        for ($i = 0; $i < count($queue); $i++) {
            [$start, $symbol, $quiet, $via, $seen] = $queue[$i];
            $key = strtolower($symbol).'|'.($quiet ? 'quiet' : 'normal');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            foreach ($unknown[strtolower($symbol)] ?? [] as $notice) {
                $notices[serialize($notice)] = $notice;
            }
            $row = ['from' => $start, 'to' => $symbol, 'certainty' => array_filter($via, fn ($e) => $e['certainty'] === 'possible') === [] ? 'declared' : 'possible', 'via' => $via];
            if (isset($targetSet[strtolower($symbol)])) {
                $paths[serialize([$start, $via])] = $row;
                if (count($paths) > $limit) {
                    $limited = true;
                    break;
                }

                continue;
            }
            if ($via !== [] && ($via[array_key_last($via)]['external'] ?? false)) {
                if (count($boundaries) < $limit) {
                    $boundaries[serialize([$start, $via])] = $row;
                } else {
                    $limited = true;
                }

                continue;
            }
            $edges = $out[strtolower($symbol)] ?? [];
            if (count($via) >= $depth) {
                $limited = $limited || $edges !== [];

                continue;
            }
            foreach ($edges as $edge) {
                if (++$visits > 10000 || count($queue) >= 1000 || ImpactExtractor::sourceLimit(0) !== null) {
                    $limited = true;
                    break 2;
                }
                if ($quiet && ($edge['requires_events'] ?? false)) {
                    continue;
                }
                $nextQuiet = ($edge['reset_quiet'] ?? false) ? false : ($quiet || ($edge['quiet'] ?? false));
                $queue[] = [$start, $edge['to'], $nextQuiet, [...$via, $edge], $seen];
            }
        }

        return ['paths' => array_slice(array_values($paths), 0, $limit), 'found' => $paths !== [], 'path_total' => count($paths), 'external_boundaries' => array_values($boundaries), 'notices' => array_values($notices), 'limited' => $limited, 'edge_visits' => $visits];
    }
}
