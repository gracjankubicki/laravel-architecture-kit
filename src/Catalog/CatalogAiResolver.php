<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** SDK calls, queued work and model-selected tools remain separate source candidates. */
final class CatalogAiResolver
{
    private int $operations = 0;

    /** @var array<string, array<int, bool>> */
    private array $invocationModes = [];

    /** @var array<string, array<int, list<array<string, mixed>>>> */
    private array $callableReferences = [];

    /** @var array<string, list<string>> */
    private array $namedFunctions = [];

    /** @var array<string, list<string>> */
    private array $namedTypes = [];

    /** @var array<string, string> */
    private array $methodNames = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $callbackValues = [];

    /** @var array<string, array<string, list<array<string, mixed>>>> */
    private array $callbackReturnSources = [];

    /** @var array<string, array<int, string>> */
    private array $responseReceivers = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $responseFactoryContexts = [];

    /** @var array<string, array<int, string>> */
    private array $attachmentCreators = [];

    private ?CatalogCallResolver $factoryCalls = null;

    public function __construct(private readonly CatalogIndex $index, private CatalogCallResolver $calls) {}

    /** Resolve only Closure-valued source candidates, without composing SDK operations.
     * @param  array<string, mixed>  $site
     * @param  array<string, mixed>  $value
     * @return list<array{target: string, sources: list<array<string, mixed>>}>
     */
    public function closureCandidates(array $site, array $value): array
    {
        $targets = $this->callbackValueTargets($site, $value, true);

        return array_map(fn ($target) => ['target' => $target, 'sources' => $this->callbackReturnSources[$site['id']][$target] ?? []], $targets);
    }

