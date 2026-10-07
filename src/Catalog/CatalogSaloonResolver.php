<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Source URL candidates; never creates a sender or executes application selectors. */
final class CatalogSaloonResolver
{
    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    /** @param list<array<string, mixed>>|null $sourceCalls */
    public function resolve(?array $sourceCalls = null): void
    {
        $profile = CatalogPackageVersions::verified($this->index, 'saloonphp/saloon');
        $edges = $sourceCalls ?? $this->index->relations;
        $operations = 0;
        foreach ($edges as $call) {
            $request = $call['metadata']['saloon_request'] ?? null;
            if ($call['kind'] !== 'calls' || ! is_array($request)) {
                continue;
            }
            $receivers = $this->calls->receiverCandidates($call['metadata']['receiver']);
            foreach ($receivers['types'] as $type) {
                $ids = $this->index->namedTypes($type);
                $requestSide = count($ids) === 1 && $this->index->hasContract($ids[0], 'Saloon\Http\Request')
                    && $this->index->hasContract($ids[0], 'Saloon\Traits\Request\HasConnector');
                if (count($ids) !== 1 || ! $requestSide && ! $this->index->hasContract($ids[0], 'Saloon\Http\Connector')) {
                    continue;
                }
                if (++$operations > 4096 || $receivers['limited'] || ImpactExtractor::sourceLimit(0) !== null) {
                    $this->notice($call, 'Saloon composition reached its source budget.', 'catalog_limit');

                    return;
                }
                $shadowed = false;
                foreach (['Saloon\Http\Connector', 'Saloon\Http\Request', 'Saloon\Http\PendingRequest', 'Saloon\Helpers\URLHelper', 'Saloon\Traits\Connector\SendsRequests', 'Saloon\Http\SoloRequest', 'Saloon\Http\Connectors\NullConnector', 'Saloon\Traits\Request\HasConnector'] as $contract) {
                    $shadowed = $shadowed || $this->index->namedTypes($contract) !== [];
                }
                if ($profile === null || $shadowed || ($requestSide ? ! $request['request_side_valid'] : ! $request['valid'] || $request['receiver'] === null)) {
                    $this->notice($call, 'Saloon package contract, request arguments or request type requires inspection.');

                    continue;
                }
                if ($requestSide && is_string($request['mock_receiver'])) {
                    $mocks = $this->calls->receiverCandidates($request['mock_receiver']);
                    $compatible = false;
                    foreach ($mocks['types'] as $mock) {
                        $mockIds = $this->index->namedTypes($mock);
                        $compatible = $compatible || strcasecmp($mock, 'Saloon\Http\Faking\MockClient') === 0
                            || count($mockIds) === 1 && $this->index->hasContract($mockIds[0], 'Saloon\Http\Faking\MockClient');
                    }
                    if (! $compatible || $mocks['limited'] || $this->index->namedTypes('Saloon\Http\Faking\MockClient') !== []) {
                        $this->notice($call, 'Request-side mock argument lacks a compatible source MockClient contract.', $mocks['limited'] ? 'catalog_limit' : 'saloon_analysis');

                        continue;
                    }
                }
                $method = strtolower($call['metadata']['method']);
                $requestTraits = $requestSide ? ['Saloon\Traits\Request\HasConnector'] : [];
                if (! $this->standardPipeline($type, $method, $call, $requestTraits)) {
                    continue;
                }
                $requests = $requestSide ? ['types' => [$type], 'limited' => false]
                    : $this->calls->receiverCandidates($request['receiver']);
                if ($requests['limited']) {
                    $this->notice($call, 'Saloon request selection reached its source budget.', 'catalog_limit');

                    continue;
                }
                $bases = $requestSide ? $this->requestConnectors($type, $call, $profile)
                    : [['connector' => $ids[0], 'base' => $this->selector($type, 'resolveBaseUrl', $call), 'sources' => []]];
                foreach ($bases as $selection) {
                    $base = $selection['base'];
                    foreach ($requests['types'] as $requestType) {
                        $requestIds = $this->index->namedTypes($requestType);
                        if (count($requestIds) !== 1 || ! $this->index->hasContract($requestIds[0], 'Saloon\Http\Request')) {
                            $this->notice($call, 'Saloon request lacks an unambiguous source Request contract.');

                            continue;
                        }
                        $endpoint = $this->selector($requestType, 'resolveEndpoint', $call, $requestTraits);
                        if ($base === null || $endpoint === null) {
                            continue;
                        }
                        $absoluteOverride = trim($base['value'], '/ ') !== '' && filter_var(str_replace('_', '-', $endpoint['value']), FILTER_VALIDATE_URL) !== false;
                        $override = $absoluteOverride ? $this->urlOverride($requestIds[0], $selection['connector'], $call) : ['value' => false, 'sources' => []];
                        if ($override === null) {
                            continue;
                        }
                        $url = $this->join($base['value'], $endpoint['value'], $override['value']);
                        if ($url === null) {
                            $this->notice($call, 'Saloon URL is dynamic, invalid or requires absolute-endpoint override selection.');

                            continue;
                        }
                        $metadata = ['package' => 'saloonphp/saloon', 'version' => $profile['version'], 'package_sources' => $profile['sources'],
                            'source_call' => ['path' => $call['path'], 'offset' => $call['metadata']['offset'], 'end_offset' => $call['metadata']['end_offset'] ?? $call['metadata']['offset']],
                            'connector' => $selection['connector'], 'request' => $requestIds[0], 'mode' => $method, 'request_side' => $requestSide,
                            'asynchronous' => $method === 'sendasync', 'execution_proven' => false, 'traffic_proven' => false,
                            'selector_sources' => [...$selection['sources'], $base['source'], $endpoint['source'], ...$override['sources']],
                            'absolute_endpoint_override' => $absoluteOverride,
                            'conditions' => ['Source selector candidates require the standard Saloon request pipeline and runtime request selection.',
                                ...($absoluteOverride ? ['The selected source URL override default must remain active; runtime assignments may replace it.'] : []),
                                ...($method === 'sendasync' ? ['The asynchronous task must be consumed.'] : []),
                                'Mocks, boot hooks and middleware may change or replace transport.',
                                ...($requestSide ? ['The source connector selection must remain active; setConnector may replace it at runtime.'] : [])]];
                        if (isset($call['metadata']['saloon_pool'])) {
                            $metadata['pool_source'] = $call['metadata']['saloon_pool'];
                            $metadata['conditions'][] = 'The source pool member list must remain selected; setRequests may replace it at runtime.';
                        }
                        $prepared = $method === 'creatependingrequest';
                        $id = $this->resource('external-endpoint', $url['endpoint'], $call);
                        $service = $this->resource('external-service', $url['origin'], $call);
                        $this->edge($call['from'], $id, $prepared ? 'references-external-endpoint' : 'uses-external-service', $call, $metadata);
                        $this->edge($id, $service, 'endpoint-of', $call, ['execution_proven' => false]);
                        foreach ($metadata['selector_sources'] as $source) {
                            if (isset($source['id'])) {
                                $this->edge($call['from'], $source['id'], ($source['structural'] ?? false) ? (($source['selector'] ?? '') === 'allowBaseUrlOverride' ? 'references-saloon-url-override' : 'references-saloon-connector') : 'invokes-saloon-selector', $call, $metadata);
                            }
                        }
                    }
                }
                if ($requests['types'] === []) {
                    $this->notice($call, 'Saloon request receiver is unresolved.');
                }
            }
        }
    }

