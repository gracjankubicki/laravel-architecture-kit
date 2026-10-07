<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Context;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogKinds;

/** Literal search over the composed source snapshot, independent of legacy discovery. */
final readonly class GraphSearch
{
    public function __construct(private CatalogIndex $index) {}

    /** @return array<string, mixed> */
    public function find(string $query, ?string $kind = null, ?string $path = null): array
    {
        $supported = CatalogKinds::all();
        sort($supported, SORT_STRING);
        $query = trim($query);
        if ($query === '' || $kind !== null && ! in_array($kind, $supported, true)
            || $path !== null && ! self::safePrefix($path)) {
            return ['status' => 'invalid_input', 'candidates' => [], 'match_count' => 0, 'supported_kinds' => $supported];
        }
        $needle = strtolower($query);
        $matches = [];
        foreach ($this->index->elements as $element) {
            if ($kind !== null && $element['kind'] !== $kind && ! in_array($kind, $element['roles'], true)) {
                continue;
            }
            $locations = array_values(array_unique([$element['path'], ...array_column($element['sources'], 'path')]));
            $pathMatches = $path === null;
            foreach ($locations as $location) {
                if ($path !== null && ($location === $path || str_starts_with($location, rtrim($path, '/').'/'))) {
                    $pathMatches = true;
                }
            }
            if (! $pathMatches) {
                continue;
            }
            $fields = [$element['id'], $element['name'], ...$locations, $element['kind'], ...$element['roles']];
            foreach (['name', 'uri', 'table', 'route_name'] as $key) {
                if (is_string($element['metadata'][$key] ?? null)) {
                    $fields[] = $element['metadata'][$key];
                }
            }
            $rank = 3;
            foreach ($fields as $field) {
                $field = strtolower($field);
                $rank = min($rank, $field === $needle ? 0 : (str_starts_with($field, $needle) ? 1 : (str_contains($field, $needle) ? 2 : 3)));
            }
            if ($rank < 3) {
                $matches[] = [...$element, 'match' => ['exact', 'prefix', 'substring'][$rank], '_rank' => $rank];
            }
        }
        usort($matches, static fn (array $a, array $b): int => [$a['_rank'], $a['kind'], $a['name'], $a['path'], $a['line'], $a['id']]
            <=> [$b['_rank'], $b['kind'], $b['name'], $b['path'], $b['line'], $b['id']]);
        foreach ($matches as &$match) {
            unset($match['_rank']);
        }
        unset($match);

        return ['status' => $matches === [] ? 'empty' : 'found', 'candidates' => $matches,
            'match_count' => count($matches), 'count_basis' => 'exact_in_analyzed_graph', 'supported_kinds' => $supported];
    }

    public static function safePrefix(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '\\')
            && ! preg_match('~(^|/)(?:\.|\.\.|vendor|node_modules|\.git)(/|$)|[\x00-\x1f:]~', $path)
            && ! str_contains($path, '//');
    }
}
