<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Audit\AnalysisNotice;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Impact\HttpRouteDiscovery;
use Illuminate\Filesystem\Filesystem;

/** Source candidates feed endpoint checks without claiming runtime registration. */
final readonly class SourceRouteMap
{
    public function __construct(private Filesystem $files, private string $basePath) {}

    /** @param list<string> $exclude */
    public function build(ProjectGraphSnapshot $graph, array $exclude = []): RouteMap
    {
        $discovery = new HttpRouteDiscovery($this->files, $this->basePath, sourceOnly: true);
        $sources = $discovery->discover($graph, $exclude);
        $notices = [];
        if (! $sources['has_sources']) {
            $notices[] = new AnalysisNotice('A_ROUTE_SOURCE_UNAVAILABLE', 'route_source_unavailable', 'routes', 1, 'No readable HTTP source declarations are available. Supply route sources or an explicit RouteMap.');
        }
        foreach ($sources['notices'] as $notice) {
            $notices[] = new AnalysisNotice('A_ROUTE_SOURCE_UNRESOLVED', 'route_source_unresolved', $notice['path'], $notice['line'], $notice['reason']);
        }
        if ($sources['limited']) {
            $notices[] = new AnalysisNotice('A_ROUTE_SOURCE_LIMIT', 'route_source_limit', 'routes', 1, 'Source route discovery reached its safe budget. Recognized routes are retained.');
        }
        $entries = [];
        $methods = [];
        $evidence = [];
        foreach ($sources['routes'] as $route) {
            $handler = $route['handler'];
            // Closures are handled by the route-logic rule, not controller analysis.
            if (isset($handler['callback'])) {
                continue;
            }
            if (! is_string($handler['class'] ?? null) || ! is_string($handler['method'] ?? null)
                || ! is_array($route['verbs']) || $route['verbs'] === [] || ! is_string($route['uri'])) {
                $notices[] = new AnalysisNotice('A_ROUTE_SOURCE_UNRESOLVED', 'route_source_unresolved', $route['source']['path'], $route['source']['line'], 'Route handler, HTTP verbs or URI cannot be resolved from source.');

                continue;
            }
            $entry = new RouteEntry(
                verbs: $route['verbs'], uri: ltrim($route['uri'], '/'), name: $route['name'], domain: $route['domain'],
                constraints: $route['constraints'] ?? [], class: $handler['class'], method: $handler['method'],
                middleware: $route['middleware'] ?? [], excludedMiddleware: $route['excluded_middleware'] ?? [],
            );
            $entries[] = $entry;
            $key = strtolower($handler['class'].'::'.$handler['method']);
            $methods[$key] = array_values(array_unique([...($methods[$key] ?? []), ...$route['verbs']]));
            $evidence[$entry->identity()] = ['knowledge' => 'static', 'execution_proven' => false,
                'source' => $route['source'], 'certainty' => $route['certainty'], 'reasons' => $route['reasons']];
        }
        if (! $discovery->freshness()['fresh']) {
            $notices[] = new AnalysisNotice('A_ROUTE_SOURCE_CHANGED', 'route_source_changed', 'routes', 1, 'Source route inputs changed or discovery was limited. Retry without refreshing the task beginning.');
        }

        return new RouteMap($methods, entries: $entries, context: ['status' => 'unavailable', 'source_routes' => $evidence,
            'providers' => [], 'middleware' => [], 'middlewareGroups' => [], 'middlewareAliases' => [], 'packageVersions' => [], 'auth' => null,
            'unavailable' => 'Runtime framework configuration was not executed.'], notices: $notices);
    }
}