    /** @param array<string, mixed> $call
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     * @return list<array<string, mixed>>
     */
    private function requestConnectors(string $type, array $call, array $profile): array
    {
        $selected = $this->calls->sourceMethodCandidate($type, 'resolveConnector', knownTraits: ['Saloon\Traits\Request\HasConnector']);
        if ($selected['unresolved'] || $selected['limited']) {
            $this->notice($call, 'Request connector selection is ambiguous or exceeds its source budget.', $selected['limited'] ? 'catalog_limit' : 'saloon_analysis');

            return [];
        }
        $method = $selected['method'];
        if ($method === null) {
            $ids = $this->index->namedTypes($type);
            if (count($ids) === 1 && $this->index->hasContract($ids[0], 'Saloon\Http\SoloRequest')) {
                return [['connector' => 'php:Saloon\Http\Connectors\NullConnector', 'sources' => [],
                    'base' => ['value' => '', 'source' => ['selector' => 'resolveBaseUrl', 'package' => 'saloonphp/saloon',
                        'version' => $profile['version'], 'package_sources' => $profile['sources'], 'contract' => 'Saloon\Http\Connectors\NullConnector']]]];
            }
            $property = count($ids) === 1 ? $this->sourceProperty($ids[0], 'connector', 'saloon_connector') : null;
            if ($property['limited'] ?? false) {
                $this->notice($call, 'Request connector property selection reached its source depth budget.', 'catalog_limit');

                return [];
            }
            if ($property !== null && ($property['metadata']['saloon_connector']['resolved'] ?? false)
                && ! ($property['metadata']['static'] ?? false)
                && (($property['metadata']['visibility'] ?? null) !== 'private' || $property['scope'] === $this->hasConnectorScope($ids[0]))) {
                $connector = $property['metadata']['saloon_connector']['type'];
                $connectors = $this->index->namedTypes($connector);
                if (count($connectors) === 1 && $this->index->hasContract($connectors[0], 'Saloon\Http\Connector')
                    && $this->standardPipeline($connector, strtolower($call['metadata']['method']), $call)) {
                    return [['connector' => $connectors[0], 'base' => $this->selector($connector, 'resolveBaseUrl', $call),
                        'sources' => [['id' => $property['id'], 'selector' => 'connector-property', 'path' => $property['path'],
                            'line' => $property['line'], 'end_line' => $property['end_line'], 'structural' => true]]]];
                }
            }
            $this->notice($call, 'Request connector property or runtime connector selection requires inspection.');

            return [];
        }
        if (($method['metadata']['visibility'] ?? null) === 'private' || ($method['metadata']['static'] ?? false)
            || ($method['metadata']['abstract'] ?? false) || ! ($method['metadata']['call_parameters']['complete'] ?? false)
            || array_filter($method['metadata']['call_parameters']['parameters'] ?? [], fn ($parameter) => $parameter['required'])) {
            $this->notice($call, 'Request connector factory is inaccessible or has an unsupported signature.');

            return [];
        }
        $element = $this->index->elements[$method['id']];
        $source = ['id' => $method['id'], 'selector' => 'resolveConnector', 'path' => $element['path'], 'line' => $element['line'], 'end_line' => $element['end_line']];
        $selections = [];
        foreach ($this->index->out[$method['id']] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if ($edge['kind'] !== 'returns-value') {
                continue;
            }
            $candidates = $this->calls->receiverCandidates($edge['metadata']['receiver']);
            if ($candidates['limited']) {
                $this->notice($call, 'Returned request connector reached its source budget.', 'catalog_limit');

                return [];
            }
            foreach ($candidates['types'] as $connector) {
                $ids = $this->index->namedTypes($connector);
                if (count($ids) !== 1 || ! $this->index->hasContract($ids[0], 'Saloon\Http\Connector')
                    || ! $this->standardPipeline($connector, strtolower($call['metadata']['method']), $call)) {
                    continue;
                }
                $selections[$ids[0]] = ['connector' => $ids[0], 'base' => $this->selector($connector, 'resolveBaseUrl', $call), 'sources' => [$source]];
            }
        }
        if ($selections === []) {
            $this->notice($call, 'Request connector factory has no source-resolved connector candidate.');
        }

        return array_values($selections);
    }

