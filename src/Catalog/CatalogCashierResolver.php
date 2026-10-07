<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Stripe operations remain conditional; local subscription checks do not become network calls. */
final class CatalogCashierResolver
{
    private const BILLABLE = 'Laravel\\Cashier\\Billable';

    private const BUILDER = 'Laravel\\Cashier\\SubscriptionBuilder';

    private const SUBSCRIPTION = 'Laravel\\Cashier\\Subscription';

    private const WEBHOOK = 'Laravel\\Cashier\\Http\\Controllers\\WebhookController';

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    /** @param list<array<string, mixed>> $routes */
    public function resolve(array $routes): void
    {
        $profile = CatalogPackageVersions::verified($this->index, 'laravel/cashier');
        $sites = [];
        foreach ($this->index->elements as $id => $element) {
            if ($element['kind'] === 'cashier-operation') {
                $sites[$element['path']][$element['offset']][$element['metadata']['method']] = $element;
            } elseif ($element['kind'] === 'class' && $this->index->hasContract($id, self::WEBHOOK) && $profile !== null && $this->index->namedTypes(self::WEBHOOK) === []) {
                $this->index->elements[$id]['roles'][] = 'cashier-webhook-controller';
                $this->index->elements[$id]['role_evidence'][] = ['role' => 'cashier-webhook-controller', 'basis' => 'source-contract', 'contract' => self::WEBHOOK,
                    'package' => 'laravel/cashier', 'version' => $profile['version'], 'package_sources' => $profile['sources'], 'path' => $element['path'], 'line' => $element['line']];
            } elseif ($element['kind'] === 'class' && $this->index->hasContract($id, self::BILLABLE)) {
                if ($profile === null || $this->index->namedTypes(self::BILLABLE) !== []) {
                    $this->notice($element, 'Cashier package version or Billable source contract is unresolved.');

                    continue;
                }
                $this->index->elements[$id]['roles'][] = 'billable-model';
                $this->index->elements[$id]['role_evidence'][] = ['role' => 'billable-model', 'basis' => 'source-contract', 'contract' => self::BILLABLE,
                    'package' => 'laravel/cashier', 'version' => $profile['version'], 'package_sources' => $profile['sources'], 'path' => $element['path'], 'line' => $element['line']];
            }
        }
        if ($profile === null) {
            return;
        }
        $operations = 0;
        $edges = $this->index->relations;
        foreach ($edges as $call) {
            $method = strtolower($call['metadata']['method'] ?? '');
            if ($call['kind'] !== 'calls' || ! isset(CatalogCashierOperations::MODEL[$method]) && ! isset(CatalogCashierOperations::BUILDER[$method]) && ! isset(CatalogCashierOperations::SUBSCRIPTION[$method])) {
                continue;
            }
            if (++$operations > 4096 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->notice($call, 'Cashier composition reached its operation or memory budget.', 'catalog_limit');

                return;
            }
            foreach ($this->receivers($call['metadata']['receiver']) as $receiver) {
                $mode = $receiver['mode'];
                $supported = match ($mode) {
                    'model' => CatalogCashierOperations::MODEL, 'builder' => CatalogCashierOperations::BUILDER, default => CatalogCashierOperations::SUBSCRIPTION,
                };
                if (! isset($supported[$method])) {
                    continue;
                }
                $site = $sites[$call['path']][$call['metadata']['offset'] ?? -1][$method] ?? null;
                if ($site === null || ! $site['metadata']['valid'][$mode] || ($call['metadata']['form'] ?? null) !== 'instance'
                    || ! $this->standard($receiver['type'], $method, $mode)) {
                    $this->notice($call, 'Cashier source override, argument shape or instance dispatch is unresolved.');

                    continue;
                }
                $root = $sites[$call['path']][$site['offset']]['newsubscription'] ?? null;
                if ($mode === 'builder' && $root !== null && ! $root['metadata']['valid']['model']) {
                    $this->notice($call, 'Cashier source subscription builder has invalid creation arguments.');

                    continue;
                }
                $local = $mode === 'model' && in_array($method, ['subscription', 'subscribed', 'ontrial', 'newsubscription'], true);
                $prepare = $method === 'newsubscription';
                if ($local) {
                    $targets = $this->index->namedTypes($receiver['type']);
                    $target = count($targets) === 1 ? $targets[0] : null;
                } else {
                    $target = $this->stripe($call);
                }
                if ($target !== null) {
                    $this->edge($call['from'], $target, $prepare ? 'prepares-cashier-subscription' : ($local ? 'reads-cashier-subscription' : 'cashier-stripe-operation'), $call, $profile,
                        ['method' => $method, 'receiver_mode' => $mode, 'runtime_standard_cashier_required' => true, 'runtime_argument_types_required' => true,
                            'subscription_present_required' => $mode === 'subscription', 'runtime_standard_subscription_model_required' => $mode === 'subscription',
                            'external_service_required' => ! $local, 'checkout_completion_proven' => false]);
                }
            }
        }
        $this->webhooks($routes, $profile);
    }

