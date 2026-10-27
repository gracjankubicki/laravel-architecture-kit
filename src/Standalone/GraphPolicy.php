<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use InvalidArgumentException;

/** Explicit ceilings when no reliable OS pressure signal is available. Never raises PHP's limit. */
final readonly class GraphPolicy
{
    public string $representation;

    public int $residentBytes;

    public int $transitionBytes;

    public int $queuePaths;

    public int $queueBytes;

    public float $debounceSeconds;

    public float $maxWindowSeconds;

    public int $postingBudget;

    /** @param array<string, mixed> $configuration */
    public function __construct(array $configuration = [])
    {
        $representation = $configuration['representation'] ?? 'php';
        if (! is_string($representation) || ! in_array($representation, ['php', 'rust'], true)) {
            throw new InvalidArgumentException('graph_runtime.representation must be php or rust.');
        }
        $this->representation = $representation;
        $limit = MemoryLimit::bytes() ?? 0;
        $this->residentBytes = self::integer($configuration, 'resident_budget_bytes', $limit, 1, PHP_INT_MAX);
        $this->transitionBytes = self::integer($configuration, 'transition_budget_bytes', $limit, 1, PHP_INT_MAX);
        $this->queuePaths = self::integer($configuration, 'queue_paths', 64, 1, 64);
        $this->queueBytes = self::integer($configuration, 'queue_bytes', 1048576, 1, 1048576);
        $this->debounceSeconds = self::integer($configuration, 'debounce_ms', 50, 1, 5000) / 1000;
        $this->maxWindowSeconds = self::integer($configuration, 'max_window_ms', 250, 1, 5000) / 1000;
        $this->postingBudget = self::integer($configuration, 'posting_budget', 32768, 0, PHP_INT_MAX);
        if ($this->maxWindowSeconds < $this->debounceSeconds) {
            throw new InvalidArgumentException('graph_runtime.max_window_ms must be at least debounce_ms.');
        }
    }

    /** @param array<string, mixed> $configuration */
    private static function integer(array $configuration, string $key, int $default, int $minimum, int $maximum): int
    {
        $value = $configuration[$key] ?? $default;
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('graph_runtime.'.$key.' requires an integer within '.$minimum.'..'.$maximum.'. Configure explicit budgets when PHP has no finite memory_limit.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return ['representation' => $this->representation, 'resident_budget_bytes' => $this->residentBytes, 'transition_budget_bytes' => $this->transitionBytes,
            'pressure_mode' => 'configured_ceiling', 'memory_limit' => ini_get('memory_limit'), 'queue_paths' => $this->queuePaths,
            'queue_bytes' => $this->queueBytes, 'posting_budget' => $this->postingBudget, 'overlay_depth' => 0,
            'debounce_ms' => $this->debounceSeconds * 1000, 'max_window_ms' => $this->maxWindowSeconds * 1000,
            'reconcile_minimum_seconds' => 30, 'reconcile_scan_multiplier' => 20, 'idle_seconds' => GraphProtocol::IDLE_SECONDS];
    }
}
