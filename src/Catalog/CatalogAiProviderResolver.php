<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** A gateway setter is configuration evidence, not a provider request. */
final class CatalogAiProviderResolver
{
    public function __construct(private readonly CatalogIndex $index) {}

    public function resolve(): void
    {
        $profile = CatalogPackageVersions::verified($this->index, 'laravel/ai');
        if ($profile === null) {
            return;
        }
        $sites = [];
        foreach ($this->index->elements as $element) {
            if ($element['kind'] === 'ai-gateway-call-site') {
                $sites[$element['path']][$element['metadata']['end_offset']] = $element;
            }
        }
        $receivers = new CatalogCallResolver($this->index);
        $methods = new CatalogCallResolver($this->index);
        $factories = new CatalogCallResolver($this->index);
        $inventory = CatalogAiProviderTypes::gatewayTraits($profile['version']);
        $this->constructors($profile, $receivers, $methods, $factories, $inventory);
        $setterReturns = new CatalogAiProviderSetterReturns;
        $operations = 0;
        $bindings = 0;
        $seen = [];
        $calls = array_values(array_filter($this->index->relations, fn ($call) => $call['kind'] === 'calls' && in_array(strtolower($call['metadata']['method'] ?? ''), CatalogAiGateways::SETTERS, true)));
        usort($calls, fn ($left, $right) => [$left['path'], $left['from'], $left['metadata']['end_offset'] ?? 0] <=> [$right['path'], $right['from'], $right['metadata']['end_offset'] ?? 0]);
        foreach ($calls as $call) {
            $method = strtolower($call['metadata']['method'] ?? '');
            if ($call['kind'] !== 'calls' || ! in_array($method, CatalogAiGateways::SETTERS, true)) {
                continue;
            }
            $key = serialize([$call['path'], $call['from'], $call['metadata']['offset'] ?? null, $call['metadata']['receiver'],
                $call['metadata']['form'], $call['metadata']['exact_receiver']]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (++$operations > 4096 || $operations % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
                $this->notice($call, 'AI provider gateway composition exceeded its source budget.', 'catalog_limit');

                return;
            }
            $channel = ucfirst(substr($method, 3, -7));
            $provider = 'Laravel\\Ai\\Contracts\\Providers\\'.$channel.'Provider';
            $gateway = 'Laravel\\Ai\\Contracts\\Gateway\\'.($channel === 'Text' && version_compare($profile['version'], '0.9.0.0', '>=') ? 'StepText' : $channel).'Gateway';
            if ($this->index->namedTypes($provider) !== [] || $this->index->namedTypes($gateway) !== []) {
                $this->notice($call, 'AI provider or gateway contract is source-shadowed.');

                continue;
            }
            $selected = $receivers->receiverCandidates($call['metadata']['receiver']);
            $fluent = $setterReturns->candidates($call);
            foreach ($fluent as $binding) {
                $selected['types'][] = $binding['metadata']['provider_type'];
            }
            $selected['types'] = array_values(array_unique($selected['types']));
            $selected['limited'] = $selected['limited'] || count($selected['types']) > 128;
            if ($selected['limited']) {
                $this->notice($call, 'AI provider receiver exceeded its source budget.', 'catalog_limit');

                continue;
            }
            foreach ($selected['types'] as $type) {
                $ids = $this->index->namedTypes($type);
                $configuration = $setterReturns->configuration($call, $type);
                if ($configuration['limited']) {
                    $this->notice($call, 'AI provider setter configuration history exceeded its source budget.', 'catalog_limit');
                }
                if (! CatalogAiProviderTypes::matches($this->index, $type, $channel, $profile['version'])) {
                    if (CatalogAiProviderTypes::ancestors($this->index, $type, $profile['version']) !== []) {
                        $this->notice($call, 'AI provider does not declare this gateway setter in the inspected SDK version.');
                    }

                    continue;
                }
                $site = $sites[$call['path']][$call['metadata']['end_offset'] ?? -1] ?? null;
                $argument = $site['metadata']['arguments'][0] ?? null;
                $value = $call['metadata']['provider_gateway_value'] ?? null;
                if ($site === null || $site['metadata']['limited'] || count($site['metadata']['arguments']) !== 1 || $argument['unpack'] || $argument['by_ref']
                    || $value === null || ($call['metadata']['form'] ?? null) !== 'instance') {
                    $this->notice($call, 'AI provider gateway argument requires inspection.');

                    continue;
                }
                $targets = [];
                $parameter = 'gateway';
                if (count($ids) === 1) {
                    $trait = 'Laravel\\Ai\\Providers\\Concerns\\Has'.$channel.'Gateway';
                    $implementation = $methods->sourceMethodCandidate($type, $method, knownTraits: array_keys($inventory), knownTraitMethods: $inventory);
                    $standardTrait = ! $implementation['limited'] && ! $implementation['unresolved'] && $implementation['method'] === null
                        && ($this->index->hasContract($ids[0], $trait) && $this->index->namedTypes($trait) === []
                            || CatalogAiProviderTypes::standardSetter($this->index, $type, $channel, $profile['version']));
                    if (! $standardTrait) {
                        $chosen = $methods->sourceCallableCandidates($type, $method, $call['metadata']['exact_receiver'], ['creator' => '', 'form' => 'instance', 'binding' => null]);
                        if ($chosen['limited'] || count($chosen['targets']) !== 1) {
                            $this->notice($call, 'AI provider setter implementation requires inspection.', $chosen['limited'] ? 'catalog_limit' : 'package_ai_analysis');

                            continue;
                        }
                        $target = $chosen['targets'][0];
                        $signature = $this->index->elements[$target]['metadata']['call_parameters'] ?? null;
                        if ($signature === null || ! $signature['complete'] || count($signature['parameters']) !== 1
                            || $signature['parameters'][0]['variadic'] || $signature['parameters'][0]['by_ref'] || ($this->index->elements[$target]['metadata']['static'] ?? false)) {
                            $this->notice($call, 'AI provider setter source parameter binding requires inspection.');

                            continue;
                        }
                        $parameter = $signature['parameters'][0]['name'];
                        $targets = [$target];
                    }
                }
                if ($ids === [] && CatalogAiProviderTypes::ancestors($this->index, $type, $profile['version']) !== []
                    && ! CatalogAiProviderTypes::standardSetter($this->index, $type, $channel, $profile['version'])) {
                    $this->notice($call, 'AI provider SDK setter trait is source-shadowed.');

                    continue;
                }
                if ($argument['name'] !== null && $argument['name'] !== $parameter) {
                    $this->notice($call, 'AI provider setter named argument does not match its declaration.');

                    continue;
                }
                $values = [['receiver' => $value['receiver'], 'sources' => []]];
                if (str_starts_with($value['receiver'], '@return:') && $value['factory_call'] !== null) {
                    $returned = $factories->factoryReturnCandidates($value['receiver'], $value['factory_call']);
                    if ($returned['limited']) {
                        $this->notice($call, 'AI provider gateway factory exceeded its source budget.', 'catalog_limit');

                        continue;
                    }
                    $values = $returned['values'];
                }
                $found = false;
                foreach ($values as $candidate) {
                    $gatewayTypes = $receivers->receiverCandidates($candidate['receiver']);
                    if ($gatewayTypes['limited']) {
                        $this->notice($call, 'AI gateway argument selection exceeded its source budget.', 'catalog_limit');

                        continue;
                    }
                    foreach ($gatewayTypes['types'] as $gatewayType) {
                        $gatewayIds = $this->index->namedTypes($gatewayType);
                        if (! CatalogAiGatewayTypes::matches($this->index, $gatewayType, $gateway, $profile['version'])) {
                            continue;
                        }
                        if (++$bindings > 4096 || $bindings % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
                            $this->notice($call, 'AI provider gateway bindings exceeded their source budget.', 'catalog_limit');

                            return;
                        }
                        $found = true;
                        $id = CatalogElement::identity($call['path'], 'ai-provider-gateway-binding', $type.':'.$method.':'.$gatewayType.':'.$site['metadata']['end_offset'], $site['offset']);
                        $metadata = ['package' => 'laravel/ai', 'version' => $profile['version'], 'method' => $method, 'provider_type' => $type,
                            'sdk_provider_ancestors' => CatalogAiProviderTypes::ancestors($this->index, $type, $profile['version']),
                            'gateway_type' => $gatewayType, 'gateway_contract' => $gateway, 'operation_stage' => 'gateway-configuration-candidate',
                            'setter_end_offset' => $call['metadata']['end_offset'] ?? null,
                            'prior_setter_bindings' => $configuration['channels'], 'setter_history_limited' => $configuration['limited'],
                            'provider_instance_origin' => $call['metadata']['receiver_instance_origin'] ?? ($fluent[0]['metadata']['provider_instance_origin'] ?? null),
                            'runtime_standard_provider_required' => $targets === [], 'source_method_body_controls_configuration' => $targets !== [],
                            'runtime_argument_binding_required' => true, 'execution_proven' => false, 'provider_traffic_proven' => false,
                            'gateway_return_sources' => $candidate['sources'], 'gateway_return_path_choice_required' => $candidate['sources'] !== []];
                        $element = new CatalogElement($id, 'AI provider '.$type.'::'.$method, 'ai-provider-gateway-binding', $site['line'], $site['end_line'], $site['offset'], $call['from'], metadata: $metadata);
                        if (! isset($this->index->elements[$id])) {
                            $this->index->elements[$id] = [...$element->toArray(), 'path' => $call['path'], 'knowledge' => 'static', 'role_evidence' => [],
                                'sources' => [['path' => $call['path'], 'line' => $call['line'], 'end_line' => $call['end_line']]]];
                            $this->index->names[strtolower($element->name)][] = $id;
                            $setterReturns->add($this->index->elements[$id]);
                        }
                        $this->edge($call['from'], $id, 'passes-ai-provider-gateway', $call, $metadata);
                        foreach ($gatewayIds as $gatewayId) {
                            $this->edge($id, $gatewayId, 'references-ai-provider-gateway', $call, $metadata);
                        }
                        foreach ($targets as $target) {
                            $this->edge($id, $target, 'invokes-ai-provider-setter', $call, $metadata);
                        }
                    }
                }
                if (! $found) {
                    $this->notice($call, 'AI provider gateway argument lacks a compatible source contract.');
                }
            }
        }
        (new CatalogAiProviderGetterResolver($this->index))->resolve($profile, $sites, $inventory);
    }

    /** @param array{version: string, sources: list<array<string, mixed>>} $profile
     * @param  array<string, list<string>>  $inventory
     */
    private function constructors(array $profile, CatalogCallResolver $receivers, CatalogCallResolver $methods, CatalogCallResolver $factories, array $inventory): void
    {
        $base = 'Laravel\\Ai\\Providers\\Provider';
        $contract = 'Laravel\\Ai\\Contracts\\Gateway\\Gateway';
        if ($this->index->namedTypes($base) !== [] || $this->index->namedTypes($contract) !== []) {
            return;
        }
        $seen = [];
        $operations = 0;
        $bindings = 0;
        foreach ($this->index->relations as $call) {
            if (! in_array($call['kind'], ['calls', 'constructs'], true) || ($call['metadata']['method'] ?? null) !== '__construct' || ($call['metadata']['form'] ?? null) !== 'new') {
                continue;
            }
            $type = $call['metadata']['receiver'];
            $ids = $this->index->namedTypes($type);
            $sdkConstructor = CatalogAiProviderTypes::inheritedGatewayConstructor($this->index, $type, $profile['version']);
            $configConstructor = CatalogAiProviderTypes::configConstructor($this->index, $type, $profile['version']);
            if (! $sdkConstructor && ! $configConstructor && (count($ids) !== 1 || ! $this->index->hasContract($ids[0], $base))) {
                continue;
            }
            if (count($ids) === 1 && ($this->index->elements[$ids[0]]['kind'] !== 'class' || ($this->index->elements[$ids[0]]['metadata']['abstract'] ?? false))) {
                $this->notice($call, 'AI provider construction requires a concrete source class.');

                continue;
            }
            $key = serialize([$call['path'], $call['from'], $call['metadata']['offset'], $type]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (++$operations > 4096 || $operations % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
                $this->notice($call, 'AI provider construction exceeded its source budget.', 'catalog_limit');

                return;
            }
            $implementation = count($ids) === 1 ? $methods->sourceMethodCandidate($type, '__construct', knownTraits: array_keys($inventory), knownTraitMethods: $inventory)
                : ['limited' => false, 'unresolved' => false, 'method' => null];
            if ($implementation['limited'] || $implementation['unresolved'] || $implementation['method'] !== null) {
                $this->notice($call, 'AI provider source constructor controls gateway configuration.', $implementation['limited'] ? 'catalog_limit' : 'package_ai_analysis');

                continue;
            }
            $arguments = $call['metadata']['provider_constructor_arguments'] ?? [];
            $names = $configConstructor ? ['config', 'events'] : ['gateway', 'config', 'events'];
            $bound = [];
            $named = false;
            foreach ($arguments as $position => $argument) {
                $name = $argument['name'] ?? ($names[$position] ?? null);
                if ($argument['unpack'] || $argument['by_ref'] || ! in_array($name, $names, true) || isset($bound[$name]) || $named && $argument['name'] === null) {
                    $bound = [];
                    break;
                }
                $bound[$name] = true;
                $named = $named || $argument['name'] !== null;
            }
            $value = $call['metadata']['provider_gateway_value'] ?? null;
            if (count($bound) !== count($names) || ! $configConstructor && $value === null) {
                $this->notice($call, 'AI provider constructor gateway or argument binding requires inspection.');

                continue;
            }
            if ($configConstructor) {
                $this->configConstruction($call, $type, $profile);

                continue;
            }
            $values = [['receiver' => $value['receiver'], 'sources' => []]];
            if (str_starts_with($value['receiver'], '@return:') && $value['factory_call'] !== null) {
                $returned = $factories->factoryReturnCandidates($value['receiver'], $value['factory_call']);
                if ($returned['limited']) {
                    $this->notice($call, 'AI provider constructor gateway factory exceeded its source budget.', 'catalog_limit');

                    continue;
                }
                $values = $returned['values'];
            }
            $found = false;
            foreach ($values as $candidate) {
                $selected = $receivers->receiverCandidates($candidate['receiver']);
                if ($selected['limited']) {
                    $this->notice($call, 'AI provider constructor gateway selection exceeded its source budget.', 'catalog_limit');

                    continue;
                }
                foreach ($selected['types'] as $gatewayType) {
                    $gatewayIds = $this->index->namedTypes($gatewayType);
                    if (! CatalogAiGatewayTypes::matches($this->index, $gatewayType, $contract, $profile['version'])) {
                        continue;
                    }
                    if (++$bindings > 4096 || $bindings % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
                        $this->notice($call, 'AI provider constructor bindings exceeded their source budget.', 'catalog_limit');

                        return;
                    }
                    $found = true;
                    $id = CatalogElement::identity($call['path'], 'ai-provider-gateway-binding', $type.':__construct:'.$gatewayType, $call['metadata']['offset']);
                    $metadata = ['package' => 'laravel/ai', 'version' => $profile['version'], 'method' => '__construct', 'provider_type' => $type,
                        'sdk_provider_ancestors' => CatalogAiProviderTypes::ancestors($this->index, $type, $profile['version']),
                        'gateway_type' => $gatewayType, 'gateway_contract' => $contract, 'operation_stage' => 'gateway-constructor-configuration-candidate',
                        'provider_instance_origin' => $call['metadata']['end_offset'] ?? null,
                        'runtime_standard_provider_required' => true, 'runtime_argument_binding_required' => true, 'source_method_body_controls_configuration' => false,
                        'execution_proven' => false, 'provider_traffic_proven' => false, 'gateway_return_sources' => $candidate['sources'],
                        'gateway_return_path_choice_required' => $candidate['sources'] !== [], 'runtime_config_and_events_types_required' => true];
                    $element = new CatalogElement($id, 'AI provider '.$type.'::__construct', 'ai-provider-gateway-binding', $call['line'], $call['end_line'], $call['metadata']['offset'], $call['from'], metadata: $metadata);
                    if (! isset($this->index->elements[$id])) {
                        $this->index->elements[$id] = [...$element->toArray(), 'path' => $call['path'], 'knowledge' => 'static', 'role_evidence' => [],
                            'sources' => [['path' => $call['path'], 'line' => $call['line'], 'end_line' => $call['end_line']]]];
                        $this->index->names[strtolower($element->name)][] = $id;
                    }
                    $this->edge($call['from'], $id, 'passes-ai-provider-gateway', $call, $metadata);
                    foreach ($gatewayIds as $gatewayId) {
                        $this->edge($id, $gatewayId, 'references-ai-provider-gateway', $call, $metadata);
                    }
                }
            }
            if (! $found) {
                $this->notice($call, 'AI provider constructor gateway lacks its required source contract.');
            }
        }
    }

    /** @param array<string, mixed> $call
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     */
    private function configConstruction(array $call, string $type, array $profile): void
    {
        $id = CatalogElement::identity($call['path'], 'ai-provider-construction', $type, $call['metadata']['offset']);
        $metadata = ['package' => 'laravel/ai', 'version' => $profile['version'], 'provider_type' => $type,
            'sdk_provider_ancestors' => CatalogAiProviderTypes::ancestors($this->index, $type, $profile['version']),
            'operation_stage' => 'provider-constructor-candidate', 'constructor_gateway_assignment' => false,
            'runtime_standard_provider_required' => true, 'runtime_config_and_events_types_required' => true,
            'execution_proven' => false, 'provider_traffic_proven' => false];
        $element = new CatalogElement($id, 'AI provider '.$type.'::__construct', 'ai-provider-construction', $call['line'], $call['end_line'], $call['metadata']['offset'], $call['from'], metadata: $metadata);
        $this->index->elements[$id] = [...$element->toArray(), 'path' => $call['path'], 'knowledge' => 'static', 'role_evidence' => [],
            'sources' => [['path' => $call['path'], 'line' => $call['line'], 'end_line' => $call['end_line']]]];
        $this->index->names[strtolower($element->name)][] = $id;
        $this->edge($call['from'], $id, 'constructs-ai-provider', $call, $metadata);
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
