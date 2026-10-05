<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Discovery;

final class SearchSchema
{
    /** @return array<string, mixed> */
    public static function get(): array
    {
        return ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'title' => 'Architecture Kit declaration search', 'oneOf' => [
            ['type' => 'object', 'required' => ['v', 'cmd', 'ok', 'candidates', 'total', 'total_is_lower_bound', 'ambiguous', 'truncated', 'limited', 'fresh', 'status', 'notices', 'analysis'], 'properties' => ['v' => ['const' => 1], 'cmd' => ['const' => 'search'], 'ok' => ['const' => true], 'candidates' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id', 'name', 'kind', 'kinds', 'path', 'line', 'selector', 'selector_scope', 'supported', 'notes', 'exact']]], 'total' => ['type' => 'integer'], 'total_is_lower_bound' => ['type' => 'boolean'], 'status' => ['enum' => ['found', 'no_matches', 'incomplete', 'limit', 'stale']]]],
            ['type' => 'object', 'required' => ['v', 'cmd', 'ok', 'm', 'msg'], 'properties' => ['ok' => ['const' => false], 'cmd' => ['const' => 'search'], 'v' => ['const' => 1], 'm' => ['type' => 'string'], 'msg' => ['type' => 'string']]],
        ]];
    }
}
