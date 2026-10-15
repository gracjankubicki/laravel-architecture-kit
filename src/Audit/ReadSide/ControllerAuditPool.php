<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\AnalysisNotice;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditSuggestion;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectSymbol;
use GracjanKubicki\ArchitectureKit\Audit\WorkerAutoloader;
use GracjanKubicki\ArchitectureKit\Audit\WorkerOutputGate;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** Controllers are independent; route diagnostics and Fortify run exactly once. */
final class ControllerAuditPool
{
    /** @param array<int, Architecture|string> $enabled
     * @param  list<int>|null  $controllers
     */
    public static function collect(RepositoryContext $context, ControllerReadAudit $audit, ProjectGraphSnapshot $graph, array $enabled, RouteMap $routes, ?AnalysisFactsCache $facts, ?array $controllers = null): ?ControllerAnalysisResult
    {
        $autoload = WorkerAutoloader::path($context);
        if ($autoload === null || ! function_exists('proc_open')) {
            return null;
        }
        $controllers ??= $audit->controllers($graph);
        if ($controllers === []) {
            return null;
        }
        $jobs = array_chunk($controllers, max(1, (int) ceil(count($controllers) / 8)));
        $processes = $buffers = $outputs = $done = [];
        $gate = new WorkerOutputGate;
        $witnesses = [];
        try {
            // Endpoint lookup needs declarations only. Dependency edges are used only
            // to narrow a changed audit, which never takes this full-audit path.
            $common = ['root' => $context->rootPath, 'home' => $context->home, 'profile' => $context->profile,
                'execute' => $context->execute, 'runtime_routes' => $context->runtimeRoutes,
                'symbols' => array_map(static fn (ProjectSymbol $symbol): array => get_object_vars($symbol), $graph->symbols),
                'controllers' => $controllers,
                'enabled' => array_map(static fn (Architecture|string $value): string => $value instanceof Architecture ? $value->value : $value, $enabled),
                'routes' => ['methods' => $routes->methods, 'unavailable' => $routes->unavailable,
                    'entries' => $routes->entries === null ? null : array_map(static fn (RouteEntry $entry): array => $entry->toArray(), $routes->entries),
                    'context' => $routes->context, 'notices' => array_map(static fn (AnalysisNotice $notice): array => $notice->toArray(), $routes->notices)],
                'facts' => $facts?->export()];
            $prefix = substr(json_encode($common, JSON_THROW_ON_ERROR), 0, -1).',';
            unset($common);
            foreach ($jobs as $index => $job) {
                $suffix = substr(json_encode(['selection' => $job, 'initial' => $index === 0, 'fortify' => $index === count($jobs) - 1], JSON_THROW_ON_ERROR), 1);
                $limit = MemoryLimit::bytes();
                if (strlen($prefix) + strlen($suffix) > 8_000_000 || ($limit !== null && memory_get_usage(true) > $limit * 0.8)) {
                    return null;
                }
                $process = new Process([PHP_BINARY, '-d', 'pcov.enabled=0', '-d', 'memory_limit='.ini_get('memory_limit'), __DIR__.'/controller-audit-worker.php', $autoload], $context->rootPath);
                $process->setTimeout(60);
                // Share the immutable prefix. Retaining a full request per process
                // would consume the parent budget before any analysis starts.
                $process->setInput(self::stream($prefix, $suffix));
                $process->disableOutput();
                $buffers[$index] = '';
                $outputs[$index] = ['findings' => [], 'suggestions' => [], 'notices' => []];
                $process->start(static function (string $type, string $bytes) use (&$buffers, &$outputs, &$done, &$witnesses, $gate, $index, $facts): void {
                    if (! $gate->accepting || $type !== Process::OUT) {
                        return;
                    }
                    $buffers[$index] .= $bytes;
                    if (strlen($buffers[$index]) > 8_000_000) {
                        throw new RuntimeException('Controller worker output exceeds its budget.');
                    }
                    while (($end = strpos($buffers[$index], "\n")) !== false) {
                        $limit = MemoryLimit::bytes();
                        if ($limit !== null && memory_get_usage(true) + $end * 16 > $limit * 0.8) {
                            throw new RuntimeException('Controller worker output does not fit the parent memory budget.');
                        }
                        $data = json_decode(substr($buffers[$index], 0, $end), true, 128, JSON_THROW_ON_ERROR);
                        $buffers[$index] = substr($buffers[$index], $end + 1);
                        if (! is_array($data) || isset($done[$index])) {
                            throw new RuntimeException('Invalid controller worker protocol.');
                        }
                        if (isset($data['done'])) {
                            if (! in_array($data['done'], [ControllerAnalysisResult::COMPLETE, ControllerAnalysisResult::INCOMPLETE, ControllerAnalysisResult::NOT_RUN], true)) {
                                throw new RuntimeException('Invalid controller worker status.');
                            }
                            $done[$index] = $data['done'];
                            $facts?->merge($data['facts'] ?? []);
                        } elseif (isset($data['facts']) && is_array($data['facts']) && array_keys($data) === ['facts']) {
                            $facts?->merge($data['facts']);
                        } elseif (! isset($data['channel']) && isset($data['witness'])) {
                            if (! is_string($data['witness']) || ! is_array($data['route'] ?? null)
                                || ! hash_equals(hash('sha256', json_encode($data['route'], JSON_THROW_ON_ERROR)), $data['witness'])
                                || (! isset($witnesses[$data['witness']]) && count($witnesses) >= 10_000)) {
                                throw new RuntimeException('Invalid controller worker route witness.');
                            }
                            $witnesses[$data['witness']] ??= $data['route'];
                        } else {
                            $channel = $data['channel'] ?? null;
                            $item = $data['item'] ?? null;
                            if (! is_string($channel) || ! is_array($item)) {
                                throw new RuntimeException('Invalid controller worker result.');
                            }
                            if (($data['witness'] ?? null) !== null) {
                                if (! is_string($data['witness']) || ! isset($witnesses[$data['witness']]) || isset($item['route'])) {
                                    throw new RuntimeException('Unknown controller worker route witness.');
                                }
                                $item['route'] = $witnesses[$data['witness']];
                            }
                            // Serial results share the same registration array for all
                            // observations. Restore that sharing after JSON transport.
                            if (is_array($item['route'] ?? null)) {
                                $key = hash('sha256', json_encode($item['route'], JSON_THROW_ON_ERROR));
                                if (! isset($witnesses[$key]) && count($witnesses) < 10_000) {
                                    $witnesses[$key] = $item['route'];
                                }
                                $item['route'] = $witnesses[$key] ?? $item['route'];
                            }
                            $outputs[$index][$channel][] = match ($channel) {
                                'findings' => new AuditFinding(...$item),
                                'suggestions' => new AuditSuggestion(...$item),
                                'notices' => new AnalysisNotice(...$item),
                                default => throw new RuntimeException('Invalid controller worker channel.'),
                            };
                        }
                    }
                });
                $processes[] = $process;
            }
            unset($prefix, $suffix);
            do {
                $running = false;
                foreach ($processes as $process) {
                    $process->checkTimeout();
                    $running = $process->isRunning() || $running;
                }
                if ($running) {
                    usleep(1000);
                }
            } while ($running);
            $parts = [];
            foreach ($processes as $index => $process) {
                if (! $process->isSuccessful() || ! isset($done[$index]) || $buffers[$index] !== '') {
                    return null;
                }
                $parts[] = new ControllerAnalysisResult(...$outputs[$index], status: $done[$index]);
            }

            return $audit->mergePartitions($parts);
        } catch (Throwable) {
            return null;
        } finally {
            $gate->accepting = false;
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }
    }

    /** @return \Generator<int, string> */
    private static function stream(string $prefix, string $suffix): \Generator
    {
        for ($offset = 0, $length = strlen($prefix); $offset < $length; $offset += 65536) {
            yield substr($prefix, $offset, 65536);
        }
        yield $suffix;
    }
}
