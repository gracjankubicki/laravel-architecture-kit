<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GracjanKubicki\ArchitectureKit\Standalone\GraphRuntime;
use GracjanKubicki\ArchitectureKit\Standalone\ObservationPolicy;
use GracjanKubicki\ArchitectureKit\Standalone\Runner;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchClient;

ObservationPolicy::instrument(static fn ($binary, $boundary): WatchClient => new WatchClient($binary, $boundary,
    ['ARCHITECTURE_KIT_WATCH_TEST_MODE' => '1']), static function (WatchClient $client): void {
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
        $control = $state->read('test-control');
        if (($control['watch_loss'] ?? false) === true) {
            $state->update('test-control', static function (array $control): array {
                unset($control['watch_loss']);

                return $control;
            });
            $client->syntheticLoss();
        }
        if (($control['watch_close'] ?? false) === true) {
            $state->update('test-control', static function (array $control): array {
                unset($control['watch_close']);

                return $control;
            });
            $client->close();
        }
    });

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
        $events[] = ['stage' => $stage, 'job' => $job['job_id'], 'pid' => getmypid(), 'time' => microtime(true), 'product' => $job['product']['kind'] ?? 'catalog', 'admission' => $job['admission'] ?? null,
            'index_digest' => $index === null ? null : hash('sha256', serialize($index->exportState())),
            'diagnostics_digest' => $index === null ? null : hash('sha256', json_encode($index->diagnostics, JSON_THROW_ON_ERROR)),
            'diagnostics_by_code' => $index === null ? null : array_count_values(array_column($index->diagnostics, 'code'))];

        return $events;
    });
    $control = $state->read('test-control');
    $blocked = static function (array $control) use ($stage, $job): bool {
        return (($control['barrier'] ?? null) === $stage && (! isset($control['job']) || $control['job'] === $job['job_id']))
            || (array_key_exists($stage, $control['barriers'] ?? [])
                && ($control['barriers'][$stage] === null || $control['barriers'][$stage] === $job['job_id']));
    };
    while ($blocked($control)) {
        usleep(10000);
        $control = $state->read('test-control');
    }
});
exit(Runner::run($argv));
