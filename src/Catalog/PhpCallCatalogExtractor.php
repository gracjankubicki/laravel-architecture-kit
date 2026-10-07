<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Reuses the existing conservative assignment/branch analysis, with opt-in callable scopes. */
final class PhpCallCatalogExtractor
{
    public function extract(FileContext $file, CatalogFacts $declarations): CatalogFacts
    {
        $owners = [];
        foreach ($declarations->elements as $element) {
            if (in_array($element->kind, ['method', 'function', 'closure'], true)) {
                $owners[$element->name][] = $element;
            }
        }
        $extractor = new ImpactExtractor;
        $facts = $extractor->extract($file, catalog: true);
        $relations = $diagnostics = [];
        foreach ($facts->calls as $call) {
            if ($call['kind'] === 'reference' && is_string($call['method']) && preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D', $call['method']) !== 1) {
                // A two-item data array is not evidence of a declared PHP callable.
                continue;
            }
            if (str_starts_with($call['from'], '(file) ')) {
                $owner = CatalogElement::identity($file->path, 'file', $file->path);
            } else {
                $candidates = array_values(array_filter($owners[$call['from']] ?? [], fn ($element) => isset($call['offset'])
                    ? $element->offset <= $call['offset'] && ($element->metadata['end_offset'] ?? -1) >= $call['offset']
                    : $element->line <= $call['line'] && $element->endLine >= $call['line']));
                if (count($candidates) !== 1) {
                    $diagnostics[] = new CatalogDiagnostic('ambiguous_call_owner', 'Call belongs to several source declarations or an unresolved lexical scope.', $call['line']);

                    continue;
                }
                $owner = $candidates[0]->id;
            }
            if ($call['receiver'] === null || $call['method'] === null) {
                $diagnostics[] = new CatalogDiagnostic('unresolved_call', 'Call receiver or method cannot be resolved from source types.', $call['line'], $owner);

                continue;
            }
            $metadata = ['receiver' => $call['receiver'], 'method' => $call['method'], 'exact_receiver' => $call['exact'], 'form' => $call['site']['form']];
            if (isset($call['receiver_origin'])) {
                $metadata['receiver_origin'] = $call['receiver_origin'];
            }
            if (isset($call['receiver_instance_origin'])) {
                $metadata['receiver_instance_origin'] = $call['receiver_instance_origin'];
            }
            if (isset($call['json_serialization_receiver'])) {
                $metadata['json_serialization_receiver'] = $call['json_serialization_receiver'];
            }
            if (isset($call['receiver_factory_call'])) {
                $metadata['receiver_factory_call'] = $call['receiver_factory_call'];
            }
            $metadata['offset'] = max(0, $call['offset'] ?? 0);
            $metadata['this_receiver'] = $call['this_receiver'] ?? false;
            $metadata['late_static_receiver'] = $call['late_static_receiver'] ?? false;
            $metadata['lexical_static_receiver'] = $call['lexical_static_receiver'] ?? null;
            $metadata['bound_callable'] = $this->binding($call['bound_callable'] ?? null, $owners);
            if (isset($call['callable_form'])) {
                $metadata['callable_form'] = $call['callable_form'];
            }
            $metadata['static_context'] = $call['site']['static_context'];
            if (isset($call['saloon_request'])) {
                $metadata['saloon_request'] = $call['saloon_request'];
            }
            if (isset($call['saloon_pool_send'])) {
                $metadata['saloon_pool_send'] = $call['saloon_pool_send'];
            }
            if (array_key_exists('saloon_pool_connector', $call)) {
                $metadata['saloon_pool_connector'] = $call['saloon_pool_connector'];
            }
            if (isset($call['saloon_pool_members'])) {
                $metadata['saloon_pool_members'] = $call['saloon_pool_members'];
            }
            if (isset($call['saloon_pool_callback_values'])) {
                foreach ($call['saloon_pool_callback_values'] as $kind => $value) {
                    $metadata['saloon_pool_callback_values'][$kind] = $this->callbackValue($value, $owners);
                }
            }
            if (isset($call['saloon_pool_member_factory'])) {
                $metadata['saloon_pool_member_factory'] = $call['saloon_pool_member_factory'];
            }
            if (isset($call['provider_constructor_arguments'])) {
                $metadata['provider_constructor_arguments'] = $call['provider_constructor_arguments'];
            }
            if (isset($call['provider_gateway_value'])) {
                $metadata['provider_gateway_value'] = $this->callbackValue($call['provider_gateway_value'], $owners);
            }
            if (isset($call['gateway_callback_values'])) {
                foreach ($call['gateway_callback_values'] as $position => $value) {
                    $metadata['gateway_callback_values'][$position] = $this->callbackValue($value, $owners);
                }
            }
            if (isset($call['ai_attachments'])) {
                $metadata['ai_attachments'] = $call['ai_attachments'];
            }
            if (isset($call['ai_attachment_factory'])) {
                $metadata['ai_attachment_factory'] = $call['ai_attachment_factory'];
            }
            if (isset($call['end_offset'])) {
                $metadata['end_offset'] = max(0, $call['end_offset']);
            }
            if (isset($call['callback_value'])) {
                $metadata['callback_value'] = $this->callbackValue($call['callback_value'], $owners);
            }
            $target = 'dispatch:'.hash('xxh128', serialize([$call['receiver'], $call['method'], $call['exact']]));
            $relations[] = new CatalogRelation($owner, $target, $call['kind'] === 'reference' ? 'callable-reference' : 'calls', $call['line'], $call['end_line'] ?? $call['line'], 'conditional', $metadata);
        }
        foreach ($extractor->catalogReturns() as $return) {
            $candidates = $owners[$return['from']] ?? [];
            $value = $return['value'];
            if ($value['type'] === null && ! isset($value['callable_name']) && ! isset($value['catalog_attachments']) && ! isset($value['catalog_pool_members'])) {
                continue;
            }
            if (count($candidates) !== 1) {
                $diagnostics[] = new CatalogDiagnostic('unresolved_return', 'Returned value or its source declaration cannot be resolved.', $return['line']);

                continue;
            }
            $receiver = $value['type'] ?? (isset($value['catalog_attachments']) ? '@ai-attachments' : (isset($value['catalog_pool_members']) ? '@pool-members' : '@named-callable:'.json_encode($value['callable_name'], JSON_THROW_ON_ERROR)));
            $metadata = ['receiver' => $receiver, 'exact_receiver' => $value['exact'], 'bound_callable' => $this->binding($value['bound_callable'] ?? null, $owners), 'offset' => max(0, $return['offset'])];
            if (isset($value['catalog_attachments'])) {
                $metadata['ai_attachments'] = $value['catalog_attachments'];
            }
            if (isset($value['catalog_pool_members'])) {
                $metadata['saloon_pool_members'] = $value['catalog_pool_members'];
            }
            if (isset($value['factory_call'])) {
                $metadata['factory_call'] = $value['factory_call'];
            }
            if (isset($value['callable_form'])) {
                $metadata['callable_form'] = $value['callable_form'];
            }
            if (isset($value['catalog_origin'])) {
                $metadata['value_origin'] = $value['catalog_origin'];
            }
            $relations[] = new CatalogRelation($candidates[0]->id, 'value:'.hash('xxh128', serialize($metadata)), 'returns-value', $return['line'], $return['end_line'], 'conditional', $metadata);
        }
        foreach ($facts->notices as $notice) {
            $diagnostics[] = new CatalogDiagnostic(str_contains(strtolower($notice['reason']), 'limit') ? 'call_limit' : 'call_analysis', $notice['reason'], max(1, $notice['line']));
        }

        return new CatalogFacts($file->path, relations: $relations, diagnostics: $diagnostics);
    }