    public function resolve(): void
    {
        $profile = CatalogPackageVersions::verified($this->index, 'laravel/ai');
        if ($profile !== null) {
            // Generic fluent calls may have exhausted their selector budget.
            // SDK composition gets independent state over the same source facts.
            $this->calls = new CatalogCallResolver($this->index);
        }
        $origins = [];
        foreach ($this->index->relations as $relation) {
            if ($relation['kind'] === 'calls' && isset($relation['metadata']['offset'])) {
                $this->attachmentCreators[$relation['path']][$relation['metadata']['offset']] = $relation['from'];
            }
            if ($relation['kind'] === 'calls' && isset($relation['metadata']['end_offset'])
                && in_array(strtolower($relation['metadata']['method']), ['then', 'each', 'catch'], true)) {
                $this->responseReceivers[$relation['path']][$relation['metadata']['end_offset']] = $relation['metadata']['receiver'];
                if (isset($relation['metadata']['receiver_factory_call'])) {
                    $this->responseFactoryContexts[$relation['path']][$relation['metadata']['end_offset']] = $relation['metadata']['receiver_factory_call'];
                }
            }
            if ($relation['kind'] === 'calls' && isset($relation['metadata']['callback_value'], $relation['metadata']['end_offset'])) {
                $this->callbackValues[$relation['path']][$relation['metadata']['end_offset']] = $relation['metadata']['callback_value'];
            }
            if ($relation['kind'] === 'calls' && isset($relation['metadata']['offset'], $relation['metadata']['receiver_origin'])) {
                $origins[$relation['path']][$relation['metadata']['offset']][$relation['metadata']['receiver_origin']] = true;
            }
            if ($relation['kind'] === 'callable-reference' && isset($relation['metadata']['offset'])) {
                $this->callableReferences[$relation['path']][$relation['metadata']['offset']][] = $relation;
            }
        }
        $agents = $tools = $toolLists = $sites = $proofs = $callbacks = $callbackSites = $siteEnds = [];
        foreach ($this->index->elements as $element) {
            if ($element['kind'] === 'function') {
                $this->namedFunctions[hash('sha256', strtolower($element['name']))][] = $element['id'];
            }
            if (in_array($element['kind'], ['class', 'trait', 'enum'], true)) {
                $this->namedTypes[hash('sha256', strtolower($element['name']))][] = $element['name'];
                foreach ($element['metadata']['trait_rules'] ?? [] as $rule) {
                    if ($rule['alias'] !== null) {
                        $this->methodNames[hash('sha256', strtolower($rule['alias']))] = $rule['alias'];
                    }
                }
            } elseif ($element['kind'] === 'method') {
                $method = substr($element['name'], strrpos($element['name'], '::') + 2);
                $this->methodNames[hash('sha256', strtolower($method))] = $method;
            }
            if (in_array($element['kind'], ['ai-tools', 'source-method-object-list'], true)) {
                $toolLists[$element['parent']][] = $element;
            } elseif ($element['kind'] === 'ai-call-site') {
                $sites[$element['path']][$element['offset']] = $element;
                $siteEnds[$element['path']][$element['metadata']['end_offset']] = $element;
                $this->invocationModes[$element['path']][$element['offset']] = in_array($element['metadata']['method'], ['queue', 'broadcastonqueue'], true);
            } elseif ($element['kind'] === 'ai-declaration') {
                $proofs[$element['parent']] = $element;
            } elseif (in_array($element['kind'], ['ai-response-callback', 'source-response-callback'], true)) {
                $callbackSites[$element['path']][$element['metadata']['end_offset']] = $element;
            }
        }
        $boundCallbacks = [];
        foreach ($callbackSites as $entries) {
            foreach ($entries as $element) {
                $resolved = $this->callbackOrigin($element, $origins, $siteEnds, $callbackSites);
                foreach ($resolved as $origin) {
                    $copy = $element;
                    $copy['metadata']['response_producer_sources'] = $origin['sources'];
                    $callbacks[$origin['path']][$origin['offset']][] = $copy;
                    $boundCallbacks[$element['id']] = true;
                }
            }
        }
        foreach ($this->index->elements as $id => $element) {
            if ($element['kind'] !== 'class') {
                continue;
            }
            foreach (['Laravel\\Ai\\Contracts\\Agent' => 'ai-agent', 'Laravel\\Ai\\Promptable' => 'ai-agent', 'Laravel\\Ai\\Contracts\\Tool' => 'ai-tool'] as $contract => $role) {
                if (! $this->index->hasContract($id, $contract)) {
                    continue;
                }
                if (! $this->room($element)) {
                    return;
                }
                if ($profile === null || $this->index->namedTypes($contract) !== []) {
                    $this->notice($element, 'AI package version or source SDK contract is unresolved.');

                    continue;
                }
                if (! in_array($role, $this->index->elements[$id]['roles'], true)) {
                    $this->index->elements[$id]['roles'][] = $role;
                }
                $local = $proofs[$id]['metadata'][$role === 'ai-agent' ? 'agent' : 'tool'] ?? false;
                $this->index->elements[$id]['role_evidence'][] = ['role' => $role, 'basis' => $local ? 'ai-symbol-resolver-and-source-contract' : 'source-contract',
                    'contract' => $contract, 'package' => 'laravel/ai', 'version' => $profile['version'], 'path' => $element['path'], 'line' => $element['line'], 'package_sources' => $profile['sources']];
                if ($role === 'ai-agent') {
                    $agents[$id] = $element;
                } else {
                    $tools[$id] = $element;
                }
            }
        }
        if ($profile === null) {
            return;
        }
        foreach ($agents as $agentId => $agent) {
            if (! $this->index->hasContract($agentId, 'Laravel\\Ai\\Contracts\\HasTools') || $this->index->namedTypes('Laravel\\Ai\\Contracts\\HasTools') !== []) {
                continue;
            }
            $method = $this->method($agent['name'], 'tools', $agent);
            if ($method === null) {
                continue;
            }
            $lists = $toolLists[$method] ?? [];
            if ($lists === []) {
                $this->notice($agent, 'AI source tools list has no discoverable return paths.');

                continue;
            }
            foreach ($lists as $list) {
                if (! $this->room($list)) {
                    return;
                }
                if (! $list['metadata']['resolved']) {
                    $this->notice($list, 'AI tools return is partial or dynamic; known members remain conditional candidates.');
                }
                $listMetadata = ['runtime_tools_list_required' => true, 'tool_list_partial' => ! $list['metadata']['resolved'],
                    'tool_return_path_choice_required' => count($lists) > 1 || $list['metadata']['conditional']];
                foreach ($list['metadata']['targets'] as $target) {
                    if (! $this->room($list)) {
                        return;
                    }
                    $ids = $this->index->namedTypes($target);
                    if (count($ids) !== 1 || (! isset($tools[$ids[0]]) && ! $this->index->hasContract($ids[0], 'Laravel\\Ai\\Contracts\\Agent')) || ($this->index->elements[$ids[0]]['metadata']['abstract'] ?? false)) {
                        $this->notice($list, 'AI tool list member has no supported concrete source SDK contract.');

                        continue;
                    }
                    $this->edge($agentId, $ids[0], 'declares-ai-tool', $list, $profile, $listMetadata);
                    if ($this->index->hasContract($ids[0], 'Laravel\\Ai\\Contracts\\Agent')) {
                        $candidate = $this->calls->sourceMethodCandidate($target, 'prompt', knownTraits: ['Laravel\\Ai\\Promptable'], knownTraitMethods: CatalogAiOperations::TRAIT_METHODS);
                        if (! $this->index->hasContract($ids[0], 'Laravel\\Ai\\Promptable') || $this->index->namedTypes('Laravel\\Ai\\Promptable') !== []
                            || $this->index->namedTypes('Laravel\\Ai\\Tools\\AgentTool') !== [] || $candidate['limited'] || $candidate['unresolved'] || $candidate['method'] !== null) {
                            $this->notice($list, 'Nested AI agent prompt or AgentTool wrapper is source-overridden or unresolved.');

                            continue;
                        }
                        $metadata = [...$listMetadata, 'runtime_agent_invocation_required' => true, 'model_tool_selection_required' => true,
                            'runtime_standard_sdk_required' => true, 'tool_approval_required_if_configured' => true];
                        $this->edge($agentId, $ids[0], 'ai-selected-agent', $list, $profile, $metadata);
                        foreach (['instructions', 'tools'] as $hook) {
                            if ($hook === 'tools' && ! $this->index->hasContract($ids[0], 'Laravel\\Ai\\Contracts\\HasTools')) {
                                continue;
                            }
                            $handler = $this->method($target, $hook, $list);
                            if ($handler !== null) {
                                $this->edge($agentId, $handler, 'ai-selected-agent-'.$hook, $list, $profile, $metadata);
                            }
                        }
                    } elseif (isset($tools[$ids[0]])) {
                        $handler = $this->method($target, 'handle', $list);
                        if ($handler !== null) {
                            $this->edge($agentId, $handler, 'ai-selected-tool-handler', $list, $profile,
                                [...$listMetadata, 'runtime_agent_invocation_required' => true, 'model_tool_selection_required' => true, 'tool_approval_required_if_configured' => true]);
                        }
                    }
                }
            }
        }
        $edges = $this->index->relations;
        foreach ($edges as $call) {
            if ($call['kind'] !== 'calls' || ! in_array($method = strtolower($call['metadata']['method'] ?? ''), CatalogAiOperations::METHODS, true)) {
                continue;
            }
            if (! $this->room($call)) {
                return;
            }
            $site = $sites[$call['path']][$call['metadata']['offset'] ?? -1] ?? null;
            foreach ($this->receivers($call['metadata']['receiver'], $call) as $type) {
                $ids = $this->index->namedTypes($type);
                if (count($ids) !== 1 || ! isset($agents[$ids[0]])) {
                    continue;
                }
                if ($site === null || ! $site['metadata']['valid'] || ($call['metadata']['form'] ?? null) !== 'instance') {
                    $this->notice($call, 'AI invocation arguments or static instance-method dispatch require inspection.');

                    continue;
                }
                $override = $this->calls->sourceMethodCandidate($type, $method, knownTraits: ['Laravel\\Ai\\Promptable'], knownTraitMethods: CatalogAiOperations::TRAIT_METHODS);
                if (! $this->index->hasContract($ids[0], 'Laravel\\Ai\\Promptable') || $this->index->namedTypes('Laravel\\Ai\\Promptable') !== []
                    || $override['limited'] || $override['unresolved'] || $override['method'] !== null) {
                    $this->notice($call, 'AI source method implementation prevents inference of standard Promptable behavior.');

                    continue;
                }
                $queued = in_array($method, ['queue', 'broadcastonqueue'], true);
                $stream = in_array($method, ['stream', 'broadcast', 'broadcastnow'], true);
                $id = CatalogElement::identity($call['path'], 'ai-invocation', $method.':'.$type, $site['offset']);
                $invocation = new CatalogElement($id, 'AI '.$method.' '.$type, 'ai-invocation', $call['line'], $call['end_line'], $site['offset'], $call['from'],
                    metadata: ['package' => 'laravel/ai', 'version' => $profile['version'], 'method' => $method, 'queued' => $queued,
                        'streamed' => $stream, 'broadcast' => str_starts_with($method, 'broadcast'), 'attachments_present' => $site['metadata']['attachments_present'], 'execution_proven' => false]);
                $this->index->elements[$id] = [...$invocation->toArray(), 'path' => $call['path'], 'knowledge' => 'static', 'role_evidence' => [],
                    'sources' => [['path' => $call['path'], 'line' => $call['line'], 'end_line' => $call['end_line']]]];
                $this->index->names[strtolower($invocation->name)][] = $id;
                $metadata = ['queued' => $queued, 'queue_delivery_required' => $queued, 'stream_consumption_required' => $stream, 'runtime_standard_sdk_required' => true];
                $this->edge($call['from'], $id, $queued ? 'queues-ai-invocation' : 'starts-ai-invocation', $call, $profile, $metadata);
                $this->edge($id, $ids[0], 'invokes-ai-agent', $call, $profile, $metadata);
                if (isset($call['metadata']['ai_attachments'])) {
                    $site['metadata']['attachments'] = $call['metadata']['ai_attachments'];
                }
                if (isset($call['metadata']['ai_attachment_factory'])) {
                    $this->factoryAttachments($id, $site, $call['metadata']['ai_attachment_factory'], $profile, $metadata);
                } else {
                    $this->attachments($id, $site, $profile, $metadata);
                }
                foreach ($callbacks[$call['path']][$site['offset']] ?? [] as $callback) {
                    if (! $this->room($callback)) {
                        return;
                    }
                    $callbackMethod = $callback['metadata']['method'];
                    $responseType = $queued ? 'Laravel\\Ai\\Responses\\QueuedAgentResponse' : 'Laravel\\Ai\\Responses\\StreamableAgentResponse';
                    if ((! $queued && ! $stream) || ! $callback['metadata']['chain_valid'] || in_array($queued ? 'each' : 'catch', $callback['metadata']['chain'], true)
                        || $queued && array_intersect($callback['metadata']['argument_forms'], ['named', 'array']) !== []
                        || ! $this->callbackValuesCompatible($callback, $queued)
                        || $this->index->namedTypes($responseType) !== []
                        || $queued && ($this->index->namedTypes('Laravel\\Ai\\Responses\\Concerns\\HasQueuedResponseCallbacks') !== [] || $this->index->namedTypes('Laravel\\Ai\\Jobs\\InvokeAgent') !== [])
                    ) {
                        $this->notice($callback, 'AI response callback receiver, method or source callable requires inspection.');

                        continue;
                    }
                    $targets = $this->callbackTargets($callback, $queued);
                    if ($targets === []) {
                        $this->notice($callback, 'AI response source callable is dynamic, unavailable or inaccessible.');
                    }
                    foreach ($targets as $target) {
                        $this->edge($id, $target, 'ai-response-'.$callbackMethod, $callback, $profile,
                            ['queue_delivery_required' => $queued, 'stream_consumption_required' => ! $queued,
                                'agent_success_required' => $queued && $callbackMethod === 'then', 'job_failure_required' => $callbackMethod === 'catch',
                                'stream_completion_required' => ! $queued && $callbackMethod === 'then', 'runtime_standard_sdk_required' => true,
                                'callback_return_path_choice_required' => isset($this->callbackReturnSources[$callback['id']][$target]),
                                'callback_return_sources' => $this->callbackReturnSources[$callback['id']][$target] ?? [],
                                'response_return_path_choice_required' => ($callback['metadata']['response_producer_sources'] ?? []) !== [],
                                'response_producer_sources' => $callback['metadata']['response_producer_sources'] ?? []]);
                    }
                }
                foreach (['instructions', 'tools'] as $hook) {
                    if ($hook === 'tools' && ! $this->index->hasContract($ids[0], 'Laravel\\Ai\\Contracts\\HasTools')) {
                        continue;
                    }
                    $target = $this->method($type, $hook, $call);
                    if ($target !== null) {
                        $this->edge($id, $target, 'ai-source-'.$hook, $call, $profile, $metadata);
                    }
                }
            }
        }
        $this->typedResponseCallbacks($callbackSites, $boundCallbacks, $origins, $profile);
    }

