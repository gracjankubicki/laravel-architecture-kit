<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use Illuminate\Support\Str;

/** Serialization invokes visible accessors conditionally; source selectors do not prove loaded data. */
final class CatalogModelSerializationResolver
{
    private int $operations = 0;

    private ?string $frameworkProfile = null;

    /** @var list<array<string, mixed>> */
    private array $frameworkSources = [];

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(): void
    {
        $this->operations = 0;
        $this->frameworkProfile = $this->resolveFrameworkProfile();
        if ($this->index->namedTypes('Illuminate\\Database\\Eloquent\\Model') !== []) {
            return;
        }
        $accessors = [];
        $httpHandlers = [];
        foreach ($this->index->relations as $edge) {
            if ($edge['kind'] === 'route-handler' && isset($this->index->elements[$edge['to']]) && ($this->index->elements[$edge['from']]['kind'] ?? null) === 'route') {
                $httpHandlers[$edge['to']][] = ['route' => $edge['from'], 'path' => $edge['path'], 'line' => $edge['line'], 'end_line' => $edge['end_line']];
            }
        }
        $customResponseFactory = false;
        foreach ($this->index->elements as $element) {
            if ($element['kind'] === 'container-binding' && in_array(strtolower($element['name']), ['illuminate\\contracts\\routing\\responsefactory', 'illuminate\\routing\\responsefactory'], true)) {
                $customResponseFactory = true;
            }
        }
        $propertyAccess = new CatalogAttributeAccessResolver($this->index, $this->calls);
        foreach ($this->index->relations as $edge) {
            if ($edge['kind'] === 'declares-accessor') {
                $accessors[$edge['from']][$edge['metadata']['attribute']][] = $edge['to'];
            }
        }
        foreach ($this->index->elements as $site) {
            if ($site['kind'] !== 'attribute-access' || ! in_array($site['metadata']['form'], ['toarray', 'attributestoarray', 'tojson', 'jsonserialize', 'json_encode', 'http_json', 'http_return', 'http_instance'], true)) {
                continue;
            }
            if ($site['metadata']['form'] === 'http_return' && ! isset($httpHandlers[$site['parent']])) {
                continue;
            }
            if (! $this->budget($site)) {
                return;
            }
            if (in_array($site['metadata']['form'], ['json_encode', 'http_json', 'http_instance'], true)) {
                $shadowed = false;
                foreach ($site['metadata']['serialization_functions'] as $function) {
                    foreach ($this->index->names[strtolower($function)] ?? [] as $id) {
                        $shadowed = $shadowed || $this->index->elements[$id]['kind'] === 'function';
                    }
                }
                if ($shadowed) {
                    $this->diagnostic($site, 'JSON encoding function is shadowed in source; builtin model serialization is not inferred.');

                    continue;
                }
            }
            if ($site['metadata']['form'] === 'http_instance' && ! $this->instanceTransport($site, $customResponseFactory)) {
                continue;
            }
            if (in_array($site['metadata']['form'], ['http_json', 'http_return', 'http_instance'], true)) {
                $shadowed = $customResponseFactory && in_array('Illuminate\\Routing\\ResponseFactory', $site['metadata']['serialization_transport']['types'], true);
                foreach ($site['metadata']['serialization_transport']['types'] as $transport) {
                    $shadowed = $shadowed || $this->index->namedTypes($transport) !== [];
                }
                if ($shadowed) {
                    $this->diagnostic($site, 'HTTP response transport is shadowed in source; standard model serialization is not inferred.');

                    continue;
                }
            }
            $receivers = $this->calls->receiverCandidates($site['metadata']['receiver']);
            if (! $propertyAccess->visibleReceiver($site, $receivers['sources'])) {
                $this->diagnostic($site, 'Serialization receiver property is inaccessible from the source lexical class.');

                continue;
            }
            foreach ($receivers['types'] as $type) {
                $models = $this->index->namedTypes($type);
                if (count($models) !== 1 || ! $this->index->hasContract($models[0], 'Illuminate\\Database\\Eloquent\\Model')) {
                    continue;
                }
                $model = $models[0];
                $recentlyCreatedRequired = false;
                if ($site['metadata']['form'] === 'http_return' && $site['metadata']['serialization_transport']['dispatch'] === 'tojson') {
                    $knownFactory = $this->index->hasContract($model, 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory')
                        && $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Factories\\HasFactory') === [];
                    if ($this->index->hasContract($model, 'Illuminate\\Contracts\\Support\\Responsable') || $this->index->hasContract($model, 'Psr\\Http\\Message\\ResponseInterface')) {
                        if ($this->index->hasContract($model, 'Illuminate\\Contracts\\Support\\Responsable')) {
                            $response = $this->calls->sourceMethodCandidate($type, 'toResponse', knownHasFactory: $knownFactory);
                            if (! $response['limited'] && ! $response['unresolved'] && $response['method'] !== null
                                && ($response['method']['metadata']['visibility'] ?? null) === 'public' && ! ($response['method']['metadata']['static'] ?? false)) {
                                $this->relation($site, $response['method']['id'], 'invokes-response-conversion', ['model' => $model, 'form' => 'http_return', 'conversion' => 'toResponse',
                                    'http_entry_sources' => $httpHandlers[$site['parent']], 'http_response_pipeline_required' => true, 'execution_proven' => false]);
                            }
                        }
                        $this->diagnostic($site, 'Returned model has a response conversion contract preceding default model serialization.');

                        continue;
                    }
                    $string = $this->calls->sourceMethodCandidate($type, '__toString', knownHasFactory: $knownFactory);
                    if ($string['limited'] || $string['unresolved']) {
                        $this->diagnostic($site, 'Returned model string conversion cannot be resolved from source.');

                        continue;
                    }
                    $recentlyCreatedRequired = $string['method'] !== null;
                    if ($string['method'] !== null && ($string['method']['metadata']['visibility'] ?? null) === 'public' && ! ($string['method']['metadata']['static'] ?? false)) {
                        $this->relation($site, $string['method']['id'], 'invokes-response-conversion', ['model' => $model, 'form' => 'http_return', 'conversion' => '__toString',
                            'http_entry_sources' => $httpHandlers[$site['parent']], 'http_response_pipeline_required' => true, 'recently_created_must_be_false' => true, 'execution_proven' => false]);
                    }
                }
                if ($receivers['limited'] || $site['metadata']['direction'] === 'inspect' || ! ($this->index->elements[$model]['metadata']['casts_resolved'] ?? false)
                    || ! $this->standard($type, $site['metadata']['serialization_transport']['dispatch'] ?? $site['metadata']['form'])) {
                    $this->diagnostic($site, 'Serialization dispatch, class attributes, arguments or source hierarchy require further composition.');

                    continue;
                }
                $snake = $this->snakeStyle($type);
                $hidden = $this->selector($type, 'hidden');
                $visible = $this->selector($type, 'visible');
                $appends = $this->selector($type, 'appends');
                $selectors = $this->changedSelectors($site, $type, $hidden, $visible, $appends);
                if ($selectors !== null) {
                    [$hidden, $visible, $appends] = $selectors;
                } else {
                    $hidden = null;
                }
                if ($snake === null || $hidden === null || $visible === null || $appends === null) {
                    $this->diagnostic($site, 'Serialization visibility or append selectors are dynamic, ambiguous or inaccessible.');

                    continue;
                }
                $registered = [];
                foreach ($accessors[$model] ?? [] as $methods) {
                    foreach ($methods as $id) {
                        if (! $this->budget($site)) {
                            return;
                        }
                        $method = $this->index->elements[$id];
                        $name = substr($method['name'], strrpos($method['name'], '::') + 2);
                        if ($method['metadata']['eloquent_attribute']['style'] === 'legacy') {
                            $standardSpelling = preg_match('/\Aget(.+)Attribute\z/D', $name, $match) === 1;
                            if (! $standardSpelling && preg_match('/\Aget(.+)Attribute\z/iD', $name, $match) !== 1) {
                                $this->diagnostic($site, 'Legacy getter spelling is not registered by the standard serialization mutator matcher.');

                                continue;
                            }
                            $name = $match[1];
                            foreach (array_unique([$method['metadata']['eloquent_attribute']['attribute'], lcfirst($name), $name]) as $alias) {
                                if (in_array(hash('sha256', $alias), $appends['keys'], true)) {
                                    $registered[$alias][] = $id;
                                }
                            }
                            if (! $standardSpelling) {
                                continue;
                            }
                        }
                        $name = lcfirst($snake['value'] ? Str::snake($name) : $name);
                        $registered[$name][] = $id;
                    }
                }
                foreach ($registered as $attribute => $methods) {
                    if (! $this->budget($site)) {
                        return;
                    }
                    $key = hash('sha256', $attribute);
                    if (in_array($key, $hidden['keys'], true) || $visible['keys'] !== [] && ! in_array($key, $visible['keys'], true)) {
                        continue;
                    }
                    $modern = array_values(array_filter($methods, fn ($id) => $this->index->elements[$id]['metadata']['eloquent_attribute']['style'] === 'attribute'));
                    $methods = array_values(array_unique($modern !== [] ? $modern : $methods));
                    if (count($methods) !== 1 || $this->hasCustomCast($model, $attribute)) {
                        $this->diagnostic($site, 'Serialization attribute selection or custom cast precedence requires inspection.');

                        continue;
                    }
                    $method = $this->index->elements[$methods[0]];
                    $descriptor = $method['metadata']['eloquent_attribute'];
                    $metadata = ['attribute' => $attribute, 'model' => $model, 'operation_source' => $site['id'], 'form' => $site['metadata']['form'],
                        'appended' => in_array($key, $appends['keys'], true), 'loaded_attribute_required' => ! in_array($key, $appends['keys'], true),
                        'runtime_visibility_must_match_source' => true, 'receiver_non_null_required' => $site['metadata']['nullsafe'],
                        'selector_sources' => [...$hidden['sources'], ...$visible['sources'], ...$appends['sources'], ...$snake['sources']], 'snake_attributes' => $snake['value'], 'runtime_naming_must_match_source' => true, 'execution_proven' => false, 'database_write_proven' => false];
                    if (isset($site['metadata']['serialization_transport'])) {
                        $metadata['serialization_dispatch'] = $site['metadata']['serialization_transport']['dispatch'];
                        $metadata['transport_types'] = $site['metadata']['serialization_transport']['types'];
                        $metadata['runtime_standard_transport_required'] = true;
                    }
                    if ($site['metadata']['form'] === 'http_return') {
                        $metadata['http_entry_sources'] = $httpHandlers[$site['parent']];
                        $metadata['http_response_pipeline_required'] = true;
                        $metadata['recently_created_required'] = $recentlyCreatedRequired;
                    }
                    if ($descriptor['style'] === 'legacy') {
                        $this->relation($site, $method['id'], 'serializes-through-accessor', $metadata);
                    } else {
                        $this->relation($site, $method['id'], 'invokes-attribute-definition', $metadata);
                        if ($descriptor['resolved'] && is_string($descriptor['get']) && isset($this->index->elements[$descriptor['get']])) {
                            $this->relation($site, $descriptor['get'], 'serializes-through-accessor', $metadata);
                        } else {
                            $this->diagnostic($site, 'Serialization accessor callback is unresolved.');
                        }
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $site */
    private function budget(array $site): bool
    {
        if (++$this->operations > 4096 || $this->operations % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
            $this->index->diagnostics[] = ['code' => 'catalog_limit', 'message' => 'Model serialization composition reached its operation or memory budget.',
                'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['id']];

            return false;
        }

        return true;
    }

    /** @return array{keys: list<string>, sources: list<array<string, mixed>>}|null */
    private function selector(string $type, string $property): ?array
    {
        $selection = $this->calls->receiverCandidates('@property:'.$type.'#'.$property);
        if ($selection['limited'] || count($selection['sources']) > 1) {
            return null;
        }
        if ($selection['sources'] === []) {
            return $this->mergeClassSelector($type, $property, ['keys' => [], 'sources' => []]);
        }
        $source = $selection['sources'][0];
        $ids = $this->index->names[strtolower($source['symbol'])] ?? [];
        if (count($ids) !== 1) {
            return null;
        }
        $declaration = $this->index->elements[$ids[0]];
        $selector = $declaration['metadata']['serialization_selector'] ?? null;
        if ($selector === null || ! $selector['resolved'] || ($declaration['metadata']['visibility'] ?? null) === 'private') {
            return null;
        }

        return $this->mergeClassSelector($type, $property, ['keys' => $selector['keys'], 'sources' => [$source]]);
    }

    /** @param array<string, mixed> $site */
    private function instanceTransport(array $site, bool $customFactory): bool
    {
        $transport = $site['metadata']['serialization_transport'];
        $descriptor = str_starts_with($transport['receiver'], '@response-factory:') ? 'Illuminate\\Contracts\\Routing\\ResponseFactory' : $transport['receiver'];
        $receiver = $this->calls->receiverCandidates($descriptor);
        if ($receiver['limited'] || ! (new CatalogAttributeAccessResolver($this->index, $this->calls))->visibleReceiver($site, $receiver['sources'])) {
            $this->diagnostic($site, 'HTTP transport receiver is inaccessible or its source lookup reached a limit.');

            return false;
        }
        $factory = in_array($transport['method'], ['json', 'make'], true);
        $contract = $factory ? 'Illuminate\\Contracts\\Routing\\ResponseFactory' : $transport['types'][0];
        $base = $factory ? 'Illuminate\\Routing\\ResponseFactory' : $contract;
        $matched = false;
        foreach ($receiver['types'] as $type) {
            $ids = $this->index->namedTypes($type);
            $hasContract = in_array($type, [$contract, $base], true) || count($ids) === 1 && ($this->index->hasContract($ids[0], $contract) || $this->index->hasContract($ids[0], $base));
            if (! $hasContract) {
                continue;
            }
            if ($factory && $customFactory || in_array($type, [$contract, $base], true) && $ids !== [] || count($ids) > 1) {
                $this->diagnostic($site, 'HTTP factory binding or framework receiver declaration is customized in source.');

                continue;
            }
            if ($ids !== []) {
                if ($factory && ! $this->index->hasContract($ids[0], $base)) {
                    $this->diagnostic($site, 'Source response factory implements the contract without the default framework implementation.');

                    continue;
                }
                $hooks = $transport['method'] === 'setcontent' ? ['setContent', 'shouldBeJson', 'morphToJson'] : [$transport['method']];
                $standard = true;
                foreach ($hooks as $hook) {
                    if (! $this->budget($site)) {
                        return false;
                    }
                    $selection = $this->calls->sourceMethodCandidate($type, $hook);
                    $standard = $standard && ! $selection['limited'] && ! $selection['unresolved'] && $selection['method'] === null;
                }
                if (! $standard) {
                    $this->diagnostic($site, 'Source HTTP response receiver overrides a serialization hook.');

                    continue;
                }
            }
            $matched = true;
        }

        return $matched;
    }

    /** @param array<string, mixed> $site
     * @param  array{keys: list<string>, sources: list<array<string, mixed>>}|null  $hidden
     * @param  array{keys: list<string>, sources: list<array<string, mixed>>}|null  $visible
     * @param  array{keys: list<string>, sources: list<array<string, mixed>>}|null  $appends
     * @return array{array{keys: list<string>, sources: list<array<string, mixed>>}|null, array{keys: list<string>, sources: list<array<string, mixed>>}|null, array{keys: list<string>, sources: list<array<string, mixed>>}|null}|null
     */
    private function changedSelectors(array $site, string $type, ?array $hidden, ?array $visible, ?array $appends): ?array
    {
        $models = $this->index->namedTypes($type);
        $knownFactory = count($models) === 1 && $this->index->hasContract($models[0], 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory')
            && $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Factories\\HasFactory') === [];
        foreach ($site['metadata']['serialization_steps'] ?? [] as $step) {
            if (! $this->budget($site) || ! $step['resolved'] || $step['condition'] === null) {
                return null;
            }
            $method = $step['method'];
            foreach (str_ends_with($method, 'if') ? [$method, substr($method, 0, -2)] : [$method] as $hook) {
                $selection = $this->calls->sourceMethodCandidate($type, $hook, knownHasFactory: $knownFactory);
                if ($selection['method'] !== null || $selection['unresolved'] || $selection['limited']) {
                    return null;
                }
            }
            if (! $step['condition']) {
                continue;
            }
            $source = ['path' => $site['path'], 'line' => $step['line'], 'end_line' => $step['line'], 'symbol' => $method, 'offset' => $step['offset']];
            $name = str_contains($method, 'hidden') ? 'hidden' : (str_contains($method, 'visible') ? 'visible' : 'appends');
            if (str_starts_with($method, 'set')) {
                $$name = ['keys' => $step['keys'], 'sources' => [$source]];
            } elseif (str_starts_with($method, 'makevisible')) {
                if ($hidden === null || $visible === null) {
                    return null;
                }
                $hidden = ['keys' => array_values(array_diff($hidden['keys'], $step['keys'])), 'sources' => [...$hidden['sources'], $source]];
                if ($visible['keys'] !== []) {
                    $visible = ['keys' => array_values(array_unique([...$visible['keys'], ...$step['keys']])), 'sources' => [...$visible['sources'], $source]];
                }
            } else {
                $selector = $$name;
                if ($selector === null) {
                    return null;
                }
                $$name = ['keys' => array_values(array_unique([...$selector['keys'], ...$step['keys']])), 'sources' => [...$selector['sources'], $source]];
            }
        }

        return [$hidden, $visible, $appends];
    }

    /** @param array{keys: list<string>, sources: list<array<string, mixed>>} $property
     * @return array{keys: list<string>, sources: list<array<string, mixed>>}|null
     */
    private function mergeClassSelector(string $type, string $name, array $property): ?array
    {
        $models = $this->index->namedTypes($type);
        $attribute = count($models) === 1 ? $this->classSelector($models[0], $name) : null;
        if ($attribute === null) {
            return null;
        }
        if ($attribute['selector'] === null) {
            return $property;
        }
        $profile = $this->frameworkProfile;
        if ($profile === null) {
            return null;
        }
        if ($profile === 'ignored') {
            return $property;
        }
        $class = 'Illuminate\\Database\\Eloquent\\Attributes\\'.ucfirst($name);
        if ($this->index->namedTypes($class) !== [] || ! $attribute['selector']['resolved']) {
            return null;
        }
        if ($profile === 'fallback' && $property['keys'] !== []) {
            return $property;
        }
        $model = $models[0];
        $knownFactory = $this->index->hasContract($model, 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory')
            && $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Factories\\HasFactory') === [];
        foreach ($profile === 'merge' ? ['resolveClassAttribute', 'merge'.ucfirst($name)] : ['resolveClassAttribute'] as $hook) {
            $selection = $this->calls->sourceMethodCandidate($type, $hook, knownHasFactory: $knownFactory);
            if ($selection['method'] !== null || $selection['unresolved'] || $selection['limited']) {
                return null;
            }
        }

        return ['keys' => array_values(array_unique([...$property['keys'], ...$attribute['selector']['keys']])), 'sources' => [...$property['sources'], ...$attribute['sources'], ...$this->frameworkSources]];
    }

    /** Laravel 12 ignores these selectors; 13.0-13.2 use fallback, 13.3+ merge them. */
    private function resolveFrameworkProfile(): ?string
    {
        $profiles = [];
        $this->frameworkSources = [];
        foreach ($this->index->elements as $element) {
            if ($element['kind'] !== 'composer-package' || ! in_array($element['name'], ['laravel/framework', 'illuminate/database'], true)) {
                continue;
            }
            $version = $element['metadata']['normalized_version'] ?? '';
            if (preg_match('/\A(12|13)\.(\d+)\.(\d+)\.0\z/D', $version, $match) !== 1) {
                return null;
            }
            $profile = $match[1] === '12' ? 'ignored' : ((int) $match[2] < 3 ? 'fallback' : 'merge');
            $profiles[] = $profile;
            $this->frameworkSources[] = ['path' => $element['path'], 'line' => $element['line'], 'end_line' => $element['end_line'],
                'symbol' => $element['name'], 'framework_version' => $version, 'selector_profile' => $profile];
        }

        return count(array_unique($profiles)) === 1 ? $profiles[0] : null;
    }

    /** @param array<string, bool> $seen
     * @return array{selector: array<string, mixed>|null, sources: list<array<string, mixed>>}|null
     */
    private function classSelector(string $model, string $name, array $seen = []): ?array
    {
        if (isset($seen[$model]) || count($seen) >= 32) {
            return null;
        }
        $seen[$model] = true;
        $element = $this->index->elements[$model];
        $selector = $element['metadata']['serialization_attributes'][$name] ?? null;
        if ($selector !== null) {
            return ['selector' => $selector, 'sources' => [['path' => $element['path'], 'line' => $element['line'], 'end_line' => $element['end_line'], 'symbol' => $element['name']]]];
        }
        $parents = [];
        foreach ($this->index->out[$model] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if ($edge['kind'] === 'extends' && isset($this->index->elements[$edge['to']])) {
                $parents[] = $edge['to'];
            }
        }
        if (count($parents) > 1) {
            return null;
        }

        return $parents === [] ? ['selector' => null, 'sources' => []] : $this->classSelector($parents[0], $name, $seen);
    }

    private function standard(string $type, string $form): bool
    {
        $hooks = ['attributesToArray', 'getArrayableAttributes', 'getArrayableAppends', 'getArrayableItems', 'getAttributes', 'getHidden', 'getVisible', 'getAppends',
            'addMutatedAttributesToArray', 'getMutatedAttributes', 'mutateAttributeForArray', 'mutateAttribute', 'mutateAttributeMarkedAttribute', 'hasAttributeMutator',
            'getAttributeMarkedMutatorMethods', 'getMutatorMethods', 'cacheMutatedAttributes', 'getClassCastableAttributeValue', 'isClassCastable', 'initializeHidesAttributes', 'initializeHasAttributes', '__construct'];
        if ($form !== 'attributestoarray') {
            array_push($hooks, 'toArray', 'withoutRecursion');
        }
        if (in_array($form, ['jsonserialize', 'tojson', 'json_encode'], true)) {
            $hooks[] = 'jsonSerialize';
        }
        if ($form === 'tojson') {
            $hooks[] = 'toJson';
        }
        $models = $this->index->namedTypes($type);
        $knownFactory = count($models) === 1 && $this->index->hasContract($models[0], 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory')
            && $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Factories\\HasFactory') === [];
        foreach ($hooks as $hook) {
            $method = $this->calls->sourceMethodCandidate($type, $hook, knownHasFactory: $knownFactory);
            if ($method['method'] !== null || $method['unresolved'] || $method['limited']) {
                return false;
            }
        }

        return true;
    }

    /** @return array{value: bool, sources: list<array<string, mixed>>}|null */
    private function snakeStyle(string $type): ?array
    {
        $selection = $this->calls->receiverCandidates('@property:'.$type.'#snakeAttributes');
        if ($selection['limited'] || count($selection['sources']) > 1) {
            return null;
        }
        if ($selection['sources'] === []) {
            return ['value' => true, 'sources' => []];
        }
        $source = $selection['sources'][0];
        $ids = $this->index->names[strtolower($source['symbol'])] ?? [];
        if (count($ids) !== 1) {
            return null;
        }
        $value = $this->index->elements[$ids[0]]['metadata']['serialization_snake'] ?? null;

        return is_bool($value) ? ['value' => $value, 'sources' => [$source]] : null;
    }

    private function hasCustomCast(string $model, string $attribute): bool
    {
        foreach ($this->index->out[$model] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if (in_array($edge['kind'], ['uses-cast', 'references-cast'], true) && ($edge['metadata']['attribute'] ?? null) === $attribute) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $site
     * @param  array<string, mixed>  $metadata
     */
    private function relation(array $site, string $target, string $kind, array $metadata): void
    {
        $this->index->addRelation(['from' => $site['parent'], 'to' => $target, 'kind' => $kind, 'path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line'],
            'knowledge' => 'static', 'resolution' => 'conditional', 'metadata' => $metadata]);
    }

    /** @param array<string, mixed> $site */
    private function diagnostic(array $site, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'eloquent_serialization_analysis', 'message' => $message, 'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['id']];
    }
}
