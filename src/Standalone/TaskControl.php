<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

/** Task evidence belongs to a repository and explicit task, never to HEAD alone. */
final readonly class TaskControl
{
    private StateStore $store;

    public function __construct(private RepositoryContext $context)
    {
        $this->store = new StateStore($context->storage.'/tasks', $context->home);
    }

    /** @return array<string, mixed> */
    public function begin(string $task): array
    {
        $capture = bin2hex(random_bytes(16));
        $reserved = $this->store->update($this->key($task), function (array $previous) use ($task, $capture): array {
            if ($previous !== []) {
                return $previous; // retries/resume cannot refresh the beginning
            }

            return ['task' => $task, 'repository' => $this->context->id, 'status' => 'blocked', 'ok' => false,
                'capture_id' => $capture, 'reason' => 'Task beginning is being captured. Do not edit until a valid beginning exists.',
                'attempts' => 0, 'created_at' => gmdate('c')];
        });
        if (($reserved['capture_id'] ?? null) !== $capture) {
            return $reserved;
        }
        // Reserve the one immutable beginning under a short lock, then analyse
        // outside it. A second caller cannot capture a later beginning.
        try {
            [$audit, $sources] = $this->stableAudit();
            $this->complete($audit);
            $result = ['status' => 'started', 'ok' => false,
                'fingerprint' => $this->context->fingerprint(), 'begin_sources' => $sources,
                'begin_errors' => $this->errors($audit), 'begin_warnings' => $audit->warnings()];
        } catch (Throwable $error) {
            $result = ['status' => 'blocked', 'ok' => false, 'reason' => $error->getMessage()];
        }

        return $this->store->update($this->key($task), static function (array $saved) use ($capture, $result): array {
            if (($saved['capture_id'] ?? null) !== $capture || ($saved['status'] ?? null) === 'skipped') {
                return $saved;
            }
            unset($saved['capture_id'], $saved['reason']);

            return [...$saved, ...$result];
        });
    }

    /** @return array<string, mixed> */
    public function check(string $task): array
    {
        $initial = $this->store->read($this->key($task));
        $result = (function (array $saved): array {
            if ($saved === []) {
                return ['status' => 'blocked', 'ok' => false, 'reason' => 'Task beginning is missing. Begin a new task before editing code.'];
            }
            if (($saved['status'] ?? '') === 'skipped') {
                return $saved;
            }
            try {
                if (! isset($saved['begin_errors']) || ($saved['fingerprint'] ?? '') !== $this->context->fingerprint()) {
                    throw new RuntimeException('Task beginning is unavailable or configuration/tool/execution policy changed. No reliable comparison.');
                }
                [$audit, $sources] = $this->stableAudit();
                $this->complete($audit);
                $current = $this->errors($audit);
                $new = 0;
                foreach ($current as $key => $count) {
                    $new += max(0, $count - ($saved['begin_errors'][$key] ?? 0));
                }
                $saved['status'] = $new === 0 ? 'pass' : 'fail';
                $saved['ok'] = $new === 0;
                $saved['new_errors'] = $new;
                $saved['previous_errors_remaining'] = array_sum($current) - $new;
                $saved['warnings'] = $audit->warnings();
                $saved['checked_sources'] = $sources;
                $saved['reason'] = $new === 0 ? 'No new recognized errors; this does not prove passing application tests.' : 'New architecture errors require correction. No authorship is inferred.';
            } catch (Throwable $error) {
                $saved['status'] = 'blocked';
                $saved['ok'] = false;
                $saved['reason'] = $error->getMessage();
            }
            $saved['attempts'] = ($saved['attempts'] ?? 0) + ($saved['ok'] ? 0 : 1);
            $saved['checked_at'] = gmdate('c');

            return $saved;
        })($initial);

        return $this->store->update($this->key($task), static function (array $saved) use ($initial, $result): array {
            if ($saved === $initial) {
                return $result;
            }
            if (($saved['status'] ?? null) === 'skipped') {
                return $saved;
            }

            // Never publish findings against a state another check has replaced.
            // Preserve the original beginning and require a fresh comparison.
            return [...$saved, 'status' => 'blocked', 'ok' => false,
                'reason' => 'Task state changed during verification. Retry without refreshing its beginning.',
                'attempts' => ($saved['attempts'] ?? 0) + 1, 'checked_at' => gmdate('c')];
        });
    }

    /** @return array<string, mixed> */
    public function status(string $task): array
    {
        $saved = $this->store->read($this->key($task));
        try {
            if (($saved['status'] ?? '') === 'pass' && (($saved['checked_sources'] ?? null) !== $this->sources() || ($saved['fingerprint'] ?? null) !== $this->context->fingerprint())) {
                $saved['status'] = 'stale';
                $saved['ok'] = false;
            }

        } catch (Throwable $error) {
            $saved['status'] = 'blocked';
            $saved['ok'] = false;
            $saved['reason'] = $error->getMessage();
        }

        return $saved === [] ? ['status' => 'missing', 'ok' => false] : $saved;
    }

    /** @return array<string, mixed> */
    public function skip(string $task, bool $confirm): array
    {
        if (! $confirm) {
            throw new RuntimeException('Skipping requires explicit confirmation for this task.');
        }

        return $this->store->update($this->key($task), static function (array $saved): array {
            if ($saved === []) {
                throw new RuntimeException('Cannot skip an unidentified task.');
            }
            $saved['status'] = 'skipped';
            $saved['ok'] = false;
            $saved['reason'] = 'Explicitly skipped for this task. Unverified, not PASS.';

            return $saved;
        });
    }

    /** @return array{ApplicationAuditResult, string} */
    private function stableAudit(): array
    {
        $config = $this->context->fingerprint();
        // Source hashes avoid treating same-stat edited files as reusable at a control boundary.
        $state = StandaloneAudit::state($this->context);
        $audit = (new ApplicationAudit(new Filesystem, $this->context->rootPath))->run(
            enabled: $state->enabled, changedOnly: false, customRules: $state->customRules,
            exclude: $state->exclude, useBaseline: false, scope: $state->auditScope,
            missingTestLevel: $state->missingTestLevel, cache: $state->graphCache,
            cacheConfiguration: [...$state->graphConfiguration(), $config],
        );
        // The full audit already hashes source contents before analysis and after
        // the result is assembled. Repeating those scans here adds no evidence.
        if ($audit->sourceFingerprint === null) {
            throw new RuntimeException('Audit input freshness was not verified for this repository. No reliable comparison.');
        }

        return [$audit, $audit->sourceFingerprint];
    }

    private function complete(ApplicationAuditResult $audit): void
    {
        if (StandaloneAudit::incomplete($audit)) {
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
