<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\PublicApi;

final class PublicApiSchema
{
    /** @return array<string, mixed> */
    public static function get(): array
    {
        $base = ['v' => ['const' => 1], 'cmd' => ['const' => 'public-api']];
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $rows = ['type' => 'array', 'items' => ['type' => 'object']];

        return ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'title' => 'Architecture Kit public API comparison', 'oneOf' => [
            ['type' => 'object', 'required' => ['v', 'cmd', 'ok', 'sources', 'analysis', 'changes', 'totals', 'notices', 'semver', 'limitations', 'next'],
                'properties' => [...$base, 'ok' => ['const' => true], 'sources' => ['type' => 'object'], 'analysis' => ['type' => 'object'],
                    'changes' => $rows, 'totals' => ['type' => 'object'], 'notices' => $rows, 'semver' => ['type' => 'object'], 'limitations' => $strings, 'next' => $strings], 'additionalProperties' => false],
            ['type' => 'object', 'required' => ['v', 'cmd', 'ok', 'm', 'msg', 'next'], 'properties' => [...$base, 'ok' => ['const' => false],
                'm' => ['type' => 'string'], 'msg' => ['type' => 'string'], 'next' => $strings], 'additionalProperties' => false],
        ]];
    }
}