    /** @param array<string, array<int, array<string, mixed>>> $callbacks
     * @param  array<string, bool>  $bound
     * @param  array<string, array<int, array<int, bool>>>  $origins
     * @param  array<string, mixed>  $profile
     */
    private function typedResponseCallbacks(array $callbacks, array $bound, array $origins, array $profile): void
    {
        foreach ($callbacks as $entries) {
            foreach ($entries as $callback) {
                if (isset($bound[$callback['id']]) || $callback['kind'] !== 'source-response-callback' || ! $this->room($callback)) {
                    continue;
                }
                $selected = $this->typedResponseOrigin($callback, $callbacks, $origins);
                if ($selected === null) {
                    continue;
                }
                $type = $selected['type'];
                $types = $this->index->namedTypes($type);
                $queued = null;
                foreach (['Laravel\\Ai\\Responses\\StreamableAgentResponse' => false, 'Laravel\\Ai\\Responses\\QueuedAgentResponse' => true] as $contract => $mode) {
                    if (strcasecmp($type, $contract) === 0 || count($types) === 1 && $this->index->hasContract($types[0], $contract)) {
                        $queued = $mode;
                        if ($this->index->namedTypes($contract) !== []) {
                            $this->notice($callback, 'AI response SDK contract is shadowed by a source declaration.');
                            $queued = null;
                        }
                        break;
                    }
                }
                if ($queued === null) {
                    continue;
                }
                $valid = true;
                foreach ([$callback, ...$selected['producers']] as $part) {
                    $valid = $valid && $part['metadata']['chain_valid'] && ! in_array($queued ? 'each' : 'catch', $part['metadata']['chain'], true)
                        && (! $queued || array_intersect($part['metadata']['argument_forms'], ['named', 'array']) === [])
                        && $this->callbackValuesCompatible($part, $queued);
                }
                if ($queued && $this->index->namedTypes('Laravel\\Ai\\Responses\\Concerns\\HasQueuedResponseCallbacks') !== []) {
                    $valid = false;
                }
                foreach ($callback['metadata']['chain'] as $method) {
                    $source = $this->calls->sourceMethodCandidate($type, $method);
                    if ($source['method'] !== null || $source['limited'] || $types !== [] && $source['unresolved']) {
                        $valid = false;
                    }
                }
                $targets = $valid ? $this->callbackTargets($callback, $queued) : [];
                if ($targets === []) {
                    $this->notice($callback, 'Typed AI response callback has an unresolved source body or overridden SDK method.');

                    continue;
                }
                $method = $callback['metadata']['method'];
                foreach ($targets as $target) {
                    $this->edge($callback['parent'], $target, 'ai-response-'.$method, $callback, $profile,
                        ['queue_delivery_required' => $queued, 'stream_consumption_required' => ! $queued,
                            'agent_success_required' => $queued && $method === 'then', 'job_failure_required' => $method === 'catch',
                            'stream_completion_required' => ! $queued && $method === 'then', 'runtime_standard_sdk_required' => true,
                            'agent_origin_unknown' => true, 'typed_response_candidate' => true,
                            'callback_return_path_choice_required' => isset($this->callbackReturnSources[$callback['id']][$target]),
                            'callback_return_sources' => $this->callbackReturnSources[$callback['id']][$target] ?? []]);
                }
            }
        }
    }

