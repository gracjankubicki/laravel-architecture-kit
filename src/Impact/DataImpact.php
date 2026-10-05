<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/** Table effects are leaves. They never participate in execution adjacency. */
final class DataImpact
{
    /** @param array<string, mixed> $subject
     * @param  array<string, mixed>|null  $declaration
     * @param  list<string>  $exclude
     * @return array<string, mixed>
     */
    public function inspect(Filesystem $files, string $basePath, ProjectGraphSnapshot $graph, array $subject, ?array $declaration, array $exclude, int $limit, int $depth): array
    {
        $sources = new ExecutionSources($files, $basePath, ['database/migrations']);
        $facts = $sources->discover($graph, $exclude);
        $catalog = new DataCatalog;
        $limited = $facts['limited'];
        foreach ($facts['analyzed_paths'] as $path) {
            if (ImpactExtractor::sourceLimit(0) !== null) {
                $limited = true;
                break;
            }
            try {
                $file = $sources->read($path);
                if ($file === null) {
                    $catalog->notices[] = ['path' => $path, 'line' => 1, 'reason' => 'DATA input changed or is unavailable.'];

                    continue;
                }
                $catalog->collect($file);
                $file->releaseAst();
            } catch (Throwable) {
                $catalog->notices[] = ['path' => $path, 'line' => 1, 'reason' => 'DATA source cannot be read.'];
            }
        }
        $extractor = new DataExtractor($catalog, $sources);
        foreach ($facts['analyzed_paths'] as $path) {
            if (ImpactExtractor::sourceLimit(0) !== null) {
                $limited = true;
                break;
            }
            try {
                $file = $sources->read($path);
                if ($file === null) {
                    $catalog->notices[] = ['path' => $path, 'line' => 1, 'reason' => 'DATA input changed or is unavailable.'];

                    continue;
                }
                $extractor->extract($file);
                $file->releaseAst();
            } catch (Throwable) {
                $catalog->notices[] = ['path' => $path, 'line' => 1, 'reason' => 'DATA extraction is incomplete.'];
            }
        }
        $links = new ExecutionLinks($facts);
        $limited = $limited || $extractor->limited || $links->limited;
        $targets = [];
        foreach ($catalog->classes as $class) {
            if (($subject['kind'] === 'file' && $class['path'] === $subject['path']) || strcasecmp($class['name'], $subject['name']) === 0) {
                $targets[strtolower($class['name'])] = $class['name'];
                foreach ($class['methods'] as $method) {
                    $targets[strtolower($method['symbol'])] = $method['symbol'];
                }
                foreach ($links->methods($class['name']) as $method) {
                    $targets[strtolower($method['symbol'])] = $method['symbol'];
                }
            }
        }
        if ($subject['kind'] === 'file') {
            $targets[strtolower('(file) '.$subject['path'])] = '(file) '.$subject['path'];
            foreach ($extractor->effects as $effect) {
                if ($effect['path'] === $subject['path'] && $effect['source_via'] === []) {
                    $targets[strtolower($effect['from'])] = $effect['from'];
                }
            }
        }
        if ($declaration !== null) {
            $targets = [strtolower($declaration['symbol']) => $declaration['symbol']];
        }
        $out = $links->out;
        $incoming = [];
        foreach ($out as $edges) {
            foreach ($edges as $edge) {
                $incoming[strtolower($edge['to'])][] = $edge;
            }
        }
        $effects = [];
        $noticesBySymbol = [];
        foreach ($extractor->effects as $effect) {
            $effects[strtolower($effect['from'])][] = $effect;
            if ($effect['model'] !== null) {
                $incoming[strtolower($effect['model'])][] = ['from' => $effect['from'], 'to' => $effect['model'], 'kind' => 'data-model-use', 'path' => $effect['path'], 'line' => $effect['line'], 'certainty' => 'possible', 'conditions' => $effect['conditions']];
            }
            foreach ([...$effect['source_via'], ...$effect['preparation_via'], ...array_merge([], ...$effect['preparation_paths'])] as $edge) {
                $incoming[strtolower($edge['to'])][] = $edge;
            }
        }
        foreach ([...$extractor->notices] as $notice) {
            $noticesBySymbol[strtolower($notice['from'])][] = $notice;
        }
        $rows = ['outgoing' => [], 'consumers' => []];
        $notices = $catalog->notices;
        foreach ($facts['notices'] as $notice) {
            if (! str_starts_with($notice['reason'], 'Anonymous execution class')) {
                $notices[] = $notice;
            }
        }
        // First find consumer methods, preserving a concrete caller-to-subject witness.
        $consumerRoots = [];
        $queue = [];
        foreach ($targets as $target) {
            $queue[] = [$target, [], []];
        }
        $visits = 0;
        for ($i = 0; $i < count($queue); $i++) {
            [$symbol, $via, $seen] = $queue[$i];
            $key = strtolower($symbol);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $edges = $incoming[$key] ?? [];
            if (count($via) >= $depth) {
                $limited = $limited || $edges !== [];

                continue;
            }
            foreach ($edges as $edge) {
                if (++$visits > 10000 || count($queue) >= 1000 || ImpactExtractor::sourceLimit(0) !== null) {
                    $limited = true;
                    break 2;
                }
                $path = [$edge, ...$via];
                if (! isset($targets[strtolower($edge['from'])])) {
                    $consumerRoots[strtolower($edge['from'])] ??= ['symbol' => $edge['from'], 'subject_via' => $path];
                }
                $queue[] = [$edge['from'], $path, $seen];
            }
        }
        foreach (['outgoing' => array_map(fn ($s) => ['symbol' => $s, 'subject_via' => []], array_values($targets)), 'consumers' => array_values($consumerRoots)] as $direction => $roots) {
            $queue = [];
            foreach ($roots as $root) {
                $queue[] = [$root['symbol'], [], [], false, $root];
            }
            for ($i = 0; $i < count($queue); $i++) {
                [$symbol, $via, $seen, $quiet, $root] = $queue[$i];
                $key = strtolower($symbol).'|'.($quiet ? 'quiet' : 'normal');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                foreach ($effects[strtolower($symbol)] ?? [] as $effect) {
                    $row = [...$effect, 'root' => $root['symbol'], 'direction' => $direction, 'via' => [...$via, ...$effect['source_via']], 'subject_via' => $root['subject_via']];
                    unset($row['source_via']);
                    $rows[$direction][hash('xxh128', serialize($row))] = $row;
                }
                array_push($notices, ...($noticesBySymbol[strtolower($symbol)] ?? []), ...($links->unknown[strtolower($symbol)] ?? []));
                $edges = $out[strtolower($symbol)] ?? [];
                if (count($via) >= $depth) {
                    $limited = $limited || $edges !== [];

                    continue;
                }
                foreach ($edges as $edge) {
                    if ($quiet && ($edge['requires_events'] ?? false)) {
                        continue;
                    }
                    if (++$visits > 10000 || count($queue) >= 1000 || count($rows[$direction]) >= 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                        $limited = true;
                        break 2;
                    }
                    $nextQuiet = ($edge['reset_quiet'] ?? false) ? false : ($quiet || ($edge['quiet'] ?? false));
                    $queue[] = [$edge['to'], [...$via, $edge], $seen, $nextQuiet, $root];
                }
            }
        }
        $fresh = $sources->fresh();
        if (! $fresh) {
            $notices[] = ['path' => '(data sources)', 'line' => 1, 'reason' => 'DATA source freshness is not established; rerun impact.'];
        }
        if ($limited) {
            $notices[] = ['path' => '(data sources)', 'line' => 1, 'reason' => 'DATA source/extraction/traversal/depth/memory limit; totals are lower bounds.'];
        }
        $unique = [];
        foreach ($notices as $notice) {
            $unique[hash('xxh128', serialize($notice))] = $notice;
        }
        $notices = array_values($unique);
        $totals = ['outgoing' => count($rows['outgoing']), 'consumers' => count($rows['consumers']), 'unresolved' => count($notices)];
        $truncated = $limited || max($totals) > $limit;

        $active = $limited || $extractor->effects !== [] || $extractor->notices !== [] || array_filter($catalog->classes, fn ($c) => $catalog->inherits($c['name'], 'Illuminate\Database\Eloquent\Model') || $catalog->inherits($c['name'], 'Illuminate\Database\Migrations\Migration')) !== [];

        return ['outgoing' => array_slice(array_values($rows['outgoing']), 0, $limit), 'consumers' => array_slice(array_values($rows['consumers']), 0, $limit), 'unresolved' => array_slice($notices, 0, $limit), 'totals' => $totals, 'status' => $truncated ? 'limit' : ($notices !== [] ? 'incomplete' : ($totals['outgoing'] + $totals['consumers'] === 0 ? 'none' : 'complete')), 'truncated' => $truncated, 'has_sources' => $active, 'fresh' => $fresh, 'source_signature' => $facts['signature'], 'limitations' => ['Effects are source possibilities, not executed SQL or applied migrations.', 'Consumer effects are context in callers, not proof of data dependence on the selected result or statement order.', 'Connections are source names/default/dynamic, not resolved database identities. Qualified table names are separate.', 'Runtime-loaded relations, polymorphic targets, macros, dynamic table overrides and unsupported SQL remain unresolved.', 'Paths, mtime and size establish source freshness; equal-stat edits are outside this guarantee.', 'Budgets: 10000 sources/10MB, 100000 expression visits, 10000 effects, 1000 path states/10000 edge visits, requested depth and PHP headroom. Limited totals are lower bounds.']];
    }
}
