<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use RuntimeException;

/** One private user/tool-home registry. Queues and retirement operate on instance tokens, not PIDs. */
final readonly class GraphResources
{
    private StateStore $store;

    public int $residentBudget;

    public int $transitionBudget;

    private ?int $requestedResident;

    private ?int $requestedTransition;

    public function __construct(RepositoryContext $context)
    {
        $this->store = new StateStore($context->home);
        $policy = new GraphPolicy($context->settings['graph_runtime'] ?? []);
        $this->requestedResident = $context->settings['graph_runtime']['resident_budget_bytes'] ?? null;
        $this->requestedTransition = $context->settings['graph_runtime']['transition_budget_bytes'] ?? null;
        $registry = $this->store->read('graph-resources-v2');
        if (! isset($registry['limits'])) {
            $registry = $this->store->update('graph-resources-v2', static function (array $registry) use ($policy): array {
                $registry['limits'] ??= ['resident_bytes' => $policy->residentBytes, 'transition_bytes' => $policy->transitionBytes,
                    'origin' => 'explicit_configuration_or_first_finite_php_limit', 'pressure_mode' => 'configured_ceiling'];

                return $registry;
            });
        }
        $limits = $this->effective();
        $this->residentBudget = $limits['resident_effective_bytes'];
        $this->transitionBudget = $limits['transition_effective_bytes'];
    }

    /** @return array<string, mixed> */
    public static function globalStatus(string $home): array
    {
        $registry = (new StateStore($home))->read('graph-resources-v2');

        return ['ok' => true, 'scope' => 'global_user_tool_home', 'tool_home' => $home,
            'limits' => $registry['limits'] ?? null, 'status' => isset($registry['limits']) ? 'configured' : 'unconfigured',
            'does_not_change_php_memory_limit' => true];
    }

    /** Explicit administration only. No query, MCP or error handler calls this operation.
     * @return array<string, mixed> */
    public static function setGlobal(string $home, int $resident, int $transition): array
    {
        if ($resident < 1 || $transition < 1) {
            throw new RuntimeException('Global resident and transition ceilings must be positive byte counts.');
        }
        (new StateStore($home))->update('graph-resources-v2', static function (array $registry) use ($resident, $transition): array {
            $registry['limits'] = ['resident_bytes' => $resident, 'transition_bytes' => $transition,
                'origin' => 'explicit_runtime_global_limits_set', 'pressure_mode' => 'configured_ceiling', 'changed_at' => gmdate('c')];

            return $registry;
        });

        return self::globalStatus($home);
    }

    /** Refreshed at admission/tick, so running controllers obey an explicit administration change.
     * @return array<string, mixed> */
    public function effective(): array
    {
        $global = $this->store->read('graph-resources-v2')['limits'];
        $resident = min($global['resident_bytes'], $this->requestedResident ?? $global['resident_bytes']);
        $transition = min($global['transition_bytes'], $this->requestedTransition ?? $global['transition_bytes']);

        return ['global' => $global, 'resident_requested_bytes' => $this->requestedResident,
            'transition_requested_bytes' => $this->requestedTransition, 'resident_effective_bytes' => $resident,
            'transition_effective_bytes' => $transition, 'reason' => $this->requestedResident !== null && $this->requestedResident > $resident
                || $this->requestedTransition !== null && $this->requestedTransition > $transition ? 'global_ceiling' : 'configured_ceiling'];
    }

    /** Stop new leases to the global LRU victim; its owner drains active requests cooperatively. */
    public function relieve(): bool
    {
        $selected = false;
        $this->store->update('graph-resources-v2', static function (array $registry) use (&$selected): array {
            $eligible = array_filter($registry['residents'] ?? [], static fn (array $entry): bool => ! ($entry['retiring'] ?? false));
            uasort($eligible, static fn (array $a, array $b): int => $a['last_activity'] <=> $b['last_activity']);
            $token = array_key_first($eligible);
            if ($token !== null) {
                $registry['residents'][$token]['retiring'] = true;
                $registry['residents'][$token]['reason'] = 'transition_budget_lru';
                $selected = true;
            }

            return $registry;
        });

        return $selected;
    }

    public function pressureDrained(): bool
    {
        return array_filter($this->store->read('graph-resources-v2')['residents'] ?? [], static fn (array $entry): bool => $entry['retiring'] ?? false) === [];
    }

    /** FIFO requests coalesce by repo/product. A hot repo cannot overtake an already waiting repo.
     * @param array<string, mixed> $owner */
    public function enqueue(string $ticket, string $repository, string $product, array $owner = []): bool
    {
        $accepted = false;
        $this->store->update('graph-resources-v2', static function (array $registry) use ($ticket, $repository, $product, $owner, &$accepted): array {
            $registry += ['queue' => [], 'builder' => null, 'residents' => []];
            if (isset($registry['queue'][$ticket]) || ($registry['builder']['ticket'] ?? null) === $ticket) {
                return $registry;
            }
            if (count($registry['queue']) >= 64) {
                return $registry;
            }
            $registry['queue'][$ticket] = ['repository' => $repository, 'product' => $product, 'owner' => $owner, 'enqueued_at' => microtime(true)];
            $accepted = true;

            return $registry;
        });

        return $accepted;
    }

    public function claim(string $ticket, string $token): bool
    {
        $claimed = false;
        $this->store->update('graph-resources-v2', static function (array $registry) use ($ticket, $token, &$claimed): array {
            if (($registry['builder'] ?? null) === null && array_key_first($registry['queue'] ?? []) === $ticket) {
                $registry['builder'] = [...$registry['queue'][$ticket], 'ticket' => $ticket, 'token' => $token, 'started_at' => microtime(true)];
                unset($registry['queue'][$ticket]);
                $claimed = true;
            }

            return $registry;
        });

        return $claimed;
    }

    /** Called outside the registry lock. Check the owned instance lifetime lock; stale PID is never authority. */
    public function recover(): void
    {
        $snapshot = $this->store->read('graph-resources-v2');
        $dead = [];
        foreach ([...($snapshot['queue'] ?? []), ...(($snapshot['builder'] ?? null) === null ? [] : [$snapshot['builder']['ticket'] => $snapshot['builder']]),
            ...array_combine(array_map(static fn ($key): string => 'controller:'.$key, array_keys($snapshot['controllers'] ?? [])), array_values($snapshot['controllers'] ?? [])),
            ...array_combine(array_map(static fn ($key): string => 'resident:'.$key, array_keys($snapshot['residents'] ?? [])), array_values($snapshot['residents'] ?? []))] as $ticket => $entry) {
            $owner = $entry['owner'] ?? [];
            if ($owner === []) {
                continue;
            }
            try {
                StateStore::assertBoundary($owner['directory'], $this->store->directory);
                $path = $owner['directory'].'/controller-'.$owner['token'].'.lock';
                StateStore::assertBoundary($path, $this->store->directory);
                if (is_link($path)) {
                    throw new RuntimeException('controller_lock_boundary');
                }
                $lock = fopen($path, 'c');
                if ($lock === false) {
                    throw new RuntimeException('controller_lock_unavailable');
                }
                if (flock($lock, LOCK_EX | LOCK_NB)) {
                    $dead[$ticket] = $entry;
                    flock($lock, LOCK_UN);
                }
                fclose($lock);
            } catch (\Throwable) {
                $dead[$ticket] = $entry;
            }
        }
        if ($dead === []) {
            return;
        }
        $this->store->update('graph-resources-v2', static function (array $registry) use ($dead): array {
            foreach ($dead as $ticket => $entry) {
                if (str_starts_with($ticket, 'controller:') && ($registry['controllers'][substr($ticket, 11)] ?? null) === $entry) {
                    unset($registry['controllers'][substr($ticket, 11)]);
                }
                if (str_starts_with($ticket, 'resident:') && ($registry['residents'][substr($ticket, 9)] ?? null) === $entry) {
                    unset($registry['residents'][substr($ticket, 9)]);
                }
                if (($registry['queue'][$ticket] ?? null) === $entry) {
                    unset($registry['queue'][$ticket]);
                }
                if (($registry['builder'] ?? null) === $entry) {
                    $registry['builder'] = null;
                }
            }

            return $registry;
        });
    }

    public function release(string $ticket): void
    {
        $this->store->update('graph-resources-v2', static function (array $registry) use ($ticket): array {
            unset($registry['queue'][$ticket]);
            if (($registry['builder']['ticket'] ?? null) === $ticket) {
                $registry['builder'] = null;
            }

            return $registry;
        });
    }

    /** @param array<string, mixed> $entry */
    public function resident(string $token, array $entry): void
    {
        $this->store->update('graph-resources-v2', function (array $registry) use ($token, $entry): array {
            $registry['residents'][$token] = [...($registry['residents'][$token] ?? []), ...$entry];
            $total = array_sum(array_column($registry['residents'], 'bytes')) + array_sum(array_column($registry['controllers'] ?? [], 'bytes'));
            $ceiling = min($registry['limits']['resident_bytes'], $this->requestedResident ?? $registry['limits']['resident_bytes']);
            if ($total > $ceiling) {
                $eligible = array_filter($registry['residents'], static fn (array $resident): bool => ! ($resident['retiring'] ?? false));
                uasort($eligible, static fn (array $a, array $b): int => $a['last_activity'] <=> $b['last_activity']);
                foreach ($eligible as $victim => $resident) {
                    if ($total <= $ceiling) {
                        break;
                    }
                    $registry['residents'][$victim]['retiring'] = true;
                    $registry['residents'][$victim]['reason'] = 'resident_budget_lru';
                    $total -= $resident['bytes'];
                }
            }

            return $registry;
        });
    }

    public function retirement(string $token): ?string
    {
        return $this->store->read('graph-resources-v2')['residents'][$token]['reason'] ?? null;
    }

    public function forget(string $token): void
    {
        $this->store->update('graph-resources-v2', static function (array $registry) use ($token): array {
            unset($registry['residents'][$token]);

            return $registry;
        });
    }

    /** @param array<string, mixed> $entry */
    public function controller(string $token, array $entry): void
    {
        $this->store->update('graph-resources-v2', static function (array $registry) use ($token, $entry): array {
            $registry['controllers'][$token] = $entry;

            return $registry;
        });
    }

    public function forgetController(string $token): void
    {
        $this->store->update('graph-resources-v2', static function (array $registry) use ($token): array {
            unset($registry['controllers'][$token]);

            return $registry;
        });
    }

    /** Reservation covers live old workers and the new process/parser/decoded/COW workspace.
     * @param array<string, mixed> $composition
     * @return array<string, mixed> */
    public function admit(int $candidateBytes, int $residentProcessBytes, bool $transition, array $composition = []): array
    {
        $registry = $this->store->read('graph-resources-v2');
        $workers = array_sum(array_column($registry['residents'] ?? [], 'bytes'));
        $controllers = array_sum(array_column($registry['controllers'] ?? [], 'bytes'));
        $retained = $workers + $controllers;
        $limits = $this->effective();
        $ceiling = $transition ? $limits['transition_effective_bytes'] : $limits['resident_effective_bytes'];
        $limit = MemoryLimit::bytes();
        $used = memory_get_usage();
        $allocated = memory_get_usage(true);
        $globalCounted = $candidateBytes + $residentProcessBytes + $retained;
        $phpCounted = $allocated + $candidateBytes;
        $globalExceeded = $globalCounted > $ceiling;
        $phpExceeded = $limit !== null && $phpCounted > $limit - 262144;
        $failed = [];
        if ($candidateBytes < 0) {
            $failed[] = 'negative_candidate';
        }
        if ($globalExceeded) {
            $failed[] = 'global_budget';
        }
        if ($phpExceeded) {
            $failed[] = 'php_budget';
        }
        $report = ['phase' => $transition ? 'transition' : 'fresh', 'transition' => $transition,
            'facts_bytes' => $composition['facts_bytes'] ?? null, 'candidate_bytes' => $candidateBytes,
            'process_bytes' => $residentProcessBytes, 'measured_rss_bytes' => $composition['measured_rss_bytes'] ?? null,
            'process_bytes_basis' => $composition['process_bytes_basis'] ?? 'caller_reservation', 'rss_reason' => $composition['rss_reason'] ?? null,
            'retained_worker_bytes' => $workers, 'retained_controller_bytes' => $controllers, 'global_retained_bytes' => $retained,
            'php_used' => $used, 'php_allocated' => $allocated, 'php_memory_limit_bytes' => $limit,
            'resident_effective_bytes' => $limits['resident_effective_bytes'], 'transition_effective_bytes' => $limits['transition_effective_bytes'],
            'global_condition' => ['counted_bytes' => $globalCounted, 'limit_bytes' => $ceiling, 'exceeded' => $globalExceeded],
            'php_condition' => ['counted_bytes' => $phpCounted, 'limit_bytes' => $limit === null ? null : $limit - 262144, 'exceeded' => $phpExceeded],
            'gc' => $composition['gc'] ?? null, 'failed_conditions' => $failed, 'admitted' => $failed === []];
        if ($failed !== []) {
            throw new GraphAdmissionRefused($transition ? 'preparation_required:catalog_transition_budget' : 'preparation_required:resident_budget', $report);
        }

        return $report;
    }
}
