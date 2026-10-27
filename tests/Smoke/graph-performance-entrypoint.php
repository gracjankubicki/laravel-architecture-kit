<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Standalone\GraphRuntime;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\Runner;

// Only explicit private tool homes receive measurement events. No test barriers or altered results.
$home = null;
foreach ($argv as $i => $argument) {
    if ($argument === '--tool-home') {
        $home = $argv[$i + 1] ?? null;
    }
}
$trace = is_string($home) && is_file($home.'/performance-provenance.json') ? $home.'/performance-events.jsonl' : null;
$parsedSources = 0;
$analysisStages = [];
$record = static function (string $stage, array $job = []) use ($trace, &$parsedSources, &$analysisStages): void {
    if ($stage === 'source.parse') {
        $parsedSources++;

        return;
    }
    if ($trace !== null) {
        file_put_contents($trace, json_encode(['stage' => $stage, 'pid' => getmypid(), 'ppid' => function_exists('posix_getppid') ? posix_getppid() : null,
            'monotonic_ns' => hrtime(true), 'utc' => gmdate('c'), 'job' => $job['job_id'] ?? null,
            'operation' => $job['operation'] ?? null, 'product' => $job['product']['kind'] ?? 'catalog',
            'memory_limit' => ini_get('memory_limit'), 'configuration_epoch' => $job['configuration_epoch'] ?? null,
            'watch_session' => $job['watch_session'] ?? null, 'observed_sequence' => $job['observed_sequence'] ?? null,
            'applied_sequence' => $job['applied_sequence'] ?? null, 'snapshot_id' => $job['snapshot_id'] ?? null,
            'execution_mode' => $job['execution_mode'] ?? null, 'admission' => $job['admission'] ?? null, 'event_kind' => $job['event_kind'] ?? null, 'path_count' => $job['path_count'] ?? null, 'source_bytes' => $job['source_bytes'] ?? null,
            'analysis_stages' => in_array($stage, ['build.after', 'process.exit'], true) ? $analysisStages : [],
            'parsed_sources' => $parsedSources, 'php_used' => memory_get_usage(), 'php_allocated' => memory_get_usage(true)], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }
};
$record('process.start');
register_shutdown_function(static fn () => $record('process.exit'));
GraphRuntime::instrument(__FILE__, static function (string $stage, array $job) use ($record, &$analysisStages): void {
    if ($stage === 'build.before' && ($context = RepositoryContext::current()) !== null) {
        AnalysisRuntime::activate($context->analysis()->withStageObserver(static function (string $name) use (&$analysisStages): void {
            $point = ['monotonic_ns' => hrtime(true), 'php_used' => memory_get_usage(), 'php_allocated' => memory_get_usage(true)];
            $analysisStages[$name] ??= ['count' => 0, 'first' => $point];
            $analysisStages[$name]['count']++;
            $analysisStages[$name]['last'] = $point;
        }));
    }
    $record($stage, $job);
});
exit(Runner::run($argv));
