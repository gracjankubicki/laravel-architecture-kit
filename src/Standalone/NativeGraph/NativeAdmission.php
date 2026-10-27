<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Standalone\GraphAdmissionRefused;
use GracjanKubicki\ArchitectureKit\Standalone\GraphProcessMemory;
use GracjanKubicki\ArchitectureKit\Standalone\GraphResources;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;

/** Global grant includes the entire worker tree and Rust owned capacity, without counting a PID twice. */
final class NativeAdmission
{
    /** Startup/parser/IPC allocator headroom is a conservative reservation, not a measured RSS peak. */
    public const HEADROOM = 100663296;

    private StateStore $state;

    private mixed $lock = null;

    private bool $released = false;

    public readonly int $resident;

    public readonly int $transition;

    /** @var array<string, mixed> */
    public readonly array $report;

    public function __construct(private readonly RepositoryContext $context, private readonly string $token, bool $transition, ?int $requested = null, int $headroom = self::HEADROOM, ?int $replaces = null)
    {
        $this->state = new StateStore($context->home);
        $lockName = 'native-grant-'.hash('sha256', $token).'.lock';
        $lockPath = $context->home.'/'.$lockName;
        StateStore::assertBoundary($lockPath, $context->home);
        if (is_link($lockPath)) {
            throw new \RuntimeException('native_grant_boundary');
        }
        $this->lock = fopen($lockPath, 'c');
        if ($this->lock === false || ! flock($this->lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('native_grant_busy');
        }
        chmod($lockPath, 0600);
        $resources = new GraphResources($context);
        $resources->recover();
        $limits = $resources->effective();
        $report = [];
        $this->state->update('graph-resources-v2', function (array $registry) use ($limits, $transition, $requested, $headroom, $replaces, $lockName, &$report): array {
            $roots = [getmypid()];
            $unknown = false;
            foreach ([...array_values($registry['controllers'] ?? []), ...array_values($registry['residents'] ?? []), ...array_values($registry['native_clients'] ?? [])] as $entry) {
                if (! is_int($entry['pid'] ?? null)) {
                    $unknown = true;
                } else {
                    $roots[] = $entry['pid'];
                }
            }
            $sample = GraphProcessMemory::forest(array_values(array_unique($roots)));
            $unknown = $unknown || $sample['rss_bytes'] === null;
            $rows = array_column($sample['processes'], 'rss_bytes', 'pid');
            $reserved = array_sum(array_column($registry['native_grants'] ?? [], 'bytes'));
            $replaced = [];
            if ($replaces !== null && $replaces !== getmypid()) {
                $replaced[$replaces] = true;
                for ($depth = 0; $depth < 8; $depth++) {
                    foreach ($sample['processes'] as $process) {
                        if (isset($replaced[$process['ppid']])) {
                            $replaced[$process['pid']] = true;
                        }
                    }
                }
            }
            $replacedRss = array_sum(array_intersect_key($rows, $replaced));
            $residentAvailable = $limits['resident_effective_bytes'] - array_sum($rows) + $replacedRss - $headroom;
            $transitionAvailable = $limits['transition_effective_bytes'] - array_sum($rows) - $reserved - $headroom;
            $ceiling = $limits[$transition ? 'transition_effective_bytes' : 'resident_effective_bytes'];
            $available = $transition ? $transitionAvailable : $residentAvailable;
            $need = $requested ?? NativeGraphClient::FRAME_BYTES * 2;
            $failed = $unknown ? ['unknown_rss'] : ($transitionAvailable < $need || ! $transition && $residentAvailable <= 0 ? ['global_budget'] : []);
            $report = ['basis' => 'unique_pid_rss_plus_reserved_owned_capacity_and_headroom', 'retained_rss_bytes' => array_sum($rows),
                'headroom_bytes' => $headroom, 'other_native_grants_bytes' => $reserved, 'available_bytes' => $available,
                'replaced_rss_bytes' => $replacedRss, 'resident_available_bytes' => $residentAvailable, 'transition_available_bytes' => $transitionAvailable,
                'requested_capacity_bytes' => $requested, 'limit_bytes' => $ceiling, 'admitted' => $failed === [], 'failed_conditions' => $failed,
                'unknown_rss' => $unknown, 'sample' => [...$sample, 'processes' => array_slice($sample['processes'], 0, 32), 'process_count' => count($sample['processes']), 'details_truncated' => count($sample['processes']) > 32], 'phase' => $transition ? 'transition' : 'fresh'];
            if ($failed !== []) {
                throw new GraphAdmissionRefused($transition ? 'preparation_required:catalog_transition_budget' : 'preparation_required:resident_budget', $report);
            }
            $registry['native_grants'][$this->token] = ['bytes' => ($requested ?? $transitionAvailable) + $headroom, 'lock' => $lockName];

            return $registry;
        });
        $this->report = $report;
        $this->resident = max(1, min($limits['resident_effective_bytes'], $report['resident_available_bytes']));
        $this->transition = min($limits['transition_effective_bytes'], $requested ?? $report['transition_available_bytes']);
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        try {
            $this->state->update('graph-resources-v2', function (array $registry): array {
                unset($registry['native_grants'][$this->token]);

                return $registry;
            });
        } finally {
            if (is_resource($this->lock)) {
                flock($this->lock, LOCK_UN);
                fclose($this->lock);
            }
        }
    }
}
