<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\DataCatalog;

/** Reuses the already parsed declaration catalog; stores database selectors and normalized cast type names. */
final class DataCatalogExtractor
{
    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $data = new DataCatalog;
        $data->collect($file);
        $casts = CatalogCasts::extract($file);
        $broadcastChannels = CatalogBroadcastChannels::extract($file);
        $members = CatalogModelMembers::extract($file);
        $scopes = CatalogQueryScopes::extract($file);
        $builders = CatalogQueryBuilders::extract($file);
        $builderMethods = CatalogQueryScopes::builderMethods($file);
        $globalScopes = CatalogGlobalScopes::extract($file, $php);
        $scopeShapes = CatalogQueryScopes::scopeMethods($file);
        $sourceReturns = CatalogSourceReturn::extract($file);
        $attributes = CatalogAttributes::extract($file);
        $serialization = CatalogSerializationSelectors::extract($file);
        $factoryModels = CatalogFactoryModels::extract($file);
        $modelFactories = CatalogFactoryModels::extract($file, modelFactories: true);
        $elements = [];
        $limitNotices = [];
        if ($serialization['limited']) {
            $limitNotices[] = new CatalogDiagnostic('catalog_limit', 'Serialization selectors reached their extraction budget.', 1);
        }
        foreach ($php->elements as $element) {
            if ($element->kind === 'method' && isset($sourceReturns[$element->offset])) {
                if ($sourceReturns[$element->offset]['value']['kind'] === 'limited') {
                    $limitNotices[] = new CatalogDiagnostic('catalog_limit', 'Deferred source return reached its structural budget.', $element->line, $element->id, limitReason: 'structure');
                }
                $element = new CatalogElement($element->id, $element->name, $element->kind, $element->line, $element->endLine,
                    $element->offset, $element->parent, $element->roles, [...$element->metadata, 'source_return' => $sourceReturns[$element->offset]]);
            }
            if (isset($serialization['properties'][$element->offset]) && $element->kind === 'property') {
                $element = new CatalogElement($element->id, $element->name, $element->kind, $element->line, $element->endLine,
                    $element->offset, $element->parent, $element->roles, [...$element->metadata, 'serialization_selector' => $serialization['properties'][$element->offset]]);
            }
            if (isset($serialization['attributes'][$element->offset]) && in_array($element->kind, ['class', 'trait'], true)) {
                $element = new CatalogElement($element->id, $element->name, $element->kind, $element->line, $element->endLine,
                    $element->offset, $element->parent, $element->roles, [...$element->metadata, 'serialization_attributes' => $serialization['attributes'][$element->offset]]);
            }
            if (array_key_exists($element->offset, $serialization['conventions']) && $element->kind === 'property') {
                $element = new CatalogElement($element->id, $element->name, $element->kind, $element->line, $element->endLine,
                    $element->offset, $element->parent, $element->roles, [...$element->metadata, 'serialization_snake' => $serialization['conventions'][$element->offset]]);
            }
            if ($element->kind === 'method') {
                $method = $data->classes[strtolower(substr($element->name, 0, (int) strrpos($element->name, '::')))]['methods'][strtolower(substr($element->name, (int) strrpos($element->name, '::') + 2))] ?? null;
                if ($method !== null && str_ends_with(strtolower($element->name), '::apply')) {
                    $element = new CatalogElement($element->id, $element->name, $element->kind, $element->line, $element->endLine,
                        $element->offset, $element->parent, $element->roles, [...$element->metadata, 'global_scope_transform' => $scopeShapes[$element->offset] ?? false]);
                }
            }
            if ($element->kind === 'method' && isset($builderMethods[$element->offset])) {
                $element = new CatalogElement($element->id, $element->name, $element->kind, $element->line, $element->endLine,
                    $element->offset, $element->parent, $element->roles, [...$element->metadata, 'query_transform' => $builderMethods[$element->offset]]);
            }
            if ($element->kind === 'method' && isset($scopes[$element->offset])) {
                $element = new CatalogElement($element->id, $element->name, $element->kind, $element->line, $element->endLine,
                    $element->offset, $element->parent, $element->roles, [...$element->metadata, 'eloquent_scope' => $scopes[$element->offset]]);
            }
            if ($element->kind === 'method' && isset($attributes[$element->offset])) {
                $element = new CatalogElement($element->id, $element->name, $element->kind, $element->line, $element->endLine,
                    $element->offset, $element->parent, $element->roles, [...$element->metadata, 'eloquent_attribute' => $attributes[$element->offset]]);
            }
            if ($element->kind === 'method' && isset($members[$element->offset])) {
                $element = new CatalogElement($element->id, $element->name, $element->kind, $element->line, $element->endLine,
                    $element->offset, $element->parent, $element->roles, [...$element->metadata, ...$members[$element->offset]]);
            }
            if ($element->kind === 'method' && isset($broadcastChannels[$element->offset])) {
                $element = new CatalogElement($element->id, $element->name, $element->kind, $element->line, $element->endLine,
                    $element->offset, $element->parent, $element->roles, [...$element->metadata, 'broadcast_channels' => $broadcastChannels[$element->offset]]);
            }
            if ($element->kind === 'method' && str_ends_with(strtolower($element->name), '::broadcaston')) {
                $class = substr($element->name, 0, -strlen('::broadcastOn'));
                $literal = $data->classes[strtolower($class)]['methods']['broadcaston']['literal_return'] ?? null;
                if ($literal === []) {
                    $elements[] = new CatalogElement($element->id, $element->name, $element->kind, $element->line, $element->endLine,
                        $element->offset, $element->parent, $element->roles, [...$element->metadata, 'broadcast_empty_channels' => true]);

                    continue;
                }
            }
            if ($element->kind === 'method' && str_ends_with(strtolower($element->name), '::via')) {
                $class = substr($element->name, 0, -strlen('::via'));
                $method = $data->classes[strtolower($class)]['methods']['via'] ?? null;
                if ($method !== null) {
                    $value = $method['literal_return'] ?? null;
                    $channels = [];
                    $resolved = is_array($value) && array_is_list($value) && count($value) <= 128;
                    foreach ($resolved ? $value : [] as $channel) {
                        if (is_string($channel) && strlen($channel) <= 500 && preg_match('/\\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\\z/D', $channel) === 1) {
                            $channels[] = $channel;
                        } else {
                            $resolved = false;
                        }
                    }
                    $elements[] = new CatalogElement($element->id, $element->name, $element->kind, $element->line, $element->endLine,
                        $element->offset, $element->parent, $element->roles, [...$element->metadata,
                            'notification_channels' => ['resolved' => $resolved, 'channels' => array_values(array_unique($channels))]]);

                    continue;
                }
            }
            $declaration = $data->classes[strtolower($element->name)] ?? null;
            if ($declaration === null || ! in_array($element->kind, ['class', 'trait'], true)) {
                $elements[] = $element;

                continue;
            }
            foreach ($globalScopes[strtolower($element->name)] ?? [] as $scope) {
                if ($scope['limited']) {
                    $limitNotices[] = new CatalogDiagnostic('catalog_limit', 'Global scope source selectors reached their list budget.', $scope['line'], $element->id);
                }
            }
            $properties = [];
            foreach (['table', 'connection'] as $name) {
                if (array_key_exists($name, $declaration['properties'])) {
                    $properties[$name] = self::selector($declaration['properties'][$name]);
                }
            }
            $methods = [];
            foreach (['gettable', 'getconnectionname', 'joiningtable', 'joiningtablesegment'] as $name) {
                if (isset($declaration['methods'][$name])) {
                    $methods[$name] = ['literal_return' => self::selector($declaration['methods'][$name]['literal_return'] ?? ['dynamic' => true])];
                }
            }
            $elements[] = new CatalogElement($element->id, $element->name, $element->kind, $element->line, $element->endLine,
                $element->offset, $element->parent, $element->roles,
                [...$element->metadata, 'data_declaration' => ['properties' => $properties, 'methods' => $methods],
                    'factory_model' => $factoryModels[strtolower($element->name)] ?? ['property' => null, 'attribute' => null, 'method' => null],
                    'model_factory' => $modelFactories[strtolower($element->name)] ?? ['property' => null, 'attribute' => null, 'method' => null],
                    'data_casts' => $casts[strtolower($element->name)] ?? ['property' => null, 'method' => null],
                    'query_builder' => $builders[strtolower($element->name)] ?? ['property' => null, 'attribute' => null, 'method' => null],
                    'global_scopes' => $globalScopes[strtolower($element->name)] ?? []]);
        }

        $seederCalls = (new SeederCallCatalogExtractor)->extract($file, $php);
        $factoryCalls = (new FactoryLifecycleCatalogExtractor)->extract($file, $php);
        $attributeAccesses = (new AttributeAccessCatalogExtractor)->extract($file, $php);

        return new CatalogFacts($file->path, [...$elements, ...$seederCalls->elements, ...$factoryCalls->elements, ...$attributeAccesses->elements], $php->relations, [...$php->diagnostics, ...$limitNotices, ...$seederCalls->diagnostics, ...$factoryCalls->diagnostics, ...$attributeAccesses->diagnostics]);
    }

    private static function selector(mixed $value): mixed
    {
        return $value === null || is_string($value) && preg_match('/\A[a-zA-Z0-9_.-]{1,256}\z/D', $value) === 1
            ? $value : ['dynamic' => true];
    }
}
