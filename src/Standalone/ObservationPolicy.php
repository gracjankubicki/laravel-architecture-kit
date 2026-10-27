<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogSettings;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogSequence;
use GracjanKubicki\ArchitectureKit\Composer\ProjectLayout;
use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimeInstallation;
use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchBoundary;
use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchClient;
use Throwable;

/** Bounded notifications are scheduling hints. Only content reconciliation recovers lost continuity. */
final class ObservationPolicy
{
    private static ?\Closure $factory = null;

    private static ?\Closure $tick = null;

    /** Internal process-test instrumentation, never populated from product requests or environment. */
    public static function instrument(?\Closure $factory = null, ?\Closure $tick = null): void
    {
        self::$factory = $factory;
        self::$tick = $tick;
    }

    private ?WatchClient $client = null;

    private int $restartAttempts = 0;

    private float $nextRestart = INF;

    /** @var array<string, string> */
    private array $paths = [];

    /** @var list<string> */
    private array $roots = [];

    public int $configurationEpoch = 0;

    public bool $fullRequired = true;

    public ?string $reason = 'bootstrap_required';

    public ?float $firstEvent = null;

    public ?float $lastEvent = null;

    public float $lastMeaningfulChange;

    public bool $toolChanged = false;

    private readonly GraphPolicy $policy;

    public function invalidate(string $reason): void
    {
        if (! $this->fullRequired) {
            $this->configurationEpoch++;
        }
        $this->fullRequired = true;
        $this->reason = $reason;
    }

    public function __construct(private readonly RepositoryContext $context)
    {
        $this->policy = new GraphPolicy($context->settings['graph_runtime'] ?? []);
        $this->lastMeaningfulChange = microtime(true);
        $scope = [...CatalogSettings::ROOTS, ...ProjectLayout::read($context->rootPath)->graphSources(), ...($context->settings['graph']['paths'] ?? [])];
        $this->extend($scope);
    }

    /** @param list<string> $scope */
    public function extend(array $scope): void
    {
        $roots = $this->roots;
        foreach ($scope as $path) {
            if (DiscoverySettings::safe($this->context->rootPath, $path)) {
                $roots[] = $this->context->rootPath.'/'.$path;
            }
        }
        foreach ([$this->context->rootPath.'/composer.json', $this->context->rootPath.'/composer.lock',
            $this->context->configSource, dirname(__DIR__)] as $path) {
            if (str_starts_with($path, '/')) {
                $roots[] = $path;
            }
        }
        $roots = array_values(array_unique($roots));
        sort($roots, SORT_STRING);
        if ($roots === $this->roots) {
            return;
        }
        $this->roots = $roots;
        $this->configurationEpoch++;
        $this->client?->close();
        $this->client = null;
        $this->fullRequired = true;
        $this->reason = 'watch_roots_changed';
        $this->restartAttempts = 0;
        $this->startClient();
        GraphRuntime::stage('watch.registered', ['job_id' => 'observation', 'configuration_epoch' => $this->configurationEpoch]);
    }

    private function startClient(): void
    {
        $this->restartAttempts++;
        $this->nextRestart = microtime(true) + min(30, 2 ** $this->restartAttempts);
        try {
            $binary = (new RuntimeInstallation($this->context->home))->binary();
            $boundary = new WatchBoundary($this->roots);
            $client = self::$factory === null ? new WatchClient($binary, $boundary) : (self::$factory)($binary, $boundary);
            $client->start();
            $client->bootstrapEvents();
            $this->client = $client;
            $this->reason = 'bootstrap_required';
        } catch (Throwable $error) {
            $this->client = null;
            $this->reason = 'polling:'.$error->getMessage();
        }
    }

    public function drain(): void
    {
        if (($this->client === null || $this->client->session?->closed) && $this->restartAttempts < 3 && microtime(true) >= $this->nextRestart) {
            $this->invalidate('watch_session_restarted');
            $this->startClient();
        }
        if ($this->client === null) {
            return;
        }
        try {
            if (self::$tick !== null) {
                (self::$tick)($this->client);
            }
            for ($i = 0; $i < 64; $i++) {
                $event = $this->client->next();
                if ($event === null) {
                    return;
                }
                GraphRuntime::stage('watch.event', ['job_id' => 'observation', 'watch_session' => $this->client->session?->id,
                    'observed_sequence' => $event->sequence, 'event_kind' => $event->kind, 'path_count' => count($event->paths)]);
                if ($event->kind === 'changes') {
                    $this->firstEvent ??= microtime(true);
                    $this->lastEvent = microtime(true);
                    foreach ($event->paths as $path) {
                        $this->paths[$path] = $event->sequence;
                        if ($path === dirname(__DIR__) || str_starts_with($path, dirname(__DIR__).'/')) {
                            $this->toolChanged = true;
                        }
                    }
                    if (count($this->paths) > $this->policy->queuePaths || array_sum(array_map('strlen', array_keys($this->paths))) > $this->policy->queueBytes) {
                        $this->paths = [];
                        $this->client->session?->lose('runtime_queue_overflow');
                    }
                    $this->lastMeaningfulChange = microtime(true);
                }
                if ($this->client->session?->rescanRequired) {
                    $this->invalidate($this->client->session->reason ?? 'continuity_lost');
                }
            }
        } catch (Throwable $error) {
            $this->invalidate($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $session = $this->client?->session;

        return ['configuration_epoch' => $this->configurationEpoch, 'watch_session' => $session === null ? null : $session->id,
            'observed_sequence' => $session === null ? '0' : $session->sequence, 'watch_health' => $session === null ? 'polling' : $session->health,
            'reason' => $this->reason, 'pending_changes' => $this->paths !== [], 'full_required' => $this->fullRequired];
    }

    /** All events are retained until every admitted product has covered this prefix.
     * @return list<string> */
    public function paths(): array
    {
        return array_keys($this->paths);
    }

    public function due(float $now): bool
    {
        return $this->firstEvent !== null && ($now - ($this->lastEvent ?? $now) >= $this->policy->debounceSeconds || $now - $this->firstEvent >= $this->policy->maxWindowSeconds);
    }

    public function confirm(string $session, string $sequence): bool
    {
        $this->drain();
        if ($this->client?->session === null || ! $this->client->session->acknowledgeReconcile($session, $sequence)) {
            return false;
        }
        $this->fullRequired = false;
        $this->reason = null;

        return true;
    }

    public function releaseCovered(string $sequence): void
    {
        foreach ($this->paths as $path => $at) {
            if (CatalogSequence::compare($at, $sequence) <= 0) {
                unset($this->paths[$path]);
            }
        }
        if ($this->paths === []) {
            $this->firstEvent = $this->lastEvent = null;
        }
    }

    public function close(): void
    {
        $this->client?->close();
    }
}
