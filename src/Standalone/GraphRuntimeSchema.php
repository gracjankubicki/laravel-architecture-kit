<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

/** Public lifecycle envelopes are distinct from each tool's existing result. */
final class GraphRuntimeSchema
{
    /** @return array<string, mixed> */
    public static function state(): array
    {
        $properties = ['availability' => ['enum' => ['ready', 'building', 'unavailable', 'failed']],
            'freshness' => ['enum' => ['current', 'stale', 'unverified']], 'checks_complete' => ['type' => 'boolean'],
            'job_id' => ['type' => 'string'], 'snapshot_id' => ['type' => ['string', 'null']],
            'input_manifest_digest' => ['type' => 'string'], 'deadline_exceeded' => ['type' => 'boolean'],
            'analysis' => ['type' => 'object'], 'cache' => ['type' => 'object'], 'next' => ['type' => ['object', 'array', 'null']]];
        $variants = [];
        foreach (['ready', 'building', 'unavailable', 'failed'] as $availability) {
            $variants[] = ['type' => 'object', 'required' => ['availability', 'freshness', 'checks_complete',
                ...($availability === 'building' ? ['job_id', 'next'] : [])],
                'properties' => [...$properties, 'availability' => ['const' => $availability]]];
        }

        return ['oneOf' => $variants];
    }

    /** @param array<string, mixed> $schema
     * @return array<string, mixed> */
    public static function extend(array $schema): array
    {
        foreach ($schema['oneOf'] as &$variant) {
            $variant['not'] = ['required' => ['availability']];
            if (isset($variant['properties'])) {
                $variant['properties']['runtime'] = self::state();
                $variant['properties']['freshness'] = ['enum' => ['current', 'stale', 'unverified']];
            }
        }
        unset($variant);
        $schema['oneOf'][] = self::state();

        return $schema;
    }
}
