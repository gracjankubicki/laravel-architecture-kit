<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Capture;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsOptions;
use GracjanKubicki\ArchitectureKit\Analysis\StreamingBasicCollector;
use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Standalone\Host;
use GracjanKubicki\ArchitectureKit\Standalone\Measurement\GraphStageMeasurement;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** Explicit experiment entry point. Not a map-prepare path and never a default backend. */
final class CaptureCommand
{
    /**
     * @param  array<array-key, mixed>  $argv
     * @param  array<array-key, mixed>  $options
     * @return array<array-key, mixed>
     */
    public static function run(string $root, string $home, ?string $profile, array $argv, array $options, ?int $entryStarted = null): array
    {
        $entryStarted ??= hrtime(true);
        $admission = $store = $measurement = $sampler = $budget = null;
        $lifecycle = new class
        {
            public bool $finishing = false;
        };
        $report = ['ok' => false, 'status' => 'incomplete', 'reason' => 'capture_pipeline_failed'];
        $observerSignal = false;
        $measurementResult = null;
        $output = $options['output'] ?? '';
        $armed = false;
        $oldAsync = false;
        $oldAlarm = $oldUsr = SIG_DFL;
        try {
            foreach (['admission-file', 'source-manifest', 'output'] as $key) {
                if (! isset($options[$key]) || ! is_string($options[$key]) || ! str_starts_with($options[$key], '/')) {
                    throw new RuntimeException('capture_admission_missing');
                }
            }
            if ($output === $root || str_starts_with($output, $root.'/') || $root === realpath($output)) {
                throw new RuntimeException('capture_output_inside_source');
            }
            for ($ancestor = $output; $ancestor !== '/'; $ancestor = dirname($ancestor)) {
                if (file_exists($ancestor.'/.git')) {
                    throw new RuntimeException('capture_output_inside_git');
                }
            }
            $policyPath = $options['admission-file'];
            if (! is_file($policyPath) || is_link($policyPath) || filesize($policyPath) > CaptureStore::MANIFEST_BYTES) {
                throw new RuntimeException('capture_admission_file');
            }
            $policy = json_decode(file_get_contents($policyPath), true, 512, JSON_THROW_ON_ERROR);
            $admission = new CaptureAdmission($policy, $argv, $options['source-manifest'], $output, ($options['once'] ?? false) === true);
            $budget = new OutputBudget($output, $policy['max_output_bytes']);
            if ($policy['max_output_bytes'] < OutputBudget::minimumBytes(strlen(TypedValue::json($policy)))) {
                throw new RuntimeException('capture_output_minimum');
            }
            CaptureStore::writePrivate($output.'/once.json', TypedValue::json(['instance' => bin2hex(random_bytes(16)), 'pid' => getmypid()]));
            CaptureStore::writePrivate($output.'/admission.json', TypedValue::json($policy));
            $oldAsync = pcntl_async_signals(true);
            $oldAlarm = pcntl_signal_get_handler(SIGALRM);
            $oldUsr = pcntl_signal_get_handler(SIGUSR1);
            pcntl_signal(SIGALRM, static function () use ($lifecycle): void {
                if (! $lifecycle->finishing) {
                    throw new RuntimeException('capture_wall_limit');
                }
            });
            pcntl_signal(SIGUSR1, static function () use (&$observerSignal): void {
                // A notification, never an asynchronous exception during cleanup.
                $observerSignal = true;
            });
            pcntl_alarm($policy['max_wall_seconds']);
            $armed = true;
            $measurement = new GraphStageMeasurement($output.'/stages.ndjson');
            GraphStageMeasurement::activate($measurement);
            CaptureStore::writePrivate($output.'/rss.ndjson', '');
            $sampler = new Process([PHP_BINARY, dirname(__DIR__).'/Measurement/sample.php', (string) getmypid(), $output.'/rss.ndjson', $output.'/observer.stop', $output.'/admission.json'], timeout: null);
            $sampler->disableOutput();
            $sampler->start();
            $readyDeadline = hrtime(true) + 2000000000;
            do {
                clearstatcache(true, $output.'/rss.ndjson');
                if (filesize($output.'/rss.ndjson') > 0) {
                    break;
                }
                $admission->check(0);
                if ($observerSignal || ! $sampler->isRunning() || hrtime(true) > $readyDeadline) {
                    throw new RuntimeException('capture_observer_unavailable');
                }
                usleep(10000);
            } while (true);
            $measurement->measure('capture.work', function () use ($root, $home, $profile, $options, $output, $admission, $measurement, $sampler, &$observerSignal, &$store): void {
                $session = $measurement->measure('session.config', function () use ($root, $home, $profile) {
                    $context = new RepositoryContext($root, $home, $profile);
                    RepositoryContext::activate($context);
                    Host::create($home);

                    return AnalysisRuntime::session();
                });
                $plan = json_decode(file_get_contents($options['source-manifest']), true, 512, JSON_THROW_ON_ERROR);
                if (($plan['root'] ?? null) !== $root) {
                    throw new RuntimeException('capture_plan_root');
                }
                $measurement->measure('source.pre_scan', static fn () => CaptureSourcePlan::assertCurrent($plan));
                $factsOptions = new BasicFactsOptions(impact: true, catalog: true, preserveOccurrences: true, roles: RoleClassifier::forProject(new Filesystem, $root));
                $identity = ['source_plan_sha256' => TypedValue::digest($plan), 'session' => $session->fingerprint(),
                    'parser' => $session->engine->engine, 'php' => $session->phpVersion, 'options' => $factsOptions->fingerprint()];
                $checkpoint = static function (int $reserved = 0) use ($admission, $sampler, &$observerSignal): void {
                    $admission->check($reserved);
                    if ($observerSignal || ! $sampler->isRunning()) {
                        throw new RuntimeException('capture_observer_failed');
                    }
                };
                $store = new CaptureStore($output.'/dataset', $plan, $identity, $factsOptions->toArray(), $checkpoint,
                    fn () => $measurement->measure('source.post_scan', static fn () => CaptureSourcePlan::assertCurrent($plan)));
                (new StreamingBasicCollector($session, $factsOptions, $root))->collect($plan,
                    fn (array $record) => $measurement->measure('sink.append', static fn () => $store->append($record)), $checkpoint);
                $measurement->measure('sink.commit', static fn () => $store->commit());
            });
            $report = ['ok' => true, 'status' => 'complete', 'schema' => CaptureStore::SCHEMA,
                'manifest_sha256' => hash_file('sha256', $output.'/dataset/manifest.json')];
            $lifecycle->finishing = true;
        } catch (Throwable $error) {
            $lifecycle->finishing = true;
            $reason = preg_match('/^capture_[a-z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'capture_pipeline_failed';
            try {
                $store?->abort($reason);
            } catch (Throwable) {
                // The final report still preserves the original failure reason.
            }
            $report = ['ok' => false, 'status' => 'incomplete', 'reason' => $reason];
        } finally {
            $lifecycle->finishing = true;
            $oldMask = [];
            if ($armed) {
                pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1, SIGALRM], $oldMask);
                pcntl_alarm(0);
                // Ignore and discard notifications until the sole sender is reaped.
                pcntl_signal(SIGUSR1, SIG_IGN);
                pcntl_signal(SIGALRM, SIG_IGN);
            }
            try {
                if ($sampler !== null) {
                    try {
                        CaptureStore::writePrivate($output.'/observer.stop', 'stop', finalization: true);
                        $deadline = hrtime(true) + 2000000000;
                        while ($sampler->isRunning() && hrtime(true) < $deadline) {
                            usleep(10000);
                        }
                        if ($sampler->isRunning()) {
                            $sampler->stop(0.2);
                            if ($report['ok']) {
                                $report = ['ok' => false, 'status' => 'incomplete', 'reason' => 'capture_observer_stop_timeout'];
                            }
                        }
                    } catch (Throwable) {
                        $sampler->stop(0.2);
                        if ($report['ok']) {
                            $report = ['ok' => false, 'status' => 'incomplete', 'reason' => 'capture_observer_stop_failed'];
                        }
                    }
                    if (! $sampler->isSuccessful() && $report['ok']) {
                        $report = ['ok' => false, 'status' => 'incomplete', 'reason' => 'capture_observer_failed'];
                    }
                    $failurePath = $output.'/observer.failure.json';
                    if (is_file($failurePath) && ! is_link($failurePath) && filesize($failurePath) <= 1024) {
                        try {
                            $failure = json_decode(file_get_contents($failurePath), true, flags: JSON_THROW_ON_ERROR);
                            if (in_array($report['reason'] ?? null, ['capture_observer_unavailable', 'capture_observer_failed'], true)) {
                                $report['reason'] = $failure['reason'];
                            }
                            $report['observer_failure'] = $failure;
                        } catch (Throwable) {
                            $report['observer_failure'] = ['reason' => 'capture_observer_failure_record_unreadable'];
                        }
                    }
                }
                $measurementResult = $measurement?->close();
                if (($measurementResult['status'] ?? null) === 'incomplete' && $report['ok']) {
                    $report = ['ok' => false, 'status' => 'incomplete', 'reason' => $measurementResult['reason']];
                }
                if (! $report['ok'] && $store !== null) {
                    try {
                        $store->abort($report['reason']);
                    } catch (Throwable) {
                    }
                }
            } finally {
                if ($armed) {
                    // SIG_IGN discarded pending notifications; no owned sender remains.
                    pcntl_signal(SIGUSR1, $oldUsr);
                    pcntl_signal(SIGALRM, $oldAlarm);
                    pcntl_sigprocmask(SIG_SETMASK, $oldMask);
                    pcntl_async_signals($oldAsync);
                }
            }
        }
        $ready = hrtime(true);
        $report['public_entry_monotonic_ns'] = $entryStarted;
        $report['result_ready_monotonic_ns'] = $ready;
        $report['observed_total_wall_ns'] = $ready - $entryStarted;
        $report['complete_wall_ns'] = $report['ok'] ? $ready - $entryStarted : null;
        if ($measurementResult !== null) {
            $report['measurement'] = $measurementResult;
        }
        if ($admission !== null) {
            $report['output_budget'] = ['max_bytes' => $admission->policy['max_output_bytes'],
                'finalization_reserve_bytes' => OutputBudget::FINALIZATION_RESERVE,
                'minimum_start_bytes' => OutputBudget::minimumBytes(strlen(TypedValue::json($admission->policy)))];
            try {
                if ($budget === null) {
                    throw new RuntimeException('capture_output_budget_unavailable');
                }
                CaptureStore::writePrivate($output.'/report.json', TypedValue::json($report), finalization: true);
            } catch (Throwable) {
                $report['ok'] = false;
                $report['status'] = 'incomplete';
                $report['complete_wall_ns'] = null;
                if (is_file($output.'/report.json') && ! is_link($output.'/report.json')) {
                    unlink($output.'/report.json');
                }
                $report['report_status'] = 'not_written';
                $report['report_reason'] = 'capture_final_report_unwritable_within_budget';
                if (is_file($output.'/dataset/complete')) {
                    unlink($output.'/dataset/complete');
                }
            }
        }
        $budget?->close();

        return $report;
    }
}
