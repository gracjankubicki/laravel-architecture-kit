<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

/** Public lifecycle envelopes are distinct from each tool's existing result. */
final class GraphRuntimeSchema
{
    /** @return array<string, mixed> */
    public static function state(): array
    {
        $properties = ['runtime_semantics' => ['const' => 2], 'confirmation' => self::confirmation(), 'availability' => ['enum' => ['ready', 'building', 'unavailable', 'failed']],
            'freshness' => ['enum' => ['current', 'last_confirmed', 'stale', 'unverified']], 'checks_complete' => ['type' => 'boolean'],
            'job_id' => ['type' => 'string'], 'snapshot_id' => ['type' => ['string', 'null']],
            'input_manifest_digest' => ['type' => 'string'], 'deadline_exceeded' => ['type' => 'boolean'],
            'analysis' => ['type' => 'object'], 'cache' => ['type' => 'object'], 'measurements' => self::measurements(), 'next' => ['type' => ['object', 'array', 'null']]];
        $variants = [];
        foreach (['ready', 'building', 'unavailable', 'failed'] as $availability) {
            $variant = ['type' => 'object', 'required' => ['runtime_semantics', 'availability', 'freshness', 'checks_complete',
                ...($availability === 'building' ? ['job_id', 'next'] : [])],
                'properties' => [...$properties, 'availability' => ['const' => $availability]]];
            if ($availability === 'unavailable') {
                // Unknown-job and endpoint errors have no product identity to invent.
                $variants[] = [...$variant, 'properties' => [...$variant['properties'], 'job_id' => ['type' => 'null']]];
                $variant['required'] = [...$variant['required'], 'job_id', 'confirmation'];
            } else {
                $variant['required'][] = 'confirmation';
            }
            $variants[] = $variant;
        }

        return ['oneOf' => $variants];
    }

    /** @return array<string, mixed> */
    private static function measurements(): array
    {
        return ['type' => 'object', 'properties' => ['admission' => self::admission(),
            'build' => ['oneOf' => [['const' => []], ['type' => 'object', 'properties' => ['admission' => self::admission()]]]]]];
    }

    /** @return array<string, mixed> */
    private static function admission(): array
    {
        $integer = ['type' => 'integer'];
        $nullableInteger = ['type' => ['integer', 'null']];
        $nullableString = ['type' => ['string', 'null']];
        $condition = ['type' => 'object', 'required' => ['counted_bytes', 'limit_bytes', 'exceeded'],
            'properties' => ['counted_bytes' => $integer, 'limit_bytes' => $nullableInteger, 'exceeded' => ['type' => 'boolean']], 'additionalProperties' => false];
        $snapshot = ['type' => 'object', 'required' => ['php_used', 'php_allocated', 'rss_bytes', 'rss_reason'],
            'properties' => ['php_used' => $integer, 'php_allocated' => $integer, 'rss_bytes' => $nullableInteger, 'rss_reason' => $nullableString], 'additionalProperties' => false];
        $properties = ['phase' => ['enum' => ['fresh', 'transition']], 'transition' => ['type' => 'boolean'], 'facts_bytes' => $integer,
            'candidate_bytes' => $integer, 'process_bytes' => $integer, 'measured_rss_bytes' => $nullableInteger,
            'process_bytes_basis' => ['enum' => ['process_tree_rss', 'php_allocated_fallback']], 'rss_reason' => $nullableString,
            'retained_worker_bytes' => $integer, 'retained_controller_bytes' => $integer, 'global_retained_bytes' => $integer,
            'php_used' => $integer, 'php_allocated' => $integer, 'php_memory_limit_bytes' => $nullableInteger,
            'resident_effective_bytes' => $integer, 'transition_effective_bytes' => $integer,
            'global_condition' => $condition, 'php_condition' => $condition,
            'gc' => ['type' => 'object', 'required' => ['before', 'after', 'collected_cycles', 'freed_allocator_bytes'],
                'properties' => ['before' => $snapshot, 'after' => $snapshot, 'collected_cycles' => $integer, 'freed_allocator_bytes' => $integer], 'additionalProperties' => false],
            'failed_conditions' => ['type' => 'array', 'items' => ['enum' => ['negative_candidate', 'global_budget', 'php_budget']]], 'admitted' => ['type' => 'boolean']];

        $native = ['basis' => ['const' => 'unique_pid_rss_plus_reserved_owned_capacity_and_headroom'],
            'retained_rss_bytes' => $integer, 'headroom_bytes' => $integer, 'other_native_grants_bytes' => $integer,
            'available_bytes' => $integer, 'replaced_rss_bytes' => $integer, 'resident_available_bytes' => $integer, 'transition_available_bytes' => $integer, 'requested_capacity_bytes' => $nullableInteger, 'limit_bytes' => $integer, 'admitted' => ['type' => 'boolean'],
            'failed_conditions' => ['type' => 'array', 'items' => ['enum' => ['unknown_rss', 'global_budget']]],
            'unknown_rss' => ['type' => 'boolean'], 'sample' => ['type' => 'object'], 'phase' => ['enum' => ['fresh', 'transition']]];

        return ['oneOf' => [
            ['type' => 'object', 'required' => array_keys($properties), 'properties' => $properties, 'additionalProperties' => false],
            ['type' => 'object', 'required' => array_keys($native), 'properties' => $native, 'additionalProperties' => false],
        ]];
    }