    /** @param array<string, bool> $seen
     * @return array<string, mixed>|null
     */
    private function sourceProperty(string $id, string $name, string $descriptor, array $seen = []): ?array
    {
        if (isset($seen[$id]) || count($seen) >= 32) {
            return ['metadata' => [], 'limited' => true];
        }
        $seen[$id] = true;
        $own = $traits = $parents = [];
        foreach ($this->index->out[$id] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            $target = $this->index->elements[$edge['to']] ?? null;
            if ($edge['kind'] === 'contains' && $target !== null && $target['kind'] === 'property' && str_ends_with($target['name'], '::$'.$name)) {
                $own[] = $target;
            } elseif ($edge['kind'] === 'uses-trait') {
                if ($target === null && strcasecmp($edge['metadata']['target_name'] ?? '', 'Saloon\Traits\Request\HasConnector') !== 0) {
                    return ['metadata' => []];
                }
                if ($target !== null && ($property = $this->sourceProperty($target['id'], $name, $descriptor, $seen)) !== null) {
                    $traits[] = $property;
                }
            } elseif ($edge['kind'] === 'extends' && $target !== null) {
                $parents[] = $target['id'];
            }
        }
        $properties = [...$own, ...$traits];
        if ($properties !== []) {
            if (array_filter($properties, fn ($property) => $property['limited'] ?? false)) {
                return ['metadata' => [], 'limited' => true];
            }
            $first = $properties[0];
            foreach ($properties as $property) {
                foreach ([$descriptor, 'types', 'visibility', 'static'] as $field) {
                    if (($property['metadata'][$field] ?? null) !== ($first['metadata'][$field] ?? null)) {
                        return ['metadata' => []];
                    }
                }
            }

            return [...$first, 'scope' => $id];
        }
        foreach ($parents as $parent) {
            if (($property = $this->sourceProperty($parent, $name, $descriptor, $seen)) !== null) {
                return $property;
            }
        }

        return null;
    }

