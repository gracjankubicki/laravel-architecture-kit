<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Composes known source pool factories and consumption without constructing a Pool. */
final class CatalogSaloonPoolResolver
{
    private ?CatalogCallResolver $factoryCalls = null;

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(): void
    {
        $profile = CatalogPackageVersions::verified($this->index, 'saloonphp/saloon');
        $shadowed = false;
        foreach (['Saloon\Http\Pool', 'Saloon\Http\Connector', 'Saloon\Traits\Connector\SendsRequests'] as $contract) {
            $shadowed = $shadowed || $this->index->namedTypes($contract) !== [];
        }
        $sites = [];
        foreach ($this->index->elements as $element) {
            if ($element['kind'] === 'saloon-pool-site') {
                $sites[$element['path']][$element['offset']][$element['metadata']['end_offset']] = $element;
            }
        }
        $pools = [];
        $edges = $this->index->relations;
        $promiseEndpoints = $promiseOrigins = [];
        foreach ($edges as $edge) {
            $source = $edge['metadata']['source_call'] ?? null;
            if ($edge['kind'] === 'uses-external-service' && ($edge['metadata']['mode'] ?? '') === 'sendasync' && is_array($source)) {
                $promiseEndpoints[$source['path']][$source['offset']][$source['end_offset']][] = $edge;
                $promiseOrigins[$source['path']][$source['end_offset']][] = $source;
            }
        }
        $visited = 0;
        foreach ($edges as $call) {
            $method = strtolower($call['metadata']['method'] ?? '');
            if (! in_array($call['kind'], ['calls', 'constructs'], true) || ! in_array($method, ['pool', '__construct'], true)) {
                continue;
            }
            if (++$visited > 4096 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->notice($call, 'Pool composition reached its source budget.', 'catalog_limit');

                return;
            }
            $site = $sites[$call['path']][$call['metadata']['offset']][$call['metadata']['end_offset'] ?? -1] ?? null;
            if ($site === null || ! $site['metadata']['valid']) {
                continue;
            }
            $site = $this->members($call, $site, $promiseOrigins);
            $constructor = $site['metadata']['form'] === 'new';
            if ($constructor !== ($method === '__construct')) {
                continue;
            }
            if ($constructor) {
                $poolType = $call['metadata']['receiver'];
                $poolIds = $this->index->namedTypes($poolType);
                if (strcasecmp($poolType, 'Saloon\Http\Pool') !== 0
                    && (count($poolIds) !== 1 || ! $this->index->hasContract($poolIds[0], 'Saloon\Http\Pool'))) {
                    continue;
                }
                foreach (['__construct', 'send', 'setRequests', 'setConcurrency', 'withResponseHandler', 'withExceptionHandler'] as $hook) {
                    if (strcasecmp($poolType, 'Saloon\Http\Pool') === 0 && $poolIds === []) {
                        // The pinned package supplies this method; there is no source subclass to inspect.
                        continue;
                    }
                    $candidate = $this->calls->sourceMethodCandidate($poolType, $hook);
                    if ($candidate['method'] !== null || $candidate['unresolved'] || $candidate['limited']) {
                        $this->notice($call, 'Source Pool subclass overrides require inspection.');

                        continue 2;
                    }
                }
                if (! is_string($call['metadata']['saloon_pool_connector'] ?? null)) {
                    $this->notice($call, 'Pool constructor connector is unresolved.');

                    continue;
                }
            }
            $receivers = $this->calls->receiverCandidates($constructor ? $call['metadata']['saloon_pool_connector'] : $call['metadata']['receiver']);
            if ($receivers['limited']) {
                $this->notice($call, 'Pool connector selection reached its source budget.', 'catalog_limit');

                continue;
            }
            foreach ($receivers['types'] as $type) {
                $ids = $this->index->namedTypes($type);
                if (count($ids) !== 1 || ! $this->index->hasContract($ids[0], 'Saloon\Http\Connector')) {
                    continue;
                }
                if ($profile === null || $shadowed) {
                    $this->notice($call, 'Pool package contract is unverified or shadowed by source declarations.');

                    continue;
                }
                $selected = $constructor ? ['method' => null, 'unresolved' => false, 'limited' => false] : $this->calls->sourceMethodCandidate($type, 'pool');
                if ($selected['method'] !== null || $selected['unresolved'] || $selected['limited']) {
                    $this->notice($call, 'Source pool override or ambiguous connector requires inspection.');

                    continue;
                }
                $source = ['id' => $site['id'], 'path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line']];
                $pools[$call['path']][$site['metadata']['end_offset']][] = ['site' => $site, 'connector' => $type, 'source' => $source,
                    'handlers' => $this->callbacks($call, $site)];
                $this->edge($call, $site['id'], 'prepares-saloon-pool', ['execution_proven' => false, 'package' => 'saloonphp/saloon', 'version' => $profile['version'], 'package_sources' => $profile['sources']]);
                if (! $site['metadata']['resolved']) {
                    $this->notice($call, 'Pool members require source inspection; the list is incomplete.');
                }
                if (! $site['metadata']['generator'] && $site['metadata']['factory'] !== null) {
                    $this->edge($call, $site['metadata']['factory'], 'invokes-saloon-pool-factory', ['execution_proven' => false, 'pool_source' => $source]);
                }
                foreach ($site['metadata']['targets'] as $target) {
                    foreach ($this->index->namedTypes($target) as $id) {
                        $this->edge($call, $id, 'references-saloon-pool-member', ['execution_proven' => false, 'pool_source' => $source]);
                    }
                }
            }
        }
        $sends = [];
        $aliases = $states = [];
        foreach ($edges as $call) {
            $origin = $call['metadata']['receiver_origin'] ?? $call['metadata']['receiver_instance_origin'] ?? null;
            $method = strtolower($call['metadata']['method'] ?? '');
            if ($call['kind'] !== 'calls' || ! in_array($method, ['send', ...array_keys(CatalogSaloonOperations::POOL_SETTERS)], true)
                || ! is_int($origin)) {
                continue;
            }
            $receiver = $call['metadata']['receiver'];
            $descriptor = str_starts_with($receiver, '@return:') ? json_decode(substr($receiver, 8), true) : null;
            $poolIds = $this->index->namedTypes($receiver);
            $poolReceiver = strcasecmp($receiver, 'Saloon\Http\Pool') === 0
                || count($poolIds) === 1 && $this->index->hasContract($poolIds[0], 'Saloon\Http\Pool');
            if (! $poolReceiver && (! is_array($descriptor) || ! in_array(strtolower($descriptor[1] ?? ''), ['pool', ...array_keys(CatalogSaloonOperations::POOL_SETTERS)], true))) {
                continue;
            }
            $path = $call['path'];
            $owner = $call['from'];
            $root = $aliases[$path][$owner][$origin] ?? $origin;
            $candidates = $states[$path][$owner][$root] ?? $pools[$path][$root] ?? [];
            if (isset(CatalogSaloonOperations::POOL_SETTERS[$method]) && $method !== 'setrequests' && $candidates !== []) {
                $site = $sites[$path][$call['metadata']['offset']][$call['metadata']['end_offset'] ?? -1] ?? null;
                if ($site === null || $site['metadata']['form'] !== $method) {
                    continue;
                }
                $updated = [];
                if (! $site['metadata']['valid']) {
                    $this->notice($call, 'Pool callback setter arguments require inspection.');
                } else {
                    foreach ($candidates as $pool) {
                        $pool['handlers'] = $this->callbacks($call, $site, $pool['handlers']);
                        $updated[] = $pool;
                    }
                }
                $states[$path][$owner][$root] = $this->boundedStates($call, $site['metadata']['conditional'] ? [...$candidates, ...$updated] : $updated);
                $aliases[$path][$owner][$site['metadata']['end_offset']] = $root;

                continue;
            }
            if ($method === 'setrequests' && $candidates !== []) {
                $site = $sites[$path][$call['metadata']['offset']][$call['metadata']['end_offset'] ?? -1] ?? null;
                if ($site === null || $site['metadata']['form'] !== 'setrequests') {
                    continue;
                }
                $site = $this->members($call, $site, $promiseOrigins);
                $updated = [];
                if (! $site['metadata']['valid']) {
                    $this->notice($call, 'Pool setRequests arguments require inspection.');
                } else {
                    foreach ($candidates as $pool) {
                        $source = ['id' => $site['id'], 'path' => $path, 'line' => $site['line'], 'end_line' => $site['end_line'],
                            'creation' => $pool['source']['creation'] ?? $pool['source']];
                        $updated[] = ['site' => $site, 'connector' => $pool['connector'], 'source' => $source, 'handlers' => $pool['handlers']];
                        $this->edge($call, $site['id'], 'replaces-saloon-pool-members', ['execution_proven' => false, 'pool_source' => $source]);
                        if (! $site['metadata']['resolved']) {
                            $this->notice($call, 'Replacement pool members are incomplete or unresolved.');
                        }
                        if (! $site['metadata']['generator'] && $site['metadata']['factory'] !== null) {
                            $this->edge($call, $site['metadata']['factory'], 'invokes-saloon-pool-factory', ['execution_proven' => false, 'pool_source' => $source]);
                        }
                    }
                }
                $next = $site['metadata']['conditional'] ? [...$candidates, ...$updated] : $updated;
                $states[$path][$owner][$root] = $this->boundedStates($call, $next);
                $aliases[$path][$owner][$site['metadata']['end_offset']] = $root;

                continue;
            }
            foreach ($candidates as $pool) {
                if (! ($call['metadata']['saloon_pool_send'] ?? false)) {
                    $this->notice($call, 'Pool send arguments require inspection.');

                    continue;
                }
                $site = $pool['site'];
                $this->edge($call, $site['id'], 'consumes-saloon-pool', ['execution_proven' => false, 'pool_source' => $pool['source']]);
                $hasMembers = ! $site['metadata']['resolved'];
                foreach ($site['metadata']['targets'] as $target) {
                    $ids = $this->index->namedTypes($target);
                    $hasMembers = $hasMembers || count($ids) !== 1 || $this->index->hasContract($ids[0], 'Saloon\Http\Request')
                        || $this->index->hasContract($ids[0], 'GuzzleHttp\Promise\PromiseInterface');
                }
                foreach ($site['metadata']['promise_sites'] as $promise) {
                    $hasMembers = $hasMembers || ($promiseEndpoints[$promise['path'] ?? $path][$promise['offset']][$promise['end_offset']] ?? []) !== [];
                }
                foreach ($pool['handlers'] as $kind => $handler) {
                    if ($kind !== 'concurrency' && ! $hasMembers) {
                        continue;
                    }
                    $condition = match ($kind) {
                        'response' => 'A pool request promise must fulfil.',
                        'exception' => 'A pool request promise must reject.',
                        default => 'The promise iterator must request a concurrency value.',
                    };
                    foreach ($handler['ids'] as $id) {
                        $this->edge($call, $id, 'invokes-saloon-pool-'.$kind.'-handler', ['execution_proven' => false,
                            'pool_source' => $pool['source'], 'registration_source' => $handler['source'], 'conditions' => [$condition]]);
                    }
                }
                if ($site['metadata']['generator'] && $site['metadata']['factory'] !== null) {
                    $this->edge($call, $site['metadata']['factory'], 'consumes-saloon-pool-generator', ['execution_proven' => false, 'pool_source' => $pool['source']]);
                }
                foreach ($site['metadata']['promise_sites'] as $promise) {
                    $prepared = $promiseEndpoints[$promise['path'] ?? $path][$promise['offset']][$promise['end_offset']] ?? [];
                    if ($prepared === []) {
                        $this->notice($call, 'Pool promise factory lacks a verified source asynchronous send.');
                    }
                    foreach ($prepared as $endpoint) {
                        $this->edge($call, $endpoint['to'], 'consumes-saloon-pool-promise', ['execution_proven' => false, 'traffic_proven' => false,
                            'pool_source' => $pool['source'], 'promise_source' => $endpoint['metadata']['source_call'], 'prepares_another_send' => false]);
                    }
                }
                foreach ($site['metadata']['targets'] as $target) {
                    $targetIds = $this->index->namedTypes($target);
                    if (count($targetIds) === 1 && $this->index->hasContract($targetIds[0], 'GuzzleHttp\Promise\PromiseInterface')
                        && $this->index->namedTypes('GuzzleHttp\Promise\PromiseInterface') === []) {
                        $this->edge($call, $targetIds[0], 'consumes-saloon-pool-promise', ['execution_proven' => false, 'pool_source' => $pool['source'], 'prepares_another_send' => false]);

                        continue;
                    }
                    $send = $call;
                    $send['metadata']['receiver'] = $pool['connector'];
                    $send['metadata']['method'] = 'sendasync';
                    $send['metadata']['saloon_request'] = ['valid' => true, 'receiver' => $target, 'request_side_valid' => false, 'mock_receiver' => null];
                    $send['metadata']['saloon_pool'] = [...$pool['source'], 'member_return_sources' => $site['metadata']['member_return_sources'] ?? []];
                    $sends[] = $send;
                    if (count($sends) >= 4096 || ImpactExtractor::sourceLimit(0) !== null) {
                        $this->notice($call, 'Pool transport candidates reached their source budget.', 'catalog_limit');
                        break 2;
                    }
                }
            }
        }
        (new CatalogSaloonResolver($this->index, $this->calls))->resolve($sends);
    }

    /** @param array<string, mixed> $call
     * @param  array<string, mixed>  $site
     * @param  array<string, array<int, list<array<string, mixed>>>>  $promiseOrigins
     * @return array<string, mixed>
     */
    private function members(array $call, array $site, array $promiseOrigins): array
    {
        $descriptor = $call['metadata']['saloon_pool_members'] ?? null;
        $factory = $call['metadata']['saloon_pool_member_factory'] ?? null;
        if (is_array($factory)) {
            $selected = ($this->factoryCalls ??= new CatalogCallResolver($this->index))->factoryReturnCandidates($factory['receiver'], $factory['call'], complete: true);
            $descriptor = ['complete' => ! $selected['limited'] && $selected['values'] !== [], 'members' => []];
            $sources = [];
            foreach ($selected['values'] as $value) {
                if (! isset($value['saloon_pool_members']) || $value['sources'] === []) {
                    $descriptor['complete'] = false;

                    continue;
                }
                $leaf = $value['sources'][array_key_last($value['sources'])];
                $sources = [...$sources, ...$value['sources']];
                $descriptor['complete'] = $descriptor['complete'] && $value['saloon_pool_members']['complete'];
                foreach ($value['saloon_pool_members']['members'] as $member) {
                    if (count($descriptor['members']) >= 128) {
                        $descriptor['complete'] = false;
                        $this->notice($call, 'Returned Pool list reached its member budget.', 'catalog_limit');
                        break 2;
                    }
                    $descriptor['members'][] = [...$member, 'path' => $leaf['path']];
                }
            }
            $site['metadata']['member_return_sources'] = $sources;
            if ($selected['limited']) {
                $this->notice($call, 'Pool member factory selection exceeded its source budget.', 'catalog_limit');
            }
        }
        if (! is_array($descriptor)) {
            return $site;
        }
        $targets = $promises = [];
        $complete = $descriptor['complete'];
        foreach ($descriptor['members'] as $member) {
            $receiver = $member['receiver'];
            $returned = str_starts_with($receiver, '@return:') ? json_decode(substr($receiver, 8), true) : null;
            $prepared = is_array($returned) && strtolower($returned[1] ?? '') === 'sendasync' && is_int($member['origin'])
                ? ($promiseOrigins[$member['path'] ?? $call['path']][$member['origin']] ?? []) : [];
            $matched = false;
            foreach ($prepared as $source) {
                $promises[] = ['offset' => $source['offset'], 'end_offset' => $source['end_offset'], 'path' => $source['path']];
                $matched = true;
            }
            if ($matched) {
                continue;
            }
            foreach ($site['metadata']['promise_sites'] as $candidate) {
                if ($candidate['end_offset'] === $member['origin']) {
                    // Retain a syntactic inline candidate, without proving its transport.
                    $promises[] = $candidate;

                    continue 2;
                }
            }
            $selected = $this->calls->receiverCandidates($receiver);
            $targets = [...$targets, ...$selected['types']];
            $complete = $complete && ! $selected['limited'] && $selected['types'] !== [];
            if ($selected['limited'] || count($targets) + count($promises) > 128) {
                $this->notice($call, 'Pool member receiver selection exceeded its source budget.', 'catalog_limit');
                $targets = array_slice($targets, 0, 128);
                $promises = array_slice($promises, 0, 128 - count($targets));
                $complete = false;
                break;
            }
        }
        $site['metadata']['targets'] = array_values(array_unique($targets));
        $site['metadata']['promise_sites'] = $promises;
        $site['metadata']['resolved'] = $complete;

        return $site;
    }

    /** @param array<string, mixed> $call
     * @param  array<string, mixed>  $site
     * @param  array<string, array<string, mixed>>  $handlers
     * @return array<string, array<string, mixed>>
     */
    private function callbacks(array $call, array $site, array $handlers = []): array
    {
        foreach ($site['metadata']['callbacks'] as $kind => $callback) {
            unset($handlers[$kind]);
            $element = $callback['id'] !== null ? ($this->index->elements[$callback['id']] ?? null) : null;
            $value = $call['metadata']['saloon_pool_callback_values'][$kind] ?? null;
            if (! $callback['resolved'] && is_array($value)) {
                $returnSources = [];
                $targets = $this->handlerTargets($call, $value, $returnSources);
                if ($targets !== []) {
                    $source = ['id' => $site['id'], 'path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line'], 'callback_return_sources' => $returnSources];
                    $handlers[$kind] = ['ids' => $targets, 'source' => $source];
                    foreach ($targets as $target) {
                        $this->edge($call, $target, 'registers-saloon-pool-callback', ['execution_proven' => false, 'callback_kind' => $kind, 'registration_source' => $source]);
                    }

                    continue;
                }
            }

            if (! $callback['resolved'] || $callback['id'] !== null && ($element === null || $element['kind'] !== 'closure'
                || $element['path'] !== $site['path'] || $element['offset'] < $site['offset']
                || $element['parent'] !== $site['parent']
                || ($element['metadata']['end_offset'] ?? PHP_INT_MAX) > $site['metadata']['end_offset'])) {
                $this->notice($call, 'Pool callback selection is unresolved or lacks a local inline callable.');

                continue;
            }
            if ($element !== null) {
                $source = ['id' => $site['id'], 'path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line']];
                $handlers[$kind] = ['ids' => [$element['id']], 'source' => $source];
                $this->edge($call, $element['id'], 'registers-saloon-pool-callback', ['execution_proven' => false, 'callback_kind' => $kind, 'registration_source' => $source]);
            }
        }

        return $handlers;
    }

    /** @param array<string, mixed> $call
     * @param  array<string, mixed>  $value
     * @param  list<array<string, mixed>>  $returnSources
     *
     * @param-out list<array<string, mixed>> $returnSources
     *
     * @return list<string>
     */
    private function handlerTargets(array $call, array $value, array &$returnSources, int $depth = 0): array
    {
        if ($depth >= 16 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->notice($call, 'Pool callback factory reached its source depth or memory budget.', 'catalog_limit');

            return [];
        }
        $receiver = $value['receiver'];
        if (str_starts_with($receiver, '@return:')) {
            if ($value['factory_call'] === null) {
                return [];
            }
            $selected = ($this->factoryCalls ??= new CatalogCallResolver($this->index))->factoryReturnCandidates($receiver, $value['factory_call'], complete: true);
            if ($selected['limited']) {
                $this->notice($call, 'Pool callback factory selection reached its source budget.', 'catalog_limit');

                return [];
            }
            $targets = [];
            foreach ($selected['values'] as $returned) {
                $binding = $returned['bound_callable'] ?? null;
                $nestedReceiver = $returned['receiver'];
                $nested = ['receiver' => $nestedReceiver, 'exact' => $returned['exact_receiver'],
                    'form' => $returned['callable_form'] ?? 'instance', 'creator' => $binding['creator'] ?? null,
                    'closure' => str_starts_with($nestedReceiver, '@closure:') || str_starts_with($nestedReceiver, '@function:') || ($binding['scope_bound'] ?? false),
                    'binding' => ($binding['late'] ?? false) ? 'static' : ($binding['lexical'] ?? null),
                    'factory_call' => $returned['factory_call'] ?? null];
                $nestedSources = [];
                $chosen = $this->handlerTargets($call, $nested, $nestedSources, $depth + 1);
                if ($chosen !== []) {
                    $targets = [...$targets, ...$chosen];
                    /** @var list<array<string, mixed>> $sources Source spans built by CatalogCallResolver::returnedValues. */
                    $sources = $returned['sources'];
                    $returnSources = [...$returnSources, ...$sources, ...$nestedSources];
                } else {
                    $this->notice($call, 'Pool callback factory has an unresolved return alternative.');
                }
                if (count($targets) > 128 || count($returnSources) > 128) {
                    $this->notice($call, 'Pool callback factory candidates reached their source budget.', 'catalog_limit');
                    $returnSources = array_slice($returnSources, 0, 128);

                    return array_values(array_unique(array_slice($targets, 0, 128)));
                }
            }

            return array_values(array_unique($targets));
        }
        if (str_starts_with($receiver, '@closure:')) {
            $targets = array_values(array_filter($this->index->names[strtolower(substr($receiver, 9))] ?? [],
                fn ($id) => $this->index->elements[$id]['kind'] === 'closure'));
        } else {
            $method = '__invoke';
            $exact = $value['exact'];
            if (str_starts_with($receiver, '@method-callable:')) {
                $descriptor = json_decode(substr($receiver, 17), true);
                if (! is_array($descriptor) || count($descriptor) !== 3) {
                    return [];
                }
                [$receiver, $method, $exact] = $descriptor;
            } elseif (str_starts_with($receiver, '@named-callable:')) {
                return [];
            }
            $creator = $value['creator'] !== null ? ($this->index->elements[$value['creator']]['name'] ?? '') : '';
            $separator = strpos($creator, '::');
            $creator = $separator === false ? $creator : substr($creator, 0, $separator);
            $form = str_starts_with($receiver, '@function:') ? 'function' : $value['form'];
            if ($form === 'bound-class') {
                $scopeTypes = $this->index->namedTypes($creator);
                $receiverTypes = $this->index->namedTypes($receiver);
                $form = $value['closure'] && count($scopeTypes) === 1 && count($receiverTypes) === 1
                    && $this->index->hasContract($scopeTypes[0], $receiver) ? 'instance' : 'static';
            }
            $selected = ($this->factoryCalls ??= new CatalogCallResolver($this->index))->sourceCallableCandidates($receiver, $method, $exact,
                ['creator' => $creator, 'form' => $form, 'binding' => $value['binding']]);
            if ($selected['limited']) {
                $this->notice($call, 'Pool callback selection reached its source budget.', 'catalog_limit');

                return [];
            }
            $targets = $selected['targets'];
        }

        if (count($targets) > 128) {
            $this->notice($call, 'Pool callback targets exceeded their source budget.', 'catalog_limit');
        }

        return array_values(array_filter(array_slice($targets, 0, 128), fn ($id) => ($this->index->elements[$id]['metadata']['return_sites']['complete'] ?? false)));
    }

    /** @param array<string, mixed> $call
     * @param  list<array<string, mixed>>  $states
     * @return list<array<string, mixed>>
     */
    private function boundedStates(array $call, array $states): array
    {
        if (count($states) > 128) {
            $this->notice($call, 'Pool selection exceeded its branch candidate budget.', 'catalog_limit');

            return array_slice($states, 0, 128);
        }

        return $states;
    }

    /** @param array<string, mixed> $call
     * @param  array<string, mixed>  $metadata
     */
    private function edge(array $call, string $to, string $kind, array $metadata): void
    {
        $this->index->addRelation(['from' => $call['from'], 'to' => $to, 'kind' => $kind, 'path' => $call['path'],
            'line' => $call['line'], 'end_line' => $call['end_line'], 'resolution' => 'conditional', 'metadata' => $metadata]);
    }

    /** @param array<string, mixed> $call */
    private function notice(array $call, string $message, string $code = 'saloon_analysis'): void
    {
        $this->index->diagnostics[] = ['code' => $code, 'message' => $message, 'path' => $call['path'], 'line' => $call['line'], 'subject' => $call['from']];
    }
}
