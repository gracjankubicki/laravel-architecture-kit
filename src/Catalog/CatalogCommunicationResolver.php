<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Delivery candidates depend on a real send site, never merely constructing a message. */
final class CatalogCommunicationResolver
{
    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(): void
    {
        $edges = $this->index->relations;
        foreach ($edges as $position => $edge) {
            if ($edge['kind'] === 'mail-view-candidate') {
                continue;
            }
            if (! in_array($edge['kind'], ['sends-mail', 'sends-notification'], true)) {
                continue;
            }
            $mail = $edge['kind'] === 'sends-mail';
            $facade = $mail ? 'Illuminate\\Support\\Facades\\Mail' : 'Illuminate\\Support\\Facades\\Notification';
            $contract = $mail ? 'Illuminate\\Mail\\Mailable' : 'Illuminate\\Notifications\\Notification';
            $target = $this->index->elements[$edge['to']] ?? null;
            if ($target !== null && $target['kind'] === 'view' && $mail) {
                continue;
            }
            $verified = $this->index->namedTypes($facade) === [] && $this->index->namedTypes($contract) === [] && $target !== null
                && $target['kind'] === 'class' && count($this->index->namedTypes($target['name'])) === 1 && $this->index->hasContract($target['id'], $contract);
            if (! $verified) {
                $this->index->relations[$position]['kind'] = $mail ? 'references-mail-candidate' : 'references-notification-candidate';
                $this->index->relations[$position]['metadata']['contract_verified'] = false;
                $this->diagnostic($edge, 'Message target or facade contract is missing, ambiguous or shadowed.');

                continue;
            }
            $queued = $edge['metadata']['queued'] ?? null;
            if ($queued === null) {
                $queued = $this->index->hasContract($target['id'], 'Illuminate\\Contracts\\Queue\\ShouldQueue')
                    || $this->index->hasContract($target['id'], 'Illuminate\\Contracts\\Queue\\ShouldQueueAfterCommit');
            }
            $this->index->relations[$position]['metadata'] = [...$edge['metadata'], 'queued' => $queued,
                'execution_stage' => $queued ? 'queue-requested' : 'delivery-requested', 'delivery_proven' => false, 'contract_verified' => true];
            $hooks = $mail ? ['build', 'headers', 'envelope', 'content', 'attachments'] : ['via'];
            if ($mail) {
                foreach (['send', 'prepareMailableForDelivery'] as $override) {
                    $selection = $this->calls->sourceMethodCandidate($target['name'], $override);
                    if ($selection['method'] !== null || $selection['unresolved']) {
                        $hooks = [$override];
                        break;
                    }
                }
            }
            foreach ($hooks as $hook) {
                $resolved = $this->calls->sourceMethodCandidate($target['name'], $hook);
                if ($resolved['limited']) {
                    $this->diagnostic($edge, 'Message hook resolution budget reached.');
                }
                $method = $resolved['method'];
                if ($method === null) {
                    continue;
                }
                if ($mail && $hook === 'build' && ($method['metadata']['visibility'] ?? null) !== 'public' || $resolved['unresolved'] || ($method['metadata']['abstract'] ?? false) || ($method['metadata']['visibility'] ?? null) === 'private'
                    || ! $mail && ($method['metadata']['visibility'] ?? null) !== 'public') {
                    $this->diagnostic($edge, 'Message hook is ambiguous or inaccessible: '.$hook.'.');

                    continue;
                }
                $this->index->addRelation([...$edge, 'to' => $method['id'], 'kind' => $mail ? 'mail-delivery-hook' : 'notification-channel-selection', 'resolution' => 'conditional',
                    'metadata' => ['message_class' => $target['id'], 'hook' => $hook, 'queued' => $queued, 'execution_proven' => false,
                        'execution_stage' => $queued && ($mail || $hook !== 'via') ? 'queue-worker-candidate' : 'delivery-preparation',
                        'conditions' => $queued && $mail ? ['Requested queue job must be executed by a worker.'] : ['The framework delivery operation must reach this hook.']]]);
            }
            if (! $mail) {
                $this->notificationChannels($edge, $target, $queued);
            }
        }
        foreach ($edges as $position => $edge) {
            if ($edge['kind'] === 'mail-view-candidate') {
                $this->mailView($position, $edge);
            } elseif ($edge['kind'] === 'notification-view-candidate') {
                $owner = $this->index->elements[$edge['from']] ?? null;
                $parent = $owner !== null && $owner['kind'] === 'method' ? ($owner['parent'] ?? null) : null;
                $verified = $parent !== null && $this->index->hasContract($parent, 'Illuminate\\Notifications\\Notification')
                    && $this->index->namedTypes('Illuminate\\Notifications\\Notification') === []
                    && $this->index->namedTypes('Illuminate\\Notifications\\Messages\\MailMessage') === [];
                $this->index->relations[$position]['kind'] = $verified ? 'prepares-notification-view' : 'references-notification-view';
                $this->index->relations[$position]['resolution'] = 'conditional';
                $this->index->relations[$position]['metadata']['contract_verified'] = $verified;
                $this->index->relations[$position]['metadata']['execution_stage'] = 'channel-message-preparation';
            }
        }
    }