    /** @param array<string, mixed>|null $binding
     * @param  array<string, list<CatalogElement>>  $owners
     * @return array<string, mixed>|null
     */
    private function binding(?array $binding, array $owners): ?array
    {
        if ($binding === null) {
            return null;
        }
        $creators = $owners[$binding['creator']] ?? [];
        if (count($creators) !== 1) {
            return null;
        }
        $binding['creator'] = $creators[0]->id;

        return $binding;
    }

    /** @param array<string, mixed> $value
     * @param  array<string, list<CatalogElement>>  $owners
     * @return array<string, mixed>
     */
    private function callbackValue(array $value, array $owners): array
    {
        $binding = $this->binding($value['bound_callable'] ?? null, $owners);
        $type = $value['type'] ?? '@named-callable:'.json_encode($value['callable_name'], JSON_THROW_ON_ERROR);

        return ['receiver' => $type, 'exact' => $value['exact'],
            'form' => $value['callable_form'] ?? 'instance', 'creator' => $binding['creator'] ?? null,
            'closure' => str_starts_with($type, '@closure:') || str_starts_with($type, '@function:') || ($binding['scope_bound'] ?? false),
            'binding' => ($binding['late'] ?? false) ? 'static' : ($binding['lexical'] ?? null),
            'factory_call' => $value['factory_call'] ?? null];
    }
}
