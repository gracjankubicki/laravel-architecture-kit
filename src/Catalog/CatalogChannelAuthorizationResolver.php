<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** A subscription authorization entrypoint never becomes a broadcast-delivery transition. */
final class CatalogChannelAuthorizationResolver
{
    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(): void
    {
        $edges = $this->index->relations;
        foreach ($edges as $edge) {
            if (! in_array($edge['kind'], ['registers-channel-class', 'registers-channel-callback'], true)) {
                continue;
            }
            if ($this->index->namedTypes('Illuminate\\Support\\Facades\\Broadcast') !== []) {
                $this->diagnostic($edge, 'Source Broadcast facade shadow prevents authorization inference.');

                continue;
            }
            $target = $this->index->elements[$edge['to']] ?? null;
            $targets = [];
            if ($edge['kind'] === 'registers-channel-callback' && $target !== null && $target['kind'] === 'closure') {
                $targets = [$target['id']];
            } elseif ($target !== null && $target['kind'] === 'class') {
                $selection = $this->calls->sourceCallableCandidates($target['name'], 'join', true, ['creator' => '(framework-broadcast)', 'form' => 'instance', 'binding' => null]);
                $targets = $selection['targets'];
                if ($selection['limited']) {
                    $this->diagnostic($edge, 'Channel authorization method resolution budget reached.');
                }
            }
            if ($targets === []) {
                $this->diagnostic($edge, 'Channel authorization callback body is absent, ambiguous or inaccessible.');
            }
            foreach ($targets as $id) {
                $this->index->addRelation([...$edge, 'to' => $id, 'kind' => 'channel-authorization', 'resolution' => 'conditional',
                    'metadata' => ['execution_proven' => false, 'authorization_result_known' => false, 'runtime_activation_known' => false,
                        'execution_stage' => 'subscription-authorization', 'conditions' => ['Registration must be active; a matching authenticated subscription request must reach this callback.']]]);
            }
            if (! $this->index->elements[$edge['from']]['metadata']['guards_resolved']) {
                $this->diagnostic($edge, 'Subscription authentication guard selection is unresolved.');
            }
        }
    }

    /** @param array<string, mixed> $edge */
    private function diagnostic(array $edge, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'broadcast_authorization_analysis', 'message' => $message,
            'path' => $edge['path'], 'line' => $edge['line'], 'subject' => $edge['from']];
    }
}