    /** @return list<array{mode: string, type: string}> */
    private function receivers(string $receiver, int $depth = 0): array
    {
        if ($depth >= 16) {
            return [];
        }
        $result = [];
        foreach ($this->calls->receiverCandidates($receiver)['types'] as $type) {
            $ids = $this->index->namedTypes($type);
            if (count($ids) === 1 && $this->index->hasContract($ids[0], self::BILLABLE) && $this->index->namedTypes(self::BILLABLE) === []) {
                $result[] = ['mode' => 'model', 'type' => $type];
            } elseif ($type === self::BUILDER || $type === self::SUBSCRIPTION) {
                $result[] = ['mode' => $type === self::BUILDER ? 'builder' : 'subscription', 'type' => $type];
            }
        }
        if ($result !== [] || ! str_starts_with($receiver, '@return:')) {
            return $result;
        }
        $value = json_decode(substr($receiver, 8), true, 32);
        if (! is_array($value) || count($value) !== 3 || ! is_string($value[0]) || ! is_string($value[1])) {
            return [];
        }
        $method = strtolower($value[1]);
        foreach ($this->receivers($value[0], $depth + 1) as $base) {
            if ($base['mode'] === 'model' && in_array($method, ['newsubscription', 'subscription'], true) && $this->standard($base['type'], $method, 'model')) {
                $result[] = ['mode' => $method === 'newsubscription' ? 'builder' : 'subscription', 'type' => $method === 'newsubscription' ? self::BUILDER : self::SUBSCRIPTION];
            } elseif ($base['mode'] === 'builder' && in_array($method, CatalogCashierOperations::FLUENT, true) && $this->index->namedTypes(self::BUILDER) === []) {
                $result[] = $base;
            } elseif ($base['mode'] === 'builder' && in_array($method, ['create', 'add', 'createandsendinvoice'], true) && $this->index->namedTypes(self::BUILDER) === []) {
                $result[] = ['mode' => 'subscription', 'type' => self::SUBSCRIPTION];
            }
        }

        return $result;
    }

    private function standard(string $type, string $method, string $mode): bool
    {
        if ($mode !== 'model') {
            return $this->index->namedTypes($type) === [];
        }
        $selected = $this->calls->sourceMethodCandidate($type, $method, knownTraits: [self::BILLABLE]);

        return ! $selected['limited'] && ! $selected['unresolved'] && $selected['method'] === null;
    }

