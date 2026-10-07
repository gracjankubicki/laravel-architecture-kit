<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ExecutionLinks;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** A returned resource can enter the response pipeline; construction alone does not serialize it. */
final class CatalogJsonResourceResolver
{
    /** @var array<string, bool> */
    private array $emitted = [];

    private int $visits = 0;

    /** @var list<int> */
    private array $frameworkMajors = [12, 13];

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(ExecutionLinks $links): void
    {
        $this->visits = 0;
        $this->frameworkMajors = $this->frameworkVersions();
        foreach (['Illuminate\\Http\\Resources\\Json\\JsonResource', 'Illuminate\\Http\\Resources\\Json\\ResourceCollection'] as $framework) {
            $shadow = $this->index->namedTypes($framework);
            if ($shadow !== []) {
                $this->notice($this->index->elements[$shadow[0]], 'Framework resource declaration is shadowed in source; default response behavior is not inferred.');

                return;
            }
        }
        foreach ($this->index->elements as $element) {
            if (! in_array($element['kind'], ['method', 'function', 'closure'], true)) {
                continue;
            }
            if (! $this->room($element)) {
                $this->notice($element, 'Resource composition reached its callable or memory budget.');

                return;
            }
            $types = $element['metadata']['return_types'] ?? [];
            $returnSources = [];
            $collectionTypes = [];
            foreach ($this->index->out[$element['id']] ?? [] as $position) {
                if (! $this->room($element)) {
                    return;
                }
                $row = $this->index->relations[$position];
                if ($row['kind'] !== 'returns-value') {
                    continue;
                }
                $receiver = $row['metadata']['receiver'];
                $values = $this->returnedResources($receiver, $element, $links, call: $row['metadata']['factory_call'] ?? null);
                foreach ($values as $value) {
                    if (! $this->room($element)) {
                        return;
                    }
                    if (! str_starts_with($value['receiver'], '@')) {
                        $types[] = $value['receiver'];
                        $collectionTypes[$value['receiver']] = ($collectionTypes[$value['receiver']] ?? false) || $value['collection'];
                        $returnSources[$value['receiver']][] = ['path' => $row['path'], 'line' => $row['line'], 'end_line' => $row['end_line']];
                        array_push($returnSources[$value['receiver']], ...$value['sources']);
                    }
                }
            }
            $factory = $element['metadata']['static_return'] ?? null;
            foreach (array_unique($types) as $type) {
                if (! $links->inherits($type, 'Illuminate\\Http\\Resources\\Json\\JsonResource') && ! $links->inherits($type, 'Illuminate\\Http\\Resources\\Json\\ResourceCollection')) {
                    continue;
                }
                $ids = $this->index->namedTypes($type);
                if (count($ids) !== 1) {
                    $this->notice($element, 'Returned JSON resource declaration is absent or ambiguous.');

                    continue;
                }
                $source = ['path' => $element['path'], 'line' => $element['line']];
                $collection = ($collectionTypes[$type] ?? false) || $factory !== null && strcasecmp($factory['class'], $type) === 0 && strtolower($factory['method']) === 'collection' && ! $this->hasMethod($type, $factory['method'], $element);
                $this->edge($element['id'], $ids[0], 'returns-resource', $source, ['collection' => $collection,
                    'return_sources' => $returnSources[$type] ?? [],
                    'conditions' => ['The callable returns this resource and its consumer resolves or serializes it; declared return types do not prove completion.']]);
                $this->hooks($type, $ids[0], $element, $links, $collection);
                if (! $collection && $links->inherits($type, 'Illuminate\\Http\\Resources\\Json\\ResourceCollection')) {
                    $this->collects($type, $ids[0], $element, $links);
                }
            }
        }
        $this->explicitOperations($links);
    }