    /** @param array<string, mixed> $callback
     * @param  array<string, array<int, array<string, mixed>>>  $callbacks
     * @param  array<string, array<int, array<int, bool>>>  $origins
     * @param  array<string, bool>  $seen
     * @return array{type: string, producers: list<array<string, mixed>>}|null
     */
    private function typedResponseOrigin(array $callback, array $callbacks, array $origins, array $seen = []): ?array
    {
        if (isset($seen[$callback['id']]) || count($seen) >= 16 || ! $this->room($callback)) {
            $this->notice($callback, 'Typed AI response producer exceeded its source depth budget.', 'catalog_limit');

            return null;
        }
        $seen[$callback['id']] = true;
        $ends = $callback['metadata']['chain_ends'];
        $receiver = $this->responseReceivers[$callback['path']][$ends[array_key_last($ends)]] ?? null;
        if ($receiver === null) {
            return null;
        }
        $selected = $this->calls->receiverCandidates($receiver);
        if ($selected['limited']) {
            $this->notice($callback, 'Typed AI response selection exceeded its source budget.', 'catalog_limit');

            return null;
        }
        if (count($selected['types']) === 1) {
            return ['type' => $selected['types'][0], 'producers' => []];
        }
        $ends = array_keys($origins[$callback['path']][$callback['offset']] ?? []);
        $producer = count($ends) === 1 ? ($callbacks[$callback['path']][$ends[0]] ?? null) : null;
        if ($producer === null || $selected['types'] !== []) {
            return null;
        }
        $origin = $this->typedResponseOrigin($producer, $callbacks, $origins, $seen);

        return $origin === null ? null : ['type' => $origin['type'], 'producers' => [$producer, ...$origin['producers']]];
    }