    /** @param list<array<string, mixed>> $routes
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     */
    private function webhooks(array $routes, array $profile): void
    {
        foreach ($routes as $route) {
            $class = $route['handler']['class'] ?? null;
            if (! is_string($class) || strtolower($route['handler']['method'] ?? '') !== 'handlewebhook') {
                continue;
            }
            $ids = $this->index->namedTypes($class);
            if ($this->index->namedTypes(self::WEBHOOK) !== [] || ($class !== self::WEBHOOK && (count($ids) !== 1 || ! $this->index->hasContract($ids[0], self::WEBHOOK)))) {
                continue;
            }
            if ($class !== self::WEBHOOK) {
                $selected = $this->calls->sourceMethodCandidate($class, 'handleWebhook');
                if ($selected['limited'] || $selected['unresolved'] || $selected['method'] !== null) {
                    continue;
                }
            }
            $source = $route['source'];
            $site = ['path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['line'], 'from' => $route['id']];
            $id = CatalogElement::identity($source['path'], 'cashier-webhook', $class, $source['offset']);
            $resource = new CatalogElement($id, 'Cashier webhook '.$class, 'cashier-webhook', $source['line'], $source['line'], $source['offset'], $route['id'],
                metadata: ['package' => 'laravel/cashier', 'version' => $profile['version'], 'handler_class' => $class, 'execution_proven' => false]);
            $this->index->elements[$id] = [...$resource->toArray(), 'path' => $source['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => [$source]];
            $this->index->names[strtolower($resource->name)][] = $id;
            $this->edge($route['id'], $id, 'cashier-webhook-entry', $site, $profile, ['runtime_standard_webhook_required' => true, 'valid_webhook_payload_required' => true,
                'configured_webhook_middleware_passes_required' => true]);
            foreach (['WebhookReceived', 'WebhookHandled'] as $event) {
                $name = 'Laravel\\Cashier\\Events\\'.$event;
                if ($this->index->namedTypes($name) !== []) {
                    $this->notice($site, 'Source Cashier event shadow prevents standard webhook event inference.');

                    continue;
                }
                $eventId = CatalogElement::resourceIdentity('event', $name);
                if (! isset($this->index->elements[$eventId])) {
                    $eventResource = new CatalogElement($eventId, $name, 'event', $source['line'], $source['line'], $source['offset'], metadata: ['logical_resource' => true]);
                    $this->index->elements[$eventId] = [...$eventResource->toArray(), 'path' => $source['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => []];
                    $this->index->names[strtolower($name)][] = $eventId;
                }
                $this->index->elements[$eventId]['sources'][] = ['path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['line']];
                $this->edge($id, $eventId, 'cashier-webhook-emits-event', $site, $profile, ['runtime_standard_webhook_required' => true, 'valid_webhook_payload_required' => true,
                    'handler_exists_and_completed_required' => $event === 'WebhookHandled']);
            }
            $this->sourceWebhookHandlers($id, $class, $site, $profile);
        }
    }

    /** @param array<string, mixed> $site
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     */
    private function sourceWebhookHandlers(string $webhook, string $class, array $site, array $profile): void
    {
        $pending = $this->index->namedTypes($class);
        $scopes = [];
        while ($pending !== []) {
            $scope = array_pop($pending);
            if (isset($scopes[$scope])) {
                continue;
            }
            if (count($scopes) >= 32) {
                $this->notice($site, 'Cashier webhook source hierarchy reached its budget.', 'catalog_limit');

                return;
            }
            $scopes[$scope] = true;
            foreach ($this->index->out[$scope] ?? [] as $position) {
                $edge = $this->index->relations[$position];
                if (in_array($edge['kind'], ['extends', 'uses-trait'], true) && isset($this->index->elements[$edge['to']])) {
                    $pending[] = $edge['to'];
                }
            }
        }
        $names = [];
        foreach ($this->index->elements as $element) {
            if ($element['kind'] !== 'method' || ! isset($scopes[$element['parent']])) {
                continue;
            }
            $short = substr($element['name'], (int) strrpos($element['name'], '::') + 2);
            if (str_starts_with($short, 'handle') && $short !== 'handleWebhook') {
                $names[$short] = true;
            }
        }
        foreach (array_keys($scopes) as $scope) {
            foreach ($this->index->elements[$scope]['metadata']['trait_rules'] ?? [] as $rule) {
                $alias = $rule['alias'] ?? null;
                if (is_string($alias) && str_starts_with($alias, 'handle') && $alias !== 'handleWebhook') {
                    $names[$alias] = true;
                }
            }
        }
        $count = 0;
        foreach (array_keys($names) as $name) {
            if (++$count > 128 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->notice($site, 'Cashier webhook handler selection reached its source budget.', 'catalog_limit');

                return;
            }
            $selected = $this->calls->sourceMethodCandidate($class, $name);
            if ($selected['limited'] || $selected['unresolved'] || $selected['method'] === null || ($selected['method']['metadata']['static'] ?? false)
                || ! in_array($selected['method']['metadata']['visibility'] ?? null, ['public', 'protected'], true)) {
                continue;
            }
            $this->edge($webhook, $selected['method']['id'], 'cashier-webhook-dispatches-handler', $site, $profile,
                ['handler_name' => $name, 'runtime_standard_webhook_required' => true, 'runtime_payload_selects_handler_required' => true,
                    'valid_webhook_payload_required' => true]);
        }
    }

    /** @param array<string, mixed> $site */
    private function stripe(array $site): string
    {
        $id = CatalogElement::resourceIdentity('external-service', 'stripe');
        if (! isset($this->index->elements[$id])) {
            $element = new CatalogElement($id, 'stripe', 'external-service', $site['line'], $site['end_line'], 0, metadata: ['logical_resource' => true]);
            $this->index->elements[$id] = [...$element->toArray(), 'path' => $site['path'], 'knowledge' => 'static', 'role_evidence' => [],
                'sources' => [['path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line']]]];
            $this->index->names['stripe'][] = $id;
        }

        return $id;
    }

    /** @param array<string, mixed> $site */
    private function notice(array $site, string $message, string $code = 'package_cashier_analysis'): void
    {
        $this->index->diagnostics[] = ['code' => $code, 'message' => $message, 'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['id'] ?? $site['from']];
    }

    /** @param array<string, mixed> $site
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     * @param  array<string, mixed>  $metadata
     */
    private function edge(string $from, string $to, string $kind, array $site, array $profile, array $metadata): void
    {
        $this->index->addRelation(['from' => $from, 'to' => $to, 'kind' => $kind, 'path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line'],
            'knowledge' => 'static', 'resolution' => 'conditional', 'metadata' => ['package' => 'laravel/cashier', 'version' => $profile['version'],
                'package_sources' => $profile['sources'], ...$metadata, 'execution_proven' => false]]);
    }
}
