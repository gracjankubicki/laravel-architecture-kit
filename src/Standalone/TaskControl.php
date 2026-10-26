<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\ControlPolicy;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

/** Task evidence belongs to a repository and explicit task, never to HEAD alone. */
final readonly class TaskControl
{
    public function __construct(private RepositoryContext $context, private ?ControlPolicy $policy = null, private bool $strict = false) {}

    private function store(): StateStore
    {
        return new StateStore($this->context->storage.'/tasks', $this->context->home);
    }

    /** @param callable(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function result(callable $operation): array
    {
        try {
            $policy = $this->policy ?? $this->context->controlPolicy();
            $result = $operation();
        } catch (Throwable $error) {
            $policy ??= $this->policy ?? ControlPolicy::Advisory;
            $result = ['status' => 'blocked', 'ok' => false, 'reason' => $error->getMessage()];
        }
        $result['analysis'] ??= ['mode' => 'basic', 'checks_complete' => false, 'deep_run' => false, 'status' => 'not_run'];
        $result['control'] = $policy->result(($result['ok'] ?? false) === true);

        return $result;
    }

    /** @return array<string, mixed> */
    public function begin(string $task): array
    {
        return $this->result(fn (): array => $this->beginTask($task));
    }

    /** @return array<string, mixed> */
    public function check(string $task): array
    {
        return $this->result(fn (): array => $this->checkTask($task));
    }

    /** @return array<string, mixed> */
    public function status(string $task): array
    {
        return $this->result(fn (): array => $this->taskStatus($task));
    }

    /** @return array<string, mixed> */
    public function skip(string $task, bool $confirm): array
    {
        return $this->result(fn (): array => $this->skipTask($task, $confirm));
    }

    /** @return array<string, mixed> */
    private function beginTask(string $task): array
    {
        $capture = bin2hex(random_bytes(16));
        $reserved = $this->store()->update($this->key($task), function (array $previous) use ($task, $capture): array {
            if ($previous !== []) {
                $this->validate($previous, $task);

                return $previous; // retries/resume cannot refresh the beginning
            }

            return ['task' => $task, 'repository' => $this->context->id, 'status' => 'blocked', 'ok' => false,
                'capture_id' => $capture, 'reason' => 'Task beginning is being captured. Do not edit until a valid beginning exists.',
                'attempts' => 0, 'revision' => 0, 'created_at' => gmdate('c')];
        });
        if (($reserved['capture_id'] ?? null) !== $capture) {
            // Revalidate outside the publication lock. Keeping the beginning
            // does not make an earlier PASS current after sources or policy drift.
            return $this->taskStatus($task);
        }
        // Reserve the one immutable beginning under a short lock, then analyse
        // outside it. A second caller cannot capture a later beginning.
        try {
            [$audit, $sources, $block] = $this->stableAudit();
            $this->complete($audit, $block);
            $result = ['status' => 'started', 'ok' => false,
                'fingerprint' => $this->context->fingerprint(), 'begin_sources' => $sources,
                'begin_errors' => $this->errors($audit), 'begin_warnings' => $audit->warnings(),
                'begin_analysis' => StandaloneAudit::analysisSummary($audit, $block),
                'analysis' => StandaloneAudit::analysisSummary($audit, $block),
                'reason' => $audit->incomplete() ? 'Beginning captured with partial analysis. Inspect audit --agent for unresolved code; only recognized errors can be compared.' : 'Task beginning captured.'];
        } catch (Throwable $error) {
            $result = ['status' => 'blocked', 'ok' => false, 'reason' => $error->getMessage()];
        }

        return $this->store()->update($this->key($task), static function (array $saved) use ($capture, $result): array {
            if (($saved['capture_id'] ?? null) !== $capture || ($saved['status'] ?? null) === 'skipped') {
                return $saved;
            }
            unset($saved['capture_id'], $saved['reason']);

            return [...$saved, ...$result, 'revision' => ($saved['revision'] ?? 0) + 1];
        });
    }

    /** @return array<string, mixed> */
    private function checkTask(string $task): array
    {
        $initial = $this->store()->read($this->key($task));
        $this->validate($initial, $task);
        if ($initial === []) {
            // A missing check is observational. It must not reserve a beginning
            // or overwrite one published concurrently after this read.
            return ['status' => 'blocked', 'ok' => false, 'reason' => 'Task beginning is missing. Begin a new task before editing code.'];
        }
        $result = (function (array $saved): array {
            if (($saved['status'] ?? '') === 'skipped') {
                return $saved;
            }
            try {
                if (! isset($saved['begin_errors']) || ($saved['fingerprint'] ?? '') !== $this->context->fingerprint()) {
                    throw new RuntimeException('Task beginning is unavailable or configuration/tool/execution policy changed. No reliable comparison.');
                }
                [$audit, $sources, $block] = $this->stableAudit();
                $this->complete($audit, $block);
                $current = $this->errors($audit);
                $new = 0;
                foreach ($current as $key => $count) {
                    $new += max(0, $count - ($saved['begin_errors'][$key] ?? 0));
                }
                $saved['ok'] = $new === 0 && (! $this->strict || $audit->architectureWarnings() === 0);
                $saved['status'] = $saved['ok'] ? 'pass' : 'fail';
                $saved['new_errors'] = $new;
                $saved['previous_errors_remaining'] = array_sum($current) - $new;
                $saved['warnings'] = $audit->warnings();
                $saved['checked_sources'] = $sources;
                $saved['analysis'] = StandaloneAudit::analysisSummary($audit, $block);
                $saved['reason'] = $saved['ok'] ? 'No new recognized errors; this does not prove passing application tests.' : ($new > 0 ? 'New architecture errors require correction. No authorship is inferred.' : 'Strict analysis requires correction of architecture warnings.');
                if ($audit->incomplete()) {
                    $saved['reason'] .= ' Analysis is partial. Inspect audit --agent for unresolved code; this is not a complete code check.';
                }
            } catch (Throwable $error) {
                $saved['status'] = 'blocked';
                $saved['ok'] = false;
                $saved['reason'] = $error->getMessage();
                $saved['analysis'] = ['mode' => 'basic', 'checks_complete' => false, 'deep_run' => false, 'status' => 'incomplete'];
            }
            $saved['attempts'] = ($saved['attempts'] ?? 0) + ($saved['ok'] ? 0 : 1);
            $saved['checked_at'] = gmdate('c');
            $saved['revision'] = ($saved['revision'] ?? 0) + 1;

            return $saved;
        })($initial);

        return $this->store()->update($this->key($task), static function (array $saved) use ($initial, $result): array {
            if ($saved === $initial) {
                return $result;
            }
            if (($saved['status'] ?? null) === 'skipped') {
                return $saved;
            }

            // Never publish findings against a state another check has replaced.
            // Preserve the original beginning and require a fresh comparison.
            return [...$saved, 'status' => 'blocked', 'ok' => false, 'analysis' => ['mode' => 'basic', 'checks_complete' => false, 'deep_run' => false, 'status' => 'incomplete'],
                'reason' => 'Task state changed during verification. Retry without refreshing its beginning.',
                'revision' => ($saved['revision'] ?? 0) + 1, 'attempts' => ($saved['attempts'] ?? 0) + 1, 'checked_at' => gmdate('c')];
        });
    }

    /** @return array<string, mixed> */
    private function taskStatus(string $task): array
    {
        $saved = $this->store()->read($this->key($task));
        $this->validate($saved, $task);
        try {
            if ($saved !== [] && ($saved['fingerprint'] ?? null) !== null && $saved['fingerprint'] !== $this->context->fingerprint()) {
                $saved['status'] = 'stale';
                $saved['ok'] = false;
                $saved['analysis']['checks_complete'] = false;
                $saved['reason'] = 'Rules or analysis identity changed. The original beginning is retained; comparison is unverified.';
            }
            if (($saved['status'] ?? '') === 'pass' && (($saved['checked_sources'] ?? null) !== $this->sources() || ($saved['fingerprint'] ?? null) !== $this->context->fingerprint())) {
                $saved['status'] = 'stale';
                $saved['ok'] = false;
                $saved['analysis']['checks_complete'] = false;
                $saved['reason'] = 'Sources or analysis identity changed after verification. Run a fresh check against the same beginning.';
            }

        } catch (Throwable $error) {
            $saved['status'] = 'blocked';
            $saved['ok'] = false;
            $saved['reason'] = $error->getMessage();
            $saved['analysis'] = ['mode' => 'basic', 'checks_complete' => false, 'deep_run' => false, 'status' => 'incomplete'];
        }

        return $saved === [] ? ['status' => 'missing', 'ok' => false] : $saved;
    }

    /** @return array<string, mixed> */
    private function skipTask(string $task, bool $confirm): array
    {
        if (! $confirm) {
            throw new RuntimeException('Skipping requires explicit confirmation for this task.');
        }

        return $this->store()->update($this->key($task), function (array $saved) use ($task): array {
            $this->validate($saved, $task);
            if ($saved === []) {
                throw new RuntimeException('Cannot skip an unidentified task.');
            }
            $saved['analysis'] = ['mode' => 'basic', 'checks_complete' => false, 'deep_run' => false, 'status' => 'not_run'];
            $saved['revision'] = ($saved['revision'] ?? 0) + 1;
            $saved['status'] = 'skipped';
            $saved['ok'] = false;
            $saved['reason'] = 'Explicitly skipped for this task. Unverified, not PASS.';

            return $saved;
        });
    }

    /** @return array{ApplicationAuditResult, string, bool} */
    private function stableAudit(): array
    {
        $config = $this->context->fingerprint();
        // Source hashes avoid treating same-stat edited files as reusable at a control boundary.
        $state = StandaloneAudit::state($this->context);
        $audit = (new ApplicationAudit(new Filesystem, $this->context->rootPath))->run(
            enabled: $state->enabled, changedOnly: false, customRules: $state->customRules,
            exclude: $state->exclude, useBaseline: false, scope: $state->auditScope,
            missingTestLevel: $state->missingTestLevel, cache: $state->graphCache,
            cacheConfiguration: [...$state->graphConfiguration(), $config], mode: AnalysisMode::Basic,
        );
        // The full audit already hashes source contents before analysis and after
        // the result is assembled. Repeating those scans here adds no evidence.
        if ($audit->sourceFingerprint === null) {
            throw new RuntimeException('Audit input freshness was not verified for this repository. No reliable comparison.');
        }

        return [$audit, $audit->sourceFingerprint, $state->blockIncompleteAnalysis];
    }

    private function complete(ApplicationAuditResult $audit, bool $block): void
    {
        if (! $audit->analysis()['checks_complete']) {
            throw new RuntimeException('Required Basic checks did not complete. No reliable comparison or PASS is available.');
        }
        if ($audit->hasAnalysisFailure()) {
            throw new RuntimeException('Required runtime route analysis failed. Inspect architecture-kit audit --agent; no reliable check is available.');
        }
        if ($block && StandaloneAudit::incomplete($audit)) {
            throw new RuntimeException('Required analysis is incomplete. Inspect architecture-kit audit --agent; no PASS is available.');
        }
    }

    /** @return array<string, int> */
    private function errors(ApplicationAuditResult $audit): array
    {
        $counts = [];
        foreach ($audit->findings as $finding) {
            if ($finding->severity === 'error') {
                // Do not persist source text/messages. Lines are deliberately not identity.
                $key = hash('sha256', serialize([$finding->rule, $finding->code, $finding->path, preg_replace('/\s+/', ' ', $finding->message)]));
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }

    /** JSON shape is part of the evidence, not merely valid JSON syntax.
     * @param  array<string, mixed>  $saved
     */
    private function validate(array $saved, string $task): void
    {
        if ($saved === []) {
            return;
        }
        if (($saved['task'] ?? null) !== $task || ($saved['repository'] ?? null) !== $this->context->id
            || ! in_array($saved['status'] ?? null, ['blocked', 'started', 'pass', 'fail', 'skipped', 'stale'], true)
            || ! is_bool($saved['ok'] ?? null) || (($saved['status'] === 'pass') !== $saved['ok'])
            || ! is_int($saved['attempts'] ?? null) || $saved['attempts'] < 0 || ! is_string($saved['created_at'] ?? null)
            || isset($saved['revision']) && (! is_int($saved['revision']) || $saved['revision'] < 0)) {
            throw new RuntimeException('Task state is corrupt. No reliable beginning or comparison is available.');
        }
        if (in_array($saved['status'], ['started', 'pass', 'fail'], true)) {
            if (! is_array($saved['begin_errors'] ?? null) || ! is_string($saved['begin_sources'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/D', $saved['begin_sources']) || ! is_string($saved['fingerprint'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/D', $saved['fingerprint']) || ($saved['begin_analysis']['mode'] ?? null) !== 'basic'
                || ($saved['begin_analysis']['checks_complete'] ?? null) !== true) {
                throw new RuntimeException('Task beginning is unavailable or incomplete. No reliable comparison.');
            }
            foreach ($saved['begin_errors'] as $key => $count) {
                if (! is_string($key) || ! is_int($count) || $count < 0) {
                    throw new RuntimeException('Task beginning error counters are corrupt.');
                }
            }
        }
        if ($saved['status'] === 'pass' && ((! is_string($saved['checked_sources'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $saved['checked_sources']))
            || ($saved['analysis']['mode'] ?? null) !== 'basic' || ($saved['analysis']['checks_complete'] ?? null) !== true)) {
            throw new RuntimeException('Task PASS evidence is unavailable or incomplete.');
        }
    }

    private function key(string $task): string
    {
        if ($task === '' || strlen($task) > 200 || preg_match('/[\x00-\x1F]/', $task)) {
            throw new RuntimeException('Provide a task identifier of 1..200 printable characters.');
        }

        return hash('sha256', $task);
    }

    private function sources(): string
    {
        return SourceFingerprint::current($this->context->rootPath, $this->context->settings);
    }
}