    /** @param array<string, mixed> $callback
     * @param  array<string, array<int, array<int, bool>>>  $origins
     * @param  array<string, array<int, array<string, mixed>>>  $sites
     * @param  array<string, array<int, array<string, mixed>>>  $callbacks
     * @param  array<string, bool>  $seen
     * @return list<array{path: string, offset: int, sources: list<array<string, mixed>>}>
     */
    private function callbackOrigin(array $callback, array $origins, array $sites, array $callbacks, array $seen = []): array
    {
        if (count($seen) >= 16) {
            $this->notice($callback, 'Stored AI response producer chain exceeds its source depth budget.', 'catalog_limit');

            return [];
        }
        if (isset($seen[$callback['id']]) || ! $this->room($callback) || ! $callback['metadata']['chain_valid']) {
            return [];
        }
        if ($callback['metadata']['invocation_offset'] !== null) {
            return [['path' => $callback['path'], 'offset' => $callback['metadata']['invocation_offset'], 'sources' => []]];
        }
        $seen[$callback['id']] = true;
        $ends = array_keys($origins[$callback['path']][$callback['offset']] ?? []);
        if (count($ends) !== 1) {
            return [];
        }
        $site = $sites[$callback['path']][$ends[0]] ?? null;
        if ($site !== null) {
            $fromFactory = $this->responseFactoryOrigins($callback, $origins, $sites, $callbacks, $seen);
            if ($fromFactory !== []) {
                return $fromFactory;
            }

            return [['path' => $callback['path'], 'offset' => $site['offset'], 'sources' => []]];
        }
        $producer = $callbacks[$callback['path']][$ends[0]] ?? null;
        if ($producer === null) {
            return $this->responseFactoryOrigins($callback, $origins, $sites, $callbacks, $seen);
        }
        $resolved = $this->callbackOrigin($producer, $origins, $sites, $callbacks, $seen);

        return array_values(array_filter($resolved, fn ($origin) => $this->validResponseProducer($producer, $origin)));
    }

    /** @param array<string, mixed> $producer
     * @param  array{path: string, offset: int, sources: list<array<string, mixed>>}  $origin
     */
    private function validResponseProducer(array $producer, array $origin): bool
    {
        $queued = $this->invocationModes[$origin['path']][$origin['offset']] ?? null;

        return $queued !== null && ! in_array($queued ? 'each' : 'catch', $producer['metadata']['chain'], true)
            && (! $queued || array_intersect($producer['metadata']['argument_forms'], ['named', 'array']) === [])
            && $this->callbackValuesCompatible($producer, $queued);
    }

    /** @param array<string, mixed> $callback
     * @param  array<string, array<int, array<int, bool>>>  $origins
     * @param  array<string, array<int, array<string, mixed>>>  $sites
     * @param  array<string, array<int, array<string, mixed>>>  $callbacks
     * @param  array<string, bool>  $seen
     * @return list<array{path: string, offset: int, sources: list<array<string, mixed>>}>
     */
    private function responseFactoryOrigins(array $callback, array $origins, array $sites, array $callbacks, array $seen): array
    {
        $ends = $callback['metadata']['chain_ends'];
        $end = $ends[array_key_last($ends)];
        $receiver = $this->responseReceivers[$callback['path']][$end] ?? null;
        $context = $this->responseFactoryContexts[$callback['path']][$end] ?? null;
        if ($receiver === null || $context === null) {
            return [];
        }
        $selected = ($this->factoryCalls ??= new CatalogCallResolver($this->index))->factoryReturnCandidates($receiver, $context);
        if ($selected['limited']) {
            $this->notice($callback, 'AI response factory exceeded its source budget.', 'catalog_limit');

            return [];
        }
        $resolved = [];
        foreach ($selected['values'] as $value) {
            if (! isset($value['value_origin']) || $value['sources'] === []) {
                continue;
            }
            $source = $value['sources'][array_key_last($value['sources'])];
            $site = $sites[$source['path']][$value['value_origin']] ?? null;
            $producer = $callbacks[$source['path']][$value['value_origin']] ?? null;
            $candidates = $site !== null ? [['path' => $source['path'], 'offset' => $site['offset'], 'sources' => []]]
                : ($producer === null ? [] : $this->callbackOrigin($producer, $origins, $sites, $callbacks, $seen));
            foreach ($candidates as $origin) {
                if ($producer !== null && ! $this->validResponseProducer($producer, $origin)) {
                    continue;
                }
                $key = $origin['path'].':'.$origin['offset'];
                if (count($resolved) >= 128 && ! isset($resolved[$key]) || ! $this->room($callback)) {
                    $this->notice($callback, 'AI response factories exceed their source candidate budget.', 'catalog_limit');

                    return array_values($resolved);
                }
                $resolved[$key] = [...$origin, 'sources' => [...$value['sources'], ...$origin['sources']]];
            }
        }

        return array_values($resolved);
    }