    private function explicitOperations(ExecutionLinks $links): void
    {
        foreach ($this->index->relations as $row) {
            $jsonEncode = isset($row['metadata']['json_serialization_receiver']);
            if ($row['kind'] !== 'calls' || ! $jsonEncode && (($row['metadata']['form'] ?? null) !== 'instance'
                || ! in_array(strtolower($row['metadata']['method'] ?? ''), ['resolve', 'resolveresourcedata', 'toattributes', 'toarray', 'jsonserialize', 'tojson', 'toprettyjson', 'response', 'toresponse'], true))) {
                continue;
            }
            $element = $this->index->elements[$row['from']] ?? null;
            if ($element === null) {
                continue;
            }
            if (! $this->room($element)) {
                return;
            }
            if ($jsonEncode) {
                $names = str_starts_with($row['metadata']['receiver'], '@function:') ? json_decode(substr($row['metadata']['receiver'], 10), true, 32) : [];
                $shadowed = false;
                foreach (is_array($names) ? $names : [] as $name) {
                    foreach (is_string($name) ? ($this->index->names[strtolower($name)] ?? []) : [] as $target) {
                        $shadowed = $shadowed || $this->index->elements[$target]['kind'] === 'function';
                    }
                }
                if ($shadowed) {
                    $this->notice($element, 'JSON encoding function is shadowed in source; builtin serialization is not inferred.');

                    continue;
                }
            }
            $receiver = $jsonEncode ? $row['metadata']['json_serialization_receiver'] : $row['metadata']['receiver'];
            $receiverSources = [];
            if (str_starts_with($receiver, '@return:')) {
                $values = $this->returnedResources($receiver, $element, $links, call: $row['metadata']['receiver_factory_call'] ?? null);
            } else {
                $candidates = $this->calls->receiverCandidates($receiver);
                $receiverSources = $candidates['sources'];
                if ($candidates['limited']) {
                    $this->notice($element, 'Explicit resource receiver lookup reached its source traversal budget.');
                }
                $values = array_map(fn ($type) => ['receiver' => $type, 'sources' => [], 'collection' => false], $candidates['types']);
            }
            foreach ($values as $value) {
                $type = $value['receiver'];
                if (! $links->inherits($type, 'Illuminate\\Http\\Resources\\Json\\JsonResource') && ! $links->inherits($type, 'Illuminate\\Http\\Resources\\Json\\ResourceCollection')) {
                    continue;
                }
                $targets = $this->index->namedTypes($type);
                if (! $this->room($element)) {
                    return;
                }
                if (count($targets) !== 1) {
                    $this->notice($element, 'Explicit resource receiver is absent, ambiguous or reached its composition budget.');

                    continue;
                }
                $operation = $jsonEncode ? 'jsonserialize' : strtolower($row['metadata']['method']);
                $name = '(resource '.$operation.') '.$row['path'].':'.$row['metadata']['offset'].':'.$type;
                $id = CatalogElement::identity($row['path'], 'resource-operation', $name, $row['metadata']['offset']);
                $site = new CatalogElement($id, $name, 'resource-operation', $row['line'], $row['end_line'], $row['metadata']['offset'], $row['from'],
                    metadata: ['operation' => $operation, 'resource_type' => $type, 'collection' => $value['collection'], 'execution_proven' => false]);
                $this->index->elements[$id] = [...$site->toArray(), 'path' => $row['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => []];
                $this->index->names[strtolower($name)][] = $id;
                $this->edge($row['from'], $id, 'invokes-resource-operation', $row, ['operation' => $operation, 'invocation_form' => $jsonEncode ? 'json_encode' : 'method', 'return_sources' => $value['sources'], 'receiver_sources' => $receiverSources,
                    'conditions' => ['The source instance call reaches this resource candidate; nullsafe branches and factory outcomes are not evaluated.']]);
                $this->edge($id, $targets[0], 'resource-operation-target', $row, ['operation' => $operation]);
                $this->operationHooks($type, $id, $element, $links, $operation, $value['collection']);
            }
        }
    }

    /** @param array<string, mixed> $element */
    private function operationHooks(string $type, string $id, array $element, ExecutionLinks $links, string $operation, bool $collection): void
    {
        if ($collection) {
            $versions = $this->frameworkMajors;
            if (in_array($operation, ['toattributes', 'resolveresourcedata'], true)) {
                if (in_array(12, $versions, true)) {
                    $this->notice($element, 'This anonymous resource collection data operation has no default Laravel 12 method.');
                }
                $versions = array_values(array_intersect($versions, [13]));
            }
            $this->itemHooks($type, $id, $element, $links, $versions);

            return;
        }
        if (in_array($operation, ['response', 'toresponse'], true)) {
            if ($operation === 'response' && $this->hasMethod($type, 'response', $element)) {
                $this->hook($type, $id, 'response', $links, 'The explicit response call reaches this source override.');
            } else {
                $this->hooks($type, $id, $element, $links, false);
            }

            return;
        }
        $this->dataHooks($type, $id, $element, $links, $operation);
    }

    /** @param array<string, mixed> $element */
    private function dataHooks(string $type, string $id, array $element, ExecutionLinks $links, string $operation): void
    {
        $selected = $defaults = [];
        foreach ($this->frameworkMajors as $major) {
            if ($major === 12 && in_array($operation, ['toattributes', 'resolveresourcedata'], true) && ! $this->hasMethod($type, $operation, $element)) {
                $this->notice($element, 'This resource data operation has no default Laravel 12 method.');

                continue;
            }
            $tail = $major === 12 ? ['resolve', 'toArray'] : ['resolve', 'resolveResourceData', 'toAttributes', 'toArray'];
            $names = match ($operation) {
                'toarray' => ['toArray'],
                'toattributes' => ['toAttributes', 'toArray'],
                'resolveresourcedata' => ['resolveResourceData', 'toAttributes', 'toArray'],
                'jsonserialize' => ['jsonSerialize', ...$tail],
                'tojson' => ['toJson', 'jsonSerialize', ...$tail],
                'toprettyjson' => ['toPrettyJson', 'toJson', 'jsonSerialize', ...$tail],
                default => $tail,
            };
            $found = false;
            foreach ($names as $name) {
                if ($major === 13 && $name === 'toArray' && $operation !== 'toarray' && $this->attributesData($type, $id, $element)) {
                    $found = true;
                    break;
                }
                if ($this->hasMethod($type, $name, $element)) {
                    $selected[$name][] = $major;
                    $found = true;
                    break;
                }
            }
            if (! $found && $links->inherits($type, 'Illuminate\\Http\\Resources\\Json\\ResourceCollection')) {
                $defaults[] = $major;
            }
        }
        foreach ($selected as $name => $versions) {
            $this->hook($type, $id, $name, $links, 'The resource data operation reaches this source override under the indicated Laravel version; subsequent default hooks are not inferred.', $versions);
        }
        if ($defaults !== []) {
            $this->operationItems($type, $id, $element, $links, $defaults);
        }
    }

    /** @param array<string, mixed> $element
     * @param  array{creator: string, form: string, binding: ?string, receiver_call?: array<string, mixed>}|null  $call
     * @return list<array{receiver: string, sources: list<array<string, mixed>>, collection: bool}>
     */
    private function returnedResources(string $receiver, array $element, ExecutionLinks $links, int $depth = 0, ?array $call = null): array
    {
        if ($depth >= 16 || ! $this->room($element)) {
            $this->notice($element, 'Resource return chain reached its depth, operation or memory budget.');

            return [];
        }
        if (! str_starts_with($receiver, '@')) {
            return [['receiver' => $receiver, 'sources' => [], 'collection' => false]];
        }
        if (! str_starts_with($receiver, '@return:')) {
            return [];
        }
        $returned = $call === null ? ['values' => [], 'limited' => false] : $this->calls->factoryReturnCandidates($receiver, $call);
        if ($returned['limited']) {
            $this->notice($element, 'Resource factory return lookup reached its source traversal budget.');
        }
        $result = [];
        foreach ($returned['values'] as $value) {
            if (! str_starts_with($value['receiver'], '@')) {
                $result[] = ['receiver' => $value['receiver'], 'sources' => $value['sources'], 'collection' => false];
            }
        }
        if ($result !== []) {
            return $result;
        }
        $descriptor = json_decode(substr($receiver, 8), true, 32);
        if (! is_array($descriptor) || array_keys($descriptor) !== [0, 1, 2] || ! is_string($descriptor[0]) || ! is_string($descriptor[1]) || ! is_bool($descriptor[2])) {
            return [];
        }
        [$producer, $method] = $descriptor;
        $method = strtolower($method);
        if (in_array($method, ['make', 'collection'], true) && ! str_starts_with($producer, '@')
            && ($links->inherits($producer, 'Illuminate\\Http\\Resources\\Json\\JsonResource') || $links->inherits($producer, 'Illuminate\\Http\\Resources\\Json\\ResourceCollection')) && ! $this->hasMethod($producer, $method, $element)) {
            if ($method === 'collection' && $this->hasMethod($producer, 'newCollection', $element)) {
                $selection = $this->calls->sourceMethodCandidate($producer, 'newCollection');
                $metadata = $selection['method']['metadata'] ?? [];
                if ($selection['unresolved'] || $selection['limited'] || ! in_array($metadata['visibility'] ?? null, ['public', 'protected'], true)
                    || ! ($metadata['static'] ?? false) || ($metadata['abstract'] ?? false)) {
                    $this->notice($element, 'Custom newCollection is inaccessible or unresolved; default anonymous collection is not inferred.');

                    return [];
                }
                $custom = '@return:'.json_encode([$producer, 'newCollection', true], JSON_THROW_ON_ERROR);
                $values = $this->returnedResources($custom, $element, $links, $depth + 1, ['creator' => $producer, 'form' => 'static', 'binding' => 'static']);
                if ($values === []) {
                    $this->notice($element, 'Custom newCollection return is unresolved; default anonymous collection is not inferred.');
                }

                return $values;
            }
            if ($method === 'collection' && $this->index->namedTypes('Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection') !== []) {
                $this->notice($element, 'Framework anonymous resource collection is shadowed in source; default collection behavior is not inferred.');

                return [];
            }

            return [['receiver' => $producer, 'sources' => [], 'collection' => $method === 'collection']];
        }
        if (! in_array($method, ['additional', 'preservequery', 'withquery'], true)) {
            return [];
        }
        foreach ($this->returnedResources($producer, $element, $links, $depth + 1, $call['receiver_call'] ?? null) as $value) {
            $type = $value['receiver'];
            if (! $links->inherits($type, 'Illuminate\\Http\\Resources\\Json\\JsonResource') && ! $links->inherits($type, 'Illuminate\\Http\\Resources\\Json\\ResourceCollection')) {
                continue;
            }
            if (! $value['collection'] && $this->hasMethod($type, $method, $element)) {
                $custom = '@return:'.json_encode([$type, $method, true], JSON_THROW_ON_ERROR);
                $customValues = $this->calls->factoryReturnCandidates($custom, ['creator' => $call['creator'] ?? '', 'form' => 'instance', 'binding' => null]);
                if ($customValues['limited']) {
                    $this->notice($element, 'Custom resource fluent return lookup reached its source traversal budget.');
                }
                foreach ($customValues['values'] as $returnedValue) {
                    if (! str_starts_with($returnedValue['receiver'], '@')) {
                        $result[] = ['receiver' => $returnedValue['receiver'], 'sources' => [...$value['sources'], ...$returnedValue['sources']], 'collection' => false];
                    }
                }
                if ($customValues['values'] === []) {
                    $this->notice($element, 'Custom resource fluent method replaces the default return contract; its result is unresolved.');
                }

                continue;
            }
            if ($method !== 'additional' && ! $value['collection'] && ! $links->inherits($type, 'Illuminate\\Http\\Resources\\Json\\ResourceCollection')) {
                $this->notice($element, 'Collection fluent method is unavailable on this resource contract.');

                continue;
            }
            $result[] = $value;
        }

        return $result;
    }

    /** @param array<string, mixed> $element */
    private function collects(string $type, string $id, array $element, ExecutionLinks $links): void
    {
        if ($this->hasMethod($type, 'collects', $element)) {
            $this->notice($element, 'Custom collects method replaces the default resource collection selector.');

            return;
        }
        $groups = [];
        foreach ($this->frameworkMajors as $major) {
            $selection = $this->collectionSelector($type, $id, $element, $links, $major);
            if ($selection === null) {
                continue;
            }
            $key = hash('xxh128', serialize($selection));
            $groups[$key] ??= [...$selection, 'versions' => []];
            $groups[$key]['versions'][] = $major;
        }
        foreach ($groups as $selection) {
            $this->edge($id, $selection['id'], 'resource-collection-item', $selection['source'], ['basis' => $selection['basis'], 'framework_versions' => $selection['versions'],
                'conditions' => ['Collection mapping may construct item resources under the indicated Laravel version; serialization depends on data and custom hooks.']]);
            $defaults = [];
            foreach ($selection['versions'] as $major) {
                $methods = $major === 12 ? ['resolve', 'toArray'] : ['resolve', 'resolveResourceData', 'toAttributes', 'toArray'];
                if (count(array_filter($methods, fn ($method) => $this->hasMethod($type, $method, $element))) === 0 && ($major !== 13 || ! $this->attributesData($type, $id, $element))) {
                    $defaults[] = $major;
                }
            }
            if ($defaults !== []) {
                $this->itemHooks($selection['target'], $selection['id'], $element, $links, $defaults);
            }
        }
    }

    /** @param array<string, mixed> $element
     * @return array{id: string, target: string, source: array<string, mixed>, basis: string}|null
     */
    private function collectionSelector(string $type, string $id, array $element, ExecutionLinks $links, int $major): ?array
    {
        $selector = null;
        foreach ($this->index->out[$id] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            $attribute = $this->index->elements[$edge['to']] ?? null;
            if ($major === 13 && $edge['kind'] === 'contains' && ($attribute['kind'] ?? null) === 'attribute' && $attribute['name'] === 'Illuminate\\Http\\Resources\\Attributes\\Collects') {
                if ($this->index->namedTypes($attribute['name']) !== []) {
                    $this->notice($element, 'Framework Collects attribute is shadowed in source; default collection selector is not inferred.');

                    return null;
                }
                $selector = $attribute;
                break;
            }
        }
        $selector ??= $this->sourceProperty($id, 'collects', $element);
        if (($selector['kind'] ?? null) === 'property' && (($selector['metadata']['visibility'] ?? null) === 'private' || ($selector['metadata']['static'] ?? false))) {
            $this->notice($element, 'Default collection selector property is inaccessible or static; naming fallback is not inferred.');

            return null;
        }
        $target = $selector['metadata']['resource_collects']['target'] ?? null;
        $source = $selector === null ? ['path' => $element['path'], 'line' => $element['line']] : ['path' => $selector['path'], 'line' => $selector['line']];
        $basis = $selector['kind'] ?? 'source-convention';
        if ($selector !== null && ! ($selector['metadata']['resource_collects']['resolved'] ?? false)) {
            $this->notice($element, 'Resource collection selector is dynamic or ambiguous.');

            return null;
        }
        if ($target === null && str_ends_with($type, 'Collection')) {
            foreach ([substr($type, 0, -10), substr($type, 0, -10).'Resource'] as $candidate) {
                if ($this->index->namedTypes($candidate) !== []) {
                    $target = $candidate;
                    $basis = 'source-convention';
                    break;
                }
            }
        }
        if ($target === null) {
            return null;
        }
        $ids = $this->index->namedTypes($target);
        if (count($ids) !== 1 || ! $links->inherits($target, 'Illuminate\\Http\\Resources\\Json\\JsonResource')) {
            $this->notice($element, 'Resource collection item declaration is absent, ambiguous or lacks the JsonResource contract.');

            return null;
        }

        return ['id' => $ids[0], 'target' => $target, 'source' => $source, 'basis' => $basis];
    }

    /** @param array<string, mixed> $element
     * @param  list<string>  $seen
     * @return array<string, mixed>|null
     */
    private function sourceProperty(string $id, string $name, array $element, array $seen = []): ?array
    {
        if (in_array($id, $seen, true) || count($seen) >= 32 || ! $this->room($element)) {
            $this->notice($element, 'Resource property lookup reached a cycle or source limit.');

            return ['metadata' => ['resource_collects' => ['resolved' => false]], 'path' => $element['path'], 'line' => $element['line']];
        }
        foreach ($this->index->out[$id] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            $property = $this->index->elements[$edge['to']] ?? null;
            if ($edge['kind'] === 'contains' && ($property['kind'] ?? null) === 'property' && str_ends_with($property['name'], '::$'.$name)) {
                return $property;
            }
        }
        foreach (['uses-trait', 'extends'] as $kind) {
            $foundSelectors = [];
            foreach ($this->index->out[$id] ?? [] as $position) {
                $edge = $this->index->relations[$position];
                if ($edge['kind'] === $kind && isset($this->index->elements[$edge['to']])) {
                    $found = $this->sourceProperty($edge['to'], $name, $element, [...$seen, $id]);
                    if ($found !== null) {
                        $foundSelectors[] = $found;
                    }
                }
            }
            if (count($foundSelectors) > 1) {
                $this->notice($element, 'Several source properties define the resource property; compatibility is unresolved.');

                return ['metadata' => ['resource_collects' => ['resolved' => false]], 'path' => $element['path'], 'line' => $element['line']];
            }
            if ($foundSelectors !== []) {
                return $foundSelectors[0];
            }
        }

        return null;
    }

    /** A structural data dependency cannot invoke every method of the property type.
     * @param  array<string, mixed>  $element
     */
    private function attributesData(string $type, string $id, array $element): bool
    {
        $types = $this->index->namedTypes($type);
        if (count($types) !== 1) {
            return false;
        }
        $property = $this->sourceProperty($types[0], 'attributes', $element);
        if ($property === null) {
            return false;
        }
        if (! isset($property['id']) || ($property['metadata']['visibility'] ?? null) === 'private' || ($property['metadata']['static'] ?? false)) {
            $this->notice($element, 'Default Laravel 13 resource attributes access is inaccessible or unresolved; toArray fallback is not inferred.');

            return true;
        }
        $this->edge($id, $property['id'], 'resource-operation-target', $property, ['data_property' => 'attributes', 'framework_versions' => [13],
            'conditions' => ['Default Laravel 13 toAttributes reads this property; initialization, value and nested serialization are not evaluated.']]);

        return true;
    }

    /** @param array<string, mixed> $element
     * @phpstan-impure
     */
    private function room(array $element): bool
    {
        if (++$this->visits > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->notice($element, 'Resource composition reached its operation or memory budget.');

            return false;
        }

        return true;
    }

    /** @return list<int> */
    private function frameworkVersions(): array
    {
        $versions = [];
        foreach ($this->index->elements as $element) {
            if ($element['kind'] === 'composer-package' && in_array($element['name'], ['laravel/framework', 'illuminate/http'], true)
                && is_string($element['metadata']['normalized_version'] ?? null)
                && preg_match('/^(12|13)\./', $element['metadata']['normalized_version'], $matches) === 1) {
                $versions[] = (int) $matches[1];
            }
        }

        return $versions === [] ? [12, 13] : array_values(array_unique($versions));
    }

    /** @param array<string, mixed> $element
     * @param  list<int>|null  $versions
     */
    private function itemHooks(string $type, string $id, array $element, ExecutionLinks $links, ?array $versions = null): void
    {
        $selected = [];
        foreach ($versions ?? $this->frameworkMajors as $major) {
            $names = $major === 12 ? ['toArray'] : ['resolve', 'resolveResourceData', 'toAttributes', 'toArray'];
            foreach ($names as $name) {
                if ($major === 13 && $name === 'toArray' && $this->attributesData($type, $id, $element)) {
                    break;
                }
                if ($this->hasMethod($type, $name, $element)) {
                    $selected[$name][] = $major;
                    break;
                }
            }
        }
        foreach ($selected as $name => $versions) {
            $this->hook($type, $id, $name, $links, 'Default collection serialization reaches each present item under the indicated Laravel version; empty or custom collection data is not evaluated.', $versions);
        }
    }

    /** @param array<string, mixed> $element
     * @param  list<int>  $versions
     */
    private function operationItems(string $type, string $id, array $element, ExecutionLinks $links, array $versions): void
    {
        $classes = $this->index->namedTypes($type);
        if (count($classes) !== 1) {
            return;
        }
        $this->collects($type, $classes[0], $element, $links);
        foreach ($this->index->out[$classes[0]] ?? [] as $position) {
            $row = $this->index->relations[$position];
            if ($row['kind'] === 'resource-collection-item') {
                $itemVersions = array_values(array_intersect($versions, $row['metadata']['framework_versions'] ?? $this->frameworkMajors));
                if ($itemVersions === []) {
                    continue;
                }
                $target = $this->index->elements[$row['to']];
                $this->edge($id, $row['to'], 'resource-operation-target', $row, ['collection_item' => true, 'framework_versions' => $itemVersions]);
                $this->itemHooks($target['name'], $id, $element, $links, $itemVersions);
            }
        }
    }

    /** @param array<string, mixed> $element */
    private function hooks(string $type, string $id, array $element, ExecutionLinks $links, bool $collection): void
    {
        if ($collection) {
            $this->itemHooks($type, $id, $element, $links);

            return;
        }
        if ($this->hasMethod($type, 'toResponse', $element)) {
            $this->hook($type, $id, 'toResponse', $links, 'Custom response conversion may replace default resource serialization.');
            $this->notice($element, 'JSON resource overrides toResponse; default HTTP response hooks are not inferred.');

            return;
        }
        foreach (['with', 'withResponse', 'jsonOptions'] as $method) {
            $this->hook($type, $id, $method, $links, 'Default HTTP resource response reaches this hook; serialization alone need not call it.');
        }
        if ($links->inherits($type, 'Illuminate\\Http\\Resources\\Json\\ResourceCollection')) {
            $this->hook($type, $id, 'paginationInformation', $links, 'Paginated resource responses may call this hook when the underlying resource is a paginator.');
        }
        $this->dataHooks($type, $id, $element, $links, 'resolve');
    }

    /** @param list<int> $versions */
    private function hook(string $type, string $id, string $name, ExecutionLinks $links, string $condition, ?array $versions = null): void
    {
        $selection = $this->calls->sourceMethodCandidate($type, $name);
        $method = $selection['method'];
        if ($selection['unresolved'] || $selection['limited'] || $method === null || ($method['metadata']['visibility'] ?? null) !== 'public' || ($method['metadata']['abstract'] ?? false) || ($method['metadata']['static'] ?? false)) {
            if ($method !== null) {
                $this->notice($this->index->elements[$id], 'Source resource hook is inaccessible, abstract or static; default execution is not inferred.');
            }

            return;
        }
        $declaration = $this->index->elements[$method['id']];
        $this->edge($id, $method['id'], 'resource-response-hook', $declaration, ['method' => $name, 'scope' => $method['scope'], 'resource_type' => $type, 'framework_versions' => $versions ?? $this->frameworkMajors, 'conditions' => [$condition]]);
    }

    /** @param array<string, mixed> $element */
    private function hasMethod(string $type, string $name, array $element): bool
    {
        $selection = $this->calls->sourceMethodCandidate($type, $name);
        if ($selection['unresolved'] || $selection['limited']) {
            $this->notice($element, 'Resource source method selection is ambiguous, unavailable or reached its traversal budget.');

            return true;
        }

        return $selection['method'] !== null;
    }

    /** @param array<string, mixed> $source
     * @param  array<string, mixed>  $metadata
     */
    private function edge(string $from, string $to, string $kind, array $source, array $metadata): void
    {
        $key = hash('xxh128', serialize([$from, $to, $kind, $source, $metadata]));
        if (isset($this->emitted[$key])) {
            return;
        }
        $this->emitted[$key] = true;
        $this->index->addRelation(['from' => $from, 'to' => $to, 'kind' => $kind, 'path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['line'],
            'resolution' => 'conditional', 'knowledge' => 'static', 'metadata' => [...$metadata, 'execution_proven' => false]]);
    }

    /** @param array<string, mixed> $element */
    private function notice(array $element, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'resource_response_analysis', 'message' => $message, 'path' => $element['path'], 'line' => $element['line'], 'subject' => $element['id']];
    }
}
