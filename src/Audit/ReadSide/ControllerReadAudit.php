<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\AnalysisNotice;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditSuggestion;
use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkContextBuilder;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;
use Illuminate\Filesystem\Filesystem;

final readonly class ControllerReadAudit
{
    private const FORTIFY_CONTROLLERS = 'Laravel\\Fortify\\Http\\Controllers\\';

    public function __construct(private Filesystem $files, private string $basePath, private ?ProjectClassification $classification = null, private ?AnalysisFactsCache $facts = null) {}

    /**
     * Compatibility adapter for callers that only consume enforced findings.
     *
     * @param  array<int, Architecture|string>  $enabled
     * @param  list<string>|null  $focusPaths
     * @return list<AuditFinding>
     */
    public function check(ProjectGraphSnapshot $graph, array $enabled, ?array $focusPaths, ?RouteMap $routes): array
    {
        return $this->analyze($graph, $enabled, $focusPaths, $routes)->findings;
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @param  list<string>|null  $focusPaths
     */
    public function analyze(ProjectGraphSnapshot $graph, array $enabled, ?array $focusPaths, ?RouteMap $routes): ControllerAnalysisResult
    {
        AnalysisRuntime::stage('deep.controller-read');
        $controllers = $this->controllers($graph);
        if ($routes === null && $controllers === []) {
            return new ControllerAnalysisResult(status: ControllerAnalysisResult::NOT_RUN);
        }
        $routes ??= RouteMap::fresh($this->basePath);
        $focusPaths = $this->affectedControllers($graph, $focusPaths);

        return $this->analyzePartition($graph, $enabled, $routes, $controllers,
            array_values(array_filter($controllers, fn (int $ordinal): bool => $focusPaths === null || in_array($graph->symbols[$ordinal]->path, $focusPaths, true))), true, true);
    }

    /** @return list<int> Source order, including duplicate declarations. */
    public function controllers(ProjectGraphSnapshot $graph): array
    {
        $controllers = [];
        foreach ($graph->symbols as $ordinal => $symbol) {
            if ($symbol->kind === 'class' && ($this->classification?->kindMatches($symbol->path, 'controller', str_starts_with($symbol->path, 'app/Http/Controllers/')) ?? str_starts_with($symbol->path, 'app/Http/Controllers/'))) {
                $controllers[] = $ordinal;
            }
        }

        return $controllers;
    }

    /** @internal Each partition uses the full source symbol index and fresh route context.
     * @param  array<int, Architecture|string>  $enabled
     * @param  list<int>  $controllers
     * @param  list<int>  $selection
     */
    public function analyzePartition(ProjectGraphSnapshot $graph, array $enabled, RouteMap $routes, array $controllers, array $selection, bool $initial, bool $fortify): ControllerAnalysisResult
    {
        $controllerKeys = [];
        foreach ($controllers as $ordinal) {
            $symbol = $graph->symbols[$ordinal];
            $controllerKeys[$symbol->path.'\0'.strtolower($symbol->name)] = true;
        }
        $selected = array_fill_keys($selection, true);
        $findings = [];
        $suggestions = [];
        $notices = $initial ? $routes->notices : [];
        foreach ($initial ? ($routes->entries ?? []) : [] as $entry) {
            if ($entry->class === null || $entry->method === null) {
                continue;
            }
            $handler = $graph->symbol($entry->class);
            if ($handler !== null && isset($controllerKeys[$handler->path.'\0'.strtolower($handler->name)])) {
                continue;
            }
            // Fortify's registered handlers have a dedicated framework pass below.
            // That pass reports unavailable implementations and callback limits itself.
            if (str_starts_with($entry->class, self::FORTIFY_CONTROLLERS)) {
                continue;
            }
            $witness = $this->routeContext($entry, $entry->class, $entry->method, $entry->verbs);
            $witness = [...$witness, ...($routes->context['source_routes'][$entry->identity()] ?? [])];
            $notices[] = $this->notice('A_SOURCE_UNAVAILABLE', 'source_unavailable',
                $witness['source']['path'] ?? $handler->path ?? 'routes',
                $witness['source']['line'] ?? $handler->line ?? 1,
                'Route handler '.$entry->class.'::'.$entry->method.' has no controller source available to endpoint analysis.',
                [], $witness);
        }
        $incomplete = $notices !== [];
        $analysed = false;
        $sourceFiles = new SourceFileCache($this->facts);
        $providerContext = null;

        foreach ($graph->symbols as $ordinal => $symbol) {
            if (! isset($selected[$ordinal])) {
                continue;
            }

            if ($routes->unavailable !== null) {
                $notices[] = $this->notice(
                    'A_ROUTE_CONTEXT_UNAVAILABLE',
                    'routes_unavailable',
                    $symbol->path,
                    $symbol->line,
                    $symbol->name.' endpoints cannot be identified. '.$routes->unavailable,
                );
                $incomplete = true;

                continue;
            }

            $methodNames = $routes->methodsFor($symbol->name);
            if ($methodNames === []) {
                continue;
            }
            $analysed = true;
            unset($sources, $source, $resolved, $effects, $method);
            $endpointEffects = [];
            $sources = new SourceIndex($this->files, $this->basePath, $graph, $sourceFiles);
            $source = $sources->get($symbol->name);
            if ($source === null) {
                $notices[] = $this->notice(
                    'A_SOURCE_UNAVAILABLE',
                    $sources->unavailableCode($symbol->name),
                    $symbol->path,
                    $symbol->line,
                    'Controller source is unavailable or exceeds the read-analysis source budget.',
                );
                $incomplete = true;

                continue;
            }

            foreach ($methodNames as $name) {
                $resolved = $sources->method($symbol->name, $name);
                if ($resolved === null) {
                    $notices[] = $this->notice(
                        'A_METHOD_UNAVAILABLE',
                        'method_unavailable',
                        $symbol->path,
                        $symbol->line,
                        'Registered endpoint '.$symbol->name.'::'.$name.' has no resolvable method body.',
                    );
                    $incomplete = true;

                    continue;
                }
                $method = $resolved[1];
                if (! $method->isPublic() || ($name !== '__invoke' && str_starts_with($name, '__'))) {
                    continue;
                }

                $contexts = $routes->contextsFor($symbol->name, $name);
                if ($contexts === []) {
                    $notices[] = $this->notice(
                        'A_ROUTE_CONTEXT_UNAVAILABLE',
                        'routes_unavailable',
                        $symbol->path,
                        $method->getStartLine(),
                        'Registered endpoint '.$symbol->name.'::'.$name.' has no route verbs or identifiable context.',
                    );
                    $incomplete = true;

                    continue;
                }
                foreach ($contexts as $context) {
                    $routeEntry = $context['route'];
                    $routeContext = $this->routeContext($routeEntry, $symbol->name, $context['method'], $context['verbs']);
                    if ($routeEntry !== null && isset($routes->context['source_routes'][$routeEntry->identity()])) {
                        $routeContext = [...$routeContext, ...$routes->context['source_routes'][$routeEntry->identity()]];
                    }
                    if (isset($routes->context['runtime_routes'])) {
                        $routeContext = [...$routeContext, ...$routes->context['runtime_routes']];
                    }
                    // Registration witnesses differ; analysis depends on the handler
                    // and its effective middleware, not the URI/name/domain/verbs.
                    $analysisKey = serialize([$context['method'], $routeEntry?->middleware, $routeEntry?->excludedMiddleware]);
                    if (! isset($endpointEffects[$analysisKey])) {
                        if ($providerContext === null) {
                            $providerContext = new FrameworkContextBuilder(new SourceIndex($this->files, $this->basePath, $graph, $sourceFiles), $routes);
                            $providerContext->build();
                        }
                        $framework = $providerContext->forRoute($sources, $routeEntry)->build();
                        $endpointEffects[$analysisKey] = (new MethodAnalyzer($sources, $framework))->analyze($symbol->name, $context['method']);
                        unset($framework);
                    }
                    $effects = $endpointEffects[$analysisKey];
                    $verbs = $context['verbs'];
                    $reads = array_intersect($verbs, ['GET', 'HEAD']) !== [];
                    $writes = array_diff($verbs, ['GET', 'HEAD', 'OPTIONS']) !== [];
                    $prefix = $symbol->name.'::'.$context['method'].' ['.implode('|', $verbs).']';

                    foreach ($effects->observations as $observation) {
                        if ($observation['kind'] === 'unknown') {
                            $reason = $observation['reason'];
                            $notices[] = $this->notice(
                                in_array($reason, ['source_budget', 'source_unavailable'], true) ? 'A_SOURCE_UNAVAILABLE' : 'A_CALL_UNRESOLVED',
                                $reason,
                                $observation['path'],
                                $observation['line'],
                                $prefix.' cannot be fully classified. '.implode(' -> ', $observation['trace']).': '.$observation['detail'],
                                $observation['trace'],
                                $routeContext,
                            );
                            $incomplete = true;
                        }
                    }
                    if ($effects->truncated) {
                        $notices[] = $this->notice(
                            'A_OBSERVATION_LIMIT',
                            'observation_limit',
                            $symbol->path,
                            $method->getStartLine(),
                            $prefix.' reached the observation limit after '.$effects->totalObservations.' observations.',
                            [],
                            $routeContext,
                        );
                        $incomplete = true;
                    }

                    array_push($suggestions, ...ArchitectureAdvice::forEndpoint(
                        $enabled,
                        $effects->observations,
                        $effects->services,
                        $symbol->path,
                        $method->getStartLine(),
                        $routeContext,
                    ));

                    if ($writes && $effects->services !== [] && $this->enabled($enabled, Architecture::ThinControllers) && $this->enabled($enabled, Architecture::Actions)) {
                        foreach ($effects->services as $service) {
                            $findings[] = $this->finding(
                                'W_THIN_CONTROLLER_SERVICE_DEPENDENCY',
                                $symbol->path,
                                $service['path'] === $symbol->path ? $service['line'] : $method->getStartLine(),
                                $prefix.' uses '.$service['type'].' for an endpoint accepting write HTTP verbs; route the write use case through an Action. '.implode(' -> ', $service['trace']),
                            );
                        }
                    }
                    if ($reads && ! $writes && $effects->services !== [] && $this->enabled($enabled, Architecture::ThinControllers) && $this->enabled($enabled, Architecture::Actions) && $this->enabled($enabled, Architecture::QueryObjects)) {
                        foreach ($effects->services as $service) {
                            $findings[] = $this->finding(
                                'W_THIN_CONTROLLER_READ_SERVICE',
                                $symbol->path,
                                $method->getStartLine(),
                                $prefix.' uses a Service for a read; Query Objects are enabled, so put reusable read composition in a Query Object.',
                            );
                        }
                    }
                }
            }
        }

        foreach ($fortify ? $this->fortifyContexts($routes) : [] as $context) {
            $entry = $context['entry'];
            $analysed = true;
            $sources = new SourceIndex($this->files, $this->basePath, $graph, $sourceFiles);
            $framework = (new FrameworkContextBuilder($sources, $routes, $entry))->build();
            $effects = (new MethodAnalyzer($sources, $framework))->analyze($entry->class, $entry->method);
            $routeContext = $this->routeContext($entry, $entry->class, $entry->method, $entry->verbs);
            array_push($suggestions, ...ArchitectureAdvice::forEndpoint(
                $enabled,
                $effects->observations,
                $effects->services,
                $entry->class,
                1,
                $routeContext,
            ));
            foreach ($effects->observations as $observation) {
                if ($observation['kind'] !== 'unknown') {
                    continue;
                }
                $reason = $observation['reason'];
                $notices[] = $this->notice(
                    in_array($reason, ['source_budget', 'source_unavailable'], true) ? 'A_SOURCE_UNAVAILABLE' : 'A_CALL_UNRESOLVED',
                    $reason,
                    $observation['path'],
                    $observation['line'],
                    $entry->class.'::'.$entry->method.' cannot be fully classified. '.implode(' -> ', $observation['trace']).': '.$observation['detail'],
                    $observation['trace'],
                    $routeContext,
                );
                $incomplete = true;
            }
            if ($effects->truncated) {
                $notices[] = $this->notice(
                    'A_OBSERVATION_LIMIT',
                    'observation_limit',
                    $entry->class,
                    1,
                    $entry->class.'::'.$entry->method.' reached the observation limit after '.$effects->totalObservations.' observations.',
                    [],
                    $routeContext,
                );
                $incomplete = true;
            }
        }

        return new ControllerAnalysisResult(
            findings: $this->deduplicateFindings($findings),
            suggestions: $this->deduplicateSuggestions($suggestions),
            notices: $this->deduplicateNotices($notices),
            status: $incomplete ? ControllerAnalysisResult::INCOMPLETE : ($analysed ? ControllerAnalysisResult::COMPLETE : ControllerAnalysisResult::NOT_RUN),
        );
    }

    /** @param list<ControllerAnalysisResult> $parts */
    public function mergePartitions(array $parts): ControllerAnalysisResult
    {
        $findings = $suggestions = $notices = [];
        $status = ControllerAnalysisResult::NOT_RUN;
        foreach ($parts as $part) {
            array_push($findings, ...$part->findings);
            array_push($suggestions, ...$part->suggestions);
            array_push($notices, ...$part->notices);
            if ($part->status === ControllerAnalysisResult::INCOMPLETE) {
                $status = ControllerAnalysisResult::INCOMPLETE;
            } elseif ($part->status === ControllerAnalysisResult::COMPLETE && $status === ControllerAnalysisResult::NOT_RUN) {
                $status = ControllerAnalysisResult::COMPLETE;
            }
        }

        return new ControllerAnalysisResult($this->deduplicateFindings($findings), $this->deduplicateSuggestions($suggestions), $this->deduplicateNotices($notices), $status);
    }

    /**
     * @param  list<string>|null  $paths
     * @return list<string>|null
     */
    private function affectedControllers(ProjectGraphSnapshot $graph, ?array $paths): ?array
    {
        if ($paths === null) {
            return null;
        }
        foreach ($paths as $path) {
            if (! str_starts_with($path, 'app/')
                || preg_match('#^app/(?:Actions|Http/Middleware|Http/Resources|Policies|Providers)/#', $path) === 1
                || ! $this->files->exists($this->basePath.'/'.$path)) {
                return null;
            }
        }
        $reverse = [];
        foreach ($graph->edges as $edge) {
            $reverse[strtolower($edge->to)][] = strtolower($edge->from);
        }
        $seen = [];
        $queue = [];
        foreach ($graph->symbols as $symbol) {
            if (in_array($symbol->path, $paths, true)) {
                $queue[] = strtolower($symbol->name);
            }
        }
        while ($queue !== []) {
            $name = array_pop($queue);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            array_push($queue, ...($reverse[$name] ?? []));
        }
        foreach ($graph->symbols as $symbol) {
            if (isset($seen[strtolower($symbol->name)])) {
                $paths[] = $symbol->path;
            }
        }

        return array_values(array_unique($paths));
    }

    /** @return list<array{entry: RouteEntry}> */
    private function fortifyContexts(RouteMap $routes): array
    {
        $contexts = [];
        foreach ($routes->entries ?? [] as $entry) {
            if ($entry->class === null
                || $entry->method === null
                || ! str_starts_with($entry->class, self::FORTIFY_CONTROLLERS)) {
                continue;
            }
            $contexts[$entry->identity()] ??= ['entry' => $entry];
        }

        return array_values($contexts);
    }

    /**
     * @param  list<string>  $verbs
     * @return array<string, mixed>
     */
    private function routeContext(?RouteEntry $entry, string $class, string $method, array $verbs): array
    {
        return $entry?->context() ?? [
            'id' => 'method:'.strtolower(ltrim($class, '\\').'::'.$method),
            'uri' => null,
            'name' => null,
            'domain' => null,
            'verbs' => $verbs,
            'middleware' => [],
            'excluded_middleware' => [],
            'bindings' => [],
        ];
    }

    /**
     * @param  list<string>  $trace
     * @param  array<string, mixed>|null  $route
     */
    private function notice(string $code, string $reason, string $path, int $line, string $message, array $trace = [], ?array $route = null): AnalysisNotice
    {
        return new AnalysisNotice($code, $reason, $path, $line, $message, $trace, $route);
    }

    /** @param array<int, Architecture|string> $enabled */
    private function enabled(array $enabled, Architecture $architecture): bool
    {
        return in_array($architecture, $enabled, true) || in_array($architecture->value, $enabled, true);
    }

    private function finding(string $code, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding(str_starts_with($code, 'E_') ? 'error' : 'warn', 'thin-controller', $path, $line, $message, code: $code);
    }

    /**
     * @param  list<AuditFinding>  $findings
     * @return list<AuditFinding>
     */
    private function deduplicateFindings(array $findings): array
    {
        $unique = [];
        foreach ($findings as $finding) {
            $unique[json_encode([$finding->rule, $finding->path, $finding->line, $finding->code, $finding->message], JSON_THROW_ON_ERROR)] = $finding;
        }

        return array_values($unique);
    }

    /**
     * @param  list<AuditSuggestion>  $suggestions
     * @return list<AuditSuggestion>
     */
    private function deduplicateSuggestions(array $suggestions): array
    {
        $unique = [];
        foreach ($suggestions as $suggestion) {
            $unique[json_encode([$suggestion->path, $suggestion->line, $suggestion->architecture, $suggestion->trace, $suggestion->route['id'] ?? null], JSON_THROW_ON_ERROR)] = $suggestion;
        }

        return array_values($unique);
    }

    /**
     * @param  list<AnalysisNotice>  $notices
     * @return list<AnalysisNotice>
     */
    private function deduplicateNotices(array $notices): array
    {
        $unique = [];
        foreach ($notices as $notice) {
            $unique[json_encode([$notice->code, $notice->reason, $notice->path, $notice->line, $notice->trace, $notice->route['id'] ?? null], JSON_THROW_ON_ERROR)] = $notice;
        }

        return array_values($unique);
    }
}
