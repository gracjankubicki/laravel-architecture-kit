<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use Illuminate\Filesystem\Filesystem;

/** A source-only A-to-B query. Structural projections never imply method execution. */
final readonly class ArchitecturePath
{
    /** @param list<string> $cacheConfiguration */
    public function __construct(private Filesystem $files, private string $basePath, private AuditScope $scope = new AuditScope, private ?ProjectGraphCache $cache = null, private array $cacheConfiguration = []) {}

    /** @param list<string> $exclude
     * @return array<string, mixed>
     */
    public function inspect(string $from, string $to, array $exclude = [], int $limit = 20, int $depth = 8): array
    {
        if (trim($from) === '' || trim($to) === '') {
            return self::error('E_PATH_ENDPOINT_REQUIRED', 'Provide both from and to as a class, method or PHP file.');
        }
        if ($limit < 0 || $limit > 500 || $depth < 1 || $depth > 32) {
            return self::error('E_PATH_LIMIT_INVALID', 'Use limit 0..500 and depth 1..32.');
        }
        $exclude = [...$exclude, 'vendor/*'];
        $loader = new ProjectGraphLoader($this->files, $this->basePath, $this->scope, $this->cache, $this->cacheConfiguration, impact: true, sourceOnly: true);
        $plan = $loader->plan($exclude);
        $graph = $loader->build($plan);
        $httpDiscovery = new HttpRouteDiscovery($this->files, $this->basePath, sourceOnly: true);
        $http = $httpDiscovery->discover($graph, $exclude);
        $sources = new ExecutionSources($this->files, $this->basePath);
        $facts = $sources->discover($graph, $exclude, $http['inputs'], $http['execution_facts']);
        $links = new ExecutionLinks($facts, externalBoundaries: true);
        $links->authorizationHttp($http['routes'], $facts);
        $locations = $classes = [];
        foreach ($graph->symbols as $symbol) {
            $classes[strtolower($symbol->name).'|'.$symbol->path] = ['name' => $symbol->name, 'path' => $symbol->path, 'line' => $symbol->line];
        }
        foreach ($facts['classes'] as $key => $class) {
            $classes[$key.'|'.$class['path']] = ['name' => $class['name'], 'path' => $class['path'], 'line' => $class['line']];
            foreach ($class['methods'] as $method) {
                $locations[strtolower($method['symbol'])] = $method['source'];
            }
        }
        foreach ($classes as $key => $class) {
            $locations[strtolower($class['name'])] = ['path' => $class['path'], 'line' => $class['line']];
        }
        // HTTP registration is a distinct witnessed edge, not the controller declaration.
        foreach ($http['routes'] as $route) {
            $handler = $route['handler'];
            $target = isset($handler['callback']) ? '(route) '.$route['source']['path'].':'.$route['source']['offset'] : null;
            if (isset($handler['class'], $handler['method'])) {
                $target = $links->method($handler['class'], $handler['method'])['symbol'] ?? null;
            }
            if ($target !== null) {
                $root = '(http) '.$route['id'];
                $links->out[strtolower($root)][] = ['from' => $root, 'to' => $target, 'kind' => 'http-handler', ...$route['source'], 'certainty' => $route['certainty'] === 'possible' ? 'possible' : 'declared', 'conditions' => [...$route['reasons'], 'Route registration must be active.'], 'route' => $route];
                $locations[strtolower($root)] = $route['source'];
                $locations[strtolower($target)] ??= $route['source'];
            }
        }
        // Callback and synthetic entry symbols have call-site locations, never invented declarations.
        foreach ($links->out as $edges) {
            foreach ($edges as $edge) {
                foreach (['from', 'to'] as $side) {
                    if (str_starts_with($edge[$side], '(')) {
                        $locations[strtolower($edge[$side])] ??= $side === 'to' && is_array($edge['registration'] ?? null) ? $edge['registration'] : ['path' => $edge['path'], 'line' => $edge['line']];
                    }
                }
            }
        }
        $a = $this->endpoint($from, $classes, $locations, $facts, $links);
        $b = $this->endpoint($to, $classes, $locations, $facts, $links);
        foreach (['from' => $a, 'to' => $b] as $side => $endpoint) {
            if (isset($endpoint['error'])) {
                return [...self::error($endpoint['error'], $endpoint['message']), 'endpoint' => $side, 'candidates' => array_slice($endpoint['candidates'] ?? [], 0, $limit), 'truncated' => count($endpoint['candidates'] ?? []) > $limit, 'limited' => $links->limited || $http['limited']];
            }
        }
        $structural = [];
        $structuralLimited = false;
        foreach ($graph->edges as $edge) {
            if (ImpactExtractor::sourceLimit(0) !== null) {
                $structuralLimited = true;
                break;
            }
            $structural[strtolower($edge->from)][] = ['from' => $edge->from, 'to' => $edge->to, 'path' => $edge->path, 'line' => $edge->line, 'kind' => $edge->kind, 'strength' => $edge->strong ? 'strong' : 'weak', 'certainty' => 'declared', 'conditions' => [], 'external' => $graph->symbol($edge->to) === null];
        }
        $walk = new PathTraversal;
        $dependency = $walk->find($a['structural'], $b['structural'], $structural, [], $limit, $depth);
        $execution = $walk->find($a['execution'], $b['execution'], $links->out, $links->unknown, $limit, $depth);
        $graphFresh = $loader->currentSignature($exclude)->files === $plan->signature->files;
        $executionFresh = $sources->fresh() && $httpDiscovery->freshness()['fresh'];
        $graphNotices = [];
        foreach ($graph->impactFacts as $input) {
            foreach ($input->notices as $notice) {
                $graphNotices[] = ['path' => $input->path, ...$notice];
            }
        }
        foreach ([$a, $b] as $endpoint) {
            if ($endpoint['structural'] === []) {
                $graphNotices[] = ['path' => $endpoint['selector'], 'line' => 1, 'reason' => 'Endpoint has no structural class declaration in the configured audit graph. Inspect the execution channel.'];
            }
            foreach ($endpoint['structural'] as $name) {
                if (! $endpoint['external'] && $graph->symbol($name) === null) {
                    $graphNotices[] = ['path' => $endpoint['selector'], 'line' => 1, 'reason' => 'Endpoint declaration is outside the configured structural graph scope: '.$name];
                }
            }
        }
        $dependency = $this->channel($dependency, $graphNotices, $structuralLimited, $graphFresh, $locations, $limit);
        $execution = $this->channel($execution, [...$links->notices, ...$http['notices']], $links->limited || $http['limited'], $executionFresh && $graphFresh, $locations, $limit);

        return ['v' => 1, 'ok' => true, 'cmd' => 'path', 'from' => $a, 'to' => $b, 'dependencies' => $dependency, 'execution' => $execution, 'cache' => $plan->cacheStatus->value, 'analysis' => ['limit' => $limit, 'depth' => $depth, 'fresh' => $graphFresh && $executionFresh, 'source_signature' => $facts['signature'], 'limitations' => ['Dependencies project method selectors onto their owning classes; they do not prove method calls or repair cost.', 'Execution class selectors include available methods; file selectors include declarations, callbacks and witnessed entry points in that file. These are sets, not runtime entry guarantees.', 'Only supported static PHP and Laravel contracts are resolved. Dynamic calls, magic dispatch, standalone functions and trait adaptations may remain unresolved.', 'External sources are terminal boundaries; vendor code is never read. A missing declaration does not prove an installed package.', 'Paths do not prove runtime execution, active routes, queue workers or due scheduled tasks.', 'Freshness uses paths, mtime and size; same-stat edits are outside this guarantee.', 'Budgets: 1000 queued path states, 10000 edge visits per channel, depth and PHP memory headroom. Totals under limits are lower bounds.']]];
    }

    /** @param array<string, array<string, mixed>> $classes
     * @param  array<string, array<string, mixed>>  $locations
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function endpoint(string $selector, array $classes, array $locations, array $facts, ExecutionLinks $links): array
    {
        $selector = trim($selector);
        [$name, $method] = array_pad(explode('::', $selector, 2), 2, null);
        $path = str_replace('\\', '/', $name);
        if (str_starts_with($path, $this->basePath.'/')) {
            $path = substr($path, strlen($this->basePath) + 1);
        }
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        $isFile = str_contains($name, '/') || str_ends_with(strtolower($name), '.php');
        $matches = [];
        foreach ($classes as $class) {
            $short = substr($class['name'], (int) strrpos('\\'.$class['name'], '\\'));
            if ($isFile ? $class['path'] === $path : (strcasecmp($class['name'], ltrim($name, '\\')) === 0 || (! str_contains(ltrim($name, '\\'), '\\') && strcasecmp($short, $name) === 0))) {
                $matches[] = $class;
            }
        }
        if (! $isFile && count($matches) > 1) {
            return ['error' => 'E_PATH_ENDPOINT_AMBIGUOUS', 'message' => 'Choose an exact FQCN.', 'candidates' => $matches];
        }
        if ($isFile && $method !== null) {
            return ['error' => 'E_PATH_ENDPOINT_INVALID', 'message' => 'Use Class::method or a file selector separately.'];
        }
        $structural = array_column($matches, 'name');
        $execution = $structural;
        foreach ($matches as $class) {
            array_push($execution, ...array_column($links->methods($class['name']), 'symbol'));
        }
        if ($isFile) {
            foreach ($locations as $symbol => $location) {
                if ($location['path'] === $path && str_starts_with($symbol, '(')) {
                    $execution[] = $symbol;
                }
            }
            if (in_array($path, $facts['analyzed_paths'], true)) {
                $execution[] = '(file) '.$path;
            }
        } elseif ($matches === []) {
            // An external endpoint is selectable only from an observed boundary witness.
            foreach ($links->out as $edges) {
                foreach ($edges as $edge) {
                    if (($edge['external'] ?? false) && (strcasecmp($edge['to'], ltrim($selector, '\\')) === 0 || ($method === null && strcasecmp(explode('::', $edge['to'])[0], ltrim($name, '\\')) === 0))) {
                        $execution[] = $edge['to'];
                        $structural[] = explode('::', $edge['to'])[0];
                    }
                }
            }
        }
        if ($matches !== [] && $method !== null) {
            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $method)) {
                return ['error' => 'E_PATH_ENDPOINT_INVALID', 'message' => 'Use a PHP method identifier.'];
            }
            $declaration = $links->method($matches[0]['name'], $method);
            if ($declaration === null) {
                return ['error' => 'E_PATH_METHOD_UNRESOLVED', 'message' => 'Method declaration is absent or unresolved.'];
            }
            $execution = [$declaration['symbol']];
        }
        if ($execution === [] && $structural === []) {
            return ['error' => $links->limited ? 'E_PATH_ANALYSIS_LIMIT' : 'E_PATH_ENDPOINT_NOT_FOUND', 'message' => 'Endpoint is not present in the analyzed sources or witnessed external boundaries.'];
        }

        return ['selector' => $selector, 'kind' => $isFile ? 'file' : ($method !== null ? 'method' : 'class'), 'declarations' => $matches, 'structural' => array_values(array_unique($structural)), 'execution' => array_values(array_unique($execution)), 'external' => ! $isFile && $matches === []];
    }

    /** @param array<string, mixed> $result
     * @param  list<array<string, mixed>>  $notices
     * @param  array<string, array<string, mixed>>  $locations
     * @return array<string, mixed>
     */
    private function channel(array $result, array $notices, bool $limited, bool $fresh, array $locations, int $limit): array
    {
        $notices = array_values(array_unique([...$notices, ...$result['notices']], SORT_REGULAR));
        $limited = $limited || $result['limited'] || count($notices) > $limit;
        foreach (['paths', 'external_boundaries'] as $group) {
            foreach ($result[$group] as &$row) {
                $symbols = [$row['from'], ...array_column($row['via'], 'to')];
                $row['nodes'] = array_map(fn ($symbol) => ['symbol' => $symbol, 'location' => $locations[strtolower($symbol)] ?? null, 'external' => ! isset($locations[strtolower($symbol)])], $symbols);
            }
            unset($row);
        }

        return [...$result, 'status' => $limited ? 'limit' : (! $fresh ? 'stale' : ($notices !== [] || array_filter($result['paths'], fn ($p) => $p['certainty'] === 'possible') !== [] ? 'incomplete' : ($result['found'] ? 'found' : 'no_path'))), 'fresh' => $fresh, 'limited' => $limited, 'notices' => array_slice($notices, 0, $limit), 'notice_total' => count($notices)];
    }

    /** @return array<string, mixed> */
    public static function error(string $code, string $message): array
    {
        return ['v' => 1, 'ok' => false, 'cmd' => 'path', 'm' => $code, 'msg' => $message];
    }
}
