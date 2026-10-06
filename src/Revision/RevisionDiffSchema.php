<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Revision;

final readonly class RevisionDiffSchema
{
    /** @return array<string, mixed> */
    public static function get(): array
    {
        $base = ['v' => ['const' => 1], 'cmd' => ['const' => 'revision-diff']];
        $objects = [];
        foreach (['sources', 'analysis', 'changes', 'totals', 'configuration_sources', 'metrics'] as $key) {
            $objects[$key] = ['type' => 'object'];
        }
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $notices = ['type' => 'array', 'items' => ['type' => 'object']];

        return ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'title' => 'Architecture Kit revision differences', 'oneOf' => [
            ['type' => 'object', 'required' => ['v', 'cmd', 'ok', ...array_keys($objects), 'notices', 'limitations', 'next'],
                'properties' => [...$base, 'ok' => ['const' => true], ...$objects, 'notices' => $notices, 'limitations' => $strings, 'next' => $strings],
                'additionalProperties' => false],
            ['type' => 'object', 'required' => ['v', 'cmd', 'ok', 'm', 'msg', 'next'],
                'properties' => [...$base, 'ok' => ['const' => false], 'm' => ['type' => 'string'], 'msg' => ['type' => 'string'], 'next' => $strings],
                'additionalProperties' => false],
        ]];
    }
}
