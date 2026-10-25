<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use RuntimeException;

/** Task-scoped source-analysis consent. This is not authentication of a human. */
final readonly class AnalysisConsent
{
    public function __construct(private RepositoryContext $context) {}

    /** The caller records an explicitly obtained grant; no runtime/custom permission is granted.
     * @return array<string, mixed>
     */
    public function grantDeep(string $task): array
    {
        return $this->store()->update($this->key($task), function (array $old) use ($task): array {
            if ($old !== []) {
                $this->validate($old, $task);

                return $old;
            }

            return ['repository' => $this->context->id, 'task' => $task, 'mode' => 'deep', 'granted_at' => gmdate('c')];
        });
    }

    public function hasDeep(string $task): bool
    {
        $record = $this->store()->read($this->key($task));
        if ($record === []) {
            return false;
        }
        $this->validate($record, $task);

        return true;
    }

    public function requireDeep(string $task): void
    {
        if (! $this->hasDeep($task)) {
            throw new RuntimeException('Deep analysis requires prior human consent for this repository and task.');
        }
    }

    /** @param array<string, mixed> $record */
    private function validate(array $record, string $task): void
    {
        if (($record['repository'] ?? null) !== $this->context->id || ($record['task'] ?? null) !== $task || ($record['mode'] ?? null) !== 'deep'
            || ! is_string($record['granted_at'] ?? null)) {
            throw new RuntimeException('Analysis consent state is corrupt or belongs to another repository/task.');
        }
    }

    private function key(string $task): string
    {
        if ($task === '' || strlen($task) > 200 || preg_match('/[\x00-\x1F]/', $task)) {
            throw new RuntimeException('Provide a task identifier of 1..200 printable characters.');
        }

        return hash('sha256', $this->context->id.':'.$task);
    }

    private function store(): StateStore
    {
        return new StateStore($this->context->storage.'/analysis-consent', $this->context->home);
    }
}
