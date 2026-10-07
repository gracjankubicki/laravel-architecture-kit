<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Getter selection depends on the provider's runtime configuration. */
final class CatalogAiProviderGetterResolver
{
    /** @var array<string, list<string>>|null */
    private ?array $sdkDescendants = null;

    public function __construct(private readonly CatalogIndex $index) {}

    /** @param array{version: string, sources: list<array<string, mixed>>} $profile
     * @param  array<string, array<int, array<string, mixed>>>  $sites
     * @param  array<string, list<string>>  $inventory
     */
    public function resolve(array $profile, array $sites, array $inventory): void
    {
        $selector = new CatalogCallResolver($this->index);
        $setterReturns = new CatalogAiProviderSetterReturns;
        $constructed = [];
        foreach ($this->index->elements as $element) {
            $setterReturns->add($element);
            if ($element['kind'] === 'ai-provider-gateway-binding' && ($element['metadata']['method'] ?? null) === '__construct'
                && is_int($element['metadata']['provider_instance_origin'] ?? null)) {
                $constructed[$element['path']][$element['parent']][$element['metadata']['provider_instance_origin']][] = $element['id'];
            }
        }
        $seen = [];
        $operations = 0;
        $selections = 0;
        foreach ($this->index->relations as $call) {
            $method = strtolower($call['metadata']['method'] ?? '');
            if ($call['kind'] !== 'calls' || ! in_array($method, CatalogAiGateways::GETTERS, true)) {
                continue;
            }
            $key = serialize([$call['path'], $call['from'], $call['metadata']['offset'] ?? null, $call['metadata']['receiver'], $call['metadata']['form'], $call['metadata']['exact_receiver']]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (++$operations > 4096 || $operations % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
                $this->notice($call, 'AI provider getter composition exceeded its source budget.', 'catalog_limit');

                return;
            }
            $receivers = $selector->receiverCandidates($call['metadata']['receiver']);
            $fluent = $setterReturns->candidates($call);
            foreach ($fluent as $binding) {
                $receivers['types'][] = $binding['metadata']['provider_type'];
            }
            $receivers['types'] = array_values(array_unique($receivers['types']));
            $receivers['limited'] = $receivers['limited'] || count($receivers['types']) > 128;
            if ($receivers['limited']) {
                $this->notice($call, 'AI provider getter receiver exceeded its source budget.', 'catalog_limit');

                continue;
            }
            $channel = ucfirst(substr($method, 0, -7));
            $providerContract = 'Laravel\\Ai\\Contracts\\Providers\\'.$channel.'Provider';
            $types = $receivers['types'];
            $contractReceivers = [];
            if (! $call['metadata']['exact_receiver']) {
                foreach ($receivers['types'] as $baseType) {
                    if (strcasecmp($baseType, $providerContract) === 0) {
                        foreach ($this->descendants($providerContract, $profile['version'], $call) as $descendant) {
                            $getter = $selector->sourceMethodCandidate($descendant, $method, knownTraits: array_keys($inventory), knownTraitMethods: $inventory);
                            $descendantIds = $this->index->namedTypes($descendant);
                            $trait = 'Laravel\\Ai\\Providers\\Concerns\\Has'.$channel.'Gateway';
                            if (! $getter['limited'] && ! $getter['unresolved'] && $getter['method'] === null
                                && (CatalogAiProviderTypes::standardSetter($this->index, $descendant, $channel, $profile['version'])
                                    || count($descendantIds) === 1 && $this->index->namedTypes($trait) === [] && $this->index->hasContract($descendantIds[0], $trait))) {
                                $types[] = $descendant;
                                $contractReceivers[$descendant] = $baseType;
                            }
                        }
                    }
                    $default = CatalogAiProviderTypes::defaultGateway($this->index, $baseType, $channel, $profile['version']);
                    if ($default === null || $default['helper'] === null) {
                        continue;
                    }
                    $baseIds = $this->index->namedTypes($baseType);
                    foreach (CatalogAiProviderTypes::ancestors($this->index, $baseType, $profile['version']) as $sdk) {
                        foreach ($this->descendants($sdk, $profile['version'], $call) as $descendant) {
                            $descendantIds = $this->index->namedTypes($descendant);
                            if (count($descendantIds) !== 1 || count($baseIds) === 1 && ! $this->index->hasContract($descendantIds[0], $baseType)) {
                                continue;
                            }
                            $helper = $selector->sourceMethodCandidate($descendant, $default['helper'], knownTraits: array_keys($inventory), knownTraitMethods: $inventory);
                            $getter = $selector->sourceMethodCandidate($descendant, $method, knownTraits: array_keys($inventory), knownTraitMethods: $inventory);
                            if (($helper['method'] !== null || $helper['unresolved'] || $helper['limited']) && $getter['method'] === null) {
                                $types[] = $descendant;
                            }
                        }
                    }
                }
            }
            foreach (array_unique($types) as $type) {
                if (! CatalogAiProviderTypes::matches($this->index, $type, $channel, $profile['version'])) {
                    continue;
                }
                $contract = 'Laravel\\Ai\\Contracts\\Gateway\\'.($channel === 'Text' && version_compare($profile['version'], '0.9.0.0', '>=') ? 'StepText' : $channel).'Gateway';
                $providerContract = 'Laravel\\Ai\\Contracts\\Providers\\'.$channel.'Provider';
                $site = $sites[$call['path']][$call['metadata']['end_offset'] ?? -1] ?? null;
                if ($this->index->namedTypes($contract) !== [] || $this->index->namedTypes($providerContract) !== []
                    || $site === null || $site['metadata']['limited'] || $call['metadata']['form'] !== 'instance') {
                    $this->notice($call, 'AI provider getter arguments, contracts or call form require inspection.');

                    continue;
                }
                $ids = $this->index->namedTypes($type);
                $configuration = $setterReturns->configuration($call, $type);
                $configured = $setterReturns->gatewayBindings($configuration, 'use'.$method);
                if ($configuration['limited']) {
                    $this->notice($call, 'AI provider getter configuration history exceeded its source budget.', 'catalog_limit');
                }
                $trait = 'Laravel\\Ai\\Providers\\Concerns\\Has'.$channel.'Gateway';
                $standard = CatalogAiProviderTypes::standardSetter($this->index, $type, $channel, $profile['version']);
                $targets = [];
                if (count($ids) === 1) {
                    $implementation = $selector->sourceMethodCandidate($type, $method, knownTraits: array_keys($inventory), knownTraitMethods: $inventory);
                    $standard = ! $implementation['limited'] && ! $implementation['unresolved'] && $implementation['method'] === null
                        && ($standard || $this->index->hasContract($ids[0], $trait) && $this->index->namedTypes($trait) === []);
                    $chosen = $selector->sourceCallableCandidates($type, $method, $call['metadata']['exact_receiver'], ['creator' => '', 'form' => 'instance', 'binding' => null]);
                    if ($chosen['limited']) {
                        $this->notice($call, 'AI provider getter source dispatch exceeded its source budget.', 'catalog_limit');
                    } else {
                        foreach ($chosen['targets'] as $candidate) {
                            $signature = $this->index->elements[$candidate]['metadata']['call_parameters'] ?? null;
                            if (CatalogAiGateways::sourceArguments($site, $signature)
                                && ! ($this->index->elements[$candidate]['metadata']['static'] ?? false)) {
                                $targets[] = $candidate;
                            }
                        }
                    }
                } elseif ($ids === [] && ! $call['metadata']['exact_receiver']) {
                    foreach ($this->descendants($type, $profile['version'], $call) as $descendant) {
                        $chosen = $selector->sourceCallableCandidates($descendant, $method, true, ['creator' => '', 'form' => 'instance', 'binding' => null]);
                        if ($chosen['limited']) {
                            $this->notice($call, 'AI SDK provider source subtype dispatch exceeded its source budget.', 'catalog_limit');

                            continue;
                        }
                        foreach ($chosen['targets'] as $candidate) {
                            if (CatalogAiGateways::sourceArguments($site, $this->index->elements[$candidate]['metadata']['call_parameters'] ?? null)
                                && ! ($this->index->elements[$candidate]['metadata']['static'] ?? false)) {
                                $targets[] = $candidate;
                            }
                        }
                    }
                }
                $standard = $standard && $site['metadata']['arguments'] === [];
                $branches = $standard ? [['standard' => true, 'target' => null]] : [];
                foreach (array_unique($targets) as $candidate) {
                    $branches[] = ['standard' => false, 'target' => $candidate];
                }
                if ($branches === []) {
                    $this->notice($call, 'AI provider getter implementation or argument binding requires inspection.');

                    continue;
                }
                foreach ($branches as $branch) {
                    $standard = $branch['standard'];
                    $target = $branch['target'];
                    if (++$selections > 4096 || $selections % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
                        $this->notice($call, 'AI provider getter selections exceeded their source budget.', 'catalog_limit');

                        return;
                    }
                    $default = $standard ? CatalogAiProviderTypes::defaultGateway($this->index, $type, $channel, $profile['version']) : null;
                    $defaultUnresolved = false;
                    $helperTarget = null;
                    if ($default !== null && $default['helper'] !== null && count($ids) === 1) {
                        $helper = $selector->sourceMethodCandidate($type, $default['helper'], knownTraits: array_keys($inventory), knownTraitMethods: $inventory);
                        if ($helper['limited'] || $helper['unresolved'] || $helper['method'] !== null) {
                            $implementation = $helper['method'];
                            $signature = $implementation['metadata']['call_parameters'] ?? null;
                            if (! $helper['limited'] && ! $helper['unresolved'] && $implementation !== null
                                && in_array($implementation['metadata']['visibility'] ?? null, ['public', 'protected'], true)
                                && ! ($implementation['metadata']['static'] ?? false) && ! ($implementation['metadata']['abstract'] ?? false)
                                && $signature !== null && $signature['complete'] && array_filter($signature['parameters'], fn ($parameter) => $parameter['required']) === []) {
                                $helperTarget = $implementation['id'];
                            }
                            $default = null;
                            $defaultUnresolved = true;
                            $this->notice($call, 'AI provider default gateway helper is source-overridden or unresolved.');
                        }
                    }
                    if ($default !== null && $default['source_shadowed']) {
                        $default = null;
                        $defaultUnresolved = true;
                        $this->notice($call, 'AI provider default gateway class is source-shadowed.');
                    }
                    $metadata = ['package' => 'laravel/ai', 'version' => $profile['version'], 'method' => $method, 'provider_type' => $type,
                        'provider_contract_runtime_implementation_required' => isset($contractReceivers[$type]) || strcasecmp($type, $providerContract) === 0,
                        'receiver_provider_contract' => $contractReceivers[$type] ?? (strcasecmp($type, $providerContract) === 0 ? $type : null),
                        'runtime_provider_type_required' => $type,
                        'gateway_contract' => $contract, 'operation_stage' => 'gateway-selection-candidate',
                        'getter_end_offset' => $call['metadata']['end_offset'] ?? null,
                        'provider_instance_origin' => $call['metadata']['receiver_instance_origin'] ?? ($fluent[0]['metadata']['provider_instance_origin'] ?? null),
                        'source_getter_target' => $target,
                        'channel_configuration_history_limited' => $configuration['limited'],
                        'runtime_argument_binding_required' => true, 'source_parameter_names_verified' => $target !== null,
                        'sdk_provider_ancestors' => CatalogAiProviderTypes::ancestors($this->index, $type, $profile['version']),
                        'runtime_standard_provider_required' => $standard, 'source_method_body_controls_selection' => ! $standard,
                        'configured_gateway_reuse_possible' => $standard, 'runtime_gateway_configuration_required' => $standard,
                        'default_gateway_candidate' => $default, 'default_gateway_resolution_required' => $defaultUnresolved,
                        'default_creation_requires_unconfigured_channel' => $default !== null || $helperTarget !== null,
                        'source_method_body_controls_default_gateway' => $helperTarget !== null,
                        'execution_proven' => false, 'provider_traffic_proven' => false];
                    $id = CatalogElement::identity($call['path'], 'ai-provider-gateway-selection', $type.':'.$method.':'.($target ?? 'sdk'), $site['offset']);
                    $element = new CatalogElement($id, 'AI provider '.$type.'::'.$method, 'ai-provider-gateway-selection', $site['line'], $site['end_line'], $site['offset'], $call['from'], metadata: $metadata);
                    $this->index->elements[$id] = [...$element->toArray(), 'path' => $call['path'], 'knowledge' => 'static', 'role_evidence' => [],
                        'sources' => [['path' => $call['path'], 'line' => $call['line'], 'end_line' => $call['end_line']]]];
                    $this->index->names[strtolower($element->name)][] = $id;
                    $this->edge($call['from'], $id, 'selects-ai-provider-gateway', $call, $metadata);
                    if ($standard) {
                        foreach ($configured as $binding) {
                            if (strcasecmp($binding['metadata']['provider_type'], $type) === 0
                                && strtolower($binding['metadata']['method']) === 'use'.$method) {
                                $this->edge($id, $binding['id'], 'uses-ai-provider-setter-gateway', $call, [
                                    ...$metadata, 'gateway_type' => $binding['metadata']['gateway_type'],
                                    'setter_return_success_required' => true, 'channel_configuration_unchanged_required' => true,
                                    'gateway_selection_proven' => false,
                                ]);
                            }
                        }
                    }
                    // Verified Has*Gateway getters read the aggregate only when the channel has no override.
                    // File/Store build separate defaults; Reranking never reads the aggregate gateway.
                    if ($standard && in_array($channel, ['Audio', 'Embedding', 'Image', 'Text', 'Transcription'], true)
                        && $configured === [] && ! $configuration['limited']
                        && CatalogAiProviderTypes::inheritedGatewayConstructor($this->index, $type, $profile['version'])) {
                        foreach ($constructed[$call['path']][$call['from']][$metadata['provider_instance_origin'] ?? -1] ?? [] as $bindingId) {
                            $binding = $this->index->elements[$bindingId];
                            if (strcasecmp($binding['metadata']['provider_type'], $type) !== 0) {
                                continue;
                            }
                            $this->edge($id, $bindingId, 'uses-ai-provider-constructor-gateway', $call, [
                                ...$metadata, 'gateway_type' => $binding['metadata']['gateway_type'],
                                'constructor_return_success_required' => true, 'channel_override_absent_required' => true,
                                'gateway_selection_proven' => false,
                            ]);
                        }
                    }
                    if ($target !== null) {
                        $this->edge($id, $target, 'invokes-ai-provider-getter', $call, $metadata);
                    }
                    if ($helperTarget !== null) {
                        $this->edge($id, $helperTarget, 'invokes-ai-provider-gateway-factory', $call, $metadata);
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $call
     * @return list<string>
     */
    private function descendants(string $sdk, string $version, array $call): array
    {
        if ($this->sdkDescendants === null) {
            $this->sdkDescendants = [];
            $visits = 0;
            foreach ($this->index->elements as $element) {
                if ($element['kind'] !== 'class') {
                    continue;
                }
                if (++$visits % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
                    $this->notice($call, 'AI provider source subtype index exceeded its memory budget.', 'catalog_limit');

                    break;
                }
                $sdkChannels = [];
                foreach (CatalogAiProviderTypes::ancestors($this->index, $element['name'], $version) as $ancestor) {
                    $this->sdkDescendants[strtolower($ancestor)][] = $element['name'];
                    $sdkChannels = [...$sdkChannels, ...CatalogAiProviderTypes::sdkChannels($ancestor, $version)];
                }
                foreach (['Audio', 'Embedding', 'File', 'Image', 'Reranking', 'Store', 'Text', 'Transcription'] as $channel) {
                    $contract = 'Laravel\\Ai\\Contracts\\Providers\\'.$channel.'Provider';
                    if (in_array($channel, $sdkChannels, true) || $this->index->hasContract($element['id'], $contract)) {
                        $this->sdkDescendants[strtolower($contract)][] = $element['name'];
                    }
                }
            }
        }
        $types = $this->sdkDescendants[strtolower($sdk)] ?? [];
        if (count($types) > 1000) {
            $this->notice($call, 'AI provider source subtype candidates exceeded their 1000-class budget.', 'catalog_limit');
        }

        return array_slice($types, 0, 1000);
    }

    /** @param array<string, mixed> $call */
    private function notice(array $call, string $message, string $code = 'package_ai_analysis'): void
    {
        $this->index->diagnostics[] = ['path' => $call['path'], ...(new CatalogDiagnostic($code, $message, $call['line'], $call['from']))->toArray()];
    }

    /** @param array<string, mixed> $call
     * @param  array<string, mixed>  $metadata
     */
    private function edge(string $from, string $to, string $kind, array $call, array $metadata): void
    {
        $this->index->addRelation(['from' => $from, 'to' => $to, 'kind' => $kind, 'path' => $call['path'], 'line' => $call['line'], 'end_line' => $call['end_line'],
            'knowledge' => 'static', 'resolution' => 'conditional', 'metadata' => $metadata]);
    }
}
