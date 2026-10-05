<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use Illuminate\Filesystem\Filesystem;

/** A bounded contextual traversal; quiet state belongs to a path, never a symbol. */
final class ExecutionImpact
{
    /** @param array<string, mixed> $subject
     * @param  array<string, mixed>|null  $declaration
     * @param  list<string>  $exclude
     * @param  array<string, mixed>  $httpSources
     * @return array<string, mixed>
     */
    public function inspect(Filesystem $files, string $basePath, ProjectGraphSnapshot $graph, array $subject, ?array $declaration, array $exclude, int $limit, int $depth, array $httpSources): array
    {
        $sources = new ExecutionSources($files, $basePath);
        $facts = $sources->discover($graph, $exclude, $httpSources['inputs'], $httpSources['execution_facts']);
        $links = new ExecutionLinks($facts);
        $seeds = [];
        foreach ($httpSources['routes'] as $route) {
            $handler = $route['handler'];
            $symbol = isset($handler['callback']) ? '(route) '.$route['source']['path'].':'.$route['source']['offset'] : null;
            if (isset($handler['class'], $handler['method'])) {
                $symbol = $links->method($handler['class'], $handler['method'])['symbol'] ?? null;
            }
            if ($symbol !== null) {
                unset($route['calls']);
                $seeds[] = ['symbol' => $symbol, 'kind' => 'http', 'source' => $route['source'], 'route' => $route];
            }
        }
        array_push($seeds, ...array_values($links->seeds));
        $targets = $subject['kind'] === 'file' ? array_column($graph->symbolsAt($subject['path']), 'name') : [$subject['name']];
        $targetSymbols = [];
        foreach ($targets as $target) {
            $targetSymbols[strtolower($target)] = true;
            foreach ($links->methods($target) as $method) {
                $targetSymbols[strtolower($method['symbol'])] = true;
            }
        }
        if ($subject['kind'] === 'file') {
            foreach ($links->out as $edges) {
                foreach ($edges as $edge) {
                    if (str_starts_with($edge['to'], '(callback) '.$subject['path'].':')) {
                        $targetSymbols[strtolower($edge['to'])] = true;
                    }
                }
            }
        }
        if ($declaration !== null) {
            $targetSymbols = [strtolower($declaration['symbol']) => true];
        }
        $queue = [];
        $limited = $links->limited;
        foreach ($seeds as $seed) {
            if (count($queue) >= 1000 || ImpactExtractor::sourceLimit(0) !== null) {
                $limited = true;
                break;
            }
            $queue[] = [$seed['symbol'], false, [], $seed, []];
        }
        $rows = [];
        $reachedNotices = [];
        $visits = 0;
        for ($i = 0; $i < count($queue); $i++) {
            [$symbol, $quiet, $via, $seed, $seen] = $queue[$i];
            $key = strtolower($symbol).'|'.($quiet ? 'quiet' : 'normal');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            array_push($reachedNotices, ...($links->unknown[strtolower($symbol)] ?? []));
            $semantic = array_filter($via, fn ($e) => ! in_array($e['kind'], ['call', 'new', 'callback'], true));
            if (isset($targetSymbols[strtolower($symbol)]) && $via !== [] && $semantic !== []) {
                $id = hash('xxh128', serialize([$seed['route']['id'] ?? $seed['symbol'], array_map(fn ($e) => [$e['from'], $e['to'], $e['path'], $e['offset'], $e['kind'], $e['mode'] ?? null, $e['timing'] ?? null, $e['conditions']], $via)]));
                $rows[$id] = ['id' => $id, 'entry' => $seed, 'target' => $symbol, 'certainty' => count(array_filter($via, fn ($e) => $e['certainty'] === 'possible')) > 0 || ($seed['route']['certainty'] ?? '') === 'possible' ? 'possible' : 'declared', 'via' => $via];

                continue;
            }
            $edges = $links->out[strtolower($symbol)] ?? [];
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
                $queue[] = [$edge['to'], $nextQuiet, [...$via, $edge], $seed, $seen];
            }
        }
        $fresh = $sources->fresh();
        $notices = [...$links->notices, ...$reachedNotices];
        if (! $fresh) {
            $notices[] = ['path' => '(execution sources)', 'line' => 1, 'reason' => $limited ? 'Execution analysis is limited; freshness is not established.' : 'Execution sources changed during analysis. Rerun impact.'];
        }
        if ($limited) {
            $notices[] = ['path' => '(execution sources)', 'line' => 1, 'reason' => 'Execution source/link/traversal/depth/memory limit reached; totals are lower bounds.'];
        }
        $unique = [];
        foreach ($notices as $notice) {
            $unique[json_encode($notice, JSON_THROW_ON_ERROR)] = $notice;
        }
        $notices = array_values($unique);
        $rows = array_values($rows);
        $truncated = $limited || count($rows) > $limit || count($notices) > $limit;
        $active = $reachedNotices !== [] || $limited || $links->seeds !== [] || $links->registrations !== [] || array_filter($facts['operations'], fn ($o) => in_array($o['kind'], ['dispatch', 'event', 'quiet', 'discovery', 'console_candidate', 'console_registration', 'console_closure', 'schedule'], true)) !== [];

        return ['flows' => array_slice($rows, 0, $limit), 'unresolved' => $active ? array_slice($notices, 0, $limit) : [], 'totals' => ['flows' => count($rows), 'unresolved' => $active ? count($notices) : 0], 'status' => ! $active ? 'none' : ($truncated ? 'limit' : ($notices !== [] || count(array_filter($rows, fn ($r) => $r['certainty'] === 'possible')) > 0 ? 'incomplete' : ($rows === [] ? 'none' : 'complete'))), 'truncated' => $active && $truncated, 'has_sources' => $active, 'source_signature' => $facts['signature'], 'fresh' => $fresh, 'limitations' => ['Console and scheduler sources do not prove active command registration, due tasks, subprocess success or worker success.', 'Source dispatch and registration witnesses do not prove runtime execution, queue configuration, retries or provider activation.', 'Chains have conditional order; batches have independent branches. Conditions, propagation and cancellation are not evaluated.', 'Model event suppression is path-local; deferred execution starts a separate event context.', 'Freshness uses paths, mtime and size. Edits preserving mtime and size are outside this guarantee.', 'Query budgets: 1000 queued path states, 10000 edge visits, requested depth and PHP memory headroom; limited totals are lower bounds.']];
    }
}
