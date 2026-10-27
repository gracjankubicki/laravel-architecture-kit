<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

/** An observation confirms a processed prefix, never that the OS has flushed notifications. */
final class ConfirmationState
{
    /** @param array<string, mixed> $job
     * @param array<string, mixed> $observation
     * @return array<string, mixed> */
    public static function make(array $job, array $observation, string $freshness, ?string $reason = null): array
    {
        return ['runtime_semantics' => 2, 'freshness' => $freshness,
            'generation' => $job['snapshot_id'] ?? null, 'snapshot_id' => $job['snapshot_id'] ?? null,
            'product_key' => $job['product_key'], 'configuration_epoch' => $observation['configuration_epoch'],
            'watch_session' => $observation['watch_session'], 'observed_sequence' => $observation['observed_sequence'],
            'applied_sequence' => $job['applied_sequence'] ?? '0', 'last_full_scan_at' => $job['last_full_scan_at'] ?? null,
            'confirmed_at' => $job['confirmed_at'] ?? null, 'basis' => $job['basis'] ?? 'none',
            'scope' => $job['scope'] ?? [], 'pending_changes' => $freshness === 'stale' || ($observation['pending_changes'] ?? false),
            'watch_health' => $observation['watch_health'], 'reason' => $reason ?? $observation['reason']];
    }

    public static function legacyFresh(string $freshness): ?bool
    {
        return match ($freshness) {
            'current' => true, 'stale' => false, default => null,
        };
    }
}
