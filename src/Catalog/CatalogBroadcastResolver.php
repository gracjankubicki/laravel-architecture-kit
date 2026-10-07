<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Event object dispatch and queue worker candidates, distinct from channel subscription auth. */
final class CatalogBroadcastResolver
{
    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(): void
    {
        $this->channels();
        $edges = $this->index->relations;
        foreach ($edges as $edge) {
            if ($edge['kind'] !== 'event-dispatch' || ! ($edge['metadata']['broadcast_object_verified'] ?? false)) {
                continue;
            }
            $type = $edge['metadata']['event'] ?? null;
            $ids = is_string($type) ? $this->index->namedTypes($type) : [];
            if (count($ids) !== 1) {
                continue;
            }
            $broadcast = $this->index->hasContract($ids[0], 'Illuminate\\Contracts\\Broadcasting\\ShouldBroadcast');
            $now = $this->index->hasContract($ids[0], 'Illuminate\\Contracts\\Broadcasting\\ShouldBroadcastNow');
            if (! $broadcast && ! $now) {
                continue;
            }
            if ($this->index->namedTypes('Illuminate\\Contracts\\Broadcasting\\ShouldBroadcast') !== [] || $this->index->namedTypes('Illuminate\\Contracts\\Broadcasting\\ShouldBroadcastNow') !== []) {
                $this->index->diagnostics[] = ['code' => 'broadcast_analysis', 'message' => 'Source declaration shadows a broadcast contract.', 'path' => $edge['path'], 'line' => $edge['line'], 'subject' => $edge['from']];

                continue;
            }
            $mode = $this->calls->sourceMethodCandidate($type, 'shouldBroadcastNow');
            $dynamicMode = ! $now && ($mode['method'] !== null || $mode['unresolved']);
            $channels = $this->calls->sourceMethodCandidate($type, 'broadcastOn');
            $empty = $channels['method'] !== null && ($this->index->elements[$channels['method']['id']]['metadata']['broadcast_empty_channels'] ?? false);
            foreach (['broadcastWhen', 'broadcastOn', 'broadcastWith', 'broadcastAs', 'broadcastConnections', 'broadcastQueue', 'shouldBroadcastNow'] as $hook) {
                if ($now && in_array($hook, ['broadcastQueue', 'shouldBroadcastNow'], true) || $empty && in_array($hook, ['broadcastWith', 'broadcastConnections'], true)) {
                    continue;
                }
                $selection = $this->calls->sourceMethodCandidate($type, $hook);
                $method = $selection['method'];
                if ($method === null) {
                    continue;
                }
                if ($selection['unresolved'] || $selection['limited'] || ($method['metadata']['visibility'] ?? null) !== 'public' || ($method['metadata']['abstract'] ?? false)) {
                    $this->index->diagnostics[] = ['code' => 'broadcast_analysis', 'message' => 'Broadcast hook is ambiguous, inaccessible or exceeds resolution budget.',
                        'path' => $edge['path'], 'line' => $edge['line'], 'subject' => $edge['from']];

                    continue;
                }
                $preparation = in_array($hook, ['broadcastWhen', 'broadcastQueue', 'shouldBroadcastNow'], true);
                $this->index->addRelation([...$edge, 'to' => $method['id'], 'kind' => 'broadcast-hook', 'resolution' => 'conditional',
                    'metadata' => ['event_class' => $ids[0], 'hook' => $hook, 'queued' => $dynamicMode ? null : ! $now, 'execution_proven' => false,
                        'execution_stage' => $preparation ? 'broadcast-preparation' : ($dynamicMode ? 'broadcast-mode-unresolved' : ($now ? 'broadcast-delivery-candidate' : 'queue-worker-candidate')),
                        'conditions' => ['The event must pass broadcastWhen and delivery conditions.', ...(! $preparation && ! $now ? ['Requested broadcast queue job must be executed.'] : [])],
                        'dispatch_source' => $edge['to']]]);
            }
        }
    }

