<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

final readonly class TableSchema
{
    /** @return array<string, mixed> */
    public static function get(): array
    {
        $rows = ['type' => 'array', 'items' => ['type' => 'object']];

        return ['type' => 'object', 'required' => ['v', 'cmd', 'ok', 'table_report', 'cache', 'snapshot', 'scope', 'next'], 'properties' => [
            'v' => ['const' => 1], 'cmd' => ['const' => 'impact'], 'ok' => ['const' => true], 'cache' => ['type' => 'string'], 'snapshot' => ['type' => 'string'], 'scope' => ['type' => 'object'], 'next' => ['type' => 'array', 'items' => ['type' => 'string']],
            'table_report' => ['type' => 'object', 'required' => ['query', 'tables', 'usages', 'unresolved', 'totals', 'status', 'truncated', 'fresh', 'source_signature', 'limit', 'depth', 'limitations'], 'properties' => [
                'query' => ['type' => 'object', 'required' => ['table', 'match', 'connection', 'operation'], 'properties' => ['table' => ['type' => 'string'], 'match' => ['enum' => ['exact', 'contains']], 'connection' => ['type' => ['string', 'null']], 'operation' => ['enum' => [null, 'read', 'write', 'schema', 'schema-read']]], 'additionalProperties' => false],
                'tables' => $rows, 'usages' => $rows, 'unresolved' => $rows, 'totals' => ['type' => 'object'], 'status' => ['enum' => ['none', 'complete', 'incomplete', 'limit']], 'truncated' => ['type' => 'boolean'], 'fresh' => ['type' => 'boolean'], 'source_signature' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 500], 'depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 32], 'limitations' => ['type' => 'array', 'items' => ['type' => 'string']],
            ], 'additionalProperties' => false],
        ], 'additionalProperties' => false];
    }
}
