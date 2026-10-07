<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Source property operations may invoke selected Eloquent attribute methods and callbacks. */
final class CatalogAttributeAccessResolver
{
    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(): void
    {
        if ($this->index->namedTypes('Illuminate\\Database\\Eloquent\\Model') !== []) {
            return;
        }
        $attributes = [];
        foreach ($this->index->relations as $relation) {
            if (in_array($relation['kind'], ['declares-accessor', 'declares-mutator'], true)) {
                $attributes[$relation['from']][hash('sha256', $relation['metadata']['attribute'])][$relation['kind']][] = $relation['to'];
            }
        }
        foreach ($this->index->elements as $site) {
            if ($site['kind'] !== 'attribute-access') {
                continue;
            }
            if (in_array($site['metadata']['form'], ['toarray', 'attributestoarray', 'tojson', 'jsonserialize', 'json_encode', 'http_json', 'http_return', 'http_instance'], true)) {
                continue;
            }
            $receivers = $this->calls->receiverCandidates($site['metadata']['receiver']);
            if (! $this->visibleReceiver($site, $receivers['sources'])) {
                $this->diagnostic($site, 'Receiver property is inaccessible from the source lexical class.');

                continue;
            }
            foreach ($receivers['types'] as $type) {
                $models = $this->index->namedTypes($type);
                if (count($models) !== 1 || ! $this->index->hasContract($models[0], 'Illuminate\\Database\\Eloquent\\Model')) {
                    continue;
                }
                if ($receivers['limited']) {
                    $this->diagnostic($site, 'Attribute receiver resolution reached its budget.');

                    continue;
                }
                $key = $site['metadata']['attribute_key'];
                if ($key === null || $site['metadata']['direction'] === 'inspect') {
                    $this->diagnostic($site, 'Dynamic property or isset/unset attribute semantics require inspection.');

                    continue;
                }
                $declarations = $attributes[$models[0]][$key] ?? [];
                $declared = $declarations['declares-accessor'][0] ?? $declarations['declares-mutator'][0] ?? null;
                if ($declared === null) {
                    continue;
                }
                $name = $this->index->elements[$declared]['metadata']['eloquent_attribute']['attribute'];
                $property = $this->calls->receiverCandidates('@property:'.$type.'#'.$name);
                if ($site['metadata']['form'] === 'property' && ($property['sources'] !== [] || $property['limited'])) {
                    $this->diagnostic($site, 'A declared PHP property or unresolved member prevents standard magic attribute inference.');

                    continue;
                }
                foreach (['read' => 'accessor', 'write' => 'mutator'] as $direction => $role) {
                    if (! in_array($site['metadata']['direction'], [$direction, 'read-write'], true)) {
                        continue;
                    }
                    $hooks = $direction === 'read'
                        ? ['__get', 'getAttribute', 'hasAttribute', 'getAttributeValue', 'transformModelValue', 'hasGetMutator', 'hasAttributeGetMutator', 'mutateAttribute', 'mutateAttributeMarkedAttribute']
                        : ['__set', 'setAttribute', 'hasSetMutator', 'hasAttributeSetMutator', 'setMutatedAttributeValue', 'setAttributeMarkedMutatedAttributeValue'];
                    if ($site['metadata']['form'] !== 'property') {
                        $hooks = array_values(array_diff($hooks, ['__get', '__set']));
                    }
                    if ($site['metadata']['form'] === 'getattributevalue') {
                        $hooks = array_values(array_diff($hooks, ['getAttribute', 'hasAttribute']));
                    }
                    if (! $this->standardHooks($type, $hooks)) {
                        $this->diagnostic($site, 'Source attribute dispatch overrides or unresolved hierarchy prevent standard attribute inference.');

                        continue;
                    }
                    $targets = $attributes[$models[0]][$key]['declares-'.$role] ?? [];
                    $legacy = array_values(array_filter($targets, fn ($id) => $this->index->elements[$id]['metadata']['eloquent_attribute']['style'] === 'legacy'));
                    $targets = $legacy !== [] ? $legacy : $targets;
                    if (count($targets) !== 1) {
                        if (count($targets) > 1) {
                            $this->diagnostic($site, 'Several selected declarations match the attribute operation.');
                        }

                        continue;
                    }
                    $method = $this->index->elements[$targets[0]];
                    $descriptor = $method['metadata']['eloquent_attribute'];
                    if ($descriptor['style'] === 'legacy') {
                        $this->relation($site, $method['id'], 'invokes-'.$role, $models[0], $receivers['sources']);
                    } else {
                        $this->relation($site, $method['id'], 'invokes-attribute-definition', $models[0], $receivers['sources']);
                        $callback = $descriptor[$direction === 'read' ? 'get' : 'set'];
                        if ($descriptor['resolved'] && is_string($callback) && isset($this->index->elements[$callback])) {
                            $this->relation($site, $callback, 'invokes-'.$role, $models[0], $receivers['sources']);
                        } else {
                            $this->diagnostic($site, 'Attribute callback is not resolved from source.');
                        }
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $site
     * @param  list<array<string, mixed>>  $sources
     */
    public function visibleReceiver(array $site, array $sources): bool
    {
        $lexical = $site['parent'];
        for ($depth = 0; $depth < 32 && is_string($lexical) && isset($this->index->elements[$lexical]) && $this->index->elements[$lexical]['kind'] !== 'class'; $depth++) {
            $lexical = $this->index->elements[$lexical]['parent'];
        }
        foreach ($sources as $source) {
            foreach ($this->index->names[strtolower($source['symbol'])] ?? [] as $id) {
                $property = $this->index->elements[$id];
                if ($property['kind'] !== 'property' || ($property['metadata']['visibility'] ?? null) === 'public') {
                    continue;
                }
                $parent = $property['parent'];
                if (is_string($parent) && ($this->index->elements[$parent]['kind'] ?? null) === 'method') {
                    $parent = $this->index->elements[$parent]['parent'];
                }
                if ($lexical === $parent) {
                    continue;
                }
                if (($property['metadata']['visibility'] ?? null) !== 'protected' || ! is_string($lexical) || ! is_string($parent) || ! isset($this->index->elements[$lexical], $this->index->elements[$parent])
                    || ! $this->index->hasContract($lexical, $this->index->elements[$parent]['name'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param list<string> $hooks */
    private function standardHooks(string $type, array $hooks): bool
    {
        $models = $this->index->namedTypes($type);
        $knownFactory = count($models) === 1 && $this->index->hasContract($models[0], 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory')
            && $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Factories\\HasFactory') === [];
        foreach ($hooks as $hook) {
            $selection = $this->calls->sourceMethodCandidate($type, $hook, knownHasFactory: $knownFactory);
            if ($selection['method'] !== null || $selection['unresolved'] || $selection['limited']) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $site
     * @param  list<array<string, mixed>>  $sources
     */
    private function relation(array $site, string $to, string $kind, string $model, array $sources): void
    {
        $this->index->addRelation(['from' => $site['parent'], 'to' => $to, 'kind' => $kind, 'path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line'],
            'knowledge' => 'static', 'resolution' => 'conditional', 'metadata' => ['attribute' => $this->index->elements[$to]['metadata']['eloquent_attribute']['attribute'] ?? $this->index->elements[$this->index->elements[$to]['parent']]['metadata']['eloquent_attribute']['attribute'] ?? null, 'model' => $model, 'operation_source' => $site['id'],
                'direction' => $site['metadata']['direction'], 'form' => $site['metadata']['form'], 'receiver_sources' => $sources, 'receiver_non_null_required' => $site['metadata']['nullsafe'],
                'execution_proven' => false, 'database_write_proven' => false]]);
    }

    /** @param array<string, mixed> $site */
    private function diagnostic(array $site, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'eloquent_attribute_analysis', 'message' => $message, 'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['id']];
    }
}
