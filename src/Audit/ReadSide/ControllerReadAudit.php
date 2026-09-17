<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkContextBuilder;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use Illuminate\Filesystem\Filesystem;

final readonly class ControllerReadAudit
{
    public function __construct(private Filesystem $files, private string $basePath) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @param  list<string>|null  $focusPaths
     * @return list<AuditFinding>
     */
    public function check(ProjectGraphSnapshot $graph, array $enabled, ?array $focusPaths, ?RouteMap $routes): array
    {
        if (! in_array(Architecture::ThinControllers, $enabled, true) || ! in_array(Architecture::Actions, $enabled, true)) {
            return [];
        }
        $routes ??= RouteMap::fresh($this->basePath);
        $focusPaths = $this->affectedControllers($graph, $focusPaths);
        $findings = [];
        foreach ($graph->symbols as $symbol) {
            if ($symbol->kind !== 'class' || ! str_starts_with($symbol->path, 'app/Http/Controllers/') || ($focusPaths !== null && ! in_array($symbol->path, $focusPaths, true))) {
                continue;
            }
            if ($routes->unavailable !== null) {
                // Without routes we cannot identify endpoints, including inherited
                // ones. Report this once instead of guessing from local methods.
                $findings[] = $this->finding('W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', $symbol->path, $symbol->line,
                    $symbol->name.' cannot be classified completely. '.$routes->unavailable);

                continue;
            }
            $methodNames = [];
            foreach (array_keys($routes->methods) as $callback) {
                if (str_starts_with($callback, strtolower($symbol->name).'::')) {
                    $methodNames[] = substr($callback, strlen($symbol->name) + 2);
                }
            }
            foreach ($routes->entries ?? [] as $entry) {
                if ($entry->class !== null && $entry->method !== null && strcasecmp($entry->class, $symbol->name) === 0) {
                    $methodNames[] = $entry->method;
                }
            }
            // An available route collection is authoritative: public helpers and
            // unregistered base controllers are not additional HTTP endpoints.
            if ($methodNames === []) {
                continue;
            }
            // Release source ASTs between controllers. Each query has its own budget.
            $sources = new SourceIndex($this->files, $this->basePath, $graph);
            $source = $sources->get($symbol->name);
            if ($source === null) {
                $findings[] = $this->finding('W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', $symbol->path, $symbol->line, 'Controller source is unavailable or exceeds the read-analysis source budget.');

                continue;
            }
            foreach (array_unique($methodNames) as $name) {
                $resolved = $sources->method($symbol->name, $name);
                if ($resolved === null) {
                    $findings[] = $this->finding('W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', $symbol->path, $symbol->line, 'Registered endpoint '.$symbol->name.'::'.$name.' has no resolvable method body.');

                    continue;
                }
                $method = $resolved[1];
                if (! $method->isPublic() || ($name !== '__invoke' && str_starts_with($name, '__'))) {
                    continue;
                }
                $routeEntries = $routes->entriesFor($symbol->name, $name);
                $routeEntries = $routeEntries !== [] ? $routeEntries : [null];
                foreach ($routeEntries as $routeEntry) {
                    $framework = (new FrameworkContextBuilder($sources, $routes, $routeEntry))->build();
                    $effects = (new MethodAnalyzer($sources, $framework))->analyze($symbol->name, $name);
                    $verbs = $routeEntry !== null ? $routeEntry->verbs : $routes->verbs($symbol->name, $name);
                    $reads = array_intersect($verbs, ['GET', 'HEAD']) !== [];
                    $writes = array_diff($verbs, ['GET', 'HEAD', 'OPTIONS']) !== [];
                    $prefix = $symbol->name.'::'.$name.' ['.implode('|', $verbs).']';
                    $known = array_values(array_filter($effects->observations, fn (array $e): bool => $e['kind'] !== 'unknown'));
                    $unknown = array_values(array_filter($effects->observations, fn (array $e): bool => $e['kind'] === 'unknown'));
                    if ($reads && $known !== []) {
                        $effect = $known[0];
                        $findings[] = $this->finding('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $symbol->path, $method->getStartLine(),
                            $prefix.' can perform '.$effect['kind'].': '.implode(' -> ', $effect['trace']).' -> '.$effect['detail'].' at '.$effect['path'].':'.$effect['line'].($unknown !== [] ? ' Other calls could not be fully analysed.' : ''));

                        continue;
                    }
                    if ($writes) {
                        foreach ($effects->services as $service) {
                            $findings[] = $this->finding('W_THIN_CONTROLLER_SERVICE_DEPENDENCY', $symbol->path,
                                $service['path'] === $symbol->path ? $service['line'] : $method->getStartLine(),
                                $prefix.' uses '.$service['type'].' for an endpoint accepting write HTTP verbs; route the write use case through an Action.');
                        }
                    }
                    if (! $reads && $verbs !== []) {
                        continue;
                    }
                    if ($verbs === [] || $unknown !== []) {
                        $detail = $verbs === [] ? ($routes->unavailable ?? 'No registered route was found for this controller method.')
                            : implode(' -> ', $unknown[0]['trace']).': '.$unknown[0]['detail'].' at '.$unknown[0]['path'].':'.$unknown[0]['line'];
                        $findings[] = $this->finding('W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', $symbol->path, $method->getStartLine(), $prefix.' cannot be classified completely. '.$detail);
                    } elseif ($effects->services !== [] && in_array(Architecture::QueryObjects, $enabled, true)) {
                        $findings[] = $this->finding('W_THIN_CONTROLLER_READ_SERVICE', $symbol->path, $method->getStartLine(), $prefix.' uses a Service for a read; Query Objects are enabled, so put reusable read composition in a Query Object.');
                    }
                }
            }
        }

        foreach ($routes->entries ?? [] as $entry) {
            if ($entry->class === null || $entry->method === null || ! str_starts_with($entry->class, 'Laravel\\Fortify\\Http\\Controllers\\') || array_intersect($entry->verbs, ['GET', 'HEAD']) === []) {
                continue;
            }
            $sources = new SourceIndex($this->files, $this->basePath, $graph);
            $framework = (new FrameworkContextBuilder($sources, $routes, $entry))->build();
            $effects = (new MethodAnalyzer($sources, $framework))->analyze($entry->class, $entry->method);
            $known = array_values(array_filter($effects->observations, fn (array $effect): bool => $effect['kind'] !== 'unknown'));
            $unknown = array_values(array_filter($effects->observations, fn (array $effect): bool => $effect['kind'] === 'unknown'));
            $prefix = $entry->class.'::'.$entry->method.' ['.implode('|', $entry->verbs).']';
            if ($known !== []) {
                $effect = $known[0];
                $findings[] = $this->finding(
                    'E_THIN_CONTROLLER_READ_SIDE_EFFECT',
                    $effect['path'],
                    $effect['line'],
                    $prefix.' can perform '.$effect['kind'].': '.implode(' -> ', $effect['trace']).' -> '.$effect['detail'].' at '.$effect['path'].':'.$effect['line'].($unknown !== [] ? ' Other calls could not be fully analysed.' : ''),
                );
            } elseif ($unknown !== []) {
                $effect = $unknown[0];
                if ($this->files->isFile($this->basePath.'/'.$effect['path'])) {
                    $findings[] = $this->finding(
                        'W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE',
                        $effect['path'],
                        $effect['line'],
                        $prefix.' cannot be classified completely. '.implode(' -> ', $effect['trace']).': '.$effect['detail'].' at '.$effect['path'].':'.$effect['line'],
                    );
                }
            }
        }

        return $findings;
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
            // Route registrations/config may change verbs without changing a PHP
            // controller. A removed source may no longer have a graph symbol.
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

    private function finding(string $code, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding(str_starts_with($code, 'E_') ? 'error' : 'warn', 'thin-controller', $path, $line, $message, code: $code);
    }
}
