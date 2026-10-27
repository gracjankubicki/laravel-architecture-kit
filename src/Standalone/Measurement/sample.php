<?php

// Private capture observer; reads only the owned tree, never records process arguments.
declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureStore;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\OutputBudget;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use GracjanKubicki\ArchitectureKit\Standalone\Measurement\ProcessSamples;

/** @var list<string> $argv */
[$script, $root, $output, $stop, $policyPath] = $argv;
$policy = json_decode(file_get_contents($policyPath), true, flags: JSON_THROW_ON_ERROR);
$budget = new OutputBudget(dirname($output), $policy['max_output_bytes']);
$rootIdentity = ProcessSamples::identity((int) $root);
$started = hrtime(true);
$stream = fopen($output, 'ab');
$known = [];
$prior = null;
$exit = 0;
try {
    while (! file_exists($stop)) {
        $start = hrtime(true);
        if ($rootIdentity === null || ProcessSamples::identity((int) $root) !== $rootIdentity) {
            throw new RuntimeException('capture_observer_identity');
        }
        $sample = ProcessSamples::observe((int) $root, $known, getmypid());
        $known = $sample['known'];
        $violations = [];
        if ($sample['admission_process_count'] > $policy['process_limits']['max_processes']) {
            $violations[] = 'max_processes';
        }
        if ($sample['admission_rss_bytes'] !== null && $sample['admission_rss_bytes'] > $policy['process_limits']['max_tree_rss_bytes']) {
            $violations[] = 'max_tree_rss_bytes';
        }
        $free = disk_free_space(dirname($output));
        if ($free === false || $free < $policy['min_free_disk_bytes']) {
            $violations[] = 'disk_limit';
        }
        if ((hrtime(true) - $started) / 1e9 > $policy['max_wall_seconds']) {
            $violations[] = 'wall_limit';
        }
        if ($violations !== []) {
            throw new RuntimeException('capture_observer_limit');
        }
        unset($sample['known']);
        $usage = getrusage();
        $sample['observer_cpu_user_us'] = $usage['ru_utime.tv_sec'] * 1000000 + $usage['ru_utime.tv_usec'];
        $sample['observer_cpu_system_us'] = $usage['ru_stime.tv_sec'] * 1000000 + $usage['ru_stime.tv_usec'];
        $sample['actual_interval_ns'] = $prior === null ? null : $start - $prior;
        $sample['gap'] = $prior !== null && $start - $prior > 100000000;
        $prior = $start;
        CaptureStore::writeStream($stream, TypedValue::json($sample)."\n");
        usleep(max(0, 50000 - (int) ((hrtime(true) - $start) / 1000)));
    }
} catch (Throwable $error) {
    $reason = preg_match('/^capture_[a-z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'capture_observer_failed';
    try {
        CaptureStore::writePrivate(dirname($output).'/observer.failure.json', TypedValue::json([
            'reason' => $reason, 'violations' => $violations ?? [], 'measurement_status' => 'incomplete',
            'monotonic_ns' => hrtime(true)]), finalization: true);
    } catch (Throwable) {
        // Parent also observes the nonzero exit; never exceeds the shared ceiling.
    }
    if ($rootIdentity !== null && ProcessSamples::identity((int) $root) === $rootIdentity) {
        posix_kill((int) $root, SIGUSR1);
    }
    $exit = 3;
} finally {
    fclose($stream);
    $budget->close();
}
exit($exit);