    /** @return array<string, mixed> */
    public static function confirmation(): array
    {
        $nullable = ['type' => ['string', 'null']];

        return ['type' => 'object', 'required' => ['runtime_semantics', 'freshness', 'generation', 'snapshot_id', 'product_key',
            'configuration_epoch', 'watch_session', 'observed_sequence', 'applied_sequence', 'last_full_scan_at',
            'confirmed_at', 'basis', 'scope', 'pending_changes', 'watch_health', 'reason'],
            'properties' => ['runtime_semantics' => ['const' => 2], 'freshness' => ['enum' => ['current', 'last_confirmed', 'stale', 'unverified']],
                'generation' => $nullable, 'snapshot_id' => $nullable, 'product_key' => ['type' => 'string'], 'configuration_epoch' => ['type' => 'integer'],
                'watch_session' => $nullable, 'observed_sequence' => ['type' => 'string'], 'applied_sequence' => ['type' => 'string'],
                'last_full_scan_at' => $nullable, 'confirmed_at' => $nullable, 'basis' => ['type' => 'string'], 'scope' => ['type' => 'array', 'items' => ['type' => 'string']],
                'pending_changes' => ['type' => 'boolean'], 'watch_health' => ['type' => 'string'], 'reason' => $nullable], 'additionalProperties' => false];
    }

    /** @param array<string, mixed> $schema
     * @return array<string, mixed> */
    public static function extend(array $schema): array
    {
        foreach ($schema['oneOf'] as &$variant) {
            $variant['not'] = ['required' => ['availability']];
            if (isset($variant['properties'])) {
                $variant['properties']['runtime'] = self::state();
                $variant['properties']['runtime_semantics'] = ['const' => 2];
                $variant['properties']['confirmation'] = self::confirmation();
                $variant['properties']['freshness'] = ['enum' => ['current', 'last_confirmed', 'stale', 'unverified']];
                if (isset($variant['properties']['fresh'])) {
                    $variant['properties']['fresh'] = ['type' => ['boolean', 'null']];
                }
                foreach (['analysis', 'dependencies', 'execution', 'data', 'reach'] as $channel) {
                    if (isset($variant['properties'][$channel])) {
                        $variant['properties'][$channel] = self::channel($variant['properties'][$channel]);
                    }
                }
            }
        }
        unset($variant);
        $schema['oneOf'][] = self::state();

        return $schema;
    }

    /** @param array<string, mixed> $schema
     * @return array<string, mixed> */
    private static function channel(array $schema): array
    {
        if (isset($schema['oneOf'])) {
            $schema['oneOf'] = array_map(self::channel(...), $schema['oneOf']);
        } elseif (($schema['type'] ?? null) === 'object' && isset($schema['properties'])) {
            $schema['properties']['fresh'] = ['type' => ['boolean', 'null']];
            $schema['properties']['runtime_semantics'] = ['const' => 2];
            $schema['properties']['freshness'] = ['enum' => ['current', 'last_confirmed', 'stale', 'unverified']];
        }

        return $schema;
    }
}
