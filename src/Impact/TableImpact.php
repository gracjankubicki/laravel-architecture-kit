<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use Illuminate\Filesystem\Filesystem;

/** Finds source operations. Tables are leaves, never execution adjacency. */
final class TableImpact
{
    /** @param list<string> $exclude
     * @return array<string, mixed>
     */
    public function inspect(Filesystem $files, string $basePath, ProjectGraphSnapshot $graph, array $exclude, string $table, string $match, ?string $connection, ?string $operation, int $limit, int $depth): array
    {
        $discovery = new HttpRouteDiscovery($files, $basePath, sourceOnly: true);
        $http = $discovery->discover($graph, $exclude);
        $analysis = DataAnalysis::collect($files, $basePath, $graph, $exclude, $http);
        $links = $analysis->links;
        $limited = $analysis->limited || $http['limited'];
        $entries = [];
        foreach ($links->seeds as $seed) {
            $entries[strtolower($seed['symbol'])][] = $seed;
        }
        foreach ($http['routes'] as $route) {
            $handler = $route['handler'];
            $target = isset($handler['callback']) ? '(route) '.$route['source']['path'].':'.$route['source']['offset'] : null;
            if (isset($handler['class'], $handler['method'])) {
                $target = $links->method($handler['class'], $handler['method'])['symbol'] ?? null;
            }
            if ($target !== null) {
                $root = '(http) '.$route['id'];
                unset($route['calls']);
                $links->out[strtolower($root)][] = ['from' => $root, 'to' => $target, 'kind' => 'http-handler', ...$route['source'], 'certainty' => $route['certainty'], 'conditions' => [...$route['reasons'], 'Route registration must be active.']];
                $entries[strtolower($root)][] = ['symbol' => $root, 'kind' => 'http', 'source' => $route['source'], 'route' => $route];
            }
        }
        $incoming = [];
        foreach ($links->out as $edges) {
            foreach ($edges as $edge) {
                $incoming[strtolower($edge['to'])][] = $edge;
            }
        }
        $usages = [];
        $tables = [];
        $visits = 0;
        $states = 0;
        $pathTotal = 0;
        foreach ($analysis->extractor->effects as $effect) {
            $name = $effect['table'];
            if (($match === 'exact' ? $name !== $table : ! str_contains($name, $table)) || ($operation !== null && $effect['kind'] !== $operation) || ! $this->connectionMatches($effect['connection'], $connection)) {
                continue;
            }
            $id = hash('xxh128', serialize($effect));
            if (isset($usages[$id])) {
                continue;
            }
            $tableKey = serialize([$name, $effect['connection']]);
            $tables[$tableKey] = ['table' => $name, 'connection' => $effect['connection']];
            $paths = [];
            $queue = [[$effect['from'], [], []]];
            for ($i = 0; $i < count($queue); $i++) {
                [$symbol, $via, $seen] = $queue[$i];
                $key = strtolower($symbol);
                if (isset($seen[$key])) {
                    continue;
                }
                if (++$states > 1000 || ! $this->hasHeadroom()) {
                    $limited = true;
                    break;
                }
                $seen[$key] = true;
                $operationVia = [...$via, ...$effect['source_via']];
                if ($this->enabled($operationVia)) {
                    $roots = $entries[$key] ?? [['symbol' => $symbol, 'kind' => $via === [] ? 'local' : 'caller']];
                    foreach ($roots as $entry) {
                        $row = ['entry' => $entry, 'via' => $operationVia, 'conditions' => $effect['conditions'], 'certainty' => 'possible'];
                        $paths[hash('xxh128', serialize($row))] = $row;
                    }
                }
                $edges = $incoming[$key] ?? [];
                if (count($via) >= $depth) {
                    $limited = $limited || $edges !== [];

                    continue;
                }
                foreach ($edges as $edge) {
                    if (++$visits > 10000 || count($queue) >= 1000 || ! $this->hasHeadroom()) {
                        $limited = true;
                        break 2;
                    }
                    $queue[] = [$edge['from'], [$edge, ...$via], $seen];
                }
            }
            $id = hash('xxh128', serialize($effect));
            $pathTotal += count($paths);
            $usages[$id] = [...$effect, 'id' => $id, 'via' => $effect['source_via'], 'paths' => array_slice(array_values($paths), 0, $limit), 'path_total' => count($paths), 'paths_truncated' => count($paths) > $limit];
            unset($usages[$id]['source_via']);
        }
        $modeledSites = [];
        foreach ($analysis->extractor->effects as $effect) {
            $modeledSites[$effect['path'].':'.$effect['offset']] = true;
        }
        $executionUnknown = array_filter(array_merge([], ...array_values($links->unknown)), fn ($n) => ! (str_starts_with($n['reason'], 'Execution call receiver/method is unresolved: (unknown)::') && isset($modeledSites[$n['path'].':'.($n['offset'] ?? -1)])));
        $notices = [...$analysis->catalog->notices, ...$analysis->extractor->notices, ...$links->notices, ...$executionUnknown, ...$http['notices']];
        $fresh = $analysis->sources->fresh() && $discovery->freshness()['fresh'];
        if (! $fresh) {
            $notices[] = ['path' => '(table sources)', 'line' => 1, 'reason' => 'Table query sources changed or freshness is unestablished; rerun impact.'];
        }
        if ($limited) {
            $notices[] = ['path' => '(table sources)', 'line' => 1, 'reason' => 'Table source/extraction/path/depth/memory limit; totals are lower bounds.'];
        }
        $unique = [];
        foreach ($notices as $notice) {
            $unique[hash('xxh128', serialize($notice))] = $notice;
        }
        $notices = array_values($unique);
        $totals = ['tables' => count($tables), 'usages' => count($usages), 'paths' => $pathTotal, 'unresolved' => count($notices)];
        $truncated = $limited || max($totals['tables'], $totals['usages'], $totals['unresolved']) > $limit || array_filter($usages, fn ($r) => $r['paths_truncated']) !== [];

        return ['query' => ['table' => $table, 'match' => $match, 'connection' => $connection, 'operation' => $operation], 'tables' => array_slice(array_values($tables), 0, $limit), 'usages' => array_slice(array_values($usages), 0, $limit), 'unresolved' => array_slice($notices, 0, $limit), 'totals' => $totals, 'status' => $truncated ? 'limit' : ($notices !== [] ? 'incomplete' : ($usages === [] ? 'none' : 'complete')), 'truncated' => $truncated, 'fresh' => $fresh, 'source_signature' => hash('xxh128', $analysis->facts['signature'].$http['signature']), 'limit' => $limit, 'depth' => $depth, 'limitations' => ['Names are matched literally and case-sensitively, including any schema qualifier; contains is a literal substring, not a wildcard.', 'Connections are source names/default/dynamic, not physical database identities. Unknown connections never match a named connection filter.', 'Unresolved rows describe global source boundaries, not proven uses of the searched table. Empty incomplete or limited results do not prove absence.', 'Usages and paths are source possibilities, not executed SQL, active routes, due schedules or completed workers. Same tables never connect processes.', 'Query preparation is separate from operation paths. Migration up/down are declarations, not applied schema changes.', 'Path event suppression is evaluated along each source path; standalone methods remain possible local uses.', 'Freshness uses paths, mtime and size; equal-stat edits are outside this guarantee.', 'Source/extraction budgets follow DATA; caller paths share 1000 states/10000 edge visits, requested depth and PHP memory headroom. Display limits apply per section and per usage paths; limited totals are lower bounds.']];
    }

    /** @phpstan-impure Memory usage changes as traversal allocates rows. */
    private function hasHeadroom(): bool
    {
        return ImpactExtractor::sourceLimit(0) === null;
    }

    /** @param array<string, mixed> $connection */
    private function connectionMatches(array $connection, ?string $filter): bool
    {
        if ($filter === null) {
            return true;
        }

        return str_starts_with($filter, 'named:') ? $connection['kind'] === 'named' && $connection['name'] === substr($filter, 6) : $connection['kind'] === $filter;
    }

    /** @param list<array<string, mixed>> $via */
    private function enabled(array $via): bool
    {
        $quiet = false;
        foreach ($via as $edge) {
            if ($quiet && ($edge['requires_events'] ?? false)) {
                return false;
            }
            $quiet = ($edge['reset_quiet'] ?? false) ? false : ($quiet || ($edge['quiet'] ?? false));
        }

        return true;
    }
}