    /** @param array<string, mixed> $edge
     * @param  array<string, mixed>  $notification
     */
    private function notificationChannels(array $edge, array $notification, bool $queued): void
    {
        $via = $this->calls->sourceMethodCandidate($notification['name'], 'via');
        $method = $via['method'];
        if ($method === null || $via['unresolved'] || ($method['metadata']['visibility'] ?? null) !== 'public') {
            return;
        }
        $descriptor = $this->index->elements[$method['id']]['metadata']['notification_channels'] ?? null;
        if ($descriptor === null || ! $descriptor['resolved']) {
            $this->diagnostic($edge, 'Notification channels are dynamic or only partially resolved.');

            return;
        }
        $builtins = ['mail' => ['toMail'], 'database' => ['toDatabase', 'toArray'], 'broadcast' => ['toBroadcast', 'toArray']];
        foreach ($descriptor['channels'] as $channel) {
            $hooks = $builtins[$channel] ?? [];
            $type = $notification['name'];
            $kind = 'notification-delivery-hook';
            if ($hooks === []) {
                $type = $channel;
                $hooks = ['send'];
                $kind = 'notification-custom-channel';
            }
            $found = false;
            foreach ($hooks as $hook) {
                $selected = $this->calls->sourceMethodCandidate($type, $hook);
                $body = $selected['method'];
                if ($body === null && ! $selected['unresolved']) {
                    continue;
                }
                if ($selected['unresolved'] || $selected['limited'] || $body === null || ($body['metadata']['abstract'] ?? false) || ($body['metadata']['visibility'] ?? null) !== 'public') {
                    $this->diagnostic($edge, 'Notification channel method is missing, ambiguous or inaccessible: '.$channel.'.');
                    $found = true;
                    break;
                }
                $this->index->addRelation([...$edge, 'to' => $body['id'], 'kind' => $kind, 'resolution' => 'conditional',
                    'metadata' => ['message_class' => $notification['id'], 'channel' => $channel, 'hook' => $hook,
                        'channel_selection_source' => $method['id'], 'queued' => $queued, 'execution_proven' => false,
                        'execution_stage' => $queued ? 'queue-worker-candidate' : 'channel-delivery-candidate',
                        'conditions' => ['Channel must be selected for the recipient; delivery may be cancelled.', ...($queued ? ['Requested notification queue job must be executed.'] : [])]]]);
                $found = true;
                break;
            }
            if (! $found) {
                $this->diagnostic($edge, 'Selected notification channel body is outside the analyzed graph: '.$channel.'.');
            }
        }
    }

    /** @param array<string, mixed> $edge */
    private function mailView(int $position, array $edge): void
    {
        $owner = $this->index->elements[$edge['from']] ?? null;
        $class = $owner !== null && $owner['kind'] === 'method' ? ($owner['parent'] ?? null) : null;
        $contract = 'Illuminate\\Mail\\Mailable';
        $verified = $class !== null && $this->index->hasContract($class, $contract) && $this->index->namedTypes($contract) === []
            && ($edge['metadata']['view_api'] !== 'content' || $this->index->namedTypes('Illuminate\\Mail\\Mailables\\Content') === []);
        if (! $verified && $owner !== null && $owner['kind'] === 'method' && $class !== null && ($this->index->elements[$class]['kind'] ?? null) === 'trait') {
            foreach ($this->index->in[$owner['id']] ?? [] as $incoming) {
                if ($this->index->relations[$incoming]['kind'] === 'mail-delivery-hook') {
                    $verified = true;
                    break;
                }
            }
        }
        if ($edge['metadata']['view_api'] === 'content' && $this->index->namedTypes('Illuminate\\Mail\\Mailables\\Content') !== []) {
            $verified = false;
        }
        if ($verified && $edge['metadata']['view_api'] === 'mailable-method' && $class !== null) {
            $method = $this->calls->sourceMethodCandidate($this->index->elements[$class]['name'], $edge['metadata']['view_parameter']);
            if ($method['method'] !== null || $method['unresolved']) {
                $verified = false;
            }
        }
        $this->index->relations[$position]['kind'] = $verified ? 'prepares-mail-view' : 'references-mail-view';
        $this->index->relations[$position]['resolution'] = 'conditional';
        $this->index->relations[$position]['metadata']['execution_stage'] = 'message-preparation';
        $this->index->relations[$position]['metadata']['contract_verified'] = $verified;
    }

    /** @param array<string, mixed> $edge */
    private function diagnostic(array $edge, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'communication_analysis', 'message' => $message,
            'path' => $edge['path'], 'line' => $edge['line'], 'subject' => $edge['from']];
    }
}
