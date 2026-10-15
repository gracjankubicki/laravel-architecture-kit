<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\AnalysisFactsCache;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** Independent local checks share no application execution or mutable task state. */
final class FileAuditPool
{
    /** @param array<string, string> $paths
     * @param  list<Architecture|string>  $enabled
     * @return array<string, AuditedFile>|null Null requires the ordinary serial path.
     */
    public static function collect(RepositoryContext $context, array $paths, array $enabled, AuditScope $scope, ?AnalysisFactsCache $facts): ?array
    {
        $autoload = WorkerAutoloader::path($context);
        if ($autoload === null || ! function_exists('proc_open')) {
            return null;
        }
        $jobs = array_fill(0, min(8, max(1, (int) ceil(count($paths) / 200))), []);
        $ordinal = 0;
        foreach ($paths as $path => $absolute) {
            $jobs[$ordinal++ % count($jobs)][] = $path;
        }
        $processes = [];
        $received = [];
        $done = [];
        $buffers = [];
        $gate = new WorkerOutputGate;
        try {
            foreach ($jobs as $index => $job) {
                $request = ['root' => $context->rootPath, 'home' => $context->home, 'profile' => $context->profile,
                    'execute' => $context->execute, 'runtime_routes' => $context->runtimeRoutes,
                    'paths' => $job, 'scope' => $scope->directories,
                    'enabled' => array_map(static fn (Architecture|string $value): string => $value instanceof Architecture ? $value->value : $value, $enabled),
                    'facts' => $facts?->export()];
                // Workers produce audit data, never a test coverage report. Disable
                // PCOV only for this process, without changing the user's php.ini.
                $process = new Process([PHP_BINARY, '-d', 'pcov.enabled=0', '-d', 'memory_limit='.ini_get('memory_limit'), __DIR__.'/file-audit-worker.php', $autoload], $context->rootPath);
                $process->setTimeout(60);
                $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
                $process->disableOutput();
                $buffers[$index] = '';
                $process->start(static function (string $type, string $bytes) use (&$buffers, &$received, &$done, $gate, $index, $job, $facts): void {
                    if (! $gate->accepting || $type !== Process::OUT) {
                        return;
                    }
                    $buffers[$index] .= $bytes;
                    if (strlen($buffers[$index]) > 8_000_000) {
                        throw new RuntimeException('Audit worker output exceeds its budget.');
                    }
                    while (($end = strpos($buffers[$index], "\n")) !== false) {
                        $limit = MemoryLimit::bytes();
                        if ($limit !== null && memory_get_usage(true) + $end * 16 > $limit * 0.8) {
                            throw new RuntimeException('Audit worker contribution does not fit the parent memory budget.');
                        }
                        $data = json_decode(substr($buffers[$index], 0, $end), true, 128, JSON_THROW_ON_ERROR);
                        $buffers[$index] = substr($buffers[$index], $end + 1);
                        if (! is_array($data) || isset($done[$index])) {
                            throw new RuntimeException('Invalid audit worker protocol.');
                        }
                        if (isset($data['done'])) {
                            if ($data['done'] !== count($job)) {
                                throw new RuntimeException('Audit worker did not finish its full batch.');
                            }
                            $facts?->merge($data['facts'] ?? []);
                            $done[$index] = true;
                        } elseif (isset($data['facts']) && is_array($data['facts']) && array_keys($data) === ['facts']) {
                            $facts?->merge($data['facts']);
                        } else {
                            $path = $data['path'] ?? null;
                            if (! is_string($path) || ! in_array($path, $job, true) || isset($received[$path])) {
                                throw new RuntimeException('Unexpected audit worker source.');
                            }
                            $received[$path] = AuditedFile::fromArray($path, $data['result'] ?? []);
                        }
                    }
                });
                $processes[] = $process;
            }
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
            foreach ($processes as $index => $process) {
                if (! $process->isSuccessful() || ! isset($done[$index]) || $buffers[$index] !== '') {
                    return null;
                }
            }

            return count($received) === count($paths) ? $received : null;
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
}
