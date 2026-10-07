<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Source gateway contracts describe possible calls, never provider traffic proof. */
final class CatalogAiGatewayResolver
{
    private int $operations = 0;

    private ?CatalogAiResolver $callbackSelector = null;

    public function __construct(private readonly CatalogIndex $index) {}

    public function resolve(): void
    {
        $profile = CatalogPackageVersions::verified($this->index, 'laravel/ai');
        $contracts = CatalogAiGateways::contracts($profile['version'] ?? '0.11.2.0');
        $known = [...CatalogAiGateways::contracts('0.8.0.0'), ...CatalogAiGateways::contracts('0.11.2.0')];
        $sites = [];
        $getterReturns = [];
        foreach ($this->index->elements as $id => $element) {
            if ($element['kind'] === 'ai-provider-gateway-selection'
                && is_int($element['metadata']['getter_end_offset'] ?? null)) {
                $getterReturns[$element['path']][$element['parent']][$element['metadata']['getter_end_offset']][] = $id;
            }
            if ($element['kind'] === 'ai-gateway-call-site') {
                $sites[$element['path']][$element['metadata']['end_offset']] = $element;
            }
            if ($element['kind'] !== 'class') {
                continue;
            }
            foreach ($known as $contract => $signature) {
                if (! $this->index->hasContract($id, $contract) && ($profile === null || ! CatalogAiGatewayTypes::matches($this->index, $element['name'], $contract, $profile['version']))) {
                    continue;
                }
                if (! $this->room($element)) {
                    return;
                }
                if ($profile === null || ! isset($contracts[$contract]) || $this->index->namedTypes($contract) !== []) {
                    $this->notice($element, 'AI gateway contract is unavailable, source-shadowed or has unresolved package metadata.');

                    continue;
                }
                if (! in_array('ai-gateway', $this->index->elements[$id]['roles'], true)) {
                    $this->index->elements[$id]['roles'][] = 'ai-gateway';
                }
                $this->index->elements[$id]['role_evidence'][] = ['role' => 'ai-gateway', 'basis' => 'source-contract', 'contract' => $contract,
                    'package' => 'laravel/ai', 'version' => $profile['version'], 'path' => $element['path'], 'line' => $element['line'], 'package_sources' => $profile['sources']];
            }
        }
        if ($profile === null) {
            return;
        }
        $receivers = new CatalogCallResolver($this->index);
        $methods = null;
        $factoryReceivers = null;
        $calls = $this->index->relations;
        $getterCalls = [];
        foreach ($calls as $candidate) {
            if ($candidate['kind'] === 'calls' && in_array(strtolower($candidate['metadata']['method'] ?? ''), CatalogAiGateways::GETTERS, true)
                && is_int($candidate['metadata']['end_offset'] ?? null)) {
                $getterCalls[$candidate['path']][$candidate['from']][$candidate['metadata']['end_offset']] = $candidate;
            }
        }
        foreach ($calls as $call) {
            $method = strtolower($call['metadata']['method'] ?? '');
            if ($call['kind'] !== 'calls' || ! in_array($method, CatalogAiGateways::METHODS, true)) {
                continue;
            }
            if (! $this->room($call)) {
                return;
            }
            $selector = str_starts_with($call['metadata']['receiver'], '@') ? ($factoryReceivers ??= new CatalogCallResolver($this->index)) : $receivers;
            $selected = $selector->receiverCandidates($call['metadata']['receiver']);
            $getterCandidates = $getterReturns[$call['path']][$call['from']][$call['metadata']['receiver_origin'] ?? -1] ?? [];
            $getterCall = $getterCalls[$call['path']][$call['from']][$call['metadata']['receiver_origin'] ?? -1] ?? null;
            if ($getterCall !== null && $getterCandidates === []) {
                $providerTypes = $receivers->receiverCandidates($getterCall['metadata']['receiver']);
                $channel = ucfirst(substr(strtolower($getterCall['metadata']['method']), 0, -7));
                $providerReturnUnresolved = $providerTypes['limited'];
                foreach ($providerTypes['types'] as $providerType) {
                    $providerReturnUnresolved = $providerReturnUnresolved || CatalogAiProviderTypes::matches($this->index, $providerType, $channel, $profile['version']);
                }
                if ($providerReturnUnresolved) {
                    $this->notice($call, 'AI provider getter return lacks a compatible source selection.', $providerTypes['limited'] ? 'catalog_limit' : 'package_ai_analysis');

                    continue;
                }
            }
            if (count($getterCandidates) > 128) {
                $this->notice($call, 'AI gateway getter return candidates exceeded their source budget.', 'catalog_limit');
                $getterCandidates = array_slice($getterCandidates, 0, 128);
            }
            $getterTypes = $getterSources = [];
            $sourceReturns = null;
            foreach ($getterCandidates as $getter) {
                $lookup = $this->index->elements[$getter]['metadata'];
                if ($lookup['runtime_standard_provider_required']) {
                    $getterTypes[$getter][] = $lookup['gateway_contract'];
                } else {
                    $target = $lookup['source_getter_target'];
                    foreach ($this->index->elements[$target]['metadata']['return_types'] ?? [] as $returnType) {
                        if (CatalogAiGatewayTypes::matches($this->index, $returnType, $lookup['gateway_contract'], $profile['version'])) {
                            $getterTypes[$getter][] = $returnType;
                        }
                    }
                    if ($sourceReturns === null && isset($call['metadata']['receiver_factory_call'])) {
                        $sourceReturns = $selector->factoryReturnCandidates($call['metadata']['receiver'], $call['metadata']['receiver_factory_call']);
                    }
                    if ($sourceReturns['limited'] ?? false) {
                        $this->notice($call, 'AI provider source getter return exceeded its source budget.', 'catalog_limit');
                    } else {
                        foreach ($sourceReturns['values'] ?? [] as $value) {
                            if (($value['sources'][0]['producer'] ?? null) !== $target) {
                                continue;
                            }
                            $returned = $selector->receiverCandidates($value['receiver']);
                            if ($returned['limited']) {
                                $this->notice($call, 'AI provider returned gateway exceeded its source budget.', 'catalog_limit');

                                continue;
                            }
                            foreach ($returned['types'] as $returnType) {
                                if (CatalogAiGatewayTypes::matches($this->index, $returnType, $lookup['gateway_contract'], $profile['version'])) {
                                    $getterTypes[$getter][] = $returnType;
                                    $getterSources[$getter][$returnType] = $value['sources'];
                                }
                            }
                        }
                    }
                }
                foreach ($getterTypes[$getter] ?? [] as $returnType) {
                    $selected['types'][] = $returnType;
                }
            }
            $selected['types'] = array_values(array_unique($selected['types']));
            if ($selected['limited']) {
                $this->notice($call, 'AI gateway receiver selection exceeded its source budget.', 'catalog_limit');

                continue;
            }
            if ($getterCandidates !== [] && $selected['types'] === []) {
                $this->notice($call, 'AI provider getter return type requires source inspection.');
            }
            foreach ($selected['types'] as $type) {
                $ids = $this->index->namedTypes($type);
                foreach ($known as $contract => $signature) {
                    if (! CatalogAiGatewayTypes::matches($this->index, $type, $contract, $profile['version'])) {
                        continue;
                    }
                    $methodContract = $contract;
                    if ($contract === 'Laravel\\Ai\\Contracts\\Gateway\\Gateway') {
                        foreach ($contracts as $origin => $originMethods) {
                            if ($origin !== $contract && isset($originMethods[$method])) {
                                $methodContract = $origin;
                                break;
                            }
                        }
                    }
                    if (! isset($contracts[$contract][$method]) || $this->index->namedTypes($contract) !== [] || $this->index->namedTypes($methodContract) !== []) {
                        $this->notice($call, 'AI gateway method is unavailable in this SDK version or its contract is source-shadowed.');

                        continue;
                    }
                    $site = $sites[$call['path']][$call['metadata']['end_offset'] ?? -1] ?? null;
                    if ($site === null || ($call['metadata']['form'] ?? null) !== 'instance' || $site['metadata']['limited'] || count($ids) !== 1 && ! $this->validArguments($site, $contracts[$contract][$method])) {
                        $this->notice($call, 'AI gateway argument shape or static dispatch requires inspection.', ($site['metadata']['limited'] ?? false) ? 'catalog_limit' : 'package_ai_analysis');

                        continue;
                    }
                    $targets = [];
                    $standardSdkCandidate = count($ids) !== 1;
                    if (count($ids) === 1) {
                        $chosen = ($methods ??= new CatalogCallResolver($this->index))->sourceCallableCandidates($type, $method, $call['metadata']['exact_receiver'], ['creator' => '', 'form' => 'instance', 'binding' => null]);
                        $implementation = $methods->sourceMethodCandidate($type, $method);
                        $sdkInherited = ! $implementation['limited'] && ! $implementation['unresolved'] && $implementation['method'] === null
                            && CatalogAiGatewayTypes::ancestors($this->index, $type, $profile['version']) !== [];
                        $standardSdkCandidate = $sdkInherited && $this->validArguments($site, $contracts[$contract][$method]);
                        if ($chosen['limited'] || $chosen['targets'] === [] && ! $sdkInherited) {
                            $this->notice($call, 'AI gateway source implementation is absent, inaccessible or unresolved.', $chosen['limited'] ? 'catalog_limit' : 'package_ai_analysis');

                            continue;
                        }
                        foreach ($chosen['targets'] as $target) {
                            if (! ($this->index->elements[$target]['metadata']['static'] ?? false) && $this->sourceArguments($site, $target)) {
                                $targets[] = $target;
                            }
                        }
                        if ($targets === [] && ! $standardSdkCandidate) {
                            $this->notice($call, 'AI gateway requires a non-static source implementation with compatible argument binding.');

                            continue;
                        }
                    }
                    $id = CatalogElement::identity($call['path'], 'ai-gateway-operation', $type.':'.$method, $site['offset']);
                    if (isset($this->index->elements[$id])) {
                        continue;
                    }
                    $metadata = ['package' => 'laravel/ai', 'version' => $profile['version'], 'method' => $method, 'contract' => $contract,
                        'operation_stage' => $method === 'ontoolinvocation' ? 'callback-registration-candidate' : 'gateway-call-candidate',
                        'implementation_unknown' => $targets === [], 'standard_sdk_method_candidate' => $standardSdkCandidate,
                        'sdk_gateway_ancestors' => CatalogAiGatewayTypes::ancestors($this->index, $type, $profile['version']),
                        'runtime_argument_binding_required' => true, 'source_parameter_names_verified' => $targets !== [], 'provider_traffic_proven' => false, 'execution_proven' => false];
                    $element = new CatalogElement($id, 'AI gateway '.$type.'::'.$method, 'ai-gateway-operation', $site['line'], $site['end_line'], $site['offset'], $call['from'], metadata: $metadata);
                    $this->index->elements[$id] = [...$element->toArray(), 'path' => $call['path'], 'knowledge' => 'static', 'role_evidence' => [],
                        'sources' => [['path' => $call['path'], 'line' => $call['line'], 'end_line' => $call['end_line']]]];
                    $this->index->names[strtolower($element->name)][] = $id;
                    $this->edge($call['from'], $id, 'calls-ai-gateway', $call, [...$metadata, 'package_sources' => $profile['sources'], 'stream_consumption_required' => in_array($method, ['streamtext', 'generatestreamstep'], true)]);
                    foreach ($getterCandidates as $getter) {
                        if (in_array($type, $getterTypes[$getter] ?? [], true)) {
                            $this->edge($getter, $id, 'uses-ai-provider-selected-gateway', $call, [...$metadata, 'getter_return_success_required' => true,
                                'runtime_gateway_configuration_required' => $this->index->elements[$getter]['metadata']['runtime_standard_provider_required'],
                                'getter_return_sources' => $getterSources[$getter][$type] ?? [], 'gateway_selection_proven' => false]);
                        }
                    }
                    if ($method === 'ontoolinvocation') {
                        $this->callbacks($id, $site, $metadata, $standardSdkCandidate, $call['metadata']['gateway_callback_values'] ?? []);
                        if ($standardSdkCandidate && $targets !== []) {
                            $this->callbacks($id, $site, $metadata, false, $call['metadata']['gateway_callback_values'] ?? []);
                        }
                    }
                    foreach ($targets as $target) {
                        $this->edge($id, $target, 'invokes-ai-gateway-method', $call, [...$metadata, 'runtime_gateway_implementation_choice_required' => true]);
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $site
     * @param  array<string, mixed>  $metadata
     * @param  array<int, array<string, mixed>>  $values
     */
    private function callbacks(string $operation, array $site, array $metadata, bool $standardCandidate, array $values): void
    {
        foreach ($site['metadata']['arguments'] as $position => $argument) {
            $target = $argument['callback'];
            $selected = $target !== null && ($this->index->elements[$target]['kind'] ?? null) === 'closure'
                ? [['target' => $target, 'sources' => []]] : [];
            if ($selected === [] && isset($values[$position])) {
                $selected = ($this->callbackSelector ??= new CatalogAiResolver($this->index, new CatalogCallResolver($this->index)))->closureCandidates($site, $values[$position]);
            }
            if ($selected === []) {
                $this->notice($site, 'AI gateway callback source requires inspection.');

                continue;
            }
            foreach ($selected as $candidate) {
                $target = $candidate['target'];
                $callbackMetadata = [...$metadata, 'callback_return_path_choice_required' => $candidate['sources'] !== [],
                    'callback_return_sources' => $candidate['sources']];
                $phase = $argument['name'] ?? ($position === 0 ? 'invoking' : 'invoked');
                if (! $standardCandidate) {
                    $this->edge($operation, $target, 'passes-ai-gateway-callback', $site, [...$callbackMetadata, 'source_method_body_controls_callback_execution' => true]);

                    continue;
                }
                $conditions = ['callback_phase' => $phase, 'runtime_standard_gateway_required' => true,
                    'runtime_tool_invocation_required' => true, 'registration_must_be_active_at_tool_start' => true,
                    'tool_handler_returned_required' => $phase === 'invoked', 'before_tool_callback_succeeded_required' => $phase === 'invoked'];
                $this->edge($operation, $target, 'registers-ai-gateway-callback', $site, [...$callbackMetadata, 'callback_phase' => $phase]);
                $this->edge($operation, $target, 'ai-gateway-tool-callback', $site, [...$callbackMetadata, ...$conditions]);
            }
        }
    }

    /** @param array<string, mixed> $site */
    private function sourceArguments(array $site, string $target): bool
    {
        $signature = $this->index->elements[$target]['metadata']['call_parameters'] ?? null;
        if ($signature === null || ! $signature['complete']) {
            $this->notice($site, 'AI gateway source parameter list is incomplete.', $signature !== null ? 'catalog_limit' : 'package_ai_analysis');

            return false;
        }

        return CatalogAiGateways::sourceArguments($site, $signature);
    }

    /** @param array<string, mixed> $site
     * @param  array{parameters: list<string>, required: list<string>}  $signature
     */
    private function validArguments(array $site, array $signature): bool
    {
        if ($site['metadata']['limited']) {
            return false;
        }
        $seen = [];
        $named = false;
        foreach ($site['metadata']['arguments'] as $position => $argument) {
            $name = $argument['name'] ?? ($signature['parameters'][$position] ?? null);
            if ($argument['unpack'] || $argument['by_ref'] || $name === null || ! in_array($name, $signature['parameters'], true)
                || isset($seen[$name]) || $named && $argument['name'] === null) {
                return false;
            }
            $seen[$name] = true;
            $named = $named || $argument['name'] !== null;
        }

        return array_diff($signature['required'], array_keys($seen)) === [];
    }

    /** @param array<string, mixed> $site */
    private function room(array $site): bool
    {
        if (++$this->operations <= 4096 && ($this->operations % 128 !== 0 || ImpactExtractor::sourceLimit(0) === null)) {
            return true;
        }
        $this->notice($site, 'AI gateway composition exceeded its source budget.', 'catalog_limit');

        return false;
    }

    /** @param array<string, mixed> $site */
    private function notice(array $site, string $message, string $code = 'package_ai_analysis'): void
    {
        $this->index->diagnostics[] = ['path' => $site['path'], ...((new CatalogDiagnostic($code, $message, $site['line'], $site['from'] ?? $site['id'] ?? null))->toArray())];
    }

    /** @param array<string, mixed> $site
     * @param  array<string, mixed>  $metadata
     */
    private function edge(string $from, string $to, string $kind, array $site, array $metadata): void
    {
        $this->index->addRelation(['from' => $from, 'to' => $to, 'kind' => $kind, 'path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line'],
            'knowledge' => 'static', 'resolution' => 'conditional', 'metadata' => $metadata]);
    }
}