    /** @param array<string, mixed> $callback
     * @return list<string>
     */
    private function callbackTargets(array $callback, bool $queued): array
    {
        $target = $callback['metadata']['target'];
        if ($target !== null && ($this->index->elements[$target]['kind'] ?? null) === 'closure') {
            return [$target];
        }
        if ($callback['metadata']['argument_forms'][0] === 'dynamic') {
            $value = $this->callbackValues[$callback['path']][$callback['metadata']['end_offset']] ?? null;
            if ($value === null) {
                return [];
            }

            return $this->callbackValueTargets($callback, $value, $queued);
        }
        if ($callback['metadata']['argument_forms'][0] === 'named' || $callback['metadata']['named_method'] !== null) {
            return $this->namedTargets($callback, $callback['metadata']['named_hash'], $callback['metadata']['named_method']);
        }
        if (! in_array($callback['metadata']['argument_forms'][0], ['first-class', 'array'], true)) {
            return [];
        }
        $targets = [];
        foreach ($this->callableReferences[$callback['path']][$callback['metadata']['argument_offset']] ?? [] as $reference) {
            if (! $this->room($callback)) {
                return [];
            }
            $creator = $reference['metadata']['bound_callable']['creator'] ?? $reference['from'];
            $scope = $callback['metadata']['argument_forms'][0] === 'array' ? '' : $this->callableScope($creator);
            $receiver = $reference['metadata']['receiver'];
            $instance = $reference['metadata']['form'] === 'instance'
                || in_array($reference['metadata']['form'], ['self', 'parent', 'static'], true) && ($reference['metadata']['static_context'] ?? true) === false;
            $instance = $instance || $callback['metadata']['argument_forms'][0] === 'first-class'
                && $reference['metadata']['form'] === 'class' && ($reference['metadata']['static_context'] ?? true) === false
                && $this->boundClassCompatible($scope, $receiver);
            $selected = $this->calls->sourceCallableCandidates($receiver, $reference['metadata']['method'], $reference['metadata']['exact_receiver'],
                ['creator' => $scope, 'form' => str_starts_with($receiver, '@function:') ? 'function' : ($instance ? 'instance' : 'static'),
                    'binding' => $reference['metadata']['late_static_receiver'] ? 'static' : $reference['metadata']['lexical_static_receiver']]);
            if ($selected['limited']) {
                $this->notice($callback, 'AI response callable selection exceeded its source budget.', 'catalog_limit');
            } else {
                array_push($targets, ...$selected['targets']);
            }
        }

        return array_values(array_unique($targets));
    }

    /** @param array<string, mixed> $callback
     * @param  array{receiver_hash: string, method_hash: ?string}|null  $selector
     * @return list<string>
     */
    private function namedTargets(array $callback, ?string $name, ?array $selector): array
    {
        if ($selector === null) {
            $targets = $this->namedFunctions[$name ?? ''] ?? [];

            return count($targets) === 1 ? $targets : [];
        }
        $receivers = $this->namedTypes[$selector['receiver_hash']] ?? [];
        $method = $this->methodNames[$selector['method_hash'] ?? ''] ?? null;
        if (count($receivers) !== 1 || $method === null) {
            return [];
        }
        $selected = $this->calls->sourceCallableCandidates($receivers[0], $method, true,
            ['creator' => '', 'form' => 'static', 'binding' => null]);
        if ($selected['limited']) {
            $this->notice($callback, 'AI response named method selection exceeded its source budget.', 'catalog_limit');

            return [];
        }

        return $selected['targets'];
    }

    /** @param array<string, mixed> $callback
     * @param  array<string, mixed>  $value
     * @return list<string>
     */
    private function callbackValueTargets(array $callback, array $value, bool $queued, int $depth = 0): array
    {
        if ($depth >= 16 || ! $this->room($callback)) {
            $this->notice($callback, 'AI callback factory source depth exceeded its budget.', 'catalog_limit');

            return [];
        }
        if (str_starts_with($value['receiver'], '@return:')) {
            $values = $this->callbackFactoryValues($callback, $value);
            $targets = [];
            foreach ($values as $returned) {
                $selected = $this->callbackValueTargets($callback, $returned, $queued, $depth + 1);
                foreach ($selected as $target) {
                    $this->callbackReturnSources[$callback['id']][$target] = $returned['sources'];
                }
                array_push($targets, ...$selected);
            }

            return array_values(array_unique($targets));
        }
        if ($queued && ! $value['closure']) {
            return [];
        }
        $receiver = $value['receiver'];
        if (str_starts_with($receiver, '@named-callable:')) {
            $selector = json_decode(substr($receiver, 16), true, 32);
            if (! is_array($selector) || ! array_is_list($selector) || count($selector) !== 3
                || ! is_string($selector[0]) || $selector[1] !== null && ! is_string($selector[1]) || $selector[2] !== null && ! is_string($selector[2])) {
                return [];
            }

            return $this->namedTargets($callback, $selector[0], $selector[1] === null ? null : ['receiver_hash' => $selector[1], 'method_hash' => $selector[2]]);
        }
        if (str_starts_with($receiver, '@closure:')) {
            return array_values(array_filter($this->index->names[strtolower(substr($receiver, 9))] ?? [],
                fn ($id) => $this->index->elements[$id]['kind'] === 'closure'));
        }
        $method = '__invoke';
        $exact = $value['exact'];
        $form = str_starts_with($receiver, '@function:') ? 'function' : $value['form'];
        if (str_starts_with($receiver, '@method-callable:')) {
            $descriptor = json_decode(substr($receiver, 17), true, 32);
            if (! is_array($descriptor) || count($descriptor) !== 3 || ! is_string($descriptor[0]) || ! is_string($descriptor[1]) || ! is_bool($descriptor[2])) {
                return [];
            }
            [$receiver, $method, $exact] = $descriptor;
        }
        $scope = $value['closure'] ? $this->callableScope($value['creator']) : '';
        if ($form === 'bound-class') {
            $form = $value['closure'] && $this->boundClassCompatible($scope, $receiver) ? 'instance' : 'static';
        }
        $selected = $this->calls->sourceCallableCandidates($receiver, $method, $exact,
            ['creator' => $scope, 'form' => $form, 'binding' => $value['binding']]);
        if ($selected['limited']) {
            $this->notice($callback, 'Stored AI callback selection exceeded its source budget.', 'catalog_limit');

            return [];
        }

        return $selected['targets'];
    }

