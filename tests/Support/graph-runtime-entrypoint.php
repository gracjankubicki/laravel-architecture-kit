<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GracjanKubicki\ArchitectureKit\Standalone\GraphRuntime;
use GracjanKubicki\ArchitectureKit\Standalone\Runner;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;

GraphRuntime::instrument(__FILE__, static function (string $stage, array $job): void {
    $home = null;
    foreach ($GLOBALS['argv'] as $i => $arg) {
        if ($arg === '--tool-home') {
            $home = $GLOBALS['argv'][$i + 1];
        }
    }
    if (! is_string($home)) {
        return;
    }
    $state = new StateStore($home);
    $source = GraphRuntime::resident();
    $index = $source['index'] ?? null;
    $state->update('test-events', static function (array $events) use ($stage, $job, $index): array {
        $events[] = ['stage' => $stage, 'job' => $job['job_id'], 'pid' => getmypid(), 'time' => microtime(true), 'product' => $job['product']['kind'] ?? 'catalog',
            'index_digest' => $index === null ? null : hash('sha256', serialize($index->exportState())),
            'diagnostics_digest' => $index === null ? null : hash('sha256', json_encode($index->diagnostics, JSON_THROW_ON_ERROR)),
            'diagnostics_by_code' => $index === null ? null : array_count_values(array_column($index->diagnostics, 'code'))];

        return $events;
    });
    $control = $state->read('test-control');
    $barrier = $control['barrier'] ?? null;
    if ($barrier === $stage && (! isset($control['job']) || $control['job'] === $job['job_id'])) {
        while (($state->read('test-control')['barrier'] ?? null) === $stage) {
            usleep(10000);
        }
    }
});
exit(Runner::run($argv));