    /** @param array<string, bool> $seen */
    private function hasConnectorScope(string $id, array $seen = []): ?string
    {
        if (isset($seen[$id]) || count($seen) >= 32) {
            return null;
        }
        $seen[$id] = true;
        foreach ($this->index->out[$id] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if ($edge['kind'] === 'uses-trait' && strcasecmp($edge['metadata']['target_name'] ?? '', 'Saloon\Traits\Request\HasConnector') === 0) {
                return $id;
            }
            if ($edge['kind'] === 'uses-trait' && isset($this->index->elements[$edge['to']]) && $this->hasConnectorScope($edge['to'], $seen) !== null) {
                return $id;
            }
        }
        foreach ($this->index->out[$id] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if ($edge['kind'] === 'extends' && isset($this->index->elements[$edge['to']]) && ($scope = $this->hasConnectorScope($edge['to'], $seen)) !== null) {
                return $scope;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $call
     * @return array{value: bool, sources: list<array<string, mixed>>}|null
     */
    private function urlOverride(string $request, string $connector, array $call): ?array
    {
        $requestFlag = $this->overrideFlag($request, $call, request: true);
        if ($requestFlag === null) {
            return null;
        }
        if (is_bool($requestFlag['value'])) {
            return $requestFlag;
        }
        foreach (['GetAccessTokenRequest', 'GetRefreshTokenRequest', 'GetUserRequest', 'GetClientCredentialsTokenRequest', 'GetClientCredentialsTokenBasicAuthRequest'] as $oauthRequest) {
            if ($this->index->hasContract($request, 'Saloon\Http\OAuth2\\'.$oauthRequest)) {
                $this->notice($call, 'OAuth request absolute endpoint override depends on OAuth configuration.');

                return null;
            }
        }
        $connectorFlag = $this->overrideFlag($connector, $call, request: false);
        if ($connectorFlag === null || ! is_bool($connectorFlag['value'])) {
            $this->notice($call, 'Connector absolute endpoint override is unresolved.');

            return null;
        }

        return ['value' => $connectorFlag['value'], 'sources' => [...$requestFlag['sources'], ...$connectorFlag['sources']]];
    }

    /** @param array<string, mixed> $call
     * @return array{value: ?bool, sources: list<array<string, mixed>>}|null
     */
    private function overrideFlag(string $id, array $call, bool $request): ?array
    {
        $property = $this->sourceProperty($id, 'allowBaseUrlOverride', 'saloon_url_override');
        if ($property === null) {
            $solo = $request && $this->index->hasContract($id, 'Saloon\Http\SoloRequest');

            return ['value' => $request ? ($solo ? true : null) : false, 'sources' => [
                ['selector' => 'allowBaseUrlOverride', 'package' => 'saloonphp/saloon',
                    'contract' => $request ? ($solo ? 'Saloon\Http\SoloRequest' : 'Saloon\Http\Request') : 'Saloon\Http\Connector', 'source_default' => true],
            ]];
        }
        $descriptor = $property['metadata']['saloon_url_override'] ?? null;
        if ($descriptor === null || ! $descriptor['resolved'] || ($property['metadata']['static'] ?? false)
            || ($property['metadata']['visibility'] ?? null) !== 'public') {
            $this->notice($call, 'Source absolute endpoint override flag is dynamic, conflicting or inaccessible.', ($property['limited'] ?? false) ? 'catalog_limit' : 'saloon_analysis');

            return null;
        }

        return ['value' => $descriptor['value'], 'sources' => [['id' => $property['id'], 'selector' => 'allowBaseUrlOverride',
            'path' => $property['path'], 'line' => $property['line'], 'end_line' => $property['end_line'], 'structural' => true]]];
    }

    /** @param array<string, mixed> $call
     * @param  list<string>  $knownTraits
     */
    private function standardPipeline(string $type, string $method, array $call, array $knownTraits = []): bool
    {
        foreach (array_unique([$method, 'createPendingRequest', 'sender', ...($knownTraits === [] ? [] : ['connector']),
            ...($method === 'sendandretry' ? ['send'] : [])]) as $hook) {
            $selected = $this->calls->sourceMethodCandidate($type, $hook, knownTraits: $knownTraits);
            if ($selected['method'] !== null || $selected['unresolved'] || $selected['limited']) {
                $this->notice($call, 'Source Saloon pipeline override or unresolved method selection requires inspection.', $selected['limited'] ? 'catalog_limit' : 'saloon_analysis');

                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $call
     * @param  list<string>  $knownTraits
     * @return array{value: string, source: array<string, mixed>}|null
     */
    private function selector(string $type, string $name, array $call, array $knownTraits = []): ?array
    {
        $selected = $this->calls->sourceMethodCandidate($type, $name, knownTraits: $knownTraits);
        $member = $selected['method'];
        if ($member === null || $selected['unresolved'] || $selected['limited'] || ($member['metadata']['visibility'] ?? null) !== 'public'
            || ($member['metadata']['static'] ?? false) || ($member['metadata']['abstract'] ?? false)
            || ! ($member['metadata']['call_parameters']['complete'] ?? false)
            || array_filter($member['metadata']['call_parameters']['parameters'] ?? [], fn ($parameter) => $parameter['required'])) {
            $this->notice($call, 'Saloon URL selector is missing, inaccessible, dynamic or ambiguous.', $selected['limited'] ? 'catalog_limit' : 'saloon_analysis');

            return null;
        }
        $element = $this->index->elements[$member['id']];
        $descriptor = $member['metadata']['source_return'] ?? null;
        try {
            $source = $this->index->sourceContents !== null ? ($this->index->sourceContents)($element['path']) : null;
        } catch (\Throwable) {
            $source = null;
        }
        if ($descriptor === null || ! is_string($source) || strlen($source) > 2 * 1024 * 1024) {
            $this->notice($call, 'Saloon selected source return is unavailable or exceeds its source budget.');

            return null;
        }
        if (! hash_equals($descriptor['hash'], hash('sha256', $source))) {
            $this->notice($call, 'Saloon selected source changed after extraction.', 'changed_inputs');

            return null;
        }
        $value = CatalogSourceReturn::decode($descriptor['value'], $source);
        if (! is_string($value) || strlen($value) > 500) {
            $this->notice($call, 'Saloon selected URL return is unresolved.');

            return null;
        }

        return ['value' => $value, 'source' => ['id' => $member['id'], 'selector' => $name,
            'path' => $element['path'], 'line' => $element['line'], 'end_line' => $element['end_line']]];
    }

    /** @return array{endpoint: string, origin: string}|null */
    private function join(string $base, string $endpoint, bool $allowOverride = false): ?array
    {
        if (trim($base, '/ ') !== '' && filter_var(str_replace('_', '-', $endpoint), FILTER_VALIDATE_URL) !== false) {
            if (! $allowOverride) {
                return null;
            }
            $base = '';
        }
        $url = trim($base, '/ ') === '' ? $endpoint : rtrim($base, '/ ').($endpoint !== '' && $endpoint !== '/' ? '/' : '').($endpoint === '/' ? '/' : ltrim($endpoint, '/ '));
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $origin = strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');

        return ['endpoint' => $origin.($parts['path'] ?? '/'), 'origin' => $origin];
    }

    /** @param array<string, mixed> $call */
    private function resource(string $kind, string $name, array $call): string
    {
        $id = CatalogElement::resourceIdentity($kind, $name);
        $source = ['path' => $call['path'], 'line' => $call['line'], 'end_line' => $call['end_line']];
        if (! isset($this->index->elements[$id])) {
            $element = new CatalogElement($id, $name, $kind, $call['line'], $call['end_line'], $call['metadata']['offset'], metadata: ['logical_resource' => true]);
            $this->index->elements[$id] = [...$element->toArray(), 'path' => $call['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => [$source]];
            $this->index->names[strtolower($name)][] = $id;
        } elseif (! in_array($source, $this->index->elements[$id]['sources'], true)) {
            $this->index->elements[$id]['sources'][] = $source;
        }

        return $id;
    }

    /** @param array<string, mixed> $call
     * @param  array<string, mixed>  $metadata
     */
    private function edge(string $from, string $to, string $kind, array $call, array $metadata): void
    {
        $this->index->addRelation(['from' => $from, 'to' => $to, 'kind' => $kind, 'path' => $call['path'],
            'line' => $call['line'], 'end_line' => $call['end_line'], 'resolution' => 'conditional', 'metadata' => $metadata]);
    }

    /** @param array<string, mixed> $call */
    private function notice(array $call, string $message, string $code = 'saloon_analysis'): void
    {
        $this->index->diagnostics[] = ['code' => $code, 'message' => $message, 'path' => $call['path'], 'line' => $call['line'], 'subject' => $call['from']];
    }
}
