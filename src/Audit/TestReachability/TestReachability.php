<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\TestReachability;

use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkContextBuilder;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceIndex;
use Illuminate\Filesystem\Filesystem;

final readonly class TestReachability
{
    public function __construct(private Filesystem $files, private string $basePath) {}

    public function needsRoutes(ProjectGraphSnapshot $graph): bool
    {
        $contexts = [];
        foreach ($graph->testInvocations as $call) {
            if ($call->kind !== 'http') {
                continue;
            }
            if ($call->context === '@pest') {
                return true;
            }
            $context = $call->context ?? '';
            $key = $context.'::'.($call->dispatch ?? '');
            if (! array_key_exists($key, $contexts)) {
                $sources = new SourceIndex($this->files, $this->basePath, $graph);
                $contexts[$key] = $sources->isA($context, 'Illuminate\\Foundation\\Testing\\TestCase')
                    && ($call->dispatch === null || $sources->method($context, $call->dispatch) === null);
            }
            if ($contexts[$key]) {
                return true;
            }
        }

        return false;
    }

    public function analyze(ProjectGraphSnapshot $graph, ?RouteMap $routes): TestReachabilityResult
    {
        $result = new TestReachabilityResult;
        $http = new HttpTestResolver($routes ?? new RouteMap);
        $memo = [];
        $memoOrigins = [];
        $contexts = [];
        $overrides = [];
        $factoryNamespace = 'Database\\Factories\\';
        $configurations = array_values(array_filter($graph->testInvocations, fn (TestInvocation $call): bool => $call->kind === 'factory-config'));
        if ($configurations !== []) {
            $factoryNamespace = count($configurations) === 1 ? $configurations[0]->uri : null;
        }
        $configurationPaths = ['composer.json', ...array_column($configurations, 'path')];
        $appNamespace = 'App\\';
        $composerPath = $this->basePath.'/composer.json';
        if ($this->files->isFile($composerPath)) {
            $composer = json_decode($this->files->get($composerPath), true);
            foreach ($composer['autoload']['psr-4'] ?? [] as $namespace => $paths) {
                if (in_array('app/', (array) $paths, true) || in_array('app', (array) $paths, true)) {
                    $appNamespace = $namespace;
                    break;
                }
            }
        }
        foreach ($graph->testInvocations as $call) {
            if ($call->kind === 'factory-config') {
                continue;
            }
            $sources = new SourceIndex($this->files, $this->basePath, $graph);
            if ($call->kind === 'http' && $call->context !== '@pest') {
                $context = $call->context ?? '';
                $contexts[$context] ??= $sources->isA($context, 'Illuminate\\Foundation\\Testing\\TestCase');
                if (! $contexts[$context]) {
                    if ($sources->unavailableReason($context) !== null) {
                        $result->incomplete($call->path, $call->line, $sources->unavailableReason($context));
                    }

                    continue;
                }
            }
            if ($call->kind === 'http' && $call->context !== '@pest' && $call->context !== null && $call->dispatch !== null && ($overrides[$call->context.'::'.$call->dispatch] ??= $sources->method($call->context, $call->dispatch) !== null)) {
                $result->incomplete($call->path, $call->line, 'Project override of HTTP dispatch method '.$call->dispatch.'.');

                continue;
            }
            $factories = new FactoryResolver($sources, $appNamespace, $factoryNamespace);
            if ($call->kind === 'factory') {
                if ($call->model === null) {
                    $result->incomplete($call->path, $call->line, $call->reason ?? 'Unknown model factory.');
                } else {
                    $key = 'factory:'.$call->model;
                    if (! isset($memo[$key])) {
                        $memo[$key] = $factories->resolve($call->model, $call->path, $call->line);
                        $memoOrigins[$key] = [...$sources->paths(), ...$configurationPaths];
                    }
                    $resolved = $memo[$key];
                    $result->symbols += $resolved->symbols;
                    $result->provenance += $resolved->provenance;
                    foreach ($resolved->diagnostics as $diagnostic) {
                        $result->incomplete($call->path, $call->line, substr($diagnostic->message, strlen('Test relationship analysis is incomplete: ')), $memoOrigins[$key]);
                    }
                }

                continue;
            }
            $entry = $http->resolve($call);
            if (is_string($entry)) {
                $result->incomplete($call->path, $call->line, $entry);

                continue;
            }
            $key = $entry->class.'::'.$entry->method.':'.hash('sha256', json_encode($entry->toArray(), JSON_THROW_ON_ERROR));
            if (! isset($memo[$key])) {
                $framework = (new FrameworkContextBuilder($sources, $routes, $entry))->build();
                $memo[$key] = (new MethodReachability($sources, $factories, $framework))->analyze($entry->class, $entry->method, $call->path, $call->line);
                $memoOrigins[$key] = [...$sources->paths(), ...$configurationPaths];
                if ($sources->get($entry->class) === null && ! str_starts_with($entry->class, 'Laravel\\Fortify\\Http\\Controllers\\')) {
                    $memo[$key]->incomplete($call->path, $call->line, $sources->unavailableReason($entry->class) ?? 'Handler source unavailable in scope: '.$key);
                }
            }
            $result->merge($memo[$key], [$call->path, ...$memoOrigins[$key]]);
        }

        return $result;
    }
}
