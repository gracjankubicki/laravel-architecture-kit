<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GracjanKubicki\ArchitectureKit\Standalone\GraphRuntime;
use GracjanKubicki\ArchitectureKit\Standalone\Runner;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;

GraphRuntime::instrument(__FILE__, static function (string $stage, array $job): void {
    $home = null;
    foreach ($GLOBALS['argv'] as $i => $argument) {
        if ($argument === '--tool-home') {
            $home = $GLOBALS['argv'][$i + 1];
        }
    }
    if (! is_string($home)) {
        return;
    }
    $state = new StateStore($home);
    $state->update('native-test-events', static function (array $events) use ($stage, $job): array {
        $events[] = ['stage' => $stage, 'job_id' => $job['job_id'] ?? null, 'snapshot_id' => $job['snapshot_id'] ?? null,
            'pid' => getmypid(), 'wall_observed_at' => microtime(true), 'lease_pinned' => $job['lease_pinned'] ?? null, 'rejection' => $job['rejection'] ?? null];

        return array_slice($events, -1024);
    });
    do {
        $control = $state->read('native-test-control');
        $blocked = (($control['barrier'] ?? null) === $stage && (! isset($control['job_id']) || $control['job_id'] === ($job['job_id'] ?? null)))
            || (array_key_exists($stage, $control['barriers'] ?? []) && ($control['barriers'][$stage] === null || $control['barriers'][$stage] === ($job['job_id'] ?? null)));
        if ($blocked) {
            usleep(10000);
        }
    } while ($blocked);
});
exit(Runner::run($argv));
