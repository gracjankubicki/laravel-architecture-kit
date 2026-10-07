<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\TestReachability\TestInvocation;
use InvalidArgumentException;

/** A declaration or logical resource. A role never changes legacy layer rules. */
final readonly class CatalogElement
{
    /** @param list<string> $roles
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $kind,
        public int $line,
        public int $endLine,
        public int $offset,
        public ?string $parent = null,
        public array $roles = [],
        public array $metadata = [],
    ) {
        if ($id === '' || $name === '' || $kind === '' || $line < 1 || $endLine < $line || $offset < 0
            || ! array_is_list($roles) || count(array_filter($roles, 'is_string')) !== count($roles)) {
            throw new InvalidArgumentException('Invalid catalog element.');
        }
        CatalogFacts::validateValues($metadata);
        if ($kind === 'saloon-pool-site') {
            if (array_keys($metadata) !== ['end_offset', 'valid', 'targets', 'resolved', 'factory', 'generator', 'form', 'conditional', 'callbacks', 'promise_sites']
                || ! is_int($metadata['end_offset']) || $metadata['end_offset'] < $offset
                || ! in_array($metadata['form'], ['pool', 'new', ...array_keys(CatalogSaloonOperations::POOL_SETTERS)], true)
                || ! is_bool($metadata['conditional'])
                || ! is_bool($metadata['valid']) || ! is_bool($metadata['resolved']) || ! is_bool($metadata['generator'])
                || ! is_array($metadata['targets']) || ! array_is_list($metadata['targets']) || count($metadata['targets']) > 128
                || $metadata['factory'] !== null && (! is_string($metadata['factory']) || $metadata['factory'] === '' || strlen($metadata['factory']) > 2000)) {
                throw new InvalidArgumentException('Invalid source Saloon pool shape.');
            }
            if (! is_array($metadata['callbacks']) || count($metadata['callbacks']) > 3) {
                throw new InvalidArgumentException('Invalid source pool callbacks.');
            }
            $expectedCallbacks = in_array($metadata['form'], ['pool', 'new'], true) ? ['concurrency', 'response', 'exception']
                : ($metadata['form'] === 'setrequests' ? [] : [CatalogSaloonOperations::POOL_SETTERS[$metadata['form']][1]]);
            if (array_keys($metadata['callbacks']) !== $expectedCallbacks) {
                throw new InvalidArgumentException('Invalid source pool callback signature.');
            }
            foreach ($metadata['callbacks'] as $kind => $callback) {
                if (! in_array($kind, ['concurrency', 'response', 'exception'], true)
                    || ! is_array($callback) || array_keys($callback) !== ['id', 'resolved'] || ! is_bool($callback['resolved'])
                    || $callback['id'] !== null && (! is_string($callback['id']) || $callback['id'] === '' || strlen($callback['id']) > 2000 || ! $callback['resolved'])) {
                    throw new InvalidArgumentException('Invalid source pool callback selector.');
                }
            }
            foreach ($metadata['targets'] as $type) {
                if (! is_string($type) || strlen($type) > 500
                    || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $type) !== 1) {
                    throw new InvalidArgumentException('Invalid source Saloon pool member.');
                }
            }
            if (! is_array($metadata['promise_sites']) || ! array_is_list($metadata['promise_sites']) || count($metadata['promise_sites']) > 128) {
                throw new InvalidArgumentException('Invalid source pool promise list.');
            }
            foreach ($metadata['promise_sites'] as $promise) {
                if (! is_array($promise) || array_keys($promise) !== ['offset', 'end_offset'] || ! is_int($promise['offset']) || ! is_int($promise['end_offset'])
                    || $promise['offset'] < $offset || $promise['end_offset'] < $promise['offset'] || $promise['end_offset'] > $metadata['end_offset']) {
                    throw new InvalidArgumentException('Invalid source pool promise span.');
                }
            }
        }
        if (array_key_exists('saloon_url_override', $metadata)) {
            $override = $metadata['saloon_url_override'];
            if ($kind !== 'property' || ! is_array($override) || array_keys($override) !== ['resolved', 'value']
                || ! is_bool($override['resolved']) || ! in_array($override['value'], [true, false, null], true)
                || ! $override['resolved'] && $override['value'] !== null) {
                throw new InvalidArgumentException('Invalid source Saloon URL override property.');
            }
        }
        if (array_key_exists('saloon_connector', $metadata)) {
            $connector = $metadata['saloon_connector'];
            if ($kind !== 'property' || ! is_array($connector) || array_keys($connector) !== ['resolved', 'type']
                || ! is_bool($connector['resolved']) || $connector['resolved'] !== ($connector['type'] !== null)
                || $connector['type'] !== null && (! is_string($connector['type']) || strlen($connector['type']) > 500
                    || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $connector['type']) !== 1)) {
                throw new InvalidArgumentException('Invalid source Saloon connector property.');
            }
        }
        if (array_key_exists('source_return', $metadata)) {
            if ($kind !== 'method' || ! is_int($metadata['end_offset'] ?? null)) {
                throw new InvalidArgumentException('Source return descriptor requires a method.');
            }
            CatalogSourceReturn::validate($metadata['source_return'], $offset, $metadata['end_offset']);
        }
        if (array_key_exists('call_parameters', $metadata)) {
            $signature = $metadata['call_parameters'];
            if (! in_array($kind, ['method', 'function', 'closure'], true) || ! is_array($signature) || array_keys($signature) !== ['complete', 'parameters']
                || ! is_bool($signature['complete']) || ! is_array($signature['parameters']) || ! array_is_list($signature['parameters']) || count($signature['parameters']) > 128) {
                throw new InvalidArgumentException('Invalid source callable parameter shape.');
            }
            $seen = [];
            foreach ($signature['parameters'] as $position => $parameter) {
                if (! is_array($parameter) || array_keys($parameter) !== ['name', 'required', 'variadic', 'by_ref']
                    || ! is_string($parameter['name']) || $parameter['name'] === '' || strlen($parameter['name']) > 1000 || isset($seen[$parameter['name']])
                    || ! is_bool($parameter['required']) || ! is_bool($parameter['variadic']) || ! is_bool($parameter['by_ref'])
                    || $parameter['variadic'] && ($parameter['required'] || $signature['complete'] && $position !== count($signature['parameters']) - 1)) {
                    throw new InvalidArgumentException('Invalid source callable parameter.');
                }
                $seen[$parameter['name']] = true;
            }
        }
        if ($kind === 'livewire-listeners') {
            if ($parent === null || array_keys($metadata) !== ['form', 'mode', 'listeners', 'resolved'] || ! is_bool($metadata['resolved'])
                || ! in_array($metadata['form'], ['method', 'property'], true) || ! in_array($metadata['mode'], ['literal', 'property', 'dynamic'], true)
                || $metadata['mode'] === 'property' && ($metadata['form'] !== 'method' || $metadata['listeners'] !== [])
                || $metadata['mode'] === 'dynamic' && $metadata['resolved']
                || ! is_array($metadata['listeners']) || ! array_is_list($metadata['listeners']) || count($metadata['listeners']) > 128) {
                throw new InvalidArgumentException('Invalid Livewire listener map.');
            }
            foreach ($metadata['listeners'] as $listener) {
                if (! is_array($listener) || array_keys($listener) !== ['event', 'method'] || ! is_string($listener['event']) || ! is_string($listener['method'])
                    || ! LivewireCatalogExtractor::eventName($listener['event']) || ! LivewireCatalogExtractor::listenerMethod($listener['method'])) {
                    throw new InvalidArgumentException('Invalid Livewire listener selector.');
                }
            }
        }
        if (array_key_exists('livewire_class_portion', $metadata)) {
            $portion = $metadata['livewire_class_portion'];
            if ($kind !== 'view' || ! is_array($portion) || array_keys($portion) !== ['start', 'end']
                || ! is_int($portion['start']) || ! is_int($portion['end']) || $portion['start'] < 0 || $portion['end'] <= $portion['start']) {
                throw new InvalidArgumentException('Invalid Livewire class portion.');
            }
        }
        if ($kind === 'livewire-view-action') {
            if ($parent === null || array_keys($metadata) !== ['directive', 'method', 'resolved'] || ! is_bool($metadata['resolved'])
                || ! is_string($metadata['directive']) || preg_match('/\A[a-z][a-z0-9_-]{0,127}\z/D', $metadata['directive']) !== 1
                || $metadata['method'] !== null && (! is_string($metadata['method']) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,255}\z/D', $metadata['method']) !== 1)
                || $metadata['resolved'] !== ($metadata['method'] !== null)) {
                throw new InvalidArgumentException('Invalid Livewire view action.');
            }
        }
        if ($kind === 'livewire-registration') {
            if ($parent === null || array_keys($metadata) !== ['name', 'class', 'resolved'] || ! is_bool($metadata['resolved'])
                || $metadata['name'] !== null && (! is_string($metadata['name']) || ! LivewireCatalogExtractor::eventName($metadata['name']))
                || $metadata['class'] !== null && (! is_string($metadata['class']) || strlen($metadata['class']) > 500 || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $metadata['class']) !== 1)
                || $metadata['resolved'] && ($metadata['name'] === null || $metadata['class'] === null)) {
                throw new InvalidArgumentException('Invalid Livewire registration.');
            }
        }
        if ($kind === 'livewire-dispatch') {
            if ($parent === null || array_keys($metadata) !== ['event', 'target_mode', 'target', 'resolved'] || ! is_bool($metadata['resolved'])
                || $metadata['event'] !== null && (! is_string($metadata['event']) || ! LivewireCatalogExtractor::eventName($metadata['event']))
                || ! in_array($metadata['target_mode'], ['global', 'self', 'component', 'runtime'], true)
                || $metadata['target'] !== null && (! is_string($metadata['target']) || strlen($metadata['target']) > 500 || ! LivewireCatalogExtractor::eventName($metadata['target']) && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $metadata['target']) !== 1)
                || $metadata['target_mode'] !== 'component' && $metadata['target'] !== null
                || $metadata['resolved'] && ($metadata['event'] === null || $metadata['target_mode'] === 'component' && $metadata['target'] === null)) {
                throw new InvalidArgumentException('Invalid Livewire dispatch.');
            }
        }
        if ($kind === 'livewire-attribute') {
            if ($parent === null || array_keys($metadata) !== ['attribute', 'hook', 'events', 'resolved'] || ! is_bool($metadata['resolved'])
                || ! in_array($metadata['hook'], ['on', 'computed', 'validate'], true)
                || $metadata['attribute'] !== 'Livewire\\Attributes\\'.ucfirst($metadata['hook'])
                || ! is_array($metadata['events']) || ! array_is_list($metadata['events']) || count($metadata['events']) > 128
                || $metadata['hook'] !== 'on' && $metadata['events'] !== []) {
                throw new InvalidArgumentException('Invalid Livewire attribute.');
            }
            foreach ($metadata['events'] as $event) {
                if (! is_string($event) || ! LivewireCatalogExtractor::eventName($event)) {
                    throw new InvalidArgumentException('Invalid Livewire event selector.');
                }
            }
        }
        if ($kind === 'broadcast-connection-default') {
            if ($parent === null || array_keys($metadata) !== ['selector', 'resolved'] || ! is_bool($metadata['resolved'])
                || $metadata['selector'] !== null && (! is_string($metadata['selector']) || ! CatalogHorizonOptions::name($metadata['selector']))
                || $metadata['resolved'] !== ($metadata['selector'] !== null)) {
                throw new InvalidArgumentException('Invalid default broadcast connection.');
            }
        }
        if ($kind === 'broadcast-connection-selector') {
            if ($parent === null || array_keys($metadata) !== ['connections', 'resolved'] || ! is_bool($metadata['resolved'])
                || ! is_array($metadata['connections']) || ! array_is_list($metadata['connections']) || count($metadata['connections']) > 128) {
                throw new InvalidArgumentException('Invalid broadcast connections.');
            }
            foreach ($metadata['connections'] as $connection) {
                if ($connection !== null && (! is_string($connection) || ! CatalogHorizonOptions::name($connection))) {
                    throw new InvalidArgumentException('Invalid broadcast connection selector.');
                }
            }
        }
        if ($kind === 'reverb-definition') {
            if ($parent === null || array_keys($metadata) !== ['form', 'selector', 'host', 'port', 'scheme', 'resolved']
                || ! in_array($metadata['form'], ['connection', 'server', 'application'], true) || ! is_bool($metadata['resolved'])
                || $metadata['selector'] !== null && (! is_string($metadata['selector']) || ! CatalogHorizonOptions::name($metadata['selector']))
                || $metadata['resolved'] !== ($metadata['selector'] !== null)
                || $metadata['host'] !== null && (! is_string($metadata['host']) || preg_match('/\A[a-zA-Z0-9_.:\[\]-]{1,253}\z/D', $metadata['host']) !== 1)
                || $metadata['port'] !== null && (! is_int($metadata['port']) || $metadata['port'] < 1 || $metadata['port'] > 65535)
                || $metadata['scheme'] !== null && ! in_array($metadata['scheme'], ['http', 'https'], true)) {
                throw new InvalidArgumentException('Invalid Reverb definition.');
            }
        }
        if ($kind === 'horizon-environment') {
            if ($parent === null || array_keys($metadata) !== ['environment', 'resolved'] || ! is_string($metadata['environment']) || ! CatalogHorizonOptions::name($metadata['environment'], true) || ! is_bool($metadata['resolved'])) {
                throw new InvalidArgumentException('Invalid Horizon environment.');
            }
        }
        if ($kind === 'horizon-definition') {
            if ($parent === null) {
                throw new InvalidArgumentException('Horizon definition requires its source owner.');
            }
            CatalogHorizonOptions::validate($metadata);
        }
        if ($kind === 'cashier-operation') {
            if ($parent === null || array_keys($metadata) !== ['method', 'valid'] || ! is_string($metadata['method'])
                || ! isset(CatalogCashierOperations::MODEL[$metadata['method']]) && ! isset(CatalogCashierOperations::BUILDER[$metadata['method']]) && ! isset(CatalogCashierOperations::SUBSCRIPTION[$metadata['method']])
                || ! is_array($metadata['valid']) || array_keys($metadata['valid']) !== ['model', 'builder', 'subscription'] || count(array_filter($metadata['valid'], 'is_bool')) !== 3) {
                throw new InvalidArgumentException('Invalid Cashier operation.');
            }
        }
        if ($kind === 'socialite-operation') {
            if ($parent === null || array_keys($metadata) !== ['method', 'driver', 'selection_present', 'valid'] || ! is_string($metadata['method'])
                || ! isset(SocialiteCatalogExtractor::PARAMETERS[$metadata['method']]) || ! is_bool($metadata['selection_present']) || ! is_bool($metadata['valid'])
                || $metadata['driver'] !== null && (! is_string($metadata['driver']) || preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,127}\z/D', $metadata['driver']) !== 1)) {
                throw new InvalidArgumentException('Invalid Socialite operation.');
            }
        }
        if ($kind === 'scout-index-selector') {
            if ($parent === null || array_keys($metadata) !== ['hook', 'selector', 'resolved'] || ! in_array($metadata['hook'], ['searchableas', 'indexableas'], true)
                || ! is_bool($metadata['resolved']) || $metadata['selector'] !== null && (! is_string($metadata['selector']) || preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_.:\-]{0,255}\z/D', $metadata['selector']) !== 1)
                || $metadata['resolved'] !== ($metadata['selector'] !== null)) {
                throw new InvalidArgumentException('Invalid Scout index selector.');
            }
        }
        if ($kind === 'scout-call-site') {
            if ($parent === null || array_keys($metadata) !== ['method', 'valid', 'root_offset', 'index_override_supplied', 'index_override', 'callbacks', 'callbacks_resolved']
                || ! is_string($metadata['method']) || ! isset(ScoutCatalogExtractor::PARAMETERS[$metadata['method']]) || ! is_bool($metadata['valid'])
                || $metadata['root_offset'] !== null && (! is_int($metadata['root_offset']) || $metadata['root_offset'] < 0)
                || ! is_bool($metadata['index_override_supplied']) || ! $metadata['index_override_supplied'] && $metadata['index_override'] !== null
                || $metadata['index_override'] !== null && (! is_string($metadata['index_override']) || preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_.:\-]{0,255}\z/D', $metadata['index_override']) !== 1)
                || ! is_array($metadata['callbacks']) || ! array_is_list($metadata['callbacks']) || count($metadata['callbacks']) > 3 || ! is_bool($metadata['callbacks_resolved'])) {
                throw new InvalidArgumentException('Invalid Scout call site.');
            }
            foreach ($metadata['callbacks'] as $callback) {
                if (! is_array($callback) || array_keys($callback) !== ['target', 'hook'] || ! is_string($callback['target'])
                    || preg_match('/\Aelement:[a-f0-9]{32}\z/D', $callback['target']) !== 1 || ! in_array($callback['hook'], ['search', 'query', 'withrawresults'], true)) {
                    throw new InvalidArgumentException('Invalid Scout callback.');
                }
            }
        }
        if ($kind === 'pennant-operation') {
            if ($parent === null) {
                throw new InvalidArgumentException('Pennant facts require a source owner.');
            }
            CatalogPennantOperations::validate($metadata);
        }
        if ($kind === 'ai-gateway-call-site') {
            if ($parent === null) {
                throw new InvalidArgumentException('AI gateway calls require a source owner.');
            }
            CatalogAiGateways::validateSite($metadata);
        }
        if (in_array($kind, ['ai-declaration', 'ai-tools', 'source-method-object-list', 'ai-response-callback', 'source-response-callback', 'ai-call-site'], true)) {
            if ($parent === null) {
                throw new InvalidArgumentException('AI facts require a source owner.');
            }
            CatalogAiOperations::validate($kind, $metadata);
        }
        if ($kind === 'fortify-operation') {
            if ($parent === null) {
                throw new InvalidArgumentException('Fortify operations require a source owner.');
            }
            CatalogFortifyRegistrations::validate($metadata);
        }
        if ($kind === 'inertia-operation') {
            if ($parent === null) {
                throw new InvalidArgumentException('Inertia operations require a source owner.');
            }
            CatalogInertiaOperations::validate($metadata);
        }
        if ($kind === 'package-descriptor') {
            if ($parent === null || array_keys($metadata) !== ['package', 'attribute', 'selector', 'value_hash', 'resolved']
                || $metadata['package'] !== 'laravel/mcp'
                || ! in_array($metadata['attribute'], ['Laravel\\Mcp\\Server\\Attributes\\Name', 'Laravel\\Mcp\\Server\\Attributes\\Description'], true)
                || $metadata['selector'] !== null && (! is_string($metadata['selector']) || preg_match('/\A[a-zA-Z0-9_.:\-]{1,128}\z/D', $metadata['selector']) !== 1)
                || $metadata['value_hash'] !== null && (! is_string($metadata['value_hash']) || preg_match('/\A[a-f0-9]{64}\z/D', $metadata['value_hash']) !== 1)
                || ! is_bool($metadata['resolved']) || $metadata['resolved'] && $metadata['value_hash'] === null
                || $metadata['attribute'] === 'Laravel\\Mcp\\Server\\Attributes\\Description' && $metadata['selector'] !== null) {
                throw new InvalidArgumentException('Invalid package descriptor.');
            }
        }
        if ($kind === 'package-operation') {
            if ($parent === null || array_keys($metadata) !== ['package', 'method', 'receiver', 'selector', 'targets', 'resolved', 'execution_proven', 'grouped_targets']
                || $metadata['package'] !== 'laravel/mcp' || ! in_array($metadata['method'], ['members', 'web', 'local'], true)
                || ! in_array($metadata['receiver'], ['tools', 'resources', 'prompts', 'Laravel\\Mcp\\Facades\\Mcp'], true)
                || $metadata['selector'] !== null && (! is_string($metadata['selector']) || preg_match('/\A[\/a-zA-Z0-9_.:\-]{1,256}\z/D', $metadata['selector']) !== 1)
                || ! is_array($metadata['targets']) || ! array_is_list($metadata['targets']) || count($metadata['targets']) > 128
                || ! is_array($metadata['grouped_targets']) || ! array_is_list($metadata['grouped_targets']) || count($metadata['grouped_targets']) > 128
                || $metadata['grouped_targets'] !== [] && ($metadata['method'] !== 'members' || $metadata['receiver'] !== 'tools')
                || ! is_bool($metadata['resolved']) || $metadata['execution_proven'] !== false) {
                throw new InvalidArgumentException('Invalid package operation.');
            }
            foreach ([...$metadata['targets'], ...$metadata['grouped_targets']] as $target) {
                if (! is_string($target) || $target === '' || strlen($target) > 1000) {
                    throw new InvalidArgumentException('Invalid package target.');
                }
            }
        }
        if (isset($metadata['serialization_selector'])) {
            if ($kind !== 'property') {
                throw new InvalidArgumentException('Serialization selectors belong to properties.');
            }
            CatalogSerializationSelectors::validate($metadata['serialization_selector']);
        }
        if (isset($metadata['serialization_attributes'])) {
            if (! in_array($kind, ['class', 'trait'], true) || ! is_array($metadata['serialization_attributes']) || array_keys($metadata['serialization_attributes']) !== ['hidden', 'visible', 'appends']) {
                throw new InvalidArgumentException('Invalid serialization class attributes.');
            }
            foreach ($metadata['serialization_attributes'] as $selector) {
                if ($selector !== null) {
                    CatalogSerializationSelectors::validate($selector);
                }
            }
        }
        if (array_key_exists('serialization_snake', $metadata) && ($kind !== 'property' || $metadata['serialization_snake'] !== null && ! is_bool($metadata['serialization_snake']))) {
            throw new InvalidArgumentException('Invalid serialization naming convention.');
        }
        if ($kind === 'attribute-access') {
            $keys = ['receiver', 'attribute_key', 'direction', 'nullsafe', 'form', 'execution_proven'];
            if (array_key_exists('serialization_steps', $metadata)) {
                $keys[] = 'serialization_steps';
                CatalogSerializationChanges::validate($metadata['serialization_steps']);
            }
            if (array_key_exists('serialization_functions', $metadata)) {
                $keys[] = 'serialization_functions';
                if (! in_array($metadata['form'] ?? null, ['json_encode', 'http_json', 'http_return', 'http_instance'], true) || ! is_array($metadata['serialization_functions'])
                    || ! array_is_list($metadata['serialization_functions']) || $metadata['form'] === 'json_encode' && count($metadata['serialization_functions']) < 1 || count($metadata['serialization_functions']) > 2) {
                    throw new InvalidArgumentException('Invalid serialization function selector.');
                }
                foreach ($metadata['serialization_functions'] as $function) {
                    if (! is_string($function) || $function === '' || strlen($function) > 1000) {
                        throw new InvalidArgumentException('Invalid serialization function name.');
                    }
                }
            }
            if (array_key_exists('serialization_transport', $metadata)) {
                $keys[] = 'serialization_transport';
                $transport = $metadata['serialization_transport'];
                $transportKeys = $metadata['form'] === 'http_instance' ? ['types', 'dispatch', 'receiver', 'method'] : ['types', 'dispatch'];
                if (! in_array($metadata['form'] ?? null, ['http_json', 'http_return', 'http_instance'], true) || ! is_array($transport) || array_keys($transport) !== $transportKeys
                    || ! in_array($transport['dispatch'], ['tojson', 'jsonserialize'], true) || ! is_array($transport['types']) || ! array_is_list($transport['types'])
                    || count($transport['types']) < 1 || count($transport['types']) > 4) {
                    throw new InvalidArgumentException('Invalid HTTP serialization transport.');
                }
                if ($metadata['form'] === 'http_instance' && (! is_string($transport['receiver']) || $transport['receiver'] === '' || strlen($transport['receiver']) > 1000
                    || ! in_array($transport['method'], ['json', 'make', 'setdata', 'setcontent'], true))) {
                    throw new InvalidArgumentException('Invalid HTTP serialization receiver.');
                }
                foreach ($transport['types'] as $type) {
                    if (! is_string($type) || ! in_array($type, ['Illuminate\\Http\\Response', 'Illuminate\\Http\\JsonResponse', 'Illuminate\\Routing\\ResponseFactory', 'Illuminate\\Contracts\\Routing\\ResponseFactory', 'Illuminate\\Support\\Facades\\Response', 'Illuminate\\Routing\\Router'], true)) {
                        throw new InvalidArgumentException('Invalid HTTP serialization type.');
                    }
                }
            }
            if ($parent === null || array_keys($metadata) !== $keys
                || ! is_string($metadata['receiver']) || $metadata['receiver'] === '' || strlen($metadata['receiver']) > 1000
                || $metadata['attribute_key'] !== null && (! is_string($metadata['attribute_key']) || preg_match('/\A[a-f0-9]{64}\z/D', $metadata['attribute_key']) !== 1)
                || ! in_array($metadata['direction'], ['read', 'write', 'read-write', 'inspect'], true)
                || ! in_array($metadata['form'], ['property', 'getattribute', 'getattributevalue', 'setattribute', 'toarray', 'attributestoarray', 'tojson', 'jsonserialize', 'json_encode', 'http_json', 'http_return', 'http_instance'], true)
                || in_array($metadata['form'], ['json_encode', 'http_json', 'http_return', 'http_instance'], true) && ! isset($metadata['serialization_functions'])
                || in_array($metadata['form'], ['http_json', 'http_return', 'http_instance'], true) && ! isset($metadata['serialization_transport'])
                || ! is_bool($metadata['nullsafe']) || $metadata['execution_proven'] !== false) {
                throw new InvalidArgumentException('Invalid attribute access descriptor.');
            }
        }
        if (isset($metadata['model_factory'])) {
            if (! in_array($kind, ['class', 'trait'], true)) {
                throw new InvalidArgumentException('Model factory selectors belong to class declarations.');
            }
            CatalogFactoryModels::validate($metadata['model_factory']);
        }
        if (isset($metadata['factory_model'])) {
            if (! in_array($kind, ['class', 'trait'], true)) {
                throw new InvalidArgumentException('Factory selectors belong to class declarations.');
            }
            CatalogFactoryModels::validate($metadata['factory_model']);
        }
        if (isset($metadata['eloquent_attribute'])) {
            if ($kind !== 'method') {
                throw new InvalidArgumentException('Eloquent attributes belong to methods.');
            }
            CatalogAttributes::validate($metadata['eloquent_attribute']);
        }
        if (isset($metadata['eloquent_relation'])) {
            if ($kind !== 'method') {
                throw new InvalidArgumentException('Eloquent relation descriptors belong to methods.');
            }
            CatalogModelMembers::validate($metadata['eloquent_relation']);
        }
        if ($kind === 'test-invocation' && ! isset($metadata['test_invocation'])) {
            throw new InvalidArgumentException('Missing test invocation descriptor.');
        }
        if (array_key_exists('test_declaration_candidate', $metadata) && ($kind !== 'method' || $metadata['test_declaration_candidate'] !== true)) {
            throw new InvalidArgumentException('Invalid test method declaration proof.');
        }
        if (array_key_exists('phpunit_providers', $metadata)) {
            $providers = $metadata['phpunit_providers'];
            if ($kind !== 'method' || ! is_array($providers) || ! array_is_list($providers) || count($providers) > 128) {
                throw new InvalidArgumentException('Invalid PHPUnit data provider list.');
            }
            foreach ($providers as $provider) {
                if (! is_array($provider) || array_keys($provider) !== ['api', 'class', 'method', 'validate_argument_count']
                    || ! in_array($provider['api'], ['DataProvider', 'DataProviderExternal'], true)
                    || ! is_string($provider['method']) || strlen($provider['method']) > 500 || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $provider['method']) !== 1
                    || $provider['class'] !== null && (! is_string($provider['class']) || strlen($provider['class']) > 1000 || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $provider['class']) !== 1)
                    || $provider['api'] === 'DataProvider' && $provider['class'] !== null
                    || $provider['api'] === 'DataProviderExternal' && $provider['class'] === null
                    || $provider['validate_argument_count'] !== null && ! is_bool($provider['validate_argument_count'])) {
                    throw new InvalidArgumentException('Invalid PHPUnit data provider descriptor.');
                }
            }
        }
        if (array_key_exists('phpunit_hooks', $metadata)) {
            if ($kind !== 'method' || ! is_array($metadata['phpunit_hooks']) || ! array_is_list($metadata['phpunit_hooks']) || count($metadata['phpunit_hooks']) > 6) {
                throw new InvalidArgumentException('Invalid PHPUnit hook list.');
            }
            foreach ($metadata['phpunit_hooks'] as $hook) {
                if (! is_array($hook) || array_keys($hook) !== ['api', 'priority'] || ! in_array($hook['api'], TestCatalogExtractor::PHPUNIT_HOOKS, true)
                    || $hook['priority'] !== null && ! is_int($hook['priority'])) {
                    throw new InvalidArgumentException('Invalid PHPUnit hook descriptor.');
                }
            }
        }
        if (isset($metadata['test_api'])) {
            if (! in_array($kind, ['pest-test', 'test-hook', 'dataset'], true)) {
                throw new InvalidArgumentException('Invalid test registration owner.');
            }
            $allowed = match ($kind) {
                'pest-test' => ['it', 'test'], 'test-hook' => ['beforeeach', 'beforeall', 'aftereach', 'afterall'], default => ['dataset']
            };
            if (! in_array($metadata['test_api'] ?? null, $allowed, true) || ($metadata['test_executed'] ?? null) !== false
                || ! array_key_exists('registration_scope', $metadata) || $metadata['registration_scope'] !== null && ! is_string($metadata['registration_scope'])) {
                throw new InvalidArgumentException('Invalid source test registration descriptor.');
            }
            if (array_key_exists('test_targets', $metadata)) {
                if ($kind !== 'test-hook' || ! in_array($metadata['registration_api'] ?? null, ['pest', 'uses'], true)
                    || ! is_bool($metadata['targets_resolved'] ?? null) || ! is_array($metadata['test_targets'])
                    || ! array_is_list($metadata['test_targets']) || count($metadata['test_targets']) > 128) {
                    throw new InvalidArgumentException('Invalid Pest hook target descriptor.');
                }
                foreach ($metadata['test_targets'] as $target) {
                    if (! is_string($target) || $target === '' || strlen($target) > 1000 || str_starts_with($target, '/') || str_contains($target, '\\')
                        || preg_match('~(?:^|/)\.\.(?:/|$)|^[A-Za-z]:~', $target)) {
                        throw new InvalidArgumentException('Invalid Pest hook source target.');
                    }
                }
            }
            if (isset($metadata['datasets'])) {
                if (! is_array($metadata['datasets']) || ! array_is_list($metadata['datasets']) || count($metadata['datasets']) > 128) {
                    throw new InvalidArgumentException('Invalid test dataset references.');
                }
                foreach ($metadata['datasets'] as $dataset) {
                    if (! is_string($dataset) || $dataset === '' || strlen($dataset) > 500) {
                        throw new InvalidArgumentException('Invalid test dataset name.');
                    }
                }
            }
        }
        if (isset($metadata['test_invocation'])) {
            if ($kind !== 'test-invocation') {
                throw new InvalidArgumentException('Invalid test invocation owner.');
            }
            TestInvocation::fromArray('', $metadata['test_invocation']);
        }
        if ($kind === 'broadcast-subscription') {
            if (! is_array($metadata['guards'] ?? null) || ! array_is_list($metadata['guards']) || ! is_bool($metadata['guards_resolved'] ?? null)
                || ($metadata['runtime_activation_known'] ?? null) !== false) {
                throw new InvalidArgumentException('Invalid channel subscription descriptor.');
            }
            foreach ($metadata['guards'] as $guard) {
                if (! is_string($guard) || preg_match('/\\A[a-zA-Z0-9_.-]{1,128}\\z/D', $guard) !== 1) {
                    throw new InvalidArgumentException('Invalid channel guard selector.');
                }
            }
        }

        if (array_key_exists('broadcast_channels', $metadata)) {
            if ($kind !== 'method') {
                throw new InvalidArgumentException('Broadcast channel descriptors belong to methods.');
            }
            CatalogBroadcastChannels::validate($metadata['broadcast_channels']);
        }

        if (array_key_exists('broadcast_empty_channels', $metadata) && ($kind !== 'method' || $metadata['broadcast_empty_channels'] !== true)) {
            throw new InvalidArgumentException('Invalid empty broadcast channel proof.');
        }

        if (array_key_exists('notification_channels', $metadata)) {
            $channels = $metadata['notification_channels'];
            if ($kind !== 'method' || ! is_array($channels) || array_keys($channels) !== ['resolved', 'channels'] || ! is_bool($channels['resolved'])
                || ! is_array($channels['channels']) || ! array_is_list($channels['channels']) || count($channels['channels']) > 128) {
                throw new InvalidArgumentException('Invalid notification channel descriptor.');
            }
            foreach ($channels['channels'] as $channel) {
                if (! is_string($channel) || strlen($channel) > 500 || preg_match('/\\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\\z/D', $channel) !== 1) {
                    throw new InvalidArgumentException('Invalid notification channel selector.');
                }
            }
        }

        if (array_key_exists('global_scopes', $metadata)) {
            if (! in_array($kind, ['class', 'trait'], true)) {
                throw new InvalidArgumentException('Global scopes belong to classes or traits.');
            }
            CatalogGlobalScopes::validate($metadata['global_scopes']);
        }
        if (array_key_exists('global_scope_transform', $metadata) && ($kind !== 'method' || ! is_bool($metadata['global_scope_transform']))) {
            throw new InvalidArgumentException('Invalid global scope query transformation.');
        }
        if (array_key_exists('query_transform', $metadata) && ($kind !== 'method' || ! is_bool($metadata['query_transform']))) {
            throw new InvalidArgumentException('Invalid source query transformation marker.');
        }
        if (array_key_exists('query_builder', $metadata)) {
            if (! in_array($kind, ['class', 'trait'], true)) {
                throw new InvalidArgumentException('Builder selectors belong to source classes or traits.');
            }
            CatalogFactoryModels::validate($metadata['query_builder']);
        }
        if (array_key_exists('eloquent_scope', $metadata) && ($kind !== 'method' || ! is_array($metadata['eloquent_scope'])
            || array_keys($metadata['eloquent_scope']) !== ['style', 'preserves_query'] || ! in_array($metadata['eloquent_scope']['style'], ['legacy', 'attribute'], true)
            || ! is_bool($metadata['eloquent_scope']['preserves_query']))) {
            throw new InvalidArgumentException('Invalid source Eloquent scope descriptor.');
        }
        if (array_key_exists('data_casts', $metadata)) {
            if (! in_array($kind, ['class', 'trait'], true)) {
                throw new InvalidArgumentException('Cast declarations belong to class or trait declarations.');
            }
            CatalogCasts::validate($metadata['data_casts']);
        }
        if (in_array($kind, ['factory-operation', 'factory-related', 'factory-configuration', 'factory-state'], true)) {
            if ($parent === null || array_keys($metadata) !== ['receiver', 'operation', 'chain_methods', 'callbacks', 'selector_resolved', 'instances_possible', 'count_overridden', 'cleared_callbacks', 'steps', 'execution_proven']
                || ! is_string($metadata['receiver']) || $metadata['receiver'] === '' || strlen($metadata['receiver']) > 1000
                || ! is_string($metadata['operation']) || strlen($metadata['operation']) > 500 || preg_match('/\A[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\z/D', $metadata['operation']) !== 1
                || in_array($kind, ['factory-operation', 'factory-related'], true) && ! in_array($metadata['operation'], FactoryLifecycleCatalogExtractor::TERMINALS, true)
                || $kind === 'factory-configuration' && $metadata['operation'] !== 'configure'
                || ! is_array($metadata['chain_methods']) || ! array_is_list($metadata['chain_methods']) || count($metadata['chain_methods']) > 65
                || count(array_filter($metadata['chain_methods'], 'is_string')) !== count($metadata['chain_methods'])
                || ! is_array($metadata['callbacks']) || ! array_is_list($metadata['callbacks']) || count($metadata['callbacks']) > 128
                || ! is_bool($metadata['selector_resolved']) || ! is_bool($metadata['instances_possible']) || ! is_bool($metadata['count_overridden'])
                || ! is_array($metadata['cleared_callbacks']) || ! array_is_list($metadata['cleared_callbacks']) || count($metadata['cleared_callbacks']) > 2
                || count(array_filter($metadata['cleared_callbacks'], 'is_string')) !== count($metadata['cleared_callbacks'])
                || array_diff($metadata['cleared_callbacks'], ['aftermaking', 'aftercreating']) !== [] || $metadata['execution_proven'] !== false) {
                throw new InvalidArgumentException('Invalid factory operation descriptor.');
            }
            foreach ($metadata['callbacks'] as $callback) {
                self::validateFactoryCallback($callback);
            }
            if (! is_array($metadata['steps']) || ! array_is_list($metadata['steps']) || count($metadata['steps']) > 65) {
                throw new InvalidArgumentException('Invalid factory pipeline steps.');
            }
            foreach ($metadata['steps'] as $step) {
                if (! is_array($step) || array_keys($step) !== ['method', 'callbacks', 'count_overridden', 'instances_possible', 'clear_stage', 'argument_count', 'line', 'end_line', 'connection', 'relationship']
                    || ! is_string($step['method']) || strlen($step['method']) > 500 || preg_match('/\A[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\z/D', $step['method']) !== 1
                    || ! is_array($step['callbacks']) || ! array_is_list($step['callbacks']) || count($step['callbacks']) > 2
                    || ! is_bool($step['count_overridden']) || ! is_bool($step['instances_possible']) || ! in_array($step['clear_stage'], [null, 'aftermaking', 'aftercreating'], true)
                    || ! is_int($step['argument_count']) || $step['argument_count'] < 0 || ! is_int($step['line']) || $step['line'] < 1 || ! is_int($step['end_line']) || $step['end_line'] < $step['line']) {
                    throw new InvalidArgumentException('Invalid factory pipeline step.');
                }
                if ($step['connection'] !== null && (! is_array($step['connection']) || array_keys($step['connection']) !== ['kind', 'name']
                    || ! in_array($step['connection']['kind'], ['named', 'model'], true)
                    || $step['connection']['kind'] === 'model' && $step['connection']['name'] !== null
                    || $step['connection']['kind'] === 'named' && (! is_string($step['connection']['name']) || preg_match('/\A[a-zA-Z0-9_.-]{1,256}\z/D', $step['connection']['name']) !== 1))) {
                    throw new InvalidArgumentException('Invalid factory connection selector.');
                }
                foreach ($step['callbacks'] as $callback) {
                    self::validateFactoryCallback($callback);
                }
                if ($step['relationship'] !== null && (! in_array($step['method'], ['has', 'for', 'hasattached'], true)
                    || ! is_array($step['relationship']) || array_keys($step['relationship']) !== ['factory', 'name']
                    || ! is_string($step['relationship']['factory']) || preg_match('/\Aelement:[a-f0-9]{32}\z/D', $step['relationship']['factory']) !== 1
                    || $step['relationship']['name'] !== null && (! is_string($step['relationship']['name']) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,255}\z/D', $step['relationship']['name']) !== 1))) {
                    throw new InvalidArgumentException('Invalid factory relationship selector.');
                }
            }
        }
        if ($kind === 'seeder-operation') {
            if ($parent === null || array_keys($metadata) !== ['receiver', 'operation', 'targets', 'selector_resolved', 'execution_proven']
                || ! is_string($metadata['receiver']) || $metadata['receiver'] === '' || strlen($metadata['receiver']) > 1000 || ! in_array($metadata['operation'], ['call', 'callwith', 'callsilent', 'callonce'], true)
                || ! is_array($metadata['targets']) || ! array_is_list($metadata['targets']) || count($metadata['targets']) > 128
                || ! is_bool($metadata['selector_resolved']) || $metadata['execution_proven'] !== false) {
                throw new InvalidArgumentException('Invalid seeder call descriptor.');
            }
            foreach ($metadata['targets'] as $type) {
                if (! is_string($type) || strlen($type) > 500 || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $type) !== 1) {
                    throw new InvalidArgumentException('Invalid seeder type selector.');
                }
            }
        }
        if ($kind === 'data-operation') {
            $excluded = $metadata['excluded_global_scopes'] ?? null;
            if (! is_array($excluded) || array_keys($excluded) !== ['all', 'keys', 'resolved'] || ! is_bool($excluded['all']) || ! is_bool($excluded['resolved'])
                || ! is_array($excluded['keys']) || ! array_is_list($excluded['keys']) || count($excluded['keys']) > 8192) {
                throw new InvalidArgumentException('Invalid global scope exclusions.');
            }
            foreach ($excluded['keys'] as $key) {
                if (! is_string($key) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.\\\\-]{0,499}\z/D', $key) !== 1) {
                    throw new InvalidArgumentException('Invalid excluded global scope key.');
                }
            }
            if ($parent === null || ! is_string($metadata['receiver'] ?? null) || ! is_string($metadata['operation'] ?? null)
                || ! is_bool($metadata['explicit_connection'] ?? null) || ! is_bool($metadata['sql'] ?? null)
                || ($metadata['execution_proven'] ?? null) !== false || ! is_array($metadata['tables'] ?? null)
                || ! array_is_list($metadata['tables']) || ! is_array($metadata['connection'] ?? null)
                || ! in_array($metadata['connection']['kind'] ?? null, ['default', 'named', 'dynamic'], true)
                || ! array_key_exists('name', $metadata['connection'])
                || $metadata['connection']['name'] !== null && (! is_string($metadata['connection']['name']) || preg_match('/\\A[a-zA-Z0-9_.-]{1,256}\\z/D', $metadata['connection']['name']) !== 1)) {
                throw new InvalidArgumentException('Invalid data operation descriptor.');
            }
            foreach (['chain_methods', 'kinds', 'unsupported', 'conditions'] as $field) {
                if (! is_array($metadata[$field] ?? null) || ! array_is_list($metadata[$field]) || count(array_filter($metadata[$field], 'is_string')) !== count($metadata[$field])) {
                    throw new InvalidArgumentException('Invalid data operation list.');
                }
            }
            if (array_diff($metadata['kinds'], ['read', 'write', 'schema', 'schema-read']) !== []) {
                throw new InvalidArgumentException('Invalid data effect kind.');
            }
            foreach ($metadata['tables'] as $table) {
                if (! is_array($table) || array_keys($table) !== ['table', 'role']
                    || ! in_array($table['role'], ['primary', 'read', 'write', 'schema', 'schema-read'], true)
                    || $table['table'] !== null && (! is_string($table['table']) || strlen($table['table']) > 256 || preg_match('/\\A[a-zA-Z_][a-zA-Z0-9_$]*(?:\\.[a-zA-Z_][a-zA-Z0-9_$]*)*\\z/D', $table['table']) !== 1)) {
                    throw new InvalidArgumentException('Invalid data table selector.');
                }
            }
        }

        if (array_key_exists('data_declaration', $metadata)) {
            $data = $metadata['data_declaration'];
            if (! in_array($kind, ['class', 'trait'], true) || ! is_array($data) || array_keys($data) !== ['properties', 'methods']
                || ! is_array($data['properties']) || ! is_array($data['methods'])
                || array_diff(array_keys($data['properties']), ['table', 'connection']) !== []
                || array_diff(array_keys($data['methods']), ['gettable', 'getconnectionname', 'joiningtable', 'joiningtablesegment']) !== []) {
                throw new InvalidArgumentException('Invalid database declaration descriptor.');
            }
            foreach ($data['methods'] as $method) {
                if (! is_array($method) || array_keys($method) !== ['literal_return']) {
                    throw new InvalidArgumentException('Invalid database selector method.');
                }
            }
            foreach ([...array_values($data['properties']), ...array_column($data['methods'], 'literal_return')] as $selector) {
                if ($selector !== null && $selector !== ['dynamic' => true]
                    && (! is_string($selector) || preg_match('/\\A[a-zA-Z0-9_.-]{1,256}\\z/D', $selector) !== 1)) {
                    throw new InvalidArgumentException('Invalid database selector.');
                }
            }
        }

        if ($kind === 'framework-validation-rule' && (! is_string($metadata['factory_method'] ?? null) || CatalogRuleFactories::type($metadata['factory_method']) === null
            || CatalogRuleFactories::type($metadata['factory_method']) !== ($metadata['rule_type'] ?? null) || ($metadata['execution_proven'] ?? null) !== false)) {
            throw new InvalidArgumentException('Invalid standard framework rule descriptor.');
        }
        if ($kind === 'resource-operation' && (! in_array($metadata['operation'] ?? null, ['resolve', 'resolveresourcedata', 'toattributes', 'toarray', 'jsonserialize', 'tojson', 'toprettyjson', 'response', 'toresponse'], true)
            || ! is_string($metadata['resource_type'] ?? null) || ! is_bool($metadata['collection'] ?? null) || ($metadata['execution_proven'] ?? null) !== false)) {
            throw new InvalidArgumentException('Invalid resource operation descriptor.');
        }
        if ($kind === 'validation-site' && (! in_array($metadata['operation'] ?? null, ['make', 'validate', 'helper', 'method-validate', 'request-validatewithbag', 'factory-make', 'factory-validate'], true)
            || ! array_key_exists('receiver_type', $metadata) || $metadata['receiver_type'] !== null && ! is_string($metadata['receiver_type'])
            || ! is_array($metadata['helper_names'] ?? null) || ! array_is_list($metadata['helper_names']) || count(array_filter($metadata['helper_names'], 'is_string')) !== count($metadata['helper_names']))) {
            throw new InvalidArgumentException('Invalid validation site descriptor.');
        }
        if ($kind === 'validation-site' && is_string($metadata['receiver_type']) && str_starts_with($metadata['receiver_type'], '@types:')) {
            $types = json_decode(substr($metadata['receiver_type'], 7), true, 32);
            if (! is_array($types) || ! array_is_list($types) || $types === [] || count($types) > 128 || count(array_filter($types, 'is_string')) !== count($types)) {
                throw new InvalidArgumentException('Invalid validation receiver type alternatives.');
            }
        }
        if (array_key_exists('resource_collects', $metadata)) {
            $descriptor = $metadata['resource_collects'];
            if (! in_array($kind, ['property', 'attribute'], true) || ! is_array($descriptor)
                || ! is_bool($descriptor['resolved'] ?? null) || ! array_key_exists('target', $descriptor)
                || $descriptor['target'] !== null && ! is_string($descriptor['target'])) {
                throw new InvalidArgumentException('Invalid resource collection selector.');
            }
        }
        if (array_key_exists('controller_middleware', $metadata)) {
            $declaration = $metadata['controller_middleware'];
            if ($kind !== 'attribute' || ! is_array($declaration) || ! array_key_exists('middleware', $declaration)
                || $declaration['middleware'] !== null && ! is_string($declaration['middleware'])
                || ! is_bool($declaration['filters_resolved'] ?? null) || ! is_bool($declaration['shape_resolved'] ?? null)) {
                throw new InvalidArgumentException('Invalid controller middleware attribute descriptor.');
            }
            foreach (['only', 'except'] as $key) {
                if (! is_array($declaration[$key] ?? null) || ! array_is_list($declaration[$key]) || count(array_filter($declaration[$key], 'is_string')) !== count($declaration[$key])) {
                    throw new InvalidArgumentException('Invalid controller middleware attribute filters.');
                }
            }
        }
        if ($kind === 'composer-dependency' && (! is_string($metadata['constraint'] ?? null) || ! is_bool($metadata['development'] ?? null) || ! is_bool($metadata['platform'] ?? null))) {
            throw new InvalidArgumentException('Invalid catalog Composer dependency.');
        }
        if ($kind === 'autoload-mapping' && (! in_array($metadata['mode'] ?? null, ['psr-4', 'psr-0', 'classmap', 'files', 'exclude-from-classmap'], true)
            || ! is_string($metadata['namespace'] ?? null) || ! is_string($metadata['source_path'] ?? null) || ! is_bool($metadata['development'] ?? null))) {
            throw new InvalidArgumentException('Invalid catalog Composer autoload mapping.');
        }
        if (array_key_exists('package_name', $metadata) && $metadata['package_name'] !== null && ! is_string($metadata['package_name'])) {
            throw new InvalidArgumentException('Invalid catalog Composer project name.');
        }
        if (array_key_exists('final', $metadata) && ! is_bool($metadata['final'])) {
            throw new InvalidArgumentException('Invalid catalog final declaration flag.');
        }
        if (array_key_exists('abstract', $metadata) && ! is_bool($metadata['abstract'])) {
            throw new InvalidArgumentException('Invalid catalog abstract declaration flag.');
        }
        if (array_key_exists('static', $metadata) && ! is_bool($metadata['static'])) {
            throw new InvalidArgumentException('Invalid catalog static declaration flag.');
        }
        if (array_key_exists('visibility', $metadata) && ! in_array($metadata['visibility'], ['public', 'protected', 'private'], true)) {
            throw new InvalidArgumentException('Invalid catalog declaration visibility.');
        }
        if (array_key_exists('trait_rules', $metadata)) {
            if (! is_array($metadata['trait_rules']) || ! array_is_list($metadata['trait_rules'])) {
                throw new InvalidArgumentException('Invalid catalog trait rules.');
            }
            foreach ($metadata['trait_rules'] as $rule) {
                if (! is_array($rule) || ! array_key_exists('trait', $rule) || $rule['trait'] !== null && ! is_string($rule['trait'])
                    || ! is_string($rule['method'] ?? null) || ! array_key_exists('alias', $rule) || $rule['alias'] !== null && ! is_string($rule['alias'])
                    || ! is_array($rule['excluded'] ?? null) || ! array_is_list($rule['excluded']) || count(array_filter($rule['excluded'], 'is_string')) !== count($rule['excluded'])
                    || array_key_exists('final', $rule) && ! is_bool($rule['final'])
                    || ! array_key_exists('visibility', $rule) || ! in_array($rule['visibility'], [null, 'public', 'protected', 'private'], true)) {
                    throw new InvalidArgumentException('Invalid catalog trait rule.');
                }
            }
        }
        if ($kind === 'composer-manifest' && (! is_array($metadata['discovery_exclusions'] ?? null) || ! array_is_list($metadata['discovery_exclusions']) || count(array_filter($metadata['discovery_exclusions'], 'is_string')) !== count($metadata['discovery_exclusions']))) {
            throw new InvalidArgumentException('Invalid catalog Composer manifest.');
        }
        if ($kind === 'composer-package' && (! is_string($metadata['version'] ?? null) || ! in_array($metadata['state'] ?? null, ['locked', 'installed'], true))) {
            throw new InvalidArgumentException('Invalid catalog Composer package.');
        }
        if ($kind === 'composer-package' && (! in_array($metadata['reference_state'] ?? null, ['absent', 'known', 'unknown'], true)
            || ! array_key_exists('source_reference', $metadata)
            || $metadata['reference_state'] === 'known' && (! is_string($metadata['source_reference']) || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $metadata['source_reference']) !== 1)
            || $metadata['reference_state'] !== 'known' && $metadata['source_reference'] !== null)) {
            throw new InvalidArgumentException('Invalid catalog Composer source reference.');
        }
        if (array_key_exists('development', $metadata) && $metadata['development'] !== null && ! is_bool($metadata['development'])) {
            throw new InvalidArgumentException('Invalid catalog Composer development state.');
        }
        if (array_key_exists('normalized_version', $metadata) && $metadata['normalized_version'] !== null && ! is_string($metadata['normalized_version'])) {
            throw new InvalidArgumentException('Invalid catalog normalized Composer version.');
        }
        if (array_key_exists('static_return', $metadata)) {
            $factory = $metadata['static_return'];
            if (! is_array($factory) || array_keys($factory) !== ['class', 'method'] || ! is_string($factory['class']) || $factory['class'] === '' || ! is_string($factory['method']) || $factory['method'] === '') {
                throw new InvalidArgumentException('Invalid catalog static return.');
            }
        }
        if (array_key_exists('parameters', $metadata)) {
            if (! is_array($metadata['parameters']) || ! array_is_list($metadata['parameters'])) {
                throw new InvalidArgumentException('Invalid catalog parameters.');
            }
            foreach ($metadata['parameters'] as $parameter) {
                if (! is_array($parameter) || ! is_int($parameter['line'] ?? null) || $parameter['line'] < 1
                    || ! is_array($parameter['types'] ?? null) || ! array_is_list($parameter['types']) || count(array_filter($parameter['types'], 'is_string')) !== count($parameter['types'])) {
                    throw new InvalidArgumentException('Invalid catalog parameter types.');
                }
            }
        }
    }

    public static function identity(string $path, string $kind, string $name, int $offset = 0): string
    {
        return 'element:'.hash('xxh128', serialize([$path, $kind, $name, $offset]));
    }

    public static function resourceIdentity(string $kind, string $name): string
    {
        return 'resource:'.hash('xxh128', serialize([$kind, $name]));
    }

    private static function validateFactoryCallback(mixed $callback): void
    {
        if (! is_array($callback) || array_keys($callback) !== ['stage', 'target', 'line', 'end_line']
            || ! in_array($callback['stage'], ['state', 'aftermaking', 'aftercreating'], true)
            || ! is_string($callback['target']) || preg_match('/\Aelement:[0-9a-f]{32}\z/D', $callback['target']) !== 1
            || ! is_int($callback['line']) || $callback['line'] < 1 || ! is_int($callback['end_line']) || $callback['end_line'] < $callback['line']) {
            throw new InvalidArgumentException('Invalid factory callback descriptor.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'kind' => $this->kind, 'line' => $this->line,
            'end_line' => $this->endLine, 'offset' => $this->offset, 'parent' => $this->parent,
            'roles' => $this->roles, 'metadata' => $this->metadata];
    }

    public static function fromArray(mixed $value): self
    {
        if (! is_array($value) || array_keys($value) !== ['id', 'name', 'kind', 'line', 'end_line', 'offset', 'parent', 'roles', 'metadata']) {
            throw new InvalidArgumentException('Invalid catalog element record.');
        }

        return new self($value['id'], $value['name'], $value['kind'], $value['line'], $value['end_line'], $value['offset'], $value['parent'], $value['roles'], $value['metadata']);
    }
}
