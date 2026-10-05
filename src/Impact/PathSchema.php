<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

final class PathSchema
{
    /** @return array<string, mixed> */
    public static function get(): array
    {
        $rows = ['type' => 'array', 'items' => ['type' => 'object']];
        $channel = ['type' => 'object', 'required' => ['paths', 'found', 'path_total', 'external_boundaries', 'notices', 'notice_total', 'status', 'fresh', 'limited', 'edge_visits'], 'properties' => ['paths' => $rows, 'found' => ['type' => 'boolean'], 'path_total' => ['type' => 'integer'], 'external_boundaries' => $rows, 'notices' => $rows, 'notice_total' => ['type' => 'integer'], 'status' => ['enum' => ['found', 'no_path', 'incomplete', 'limit', 'stale']], 'fresh' => ['type' => 'boolean'], 'limited' => ['type' => 'boolean'], 'edge_visits' => ['type' => 'integer']], 'additionalProperties' => false];
        $base = ['v' => ['const' => 1], 'cmd' => ['const' => 'path']];

        return ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'title' => 'Architecture Kit path agent output', 'oneOf' => [
            ['type' => 'object', 'required' => ['v', 'cmd', 'ok', 'from', 'to', 'dependencies', 'execution', 'cache', 'analysis'], 'properties' => [...$base, 'ok' => ['const' => true], 'from' => ['type' => 'object'], 'to' => ['type' => 'object'], 'dependencies' => $channel, 'execution' => $channel, 'cache' => ['type' => 'string'], 'analysis' => ['type' => 'object']], 'additionalProperties' => false],
            ['type' => 'object', 'required' => ['v', 'cmd', 'ok', 'm', 'msg'], 'properties' => [...$base, 'ok' => ['const' => false], 'm' => ['type' => 'string'], 'msg' => ['type' => 'string'], 'endpoint' => ['enum' => ['from', 'to']], 'candidates' => $rows, 'truncated' => ['type' => 'boolean'], 'limited' => ['type' => 'boolean']], 'additionalProperties' => false],
        ]];
    }
}
