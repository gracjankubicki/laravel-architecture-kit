<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache;

use GracjanKubicki\ArchitectureKit\Standalone\GraphRuntime;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use RuntimeException;

/** Publication and clear share this lock; a pre-clear writer cannot republish. */
final readonly class GraphEpoch
{
    private string $directory;

    public string $token;

    public function __construct(RepositoryContext $context)
    {
        $this->directory = $context->storage;
        new StateStore($this->directory, $context->home);
        $this->token = GraphRuntime::epoch() ?? $this->current();
    }

    public function current(): string
    {
        $value = (new StateStore($this->directory))->read('graph-epoch');

        return is_string($value['token'] ?? null) ? $value['token'] : 'initial';
    }

    public function unchanged(): bool
    {
        return $this->token === $this->current();
    }

    /** @param callable(): mixed $publish */
    public function publish(callable $publish): mixed
    {
        return $this->locked(LOCK_SH, function () use ($publish): mixed {
            if (! $this->unchanged()) {
                throw new RuntimeException('cache_cleared');
            }

            return $publish();
        });
    }

    /** @param callable(): int $clear */
    public function clear(callable $clear): int
    {
        return $this->locked(LOCK_EX, function () use ($clear): int {
            (new StateStore($this->directory))->update('graph-epoch', static fn () => ['token' => bin2hex(random_bytes(16))]);

            return $clear();
        });
    }

    /** @param callable(): mixed $operation */
    private function locked(int $mode, callable $operation): mixed
    {
        $path = $this->directory.'/graph-publication.lock';
        StateStore::assertBoundary($path, $this->directory);
        if (is_link($path)) {
            throw new RuntimeException('epoch_lock_boundary');
        }
        $lock = fopen($path, 'c');
        if ($lock === false || ! flock($lock, $mode)) {
            throw new RuntimeException('epoch_lock');
        }
        chmod($path, 0600);
        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
