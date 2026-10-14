<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use Illuminate\Filesystem\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
        return $this->store->update($this->key($task), function (array $previous) use ($task): array {
            if ($previous !== []) {
                return $previous; // retries/resume cannot refresh the beginning
            }
            try {
                [$audit, $sources] = $this->stableAudit();
                $this->complete($audit);

                return ['task' => $task, 'repository' => $this->context->id, 'status' => 'started', 'ok' => false,
                    'fingerprint' => $this->context->fingerprint(), 'begin_sources' => $sources,
                    'begin_errors' => $this->errors($audit), 'begin_warnings' => $audit->warnings(),
                    'attempts' => 0, 'created_at' => gmdate('c')];
            } catch (Throwable $error) {
                return ['task' => $task, 'repository' => $this->context->id, 'status' => 'blocked', 'ok' => false,
                    'reason' => $error->getMessage(), 'attempts' => 0, 'created_at' => gmdate('c')];
            }
        });
    }

    /** @return array<string, mixed> */
    public function check(string $task): array
    {
        return $this->store->update($this->key($task), function (array $saved): array {
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
        $before = $this->sources();
        $config = $this->context->fingerprint();
        // Source hashes avoid treating same-stat edited files as reusable at a control boundary.
        $state = StandaloneAudit::state($this->context);
        $audit = (new ApplicationAudit(new Filesystem, $this->context->rootPath))->run(
            enabled: $state->enabled, changedOnly: false, customRules: $state->customRules,
            exclude: $state->exclude, useBaseline: false, scope: $state->auditScope,
            missingTestLevel: $state->missingTestLevel, cache: $state->graphCache,
            cacheConfiguration: [...$state->graphConfiguration(), $config, $before],
        );
        if ($before !== $this->sources() || $config !== $this->context->fingerprint()) {
            throw new RuntimeException('Inputs changed during audit. Retry without refreshing the task beginning.');
        }

        return [$audit, $before];
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
        $parts = [];
        $root = $this->context->rootPath;
        $iterator = new RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => ! $file->isLink() && ! in_array($file->getFilename(), ['vendor', 'node_modules', '.git', '.env'], true),
        ));
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile() || (! str_ends_with($file->getFilename(), '.php') && ! in_array($file->getFilename(), ['composer.json', 'composer.lock'], true))) {
                continue;
            }
            if (count($parts) >= 20000 || $file->getSize() > 8000000) {
                throw new RuntimeException('Task source fingerprint limit reached.');
            }
            $hash = hash_file('sha256', $file->getPathname());
            if ($hash === false) {
                throw new RuntimeException('Task source is unreadable.');
            }
            $parts[substr($file->getPathname(), strlen($root) + 1)] = $hash;
        }
        ksort($parts);

        return hash('sha256', serialize($parts));
    }
}
