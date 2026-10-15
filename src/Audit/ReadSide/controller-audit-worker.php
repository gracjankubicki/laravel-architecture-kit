<?php

declare(strict_types=1);

// The parent supplies only the tool's autoloader and data from the current audit.
require $_SERVER['argv'][1];

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\AnalysisNotice;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectSymbol;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\AnalysisFactsCache;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\ControllerReadAudit;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use Illuminate\Filesystem\Filesystem;

try {
    $input = stream_get_contents(STDIN, 8_000_001);
    if ($input === false || strlen($input) > 8_000_000) {
        throw new RuntimeException('Controller worker input exceeds its budget.');
    }
    $limit = MemoryLimit::bytes();
    if ($limit !== null && memory_get_usage(true) + strlen($input) * 16 > $limit * 0.8) {
        throw new RuntimeException('Controller worker input does not fit its memory budget.');
    }
    $request = json_decode($input, true, 128, JSON_THROW_ON_ERROR);
    unset($input);
    $context = new RepositoryContext($request['root'], $request['home'], $request['profile'], $request['execute'], runtimeRoutes: $request['runtime_routes']);
    RepositoryContext::activate($context);
    if (! is_array($request['symbols']) || ! array_is_list($request['symbols']) || count($request['symbols']) > 20_000) {
        throw new RuntimeException('Invalid controller worker symbol index.');
    }
    $symbols = [];
    foreach ($request['symbols'] as $symbol) {
        $symbols[] = new ProjectSymbol(...$symbol);
    }
    $graph = new ProjectGraphSnapshot($symbols, []);
    foreach (['controllers', 'selection'] as $field) {
        if (! is_array($request[$field]) || ! array_is_list($request[$field])) {
            throw new RuntimeException('Invalid controller worker selection.');
        }
        foreach ($request[$field] as $ordinal) {
            if (! is_int($ordinal) || ! isset($symbols[$ordinal]) || $symbols[$ordinal]->kind !== 'class'
                || ($field === 'selection' && ! in_array($ordinal, $request['controllers'], true))) {
                throw new RuntimeException('Invalid controller worker selection.');
            }
        }
    }
    unset($request['symbols'], $symbols, $symbol);
    $routes = new RouteMap($request['routes']['methods'], $request['routes']['unavailable'],
        $request['routes']['entries'] === null ? null : array_map(RouteEntry::fromArray(...), $request['routes']['entries']),
        $request['routes']['context'], array_map(static fn (array $notice) => new AnalysisNotice(...$notice), $request['routes']['notices']));
    $enabled = array_map(static fn (string $value): Architecture|string => Architecture::tryFrom($value) ?? $value, $request['enabled']);
    $facts = $request['facts'] === null ? null : new AnalysisFactsCache('', '', null);
    $facts?->merge($request['facts'] ?? []);
    unset($request['routes'], $request['facts']);
    $result = (new ControllerReadAudit(new Filesystem, $context->rootPath, facts: $facts))
        ->analyzePartition($graph, $enabled, $routes, $request['controllers'], $request['selection'], $request['initial'], $request['fortify']);
    $witnesses = [];
    foreach (['findings', 'suggestions', 'notices'] as $channel) {
        foreach ($result->$channel as $item) {
            $data = get_object_vars($item);
            $witness = null;
            if (is_array($data['route'] ?? null)) {
                $routeJson = json_encode($data['route'], JSON_THROW_ON_ERROR);
                $key = hash('sha256', $routeJson);
                if (! isset($witnesses[$key]) && count($witnesses) < 10_000) {
                    echo json_encode(['witness' => $key, 'route' => $data['route']], JSON_THROW_ON_ERROR)."\n";
                    $witnesses[$key] = true;
                }
                if (isset($witnesses[$key])) {
                    $witness = $key;
                    unset($data['route']);
                }
            }
            echo json_encode(['channel' => $channel, 'item' => $data, 'witness' => $witness], JSON_THROW_ON_ERROR)."\n";
        }
    }
    foreach ($facts?->chunks() ?? [] as $chunk) {
        echo json_encode(['facts' => $chunk], JSON_THROW_ON_ERROR)."\n";
    }
    echo json_encode(['done' => $result->status], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Controller worker failed: '.$error->getMessage()."\n");
    exit(2);
}