    private function channels(): void
    {
        $subscriptions = array_filter($this->index->elements, fn ($row) => $row['kind'] === 'broadcast-subscription');
        $matches = 0;
        foreach ($this->index->elements as $event) {
            if ($event['kind'] !== 'class' || count($this->index->namedTypes($event['name'])) !== 1
                || ! $this->index->hasContract($event['id'], 'Illuminate\\Contracts\\Broadcasting\\ShouldBroadcast')
                    && ! $this->index->hasContract($event['id'], 'Illuminate\\Contracts\\Broadcasting\\ShouldBroadcastNow')
                || $this->index->namedTypes('Illuminate\\Contracts\\Broadcasting\\ShouldBroadcast') !== []
                || $this->index->namedTypes('Illuminate\\Contracts\\Broadcasting\\ShouldBroadcastNow') !== []) {
                continue;
            }
            $selection = $this->calls->sourceMethodCandidate($event['name'], 'broadcastOn');
            $method = $selection['method'];
            if ($method === null || $selection['unresolved'] || $selection['limited'] || ($method['metadata']['visibility'] ?? null) !== 'public' || ($method['metadata']['abstract'] ?? false)) {
                continue;
            }
            $method = $this->index->elements[$method['id']];
            $descriptor = $method['metadata']['broadcast_channels'] ?? null;
            if ($descriptor === null || ! $descriptor['resolved']) {
                $this->index->diagnostics[] = ['code' => 'broadcast_channel_analysis', 'message' => 'Broadcast channel return is dynamic or only partially resolved.', 'path' => $method['path'], 'line' => $method['line'], 'subject' => $method['id']];
            }
            foreach ($descriptor['channels'] ?? [] as $channel) {
                // Private and presence constructors depend on the base Channel constructor too.
                if ($this->index->namedTypes($channel['class']) !== [] || $this->index->namedTypes('Illuminate\\Broadcasting\\Channel') !== []) {
                    $this->index->diagnostics[] = ['code' => 'broadcast_channel_analysis', 'message' => 'Source channel constructor shadow prevents channel inference.', 'path' => $method['path'], 'line' => $channel['line'], 'subject' => $method['id']];

                    continue;
                }
                $visibility = CatalogBroadcastChannels::TYPES[$channel['class']];
                $name = ($visibility === 'public' ? '' : $visibility.'-').$channel['name'];
                $id = CatalogElement::resourceIdentity('broadcast-channel', $visibility.'::'.$channel['name']);
                $source = ['path' => $method['path'], 'line' => $channel['line'], 'end_line' => $channel['line']];
                if (! isset($this->index->elements[$id])) {
                    $element = new CatalogElement($id, $name, 'broadcast-channel', $channel['line'], $channel['line'], 0, metadata: ['logical_resource' => true, 'channel_type' => $visibility, 'selector' => $channel['name'], 'delivery_proven' => false]);
                    $this->index->elements[$id] = [...$element->toArray(), 'path' => $method['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => []];
                    $this->index->names[strtolower($name)][] = $id;
                }
                $this->index->elements[$id]['sources'][] = $source;
                $base = ['from' => $method['id'], 'to' => $id, 'kind' => 'broadcast-channel-target', ...$source, 'knowledge' => 'static', 'resolution' => 'conditional', 'metadata' => ['execution_proven' => false, 'delivery_proven' => false, 'event_class' => $event['id'], 'conditions' => ['Broadcast must be requested and delivery conditions must pass.']]];
                $this->index->addRelation($base);
                if ($visibility === 'public') {
                    continue;
                }
                foreach ($subscriptions as $subscription) {
                    if (++$matches > 25000) {
                        $this->index->diagnostics[] = ['code' => 'broadcast_channel_limit', 'message' => 'Channel template matching budget reached.', ...$source, 'subject' => $method['id']];

                        return;
                    }
                    if (str_contains($subscription['name'], '*')) {
                        $this->index->diagnostics[] = ['code' => 'broadcast_channel_analysis', 'message' => 'Nonliteral channel pattern requires inspection.', ...$source, 'subject' => $subscription['id']];

                        continue;
                    }
                    $pattern = preg_quote($subscription['name'], '~');
                    $pattern = preg_replace('/\\\\\{[^{}]+\\\\\}/', '[^.]+', $pattern);
                    $pattern ??= '';
                    if (preg_match('~\\A'.$pattern.'\\z~D', $channel['name']) === 1) {
                        $this->index->addRelation([...$base, 'from' => $id, 'to' => $subscription['id'], 'kind' => 'references-channel-authorization', 'resolution' => 'structural', 'metadata' => ['execution_proven' => false, 'runtime_activation_known' => false, 'template_match' => true]]);
                    }
                }
            }
        }
    }
}
