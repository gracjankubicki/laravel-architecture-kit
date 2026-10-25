<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Analysis\ControlPolicy;
use RuntimeException;

final class SessionBinding
{
    /** @return array<string, mixed> */
    public static function bind(RepositoryContext $context, mixed $client, mixed $session, string $task): array
    {
        if (! in_array($client, ['codex', 'claude'], true) || ! is_string($session) || $session === '' || strlen($session) > 200) {
            throw new RuntimeException('Provide a supported client and a session identifier.');
        }
        $tasks = new TaskControl($context);
        if ($tasks->status($task)['status'] === 'missing') {
            throw new RuntimeException('Begin the task before binding its session.');
        }

        return (new StateStore($context->storage.'/sessions', $context->home))->update(hash('sha256', $client.':'.$session), static function (array $old) use ($task, $client, $tasks, $context): array {
            $previous = $old['task'] ?? null;
            if (is_string($previous) && $previous !== $task && $context->controlPolicy() === ControlPolicy::Enforce && ! in_array($tasks->status($previous)['status'], ['pass', 'skipped'], true)) {
                throw new RuntimeException('Previous task has not passed or been explicitly skipped. Its beginning cannot be replaced.');
            }

            return [...$old, 'task' => $task, 'client' => $client];
        });
    }
}
