<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Configuration resources do not prove a running server or successful delivery. */
final class CatalogReverbResolver
{
    private int $deliveryVisits = 0;

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(): void
    {
        $profile = CatalogPackageVersions::verified($this->index, 'laravel/reverb');
        $connections = $defaults = [];
        $count = 0;
        foreach ($this->index->elements as $site) {
            if ($site['kind'] === 'broadcast-connection-default') {
                $defaults[] = $site;

                continue;
            }
            if ($site['kind'] !== 'reverb-definition') {
                continue;
            }
            if (++$count > 1024 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->notice($site, 'Reverb resource composition reached its memory or definition budget.', 'catalog_limit');

                return;
            }
            if ($profile === null || $this->index->namedTypes('Laravel\\Reverb\\ReverbServiceProvider') !== []
                || $this->index->namedTypes('Laravel\\Reverb\\ServerProviderManager') !== []) {
                $this->notice($site, 'Reverb version or source server provider contract requires inspection.');

                continue;
            }
            $metadata = $site['metadata'];
            if (! $metadata['resolved']) {
                $this->notice($site, 'Reverb configuration selector requires inspection.');

                continue;
            }
            $kind = match ($metadata['form']) {
                'connection' => 'broadcast-connection',
                'server' => 'reverb-server',
                'application' => 'reverb-application',
                default => null,
            };
            if ($kind === null) {
                $this->notice($site, 'Unknown Reverb configuration form requires inspection.');

                continue;
            }
            // Application slot identity never uses a credential or joins endpoints by host similarity.
            $id = CatalogElement::resourceIdentity($kind, $site['path'].':'.$metadata['selector']);
            $resource = new CatalogElement($id, $metadata['selector'], $kind, $site['line'], $site['end_line'], $site['offset'],
                metadata: ['logical_resource' => true, 'driver' => 'reverb', 'host' => $metadata['host'], 'port' => $metadata['port'],
                    'scheme' => $metadata['scheme'], 'runtime_activation_known' => false, 'credentials_omitted' => true]);
            $this->index->elements[$id] = [...$resource->toArray(), 'path' => $site['path'], 'knowledge' => 'static', 'role_evidence' => [],
                'sources' => [['path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line']]]];
            $this->index->names[strtolower($resource->name)][] = $id;
            $this->edge($site['id'], $id, 'declares-'.$kind, $site, $profile);
            if ($metadata['form'] === 'connection') {
                $connections[$metadata['selector']][] = $id;
            }
        }
        if ($profile === null) {
            return;
        }
        foreach ($defaults as $site) {
            $selector = $site['metadata']['selector'];
            if (! $site['metadata']['resolved']) {
                if ($connections !== []) {
                    $this->notice($site, 'Default broadcast connection depends on dynamic configuration.');
                }

                continue;
            }
            $candidates = $connections[$selector] ?? [];
            if (count($candidates) === 1) {
                $this->edge($site['id'], $candidates[0], 'selects-default-broadcast-connection', $site, $profile);
            }
        }
        $this->deliveries($connections, $defaults, $profile);
    }

    /** @param array<string, list<string>> $connections
     * @param  list<array<string, mixed>>  $defaults
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     */
    private function deliveries(array $connections, array $defaults, array $profile): void
    {
        $selectors = [];
        foreach ($this->index->elements as $element) {
            if ($element['kind'] === 'broadcast-connection-selector') {
                $selectors[$element['parent']][] = $element;
            }
        }
        $this->deliveryVisits = 0;
        $edges = $this->index->relations;
        foreach ($edges as $edge) {
            if ($edge['kind'] !== 'broadcast-hook' || ($edge['metadata']['hook'] ?? null) !== 'broadcastOn') {
                continue;
            }
            if ($this->deliveryLimited()) {
                $this->notice($edge, 'Reverb delivery composition reached its source budget.', 'catalog_limit');

                return;
            }
            $eventId = $edge['metadata']['event_class'];
            $event = $this->index->elements[$eventId] ?? null;
            if ($event === null || ($this->index->elements[$edge['to']]['metadata']['broadcast_empty_channels'] ?? false)) {
                continue;
            }
            if ($this->index->namedTypes('Illuminate\\Broadcasting\\BroadcastEvent') !== []
                || $this->index->namedTypes('Illuminate\\Broadcasting\\BroadcastManager') !== []) {
                $this->notice($event, 'Source broadcast transport shadows prevent Reverb delivery inference.');

                continue;
            }
            $selection = $this->calls->sourceMethodCandidate($event['name'], 'broadcastConnections', knownTraits: [
                'Illuminate\\Foundation\\Events\\Dispatchable', 'Illuminate\\Broadcasting\\InteractsWithSockets', 'Illuminate\\Queue\\SerializesModels',
            ]);
            if ($selection['unresolved'] || $selection['limited']) {
                $this->notice($event, 'Event broadcast connection method or runtime trait state requires inspection.');

                continue;
            }
            $method = $selection['method'];
            $evidence = [];
            if ($method !== null) {
                $facts = $selectors[$method['id']] ?? [];
                if (count($facts) !== 1 || ! $facts[0]['metadata']['resolved'] || ($method['metadata']['visibility'] ?? null) !== 'public') {
                    $this->notice($event, 'Event broadcast connections are dynamic or inaccessible.');

                    continue;
                }
                $names = $facts[0]['metadata']['connections'];
                $evidence[] = $facts[0]['id'];
            } else {
                if (! $this->sourceParentsComplete($eventId)) {
                    $this->notice($event, 'External event ancestry may override broadcast connection selection.');

                    continue;
                }
                $names = [null];
            }
            foreach (array_unique($names, SORT_REGULAR) as $name) {
                if ($this->deliveryLimited()) {
                    $this->notice($event, 'Reverb delivery connection expansion reached its source budget.', 'catalog_limit');

                    return;
                }
                $sources = $evidence;
                if ($name === null) {
                    if (count($defaults) !== 1 || ! $defaults[0]['metadata']['resolved']) {
                        $this->notice($event, 'Default event broadcast connection is missing, dynamic or ambiguous.');

                        continue;
                    }
                    $name = $defaults[0]['metadata']['selector'];
                    $sources[] = $defaults[0]['id'];
                }
                $targets = $connections[$name] ?? [];
                if (count($targets) !== 1) {
                    $this->notice($event, 'Selected event connection has no unique source Reverb configuration.');

                    continue;
                }
                $this->index->addRelation([...$edge, 'to' => $targets[0], 'kind' => 'broadcasts-via-reverb', 'resolution' => 'conditional',
                    'metadata' => [...$edge['metadata'], 'package' => 'laravel/reverb', 'version' => $profile['version'], 'package_sources' => $profile['sources'],
                        'connection_selector' => $name, 'selector_sources' => $sources, 'broadcast_hook_source' => $edge['to'],
                        'queue_delivery_required' => ($edge['metadata']['queued'] ?? null) !== false,
                        'runtime_connection_configuration_required' => true, 'nonempty_channels_required' => true,
                        'runtime_server_available_required' => true, 'execution_proven' => false]]);
            }
        }
    }

    /** @phpstan-impure */
    private function deliveryLimited(): bool
    {
        return ++$this->deliveryVisits > 25000 || ImpactExtractor::sourceLimit(0) !== null;
    }

    private function sourceParentsComplete(string $event): bool
    {
        $pending = [$event];
        $seen = [];
        while ($pending !== []) {
            $id = array_pop($pending);
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
            if (count($seen) > 32) {
                return false;
            }
            foreach ($this->index->out[$id] ?? [] as $position) {
                $edge = $this->index->relations[$position];
                if ($edge['kind'] !== 'extends') {
                    continue;
                }
                if (! isset($this->index->elements[$edge['to']])) {
                    return false;
                }
                $pending[] = $edge['to'];
            }
        }

        return true;
    }

    /** @param array<string, mixed> $site */
    private function notice(array $site, string $message, string $code = 'package_reverb_analysis'): void
    {
        $this->index->diagnostics[] = ['code' => $code, 'message' => $message, 'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['id'] ?? $site['from']];
    }

    /** @param array<string, mixed> $site
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     */
    private function edge(string $from, string $to, string $kind, array $site, array $profile): void
    {
        $this->index->addRelation(['from' => $from, 'to' => $to, 'kind' => $kind, 'path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line'],
            'knowledge' => 'static', 'resolution' => 'structural', 'metadata' => ['package' => 'laravel/reverb', 'version' => $profile['version'],
                'package_sources' => $profile['sources'], 'runtime_activation_known' => false, 'execution_proven' => false]]);
    }
}
