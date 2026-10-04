<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

final readonly class ImpactSchema
{
    /** @return array<string, mixed> */
    public static function get(): array
    {
        $rows = ['type' => 'array', 'items' => ['type' => 'object']];
        $base = ['v' => ['const' => 1], 'cmd' => ['const' => 'impact'], 'ok' => ['type' => 'boolean']];

        return ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'title' => 'Architecture Kit impact agent output', 'oneOf' => [
            ['type' => 'object', 'required' => ['v', 'cmd', 'ok', 'subject', 'dependents', 'dependencies', 'possible', 'references', 'class_context', 'tests', 'analysis', 'cache', 'snapshot', 'scope', 'next'],
                'properties' => [...$base, 'ok' => ['const' => true], 'subject' => ['type' => 'object'], 'dependents' => $rows, 'dependencies' => $rows,
                    'possible' => ['type' => 'object', 'required' => ['dependents', 'dependencies', 'overrides'], 'properties' => ['dependents' => $rows, 'dependencies' => $rows, 'overrides' => $rows], 'additionalProperties' => false],
                    'references' => ['type' => 'object', 'required' => ['dependents', 'dependencies'], 'properties' => ['dependents' => $rows, 'dependencies' => $rows], 'additionalProperties' => false],
                    'class_context' => ['type' => 'object'], 'tests' => $rows, 'analysis' => ['type' => 'object', 'required' => ['status', 'notices', 'notice_total', 'limitations', 'limit', 'depth', 'truncated', 'expand'], 'properties' => [
                        'status' => ['enum' => ['complete', 'none', 'incomplete', 'limit']], 'notices' => $rows, 'notice_total' => ['type' => 'integer'], 'limitations' => ['type' => 'array', 'items' => ['type' => 'string']], 'limit' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 500], 'depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 32], 'truncated' => ['type' => 'boolean'], 'expand' => ['type' => 'string']], 'additionalProperties' => false],
                    'cache' => ['type' => 'string'], 'snapshot' => ['type' => 'string'], 'scope' => ['type' => 'object'], 'next' => ['type' => 'array', 'items' => ['type' => 'string']]], 'additionalProperties' => false],
            ['type' => 'object', 'required' => ['v', 'cmd', 'ok', 'm', 'msg', 'next'], 'properties' => [...$base, 'ok' => ['const' => false], 'm' => ['type' => 'string'], 'msg' => ['type' => 'string'], 'next' => ['type' => 'array', 'items' => ['type' => 'string']], 'candidates' => $rows, 'trunc' => ['type' => 'boolean']], 'additionalProperties' => false],
        ]];
    }
}
