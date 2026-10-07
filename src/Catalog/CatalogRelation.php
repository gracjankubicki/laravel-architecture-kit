<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use InvalidArgumentException;

/** Local evidence may refer to a named target resolved when composing the snapshot. */
final readonly class CatalogRelation
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $from,
        public string $to,
        public string $kind,
        public int $line,
        public int $endLine,
        public string $resolution = 'resolved',
        public array $metadata = [],
    ) {
        if ($from === '' || $to === '' || $kind === '' || $line < 1 || $endLine < $line || ! in_array($resolution, ['resolved', 'conditional'], true)) {
            throw new InvalidArgumentException('Invalid catalog relation.');
        }
        CatalogFacts::validateValues($metadata);
        if (array_key_exists('saloon_pool_members', $metadata)) {
            $members = $metadata['saloon_pool_members'];
            if (! in_array($kind, ['calls', 'returns-value'], true)
                || $kind === 'calls' && ! in_array(strtolower($metadata['method'] ?? ''), ['pool', 'setrequests', '__construct'], true)
                || $kind === 'returns-value' && ! in_array($metadata['receiver'] ?? null, ['@pool-members', '@ai-attachments'], true)
                || ! is_array($members) || array_keys($members) !== ['complete', 'members'] || ! is_bool($members['complete'])
                || ! is_array($members['members']) || ! array_is_list($members['members']) || count($members['members']) > 128) {
                throw new InvalidArgumentException('Invalid source pool member receivers.');
            }
            foreach ($members['members'] as $member) {
                if (! is_array($member) || array_keys($member) !== ['receiver', 'origin']
                    || ! is_string($member['receiver']) || $member['receiver'] === '' || strlen($member['receiver']) > 10000
                    || $member['origin'] !== null && (! is_int($member['origin']) || $member['origin'] < 0)) {
                    throw new InvalidArgumentException('Invalid source pool member receiver.');
                }
            }
        }
        if (array_key_exists('saloon_pool_connector', $metadata)
            && ($kind !== 'calls' || ($metadata['method'] ?? '') !== '__construct'
                || $metadata['saloon_pool_connector'] !== null && (! is_string($metadata['saloon_pool_connector']) || $metadata['saloon_pool_connector'] === ''))) {
            throw new InvalidArgumentException('Invalid source Pool connector type.');
        }
        if (array_key_exists('saloon_pool_send', $metadata)
            && ($kind !== 'calls' || strtolower($metadata['method'] ?? '') !== 'send' || ! is_bool($metadata['saloon_pool_send']))) {
            throw new InvalidArgumentException('Invalid Saloon pool consumption shape.');
        }
        if (array_key_exists('ai_attachments', $metadata)) {
            if (! in_array($kind, ['calls', 'returns-value'], true) || $kind === 'returns-value' && ($metadata['receiver'] ?? null) !== '@ai-attachments') {
                throw new InvalidArgumentException('AI attachments belong to source calls or explicit returned attachment values.');
            }
            CatalogAiOperations::validateAttachments($metadata['ai_attachments']);
        }
        foreach (['ai_attachment_factory', 'saloon_pool_member_factory'] as $field) {
            if (! array_key_exists($field, $metadata)) {
                continue;
            }
            $factory = $metadata[$field];
            if ($field === 'saloon_pool_member_factory' && ! in_array(strtolower($metadata['method'] ?? ''), ['pool', 'setrequests', '__construct'], true)) {
                throw new InvalidArgumentException('Invalid Pool member factory consumer.');
            }
            if ($kind !== 'calls' || ! is_array($factory) || array_keys($factory) !== ['receiver', 'call']
                || ! is_string($factory['receiver']) || ! str_starts_with($factory['receiver'], '@return:')) {
                throw new InvalidArgumentException('Invalid source value factory.');
            }
            $descriptor = json_decode(substr($factory['receiver'], 8), true, 32);
            if (! is_array($descriptor) || ! array_is_list($descriptor) || count($descriptor) !== 3
                || ! is_string($descriptor[0]) || $descriptor[0] === '' || ! is_string($descriptor[1]) || $descriptor[1] === '' || ! is_bool($descriptor[2])) {
                throw new InvalidArgumentException('Invalid source value factory receiver.');
            }
            self::validateFactoryCall($factory['call']);
        }
        if (array_key_exists('end_offset', $metadata) && (! is_int($metadata['end_offset']) || $metadata['end_offset'] < 0)) {
            throw new InvalidArgumentException('Invalid catalog call end offset.');
        }
        if (array_key_exists('value_origin', $metadata) && ($kind !== 'returns-value' || ! is_int($metadata['value_origin']) || $metadata['value_origin'] < 0)) {
            throw new InvalidArgumentException('Invalid returned value origin.');
        }
        if (array_key_exists('saloon_request', $metadata)) {
            $request = $metadata['saloon_request'];
            if ($kind !== 'calls' || ! is_array($request) || array_keys($request) !== ['valid', 'receiver', 'request_side_valid', 'mock_receiver']
                || ! is_bool($request['valid']) || ! is_bool($request['request_side_valid'])
                || $request['receiver'] !== null && (! is_string($request['receiver']) || $request['receiver'] === '')
                || $request['mock_receiver'] !== null && (! is_string($request['mock_receiver']) || $request['mock_receiver'] === '')) {
                throw new InvalidArgumentException('Invalid Saloon request type descriptor.');
            }
        }
        if (array_key_exists('saloon_pool_callback_values', $metadata)) {
            $callbacks = $metadata['saloon_pool_callback_values'];
            if ($kind !== 'calls' || ! in_array(strtolower($metadata['method'] ?? ''), ['pool', '__construct', ...array_keys(CatalogSaloonOperations::POOL_SETTERS)], true)
                || ! is_array($callbacks) || $callbacks === [] || count($callbacks) > 3) {
                throw new InvalidArgumentException('Invalid Pool callback descriptors.');
            }
            foreach ($callbacks as $callbackKind => $value) {
                if (! in_array($callbackKind, ['response', 'exception', 'concurrency'], true)) {
                    throw new InvalidArgumentException('Invalid Pool callback kind.');
                }
                self::validateCallbackValue($value);
            }
        }
        if (array_key_exists('callback_value', $metadata)) {
            self::validateCallbackValue($metadata['callback_value']);
        }
        if (array_key_exists('provider_gateway_value', $metadata)) {
            if ($kind !== 'calls' || ! in_array(strtolower($metadata['method'] ?? ''), [...CatalogAiGateways::SETTERS, '__construct'], true)) {
                throw new InvalidArgumentException('Invalid AI provider gateway argument.');
            }
            self::validateCallbackValue($metadata['provider_gateway_value']);
        }
        if (array_key_exists('provider_constructor_arguments', $metadata)) {
            $arguments = $metadata['provider_constructor_arguments'];
            if ($kind !== 'calls' || ($metadata['method'] ?? null) !== '__construct' || ($metadata['form'] ?? null) !== 'new'
                || ! is_array($arguments) || ! array_is_list($arguments) || ! in_array(count($arguments), [2, 3], true)) {
                throw new InvalidArgumentException('Invalid AI provider constructor shape.');
            }
            foreach ($arguments as $argument) {
                if (! is_array($argument) || array_keys($argument) !== ['name', 'unpack', 'by_ref'] || ! is_bool($argument['unpack']) || ! is_bool($argument['by_ref'])
                    || $argument['name'] !== null && (! is_string($argument['name']) || $argument['name'] === '' || strlen($argument['name']) > 1000)) {
                    throw new InvalidArgumentException('Invalid AI provider constructor argument.');
                }
            }
        }
        if (array_key_exists('gateway_callback_values', $metadata)) {
            $values = $metadata['gateway_callback_values'];
            if ($kind !== 'calls' || strtolower($metadata['method'] ?? '') !== 'ontoolinvocation' || ! is_array($values) || count($values) > 128) {
                throw new InvalidArgumentException('Invalid AI gateway callback arguments.');
            }
            foreach ($values as $position => $value) {
                if (! is_int($position) || $position < 0 || $position >= 128) {
                    throw new InvalidArgumentException('Invalid AI gateway callback position.');
                }
                self::validateCallbackValue($value);
            }
        }
        if ($kind === 'returns-value' && is_string($metadata['receiver'] ?? null) && str_starts_with($metadata['receiver'], '@named-callable:')) {
            self::validateNamedCallable($metadata['receiver']);
        }
        if (array_key_exists('callable_form', $metadata) && ! in_array($metadata['callable_form'], ['static', 'instance', 'bound-class'], true)) {
            throw new InvalidArgumentException('Invalid returned callable form.');
        }
        if ($kind === 'returns-parameter' && (! is_int($metadata['parameter_index'] ?? null) || $metadata['parameter_index'] < 0)) {
            throw new InvalidArgumentException('Invalid returned parameter index.');
        }
        if (($kind === 'returns-parameter' || $kind === 'returns-null' && ! isset($metadata['return_origin']))
            && (! is_int($metadata['offset'] ?? null) || $metadata['offset'] < 0)) {
            throw new InvalidArgumentException('Invalid return source offset.');
        }
        if (array_key_exists('return_origin', $metadata) && ($kind !== 'returns-null' || ! in_array($metadata['return_origin'], ['implicit'], true))) {
            throw new InvalidArgumentException('Invalid null return origin.');
        }
        foreach (['conditionable_fallback', 'conditionable_supplier'] as $field) {
            if (! array_key_exists($field, $metadata)) {
                continue;
            }
            $fallback = $metadata[$field];
            if (! is_array($fallback) || ! is_string($fallback['callback_receiver'] ?? null) || ! is_string($fallback['callback_method'] ?? null)
                || ! is_bool($fallback['callback_exact'] ?? null) || ! isset($fallback['factory_call'])) {
                throw new InvalidArgumentException('Invalid Conditionable fallback descriptor.');
            }
            self::validateFactoryCall($fallback['factory_call']);
        }
        if (array_key_exists('conditionable_contexts', $metadata)) {
            $contexts = $metadata['conditionable_contexts'];
            if (! is_array($contexts) || ! array_is_list($contexts) || $contexts === [] || count($contexts) > 32) {
                throw new InvalidArgumentException('Invalid Conditionable contexts.');
            }
            foreach ($contexts as $context) {
                if (! is_array($context) || array_keys($context) !== ['factory', 'method'] || ! in_array($context['factory'], CatalogRuleFactories::CONDITIONABLE, true)
                    || ! in_array($context['method'], ['when', 'unless'], true)) {
                    throw new InvalidArgumentException('Invalid Conditionable context.');
                }
            }
        }
        if (in_array($kind, ['returned-rule-builder-callback', 'returned-rule-builder-callable'], true) && ! in_array($metadata['builder_branch'] ?? null, ['condition', 'callback', 'default'], true)) {
            throw new InvalidArgumentException('Invalid rule builder branch.');
        }
        if (array_key_exists('deferred_rule_contexts', $metadata)) {
            $contexts = $metadata['deferred_rule_contexts'];
            if (! is_array($contexts) || ! array_is_list($contexts) || $contexts === [] || count($contexts) > 32) {
                throw new InvalidArgumentException('Invalid deferred rule contexts.');
            }
            foreach ($contexts as $context) {
                if (! is_array($context) || array_keys($context) !== ['factory', 'modifier'] || ! in_array($context['factory'], ['file', 'imagefile', 'email'], true)
                    || ! in_array($context['modifier'], $context['factory'] === 'imagefile' ? ['rules', 'dimensions'] : ['rules'], true)) {
                    throw new InvalidArgumentException('Invalid deferred rule modifier.');
                }
            }
        }
        if (array_key_exists('json_serialization_receiver', $metadata) && ! is_string($metadata['json_serialization_receiver'])) {
            throw new InvalidArgumentException('Invalid JSON serialization receiver.');
        }
        foreach (['factory_call', 'receiver_factory_call'] as $field) {
            if (array_key_exists($field, $metadata)) {
                self::validateFactoryCall($metadata[$field]);
            }
        }
        if (array_key_exists('framework_rule_contexts', $metadata)) {
            $contexts = $metadata['framework_rule_contexts'];
            if (! is_array($contexts) || ! array_is_list($contexts) || $contexts === [] || count($contexts) > 32) {
                throw new InvalidArgumentException('Invalid framework rule contexts.');
            }
            foreach ($contexts as $context) {
                if (! is_array($context) || array_keys($context) !== ['method', 'branch'] || ! in_array($context['method'], ['when', 'unless', 'foreach', 'anyof', ...CatalogRuleFactories::CONDITIONS], true)
                    || ! in_array($context['branch'], $context['method'] === 'anyof' ? ['rules'] : ($context['method'] === 'foreach' || in_array($context['method'], CatalogRuleFactories::CONDITIONS, true) ? ['callback'] : ['condition', 'rules', 'defaultRules']), true)) {
                    throw new InvalidArgumentException('Invalid framework rule branch.');
                }
            }
        }
        if ($kind === 'validator-instance-candidate' && ! in_array($metadata['validator_method'] ?? null, ['passes', 'fails', 'validate', 'validatewithbag', 'validated', 'safe'], true)) {
            throw new InvalidArgumentException('Invalid validator instance method.');
        }
        if ($kind === 'returned-rule-factory') {
            if (! isset($metadata['factory_call'])) {
                throw new InvalidArgumentException('Missing source rule factory call form.');
            }
            $receiver = $metadata['return_receiver'] ?? null;
            $descriptor = is_string($receiver) && str_starts_with($receiver, '@return:') ? json_decode(substr($receiver, 8), true, 32) : null;
            if (! is_array($descriptor) || array_keys($descriptor) !== [0, 1, 2] || ! is_string($descriptor[0]) || ! is_string($descriptor[1]) || ! is_bool($descriptor[2])) {
                throw new InvalidArgumentException('Invalid rule factory return descriptor.');
            }
        }
        if (in_array($kind, ['returned-rule-condition-callable', 'returned-rule-query-callable', 'returned-rule-builder-callable'], true) && (! is_string($metadata['callback_receiver'] ?? null) || ! is_string($metadata['callback_method'] ?? null)
            || ! is_bool($metadata['callback_exact'] ?? null) || ! isset($metadata['factory_call']))) {
            throw new InvalidArgumentException('Invalid first-class rule condition descriptor.');
        }
        if (array_key_exists('fluent_methods', $metadata) && (! is_array($metadata['fluent_methods']) || ! array_is_list($metadata['fluent_methods'])
            || count($metadata['fluent_methods']) > 32 || count(array_filter($metadata['fluent_methods'], 'is_string')) !== count($metadata['fluent_methods']))) {
            throw new InvalidArgumentException('Invalid framework rule fluent method list.');
        }
        if (array_key_exists('offset', $metadata) && (! is_int($metadata['offset']) || $metadata['offset'] < 0)) {
            throw new InvalidArgumentException('Invalid catalog relation source offset.');
        }
        if (array_key_exists('receiver_origin', $metadata) && (! is_int($metadata['receiver_origin']) || $metadata['receiver_origin'] < 0)) {
            throw new InvalidArgumentException('Invalid source receiver origin offset.');
        }
        if (array_key_exists('receiver_instance_origin', $metadata) && (! is_int($metadata['receiver_instance_origin']) || $metadata['receiver_instance_origin'] < 0)) {
            throw new InvalidArgumentException('Invalid source receiver instance origin offset.');
        }
        if ($kind === 'returns-value' && (! is_string($metadata['receiver'] ?? null) || ! is_bool($metadata['exact_receiver'] ?? null))) {
            throw new InvalidArgumentException('Invalid catalog returned value descriptor.');
        }
        if (str_starts_with($to, 'execution:')) {
            ExecutionCatalogExtractor::validate($metadata);
        }
        if (str_starts_with($to, 'http:')) {
            foreach (['provider_owner', 'provider_method', 'provider_scope'] as $key) {
                if (array_key_exists($key, $metadata) && $metadata[$key] !== null && ! is_string($metadata[$key])) {
                    throw new InvalidArgumentException('Invalid HTTP provider candidate.');
                }
            }
            if (! in_array($metadata['operation'] ?? null, ['route', 'load', 'provider', 'provider-settings'], true) || ! is_int($metadata['offset'] ?? null)
                || ! is_array($metadata['context'] ?? null) || ! is_array($metadata['load_paths'] ?? null) || ! array_is_list($metadata['load_paths'])
                || count(array_filter($metadata['load_paths'], 'is_string')) !== count($metadata['load_paths']) || ! is_string($metadata['resource'] ?? null)) {
                throw new InvalidArgumentException('Invalid HTTP template descriptor.');
            }
            if (($metadata['operation'] ?? null) === 'provider-settings' && (! array_key_exists('bootstrap_enabled', $metadata) || $metadata['bootstrap_enabled'] !== null && ! is_bool($metadata['bootstrap_enabled']))) {
                throw new InvalidArgumentException('Invalid HTTP provider bootstrap setting.');
            }
            foreach (['uri', 'provider'] as $key) {
                if (! array_key_exists($key, $metadata) || $metadata[$key] !== null && ! is_string($metadata[$key])) {
                    throw new InvalidArgumentException('Invalid HTTP template name.');
                }
            }
            $context = $metadata['context'];
            foreach (['prefix', 'name', 'domain', 'namespace', 'controller'] as $key) {
                if (! array_key_exists($key, $context) || $context[$key] !== null && ! is_string($context[$key])) {
                    throw new InvalidArgumentException('Invalid HTTP context name.');
                }
            }
            foreach (['middleware', 'excluded_middleware', 'reasons'] as $key) {
                if (! array_key_exists($key, $context) || $context[$key] !== null && (! is_array($context[$key]) || ! array_is_list($context[$key]) || count(array_filter($context[$key], 'is_string')) !== count($context[$key]))) {
                    throw new InvalidArgumentException('Invalid HTTP context list.');
                }
            }
            if (! is_bool($context['possible'] ?? null) || ! is_array($context['specified'] ?? null) || $context['reasons'] === null) {
                throw new InvalidArgumentException('Invalid HTTP context state.');
            }
            $verbs = $metadata['verbs'] ?? null;
            if ($verbs !== null && (! is_array($verbs) || ! array_is_list($verbs) || count(array_filter($verbs, 'is_string')) !== count($verbs))) {
                throw new InvalidArgumentException('Invalid HTTP methods.');
            }
            $handler = $metadata['handler'] ?? null;
            if ($handler !== null && (! is_array($handler) || ! (
                in_array(array_keys($handler), [['callback'], ['callback', 'parameters']], true) && ($handler['callback'] === null || is_string($handler['callback']))
                || array_keys($handler) === ['class', 'method'] && is_string($handler['class']) && is_string($handler['method'])
                || array_keys($handler) === ['string'] && is_string($handler['string'])
            ))) {
                throw new InvalidArgumentException('Invalid HTTP handler.');
            }
            if (isset($handler['parameters'])) {
                if (! is_array($handler['parameters']) || ! array_is_list($handler['parameters'])) {
                    throw new InvalidArgumentException('Invalid HTTP callback parameters.');
                }
                foreach ($handler['parameters'] as $parameter) {
                    if (! is_array($parameter) || ! is_string($parameter['name'] ?? null) || ! is_bool($parameter['nullable'] ?? null)
                        || ! is_bool($parameter['route_resolvable'] ?? null) || ! is_array($parameter['types'] ?? null)
                        || ! array_is_list($parameter['types']) || count(array_filter($parameter['types'], 'is_string')) !== count($parameter['types'])) {
                        throw new InvalidArgumentException('Invalid HTTP callback parameter shape.');
                    }
                }
            }
        }
        if (str_starts_with($to, 'dispatch:') && (! is_string($metadata['receiver'] ?? null) || ! is_string($metadata['method'] ?? null)
            || ! is_bool($metadata['exact_receiver'] ?? null) || ! is_string($metadata['form'] ?? null))) {
            throw new InvalidArgumentException('Invalid catalog dispatch descriptor.');
        }
        foreach (['this_receiver', 'late_static_receiver', 'static_context'] as $binding) {
            if (array_key_exists($binding, $metadata) && ! is_bool($metadata[$binding])) {
                throw new InvalidArgumentException('Invalid catalog receiver binding.');
            }
        }
        if (array_key_exists('lexical_static_receiver', $metadata) && ! in_array($metadata['lexical_static_receiver'], [null, 'self', 'parent'], true)) {
            throw new InvalidArgumentException('Invalid catalog lexical receiver binding.');
        }
        if (isset($metadata['bound_callable'])) {
            $binding = $metadata['bound_callable'];
            if (! is_array($binding) || ! in_array(array_keys($binding), [['method', 'this', 'late', 'lexical'], ['method', 'this', 'late', 'lexical', 'creator'], ['method', 'this', 'late', 'lexical', 'creator', 'scope_bound']], true) || ! is_string($binding['method'])
                || array_key_exists('creator', $binding) && (! is_string($binding['creator']) || $binding['creator'] === '')
                || array_key_exists('scope_bound', $binding) && ! is_bool($binding['scope_bound'])
                || ! is_bool($binding['this']) || ! is_bool($binding['late']) || ! in_array($binding['lexical'], [null, 'self', 'parent'], true)) {
                throw new InvalidArgumentException('Invalid catalog callable binding.');
            }
        }
        if (str_starts_with($to, 'container:')) {
            foreach (['abstracts', 'implementations', 'contexts'] as $key) {
                if (! is_array($metadata[$key] ?? null) || ! array_is_list($metadata[$key]) || count($metadata[$key]) > 128 || count(array_filter($metadata[$key], 'is_string')) !== count($metadata[$key])) {
                    throw new InvalidArgumentException('Invalid container registration names.');
                }
            }
            if (! is_string($metadata['receiver'] ?? null) || ! is_string($metadata['mode'] ?? null) || ! is_int($metadata['offset'] ?? null)
                || ! is_bool($metadata['context_unknown'] ?? null) || ! is_bool($metadata['factory_unknown'] ?? null)
                || ! array_key_exists('callback', $metadata) || $metadata['callback'] !== null && ! is_string($metadata['callback'])) {
                throw new InvalidArgumentException('Invalid container registration descriptor.');
            }
        }
    }

    private static function validateCallbackValue(mixed $value): void
    {
        if (! is_array($value) || array_keys($value) !== ['receiver', 'exact', 'form', 'creator', 'closure', 'binding', 'factory_call']
            || ! is_string($value['receiver']) || $value['receiver'] === '' || ! is_bool($value['exact']) || ! is_bool($value['closure'])
            || ! in_array($value['form'], ['static', 'instance', 'bound-class'], true)
            || $value['creator'] !== null && (! is_string($value['creator']) || $value['creator'] === '')
            || ! in_array($value['binding'], [null, 'self', 'parent', 'static'], true)) {
            throw new InvalidArgumentException('Invalid catalog callback value.');
        }
        if ($value['factory_call'] !== null) {
            self::validateFactoryCall($value['factory_call']);
        }
        if (str_starts_with($value['receiver'], '@named-callable:')) {
            self::validateNamedCallable($value['receiver']);
            if ($value['closure']) {
                throw new InvalidArgumentException('A named callback is not a Closure.');
            }
        }
    }

    private static function validateNamedCallable(string $receiver): void
    {
        $selector = json_decode(substr($receiver, 16), true, 32);
        if (! is_array($selector) || ! array_is_list($selector) || count($selector) !== 3 || ! is_string($selector[0])
            || ($selector[1] === null) !== ($selector[2] === null)) {
            throw new InvalidArgumentException('Invalid catalog named callback selector.');
        }
        foreach ($selector as $hash) {
            if ($hash !== null && (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1)) {
                throw new InvalidArgumentException('Invalid catalog named callback hash.');
            }
        }
    }

    private static function validateFactoryCall(mixed $call, int $depth = 0): void
    {
        if ($depth >= 8 || ! is_array($call) || ! in_array(array_keys($call), [['creator', 'form', 'binding'], ['creator', 'form', 'binding', 'receiver_call']], true)
            || ! is_string($call['creator']) || ! in_array($call['form'], ['static', 'function', 'instance'], true)
            || ! in_array($call['binding'], [null, 'self', 'static', 'parent'], true) || $call['form'] !== 'static' && $call['binding'] !== null) {
            throw new InvalidArgumentException('Invalid source factory call context.');
        }
        if (isset($call['receiver_call'])) {
            self::validateFactoryCall($call['receiver_call'], $depth + 1);
        } elseif (array_key_exists('receiver_call', $call)) {
            throw new InvalidArgumentException('Invalid source factory receiver context.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'kind' => $this->kind, 'line' => $this->line,
            'end_line' => $this->endLine, 'resolution' => $this->resolution, 'metadata' => $this->metadata];
    }

    public static function fromArray(mixed $value): self
    {
        if (! is_array($value) || array_keys($value) !== ['from', 'to', 'kind', 'line', 'end_line', 'resolution', 'metadata']) {
            throw new InvalidArgumentException('Invalid catalog relation record.');
        }
        if (in_array($value['kind'], ['returned-rule-builder-callback', 'returned-rule-builder-callable'], true) && ! isset($value['metadata']['conditionable_contexts'])) {
            throw new InvalidArgumentException('Missing cached Conditionable callback context.');
        }

        return new self($value['from'], $value['to'], $value['kind'], $value['line'], $value['end_line'], $value['resolution'], $value['metadata']);
    }
}
