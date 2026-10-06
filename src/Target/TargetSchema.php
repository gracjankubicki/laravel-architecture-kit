<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Target;

final readonly class TargetSchema
{
    /** @return array<string, mixed> */
    public static function get(): array
    {
        $base = ['v' => ['const' => 1], 'cmd' => ['const' => 'architecture-target']];
        $objects = [];
        foreach (['target', 'source', 'analysis', 'gate', 'totals', 'audit'] as $key) {
            $objects[$key] = ['type' => 'object'];
        }
        foreach (['subject', 'progress', 'reference_candidate'] as $key) {
            $objects[$key] = ['type' => ['object', 'null']];
        }
        foreach (['elements', 'migration_order', 'notices'] as $key) {
            $objects[$key] = ['type' => 'array', 'items' => ['type' => 'object']];
        }
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];

        return ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'title' => 'Architecture target migration report', 'oneOf' => [
            ['type' => 'object', 'required' => ['v', 'cmd', 'ok', 'configured', ...array_keys($objects), 'limitations', 'next'], 'properties' => [...$base, 'ok' => ['const' => true], 'configured' => ['const' => true], ...$objects, 'limitations' => $strings, 'next' => $strings], 'additionalProperties' => false],
            ['type' => 'object', 'required' => ['v', 'cmd', 'ok', 'configured', 'next'], 'properties' => [...$base, 'ok' => ['const' => true], 'configured' => ['const' => false], 'next' => $strings], 'additionalProperties' => false],
            ['type' => 'object', 'required' => ['v', 'cmd', 'ok', 'm', 'msg', 'next'], 'properties' => [...$base, 'ok' => ['const' => false], 'm' => ['type' => 'string'], 'msg' => ['type' => 'string'], 'next' => $strings], 'additionalProperties' => false],
        ]];
    }
}
