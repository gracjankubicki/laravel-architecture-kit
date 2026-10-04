<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use Illuminate\Filesystem\Filesystem;

/** HTTP context has its own bounded query; it never changes preflight verdicts. */
final class HttpRouteImpact
{
    private int $visits = 0;

    private bool $limited = false;

    private int $queued = 0;

    /**
     * @param  array<string, mixed>  $subject
     * @param  array<string, mixed>|null  $declaration
     * @param  list<string>  $exclude
     * @param  array<string, mixed>|null  $sources
     * @return array<string, mixed>
     */
    public function inspect(Filesystem $files, string $basePath, ProjectGraphSnapshot $graph, array $subject, ?array $declaration, array $exclude, int $limit, int $depth, ?HttpRouteDiscovery $discovery = null, ?array $sources = null): array
    {
        $discovery ??= new HttpRouteDiscovery($files, $basePath);
        $sources ??= $discovery->discover($graph, $exclude);
        $notices = $sources['notices'];
        $this->limited = $sources['limited'];
        $rows = [];
        $routes = $sources['routes'];
        if ($routes !== [] && $this->budget()) {
            $facts = [];
            foreach ($routes as $route) {
                if ($route['calls'] !== []) {
                    $facts[] = new ImpactFacts($route['source']['path'], [], $route['calls'], []);
                }
            }
            $index = new ImpactIndex(new ProjectGraphSnapshot($graph->symbols, $graph->edges, $graph->testInvocations, [...$graph->impactFacts, ...$facts]));
            $this->limited = $this->limited || $index->limitReached();
            $targets = $subject['kind'] === 'file' ? array_column($graph->symbolsAt($subject['path']), 'name') : [$subject['name']];
            $targetKeys = array_map('strtolower', $targets);
            $paths = [];
            $queue = [];
            if ($declaration !== null) {
                $this->queued++;
                $queue[] = [$declaration['symbol'], [], false];
            } else {
                foreach ($index->classes as $class) {
                    if (in_array(strtolower($class['name']), $targetKeys, true)) {
                        foreach ($class['methods'] as $method) {
                            if (! $this->queueRoom() || ! $this->budget()) {
                                $this->limited = true;
                                break 2;
                            }
                            $queue[] = [$class['name'].'::'.$method['name'], [], false];
                        }
                    }
                }
                if ($subject['kind'] === 'file' && $this->queueRoom()) {
                    $queue[] = ['(file) '.$subject['path'], [], false];
                }
                // Construction without an explicit constructor still references the class.
                // Preserve its source witness instead of inventing a declaration.
                foreach ($index->calls as $call) {
                    if (! $this->budget()) {
                        break;
                    }
                    $receiver = $index->receiver($call['receiver']);
                    if ($call['kind'] === 'reference' || $receiver === null || ! in_array(strtolower($receiver), $targetKeys, true)) {
                        continue;
                    }
                    if (! $this->queueRoom()) {
                        break;
                    }
                    $queue[] = [$call['from'], [['from' => $call['from'], 'to' => $receiver, 'path' => $call['path'], 'line' => $call['line'], 'kind' => $call['method'] === '__construct' ? 'new' : 'call', 'certainty' => $call['exact'] ? 'resolved' : 'possible']], ! $call['exact']];

                }
            }
            $visited = [];
            for ($i = 0; $i < count($queue); $i++) {
                [$current, $via, $possible] = $queue[$i];
                $key = strtolower($current).'|'.($possible ? 'possible' : 'resolved');
                if (isset($visited[$key])) {
                    continue;
                }
                $visited[$key] = true;
                $paths[$key] = ['via' => $via, 'possible' => $possible];
                $edges = $index->edges($current, false);
                if (count($via) >= $depth) {
                    $this->limited = $this->limited || $edges !== [];

                    continue;
                }
                foreach ($edges as $edge) {
                    if (! $this->budget()) {
                        break 2;
                    }

                    if ($edge['kind'] === 'reference') {
                        continue;
                    }
                    if (! $this->queueRoom()) {
                        break;
                    }
                    $queue[] = [$edge['from'], [$edge, ...$via], $possible || $edge['certainty'] === 'possible'];
                }
            }
            // Forward reachability shares the query budget and collects uncertainty even
            // when no static path to the requested target can be established.
            $handlerQueue = [];
            $handlerSeeds = [];
            foreach ($routes as $route) {
                if (! $this->budget()) {
                    break;
                }
                $handler = $route['handler'];
                $symbol = $handler['callback'] ?? null;
                if (isset($handler['class'], $handler['method'])) {
                    $symbol = $index->method($handler['class'], $handler['method'])['symbol'] ?? null;
                }
                if ($symbol === null || isset($handlerSeeds[strtolower($symbol)])) {
                    continue;
                }
                if (! $this->queueRoom()) {
                    break;
                }
                $handlerSeeds[strtolower($symbol)] = true;
                $handlerQueue[] = [$symbol, 0];
            }
            $sourceNotices = [];
            foreach ($index->notices as $notice) {
                if (! $this->budget()) {
                    break;
                }
                $sourceNotices[$notice['path']][] = $notice;
            }
            $forwardVisited = [];
            $targetMethod = $declaration['name'] ?? null;
            for ($i = 0; $i < count($handlerQueue); $i++) {
                [$symbol, $level] = $handlerQueue[$i];
                if (isset($forwardVisited[strtolower($symbol)])) {
                    continue;
                }
                $forwardVisited[strtolower($symbol)] = true;
                foreach ($index->unresolved($symbol, $targetMethod, false) as $notice) {
                    if (! $this->budget()) {
                        break 2;
                    }
                    if (! isset($notice['from']) || strcasecmp($notice['from'], $symbol) === 0) {
                        $notices[] = $notice;
                    }
                }
                $owner = explode('::', $symbol, 2)[0];
                $sourcePath = $index->classes[strtolower($owner)]['path'] ?? null;
                foreach ($sourceNotices[$sourcePath ?? ''] ?? [] as $notice) {
                    if (! $this->budget()) {
                        break 2;
                    }
                    $notices[] = $notice;
                }
                $edges = $index->edges($symbol, true);
                if ($level >= $depth) {
                    $this->limited = $this->limited || $edges !== [];

                    continue;
                }
                foreach ($edges as $edge) {
                    if (! $this->budget()) {
                        break 2;
                    }
                    if ($edge['kind'] === 'reference') {
                        continue;
                    }
                    if (isset($handlerSeeds[strtolower($edge['to'])])) {
                        continue;
                    }
                    if (! $this->queueRoom()) {
                        break;
                    }
                    $handlerSeeds[strtolower($edge['to'])] = true;
                    $handlerQueue[] = [$edge['to'], $level + 1];
                }
            }
            foreach ($routes as $route) {
                if (! $this->budget()) {
                    break;
                }
                $handler = $route['handler'];
                $method = null;
                if (isset($handler['callback'])) {
                    $symbol = $handler['callback'];
                } elseif (isset($handler['class'], $handler['method'])) {
                    $method = $index->method($handler['class'], $handler['method']);
                    $symbol = $method['symbol'] ?? $handler['class'].'::'.$handler['method'];
                    if ($method === null) {
                        $notices[] = [...$route['source'], 'reason' => 'HTTP handler method is missing, external or unresolved: '.$symbol];
                    }
                } else {
                    $notices[] = [...$route['source'], 'reason' => 'HTTP handler is unresolved.'];

                    continue;
                }
                $path = $paths[strtolower($symbol).'|resolved'] ?? $paths[strtolower($symbol).'|possible'] ?? null;
                $directClass = $declaration === null && isset($handler['class']) && in_array(strtolower($handler['class']), $targetKeys, true);
                $directFile = $declaration === null && $subject['kind'] === 'file' && $route['source']['path'] === $subject['path'];
                if ($path === null && ! $directClass && ! $directFile) {
                    continue;
                }
                $possible = $route['certainty'] === 'possible' || ($path['possible'] ?? false) || (isset($handler['class']) && $method === null);
                $entry = ['from' => $route['id'], 'to' => $symbol, 'path' => $route['source']['path'], 'line' => $route['source']['line'], 'kind' => 'route-handler', 'certainty' => $possible ? 'possible' : 'declared'];
                unset($route['calls']);
                $rows[] = [...$route, 'certainty' => $possible ? 'possible' : 'declared', 'via' => [$entry, ...($path['via'] ?? [])]];

            }
        }
        if ($this->limited && ! $sources['limited']) {
            $notices[] = ['path' => $subject['path'], 'line' => $subject['line'], 'reason' => 'HTTP query index, traversal, seed/depth or memory limit reached; totals are lower bounds.'];
        }
        $freshness = $discovery->freshness();
        $this->limited = $this->limited || $freshness['limited'];
        if (! $freshness['fresh']) {
            $notices[] = ['path' => '(HTTP sources)', 'line' => 1, 'reason' => $this->limited ? 'HTTP source analysis is limited; freshness is not established.' : 'HTTP source files changed during analysis. Rerun impact.'];
        }
        $unique = [];
        foreach ($notices as $notice) {
            $unique[json_encode($notice, JSON_THROW_ON_ERROR)] = $notice;
        }
        $notices = array_values($unique);
        $truncated = $this->limited || count($rows) > $limit || count($notices) > $limit;
        $incomplete = $notices !== [] || count(array_filter($rows, fn ($r) => $r['certainty'] === 'possible')) > 0;

        return ['routes' => array_slice($rows, 0, $limit), 'unresolved' => array_slice($notices, 0, $limit), 'totals' => ['routes' => count($rows), 'unresolved' => count($notices)], 'status' => $truncated ? 'limit' : ($incomplete ? 'incomplete' : ($rows === [] ? 'none' : 'complete')), 'truncated' => $truncated, 'has_sources' => $sources['has_sources'], 'source_signature' => $sources['signature'], 'fresh' => $freshness['fresh'], 'limitations' => ['HTTP declarations and static registration chains do not prove an active runtime route or request execution.', 'Dynamic registrations, middleware execution, model binding, framework dispatch and callers outside the graph require inspection.', 'URL generation is not route execution. Nested callbacks and callable references are not immediate calls.', 'Totals are lower bounds when limited; global query budgets are 10000 visits and 1000 queued seeds, with PHP headroom and request depth.']];
    }

    private function queueRoom(): bool
    {
        if ($this->queued >= 1000) {
            $this->limited = true;

            return false;
        }
        $this->queued++;

        return true;
    }

    private function budget(): bool
    {
        if (++$this->visits > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;

            return false;
        }

        return true;
    }
}
