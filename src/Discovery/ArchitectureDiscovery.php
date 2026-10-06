<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Discovery;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Impact\ExecutionLinks;
use GracjanKubicki\ArchitectureKit\Impact\ExecutionSources;
use GracjanKubicki\ArchitectureKit\Impact\HttpRouteDiscovery;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/** Literal discovery returns declarations, not inferred runtime entry points. */
final class ArchitectureDiscovery
{
    private bool $candidateLimited = false;

    public const KINDS = ['class', 'interface', 'trait', 'enum', 'method', 'file', 'route', 'command', 'job', 'controller', 'model', 'action', 'query', 'service', 'listener', 'event', 'policy', 'request', 'resource', 'test', 'data', 'value-object', 'exception', 'builder', 'port', 'provider'];

    public function __construct(private readonly Filesystem $files, private readonly string $basePath) {}

    /** @return array<string, mixed> */
    public function search(string $query = '', ?string $kind = null, int $limit = 20): array
    {
        $this->candidateLimited = false;
        $query = trim($query);
        if ($limit < 0 || $limit > 500 || strlen($query) > 500 || ($kind !== null && ! in_array($kind, self::KINDS, true))) {
            return self::error('E_SEARCH_INPUT', 'Use a literal query up to 500 bytes, supported kind and limit 0..500.');
        }
        if ($query === '' && $kind === null) {
            return self::error('E_SEARCH_QUERY', 'Provide a name/path fragment or a kind filter.');
        }
        try {
            $settings = DiscoverySettings::load($this->files, $this->basePath);
            $exclude = [...$settings->exclude, 'vendor/*'];
            $loader = new ProjectGraphLoader($this->files, $this->basePath, $settings->scope, $settings->cache, $settings->fingerprint, impact: true, sourceOnly: true);
            $plan = $loader->plan($exclude);
            $graph = $loader->build($plan);
            $httpSource = new HttpRouteDiscovery($this->files, $this->basePath, sourceOnly: true);
            $http = $httpSource->discover($graph, $exclude);
            $sources = new ExecutionSources($this->files, $this->basePath, $settings->scope->directories, retainDeclarations: true);
            $facts = $sources->discover($graph, $exclude, $http['inputs'], $http['execution_facts']);
            $links = new ExecutionLinks($facts, externalBoundaries: true);
            $links->authorizationHttp($http['routes'], $facts);
            $notices = [...$links->notices, ...$http['notices']];
            $rows = [];
            $limited = $links->limited || $http['limited'];
            $classes = $facts['classes'];
            $declarations = $facts['class_declarations'];
            $jobs = $names = [];
            foreach ($declarations as $declaration) {
                $names[strtolower($declaration['name'])] = ($names[strtolower($declaration['name'])] ?? 0) + 1;
            }
            foreach ($links->out as $edges) {
                foreach ($edges as $edge) {
                    if ($edge['kind'] === 'job-handler') {
                        $jobs[strtolower($edge['job'])] = true;
                    }
                }
            }
            foreach ($declarations as $class) {
                if (ImpactExtractor::sourceLimit(0) !== null || count($rows) >= 50000) {
                    $limited = true;
                    break;
                }
                $classification = $loader->classification->describe($class['path'], $class['name'], $class['kind'], $class['methods'] !== []);
                $declared = $loader->classification->mappings->roleMapping($class['path'], $class['name']);
                $types = [$class['kind']];
                if (isset($declared['kind']) && in_array($classification['application_kind'], self::KINDS, true)) {
                    $types[] = $classification['application_kind'];
                }
                $hints = [];
                foreach (['Controllers' => 'controller', 'Models' => 'model', 'Actions' => 'action', 'Queries' => 'query', 'Services' => 'service', 'Listeners' => 'listener', 'Events' => 'event', 'Policies' => 'policy', 'Requests' => 'request', 'Resources' => 'resource', 'Tests' => 'test'] as $folder => $type) {
                    if ((! isset($declared['kind']) || $declared['kind'] === $type) && (str_contains('/'.$class['path'], '/'.$folder.'/') || $type === 'test' && str_starts_with($class['path'], 'tests/'))) {
                        $types[] = $type;
                    }
                }
                if (str_contains('/'.$class['path'], '/Jobs/')) {
                    $hints[] = 'Jobs directory is a placement hint, not a job contract or execution witness.';
                }
                if ($this->inherits($class, 'Illuminate\\Contracts\\Queue\\ShouldQueue', $classes) || $this->inherits($class, 'Illuminate\\Contracts\\Queue\\ShouldQueueAfterCommit', $classes) || $this->inherits($class, 'Illuminate\\Foundation\\Bus\\Dispatchable', $classes)) {
                    $types[] = 'job';
                }
                if (isset($jobs[strtolower($class['name'])])) {
                    $types[] = 'job';
                }
                $duplicate = $names[strtolower($class['name'])] > 1;
                $notes = [...$hints, ...(isset($declared['kind']) ? ['Project-declared application kind is classification, not proof of a framework contract or runtime execution.'] : []), ...($duplicate ? ['Duplicate class name: use the broader file selector and inspect each declaration.'] : [])];
                $this->add($rows, $class['name'], $class['kind'], $class, $types, $duplicate ? $class['path'] : $class['name'], $duplicate ? 'file' : 'symbol', $notes, [substr($class['name'], (int) strrpos('\\'.$class['name'], '\\'))], $classification);
                foreach ($class['methods'] as $method) {
                    $this->add($rows, $method['symbol'], 'method', $method['source'], ['method'], $duplicate ? $class['path'] : $method['symbol'], $duplicate ? 'file' : 'symbol', $notes, [$method['name']], $classification);
                }
            }
            foreach ($facts['analyzed_paths'] as $path) {
                $this->add($rows, $path, 'file', ['path' => $path, 'line' => 1], ['file'], $path, 'file', ['File selector includes all declarations and witnessed entry points in this file.']);
            }
            foreach ($http['routes'] as $route) {
                $handler = $route['handler'];
                $resolved = isset($handler['class'], $handler['method']) && $links->method($handler['class'], $handler['method']) !== null && ($names[strtolower($handler['class'])] ?? 0) === 1;
                $selector = $resolved ? $handler['class'].'::'.$handler['method'] : $route['source']['path'];
                $name = ($route['name'] ?? '').' '.($route['uri'] ?? $route['path'] ?? '');
                $this->add($rows, trim($name), 'route', $route['source'], ['route'], $selector, $resolved ? 'symbol' : 'file', [...$route['reasons'], 'Route declaration does not prove runtime registration.', ...(! $resolved ? ['File selector is broader than this route callback.'] : [])], [$route['name'] ?? '', $route['uri'] ?? '']);
            }
            foreach ($links->out as $edges) {
                foreach ($edges as $edge) {
                    if ($edge['kind'] !== 'console-handler') {
                        continue;
                    }
                    $symbol = $edge['to'];
                    $fileScope = str_starts_with($symbol, '(');
                    $this->add($rows, $edge['command'], 'command', $edge, ['command'], $fileScope ? $edge['path'] : $symbol, $fileScope ? 'file' : 'symbol', [...$edge['conditions'], ...($fileScope ? ['File selector is broader than this command callback.'] : [])]);
                }
            }
            $matches = [];
            foreach ($rows as $row) {
                if ($kind !== null && ! in_array($kind, $row['kinds'], true)) {
                    continue;
                }
                $values = [$row['name'], $row['path'], ...$row['aliases']];
                $exact = $query !== '' && count(array_filter($values, fn ($value) => strcasecmp($value, $query) === 0)) > 0;
                if ($query === '' || count(array_filter($values, fn ($value) => stripos($value, $query) !== false)) > 0) {
                    $matches[] = [...$row, 'exact' => $exact];
                }
            }
            usort($matches, fn ($a, $b) => [$a['exact'] ? 0 : 1, strtolower($a['name']), $a['kind'], $a['path'], $a['line'], $a['id']] <=> [$b['exact'] ? 0 : 1, strtolower($b['name']), $b['kind'], $b['path'], $b['line'], $b['id']]);
            $limited = $limited || $this->candidateLimited;
            $fresh = $sources->fresh() && $httpSource->freshness()['fresh'] && $loader->currentSignature($exclude)->files === $plan->signature->files && DiscoverySettings::stat($this->basePath.'/config/architectures.php') === $settings->configStat;
            $total = count($matches);

            return ['v' => 1, 'cmd' => 'search', 'ok' => true, 'query' => $query, 'kind' => $kind, 'candidates' => array_slice($matches, 0, $limit), 'total' => $total, 'total_is_lower_bound' => $limited, 'ambiguous' => $total > 1, 'truncated' => $total > $limit, 'status' => $limited ? 'limit' : (! $fresh ? 'stale' : ($notices !== [] ? 'incomplete' : ($total === 0 ? 'no_matches' : 'found'))), 'limited' => $limited, 'fresh' => $fresh, 'notices' => array_slice($notices, 0, $limit), 'notice_total' => count($notices), 'cache' => $plan->cacheStatus->value, 'analysis' => ['scope' => $settings->scope->directories, 'source_signature' => $facts['signature'], 'limitations' => ['Only supported static PHP and Laravel declarations are recognized.', 'Dynamic or unrecognized sources remain notices; results do not prove runtime execution.', 'Freshness uses path/mtime/size; same-stat edits are outside this guarantee.', 'No fuzzy search or automatic candidate selection. Use the returned selector with impact/path; tables use impact table searches.']]];
        } catch (Throwable $exception) {
            return self::error('E_SEARCH_FAILED', $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $class
     * @param  array<string, array<string, mixed>>  $classes
     * @param  array<string, bool>  $seen
     */
    private function inherits(array $class, string $target, array $classes, array $seen = []): bool
    {
        $key = strtolower($class['name']);
        if (isset($seen[$key]) || count($seen) >= 32) {
            return false;
        }
        $seen[$key] = true;
        foreach ([...$class['parents'], ...$class['traits']] as $parent) {
            if (strcasecmp($parent, $target) === 0 || isset($classes[strtolower($parent)]) && $this->inherits($classes[strtolower($parent)], $target, $classes, $seen)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, array<string, mixed>> $rows
     * @param  array<string, mixed>  $source
     * @param  list<string>  $kinds
     * @param  list<string>  $notes
     * @param  list<string>  $aliases
     * @param  array<string, mixed>  $classification
     */
    private function add(array &$rows, string $name, string $kind, array $source, array $kinds, ?string $selector, string $scope, array $notes = [], array $aliases = [], array $classification = []): void
    {
        if (count($rows) >= 50000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->candidateLimited = true;

            return;
        }
        $id = hash('xxh128', serialize([$name, $kind, $source['path'], $source['line'], $source['offset'] ?? null]));
        $rows[$id] = ['id' => $id, 'name' => $name, 'kind' => $kind, 'kinds' => array_values(array_unique($kinds)), 'path' => $source['path'], 'line' => $source['line'], 'selector' => $selector, 'selector_scope' => $scope, 'supported' => $selector !== null, 'notes' => $notes, 'aliases' => $aliases, 'classification' => $classification];
    }

    /** @return array<string, mixed> */
    public static function error(string $code, string $message): array
    {
        return ['v' => 1, 'cmd' => 'search', 'ok' => false, 'm' => $code, 'msg' => $message];
    }
}