    /** @param array<string, mixed> $callback
     * @param  array<string, mixed>  $value
     * @return list<array<string, mixed>>
     */
    private function callbackFactoryValues(array $callback, array $value): array
    {
        if ($value['factory_call'] === null) {
            return [];
        }
        $selected = ($this->factoryCalls ??= new CatalogCallResolver($this->index))->factoryReturnCandidates($value['receiver'], $value['factory_call']);
        if ($selected['limited']) {
            $this->notice($callback, 'AI callback factory selection exceeded its source budget.', 'catalog_limit');

            return [];
        }
        $values = [];
        foreach ($selected['values'] as $returned) {
            $binding = $returned['bound_callable'] ?? null;
            $receiver = $returned['receiver'];
            $values[] = ['receiver' => $receiver, 'exact' => $returned['exact_receiver'],
                'form' => $returned['callable_form'] ?? 'instance', 'creator' => $binding['creator'] ?? null,
                'closure' => str_starts_with($receiver, '@closure:') || str_starts_with($receiver, '@function:') || ($binding['scope_bound'] ?? false),
                'binding' => ($binding['late'] ?? false) ? 'static' : ($binding['lexical'] ?? null),
                'factory_call' => $returned['factory_call'] ?? null, 'sources' => $returned['sources']];
        }

        return $values;
    }

    /** @param array<string, mixed> $site
     * @param  array<string, mixed>  $factory
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     * @param  array<string, mixed>  $delivery
     */
    private function factoryAttachments(string $invocation, array $site, array $factory, array $profile, array $delivery): void
    {
        $selected = ($this->factoryCalls ??= new CatalogCallResolver($this->index))->factoryReturnCandidates($factory['receiver'], $factory['call']);
        if ($selected['limited']) {
            $this->notice($site, 'AI attachment factory return selection exceeded its source budget.', 'catalog_limit');

            return;
        }
        $found = false;
        foreach ($selected['values'] as $returned) {
            if (! $this->room($site)) {
                return;
            }
            if (! isset($returned['ai_attachments']) || $returned['sources'] === []) {
                $this->notice($site, 'AI attachment factory has an unsupported return candidate.');

                continue;
            }
            $sources = $returned['sources'];
            $leaf = $sources[array_key_last($sources)];
            $copy = [...$site, 'path' => $leaf['path'], 'parent' => $leaf['producer']];
            $copy['metadata']['attachments'] = $returned['ai_attachments'];
            $this->attachments($invocation, $copy, $profile, [...$delivery, 'attachment_return_path_choice_required' => true,
                'attachment_return_sources' => $sources, 'attachment_consumer_source' => ['path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line']]]);
            $found = true;
        }
        if (! $found) {
            $this->notice($site, 'AI attachment factory return cannot be resolved from source.');
        }
    }

    /** @param array<string, mixed> $site
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     * @param  array<string, mixed>  $delivery
     */
    private function attachments(string $invocation, array $site, array $profile, array $delivery): void
    {
        if ($site['metadata']['attachments']['limited']) {
            $this->notice($site, 'AI attachment list exceeded its 128-member source budget.', 'catalog_limit');
        } elseif (! $site['metadata']['attachments']['resolved']) {
            $this->notice($site, 'AI attachments contain a dynamic or unsupported source expression.');
        }
        foreach ($site['metadata']['attachments']['files'] as $file) {
            if (! $this->room($site)) {
                return;
            }
            if (! $file['valid'] || $this->index->namedTypes($file['receiver']) !== []
                || strcasecmp($file['receiver'], 'Laravel\\Ai\\Files\\Video') === 0 && version_compare($profile['version'], '0.10.0.0', '<')) {
                $this->notice($site, 'AI attachment factory is invalid, source-shadowed or unavailable in this SDK version.');

                continue;
            }
            $id = CatalogElement::identity($site['path'], 'ai-attachment', $file['receiver'].':'.$file['method'], $file['offset']);
            $new = ! isset($this->index->elements[$id]);
            $creator = $this->attachmentCreators[$site['path']][$file['offset']] ?? $site['parent'];
            $element = new CatalogElement($id, 'AI attachment '.$file['receiver'].'::'.$file['method'], 'ai-attachment', $file['line'], $file['end_line'], $file['offset'], $creator,
                metadata: ['package' => 'laravel/ai', 'version' => $profile['version'], 'file_type' => $file['receiver'], 'factory_method' => $file['method'], 'execution_proven' => false]);
            $this->index->elements[$id] = [...$element->toArray(), 'path' => $site['path'], 'knowledge' => 'static', 'role_evidence' => [],
                'sources' => [['path' => $site['path'], 'line' => $file['line'], 'end_line' => $file['end_line']]]];
            if (! in_array($id, $this->index->names[strtolower($element->name)] ?? [], true)) {
                $this->index->names[strtolower($element->name)][] = $id;
            }
            $evidence = [...$site, 'line' => $file['line'], 'end_line' => $file['end_line']];
            if ($new) {
                $this->edge($creator, $id, 'prepares-ai-attachment', $evidence, $profile, ['file_content_retained' => false]);
            }
            $this->edge($invocation, $id, 'uses-ai-attachment', $evidence, $profile, $delivery);
        }
    }

    private function boundClassCompatible(string $scope, string $receiver): bool
    {
        $creators = $this->index->namedTypes($scope);

        return count($creators) === 1 && count($this->index->namedTypes($receiver)) === 1
            && $this->index->hasContract($creators[0], $receiver);
    }

    private function callableScope(?string $creator): string
    {
        for ($depth = 0; $creator !== null && $depth < 32; $depth++) {
            $element = $this->index->elements[$creator] ?? null;
            if ($element === null) {
                break;
            }
            if (in_array($element['kind'], ['class', 'trait', 'enum'], true)) {
                return $element['name'];
            }
            $creator = $element['parent'];
        }

        return '';
    }

    /** @param array<string, mixed> $callback */
    private function callbackValuesCompatible(array $callback, bool $queued): bool
    {
        if (! $queued) {
            return true;
        }
        foreach ($callback['metadata']['chain_ends'] as $end) {
            $value = $this->callbackValues[$callback['path']][$end] ?? null;
            if ($value !== null && ! $value['closure']) {
                if (! str_starts_with($value['receiver'], '@return:') || $this->callbackValueTargets($callback, $value, true) === []) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $site
     * @return list<string>
     */
    private function receivers(string $receiver, array $site, int $depth = 0): array
    {
        if ($depth >= 16 || ! $this->room($site)) {
            return [];
        }
        $selected = $this->calls->receiverCandidates($receiver);
        if ($selected['limited']) {
            $this->notice($site, 'AI receiver selection reached its source budget.', 'catalog_limit');

            return [];
        }
        if ($selected['types'] !== [] || ! str_starts_with($receiver, '@return:')) {
            return $selected['types'];
        }
        $descriptor = json_decode(substr($receiver, 8), true, 8);
        if (! is_array($descriptor) || count($descriptor) !== 3 || ! is_string($descriptor[0]) || ! is_string($descriptor[1])) {
            return [];
        }
        $result = [];
        foreach ($this->receivers($descriptor[0], $site, $depth + 1) as $type) {
            $ids = $this->index->namedTypes($type);
            if (count($ids) !== 1 || ! $this->index->hasContract($ids[0], 'Laravel\\Ai\\Promptable') || $this->index->namedTypes('Laravel\\Ai\\Promptable') !== []) {
                continue;
            }
            $method = $this->calls->sourceMethodCandidate($type, $descriptor[1], knownTraits: ['Laravel\\Ai\\Promptable'], knownTraitMethods: CatalogAiOperations::TRAIT_METHODS);
            if (strtolower($descriptor[1]) === 'make' && ! $method['limited'] && ! $method['unresolved'] && $method['method'] === null) {
                $result[] = $type;
            } else {
                $this->notice($site, 'AI fluent/factory return is unresolved or source-overridden.');
            }
        }

        return array_values(array_unique($result));
    }

    /** @param array<string, mixed> $site */
    private function method(string $type, string $name, array $site): ?string
    {
        $method = $this->calls->sourceMethodCandidate($type, $name, knownTraits: ['Laravel\\Ai\\Promptable'], knownTraitMethods: CatalogAiOperations::TRAIT_METHODS);
        if ($method['limited'] || $method['unresolved'] || $method['method'] === null
            || ($method['method']['metadata']['visibility'] ?? null) !== 'public' || ($method['method']['metadata']['static'] ?? false)) {
            $this->notice($site, 'AI source hook is absent, ambiguous or inaccessible.');

            return null;
        }

        return $method['method']['id'];
    }

    /** @param array<string, mixed> $site */
    private function room(array $site): bool
    {
        if (++$this->operations <= 4096 && ($this->operations % 128 !== 0 || ImpactExtractor::sourceLimit(0) === null)) {
            return true;
        }
        $this->notice($site, 'AI composition reached its operation or memory budget.', 'catalog_limit');

        return false;
    }

    /** @param array<string, mixed> $site */
    private function notice(array $site, string $message, string $code = 'package_ai_analysis'): void
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
            'knowledge' => 'static', 'resolution' => 'conditional', 'metadata' => ['package' => 'laravel/ai', 'version' => $profile['version'],
                'package_sources' => $profile['sources'], ...$metadata, 'execution_proven' => false]]);
    }
}
