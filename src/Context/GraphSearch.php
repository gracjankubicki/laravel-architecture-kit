<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Context;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogKinds;

/** Literal search over the composed source snapshot, independent of legacy discovery. */
final class GraphSearch
{
    /** @var array<string, mixed> */
    private array $execution = ['mode' => 'not_run', 'reason' => null];

    /** Metadata is separate to preserve the complete legacy find() response.
     * @return array<string, mixed>
     */
    public function execution(): array
    {
        return $this->execution;
    }

    public function __construct(private readonly CatalogIndex $index) {}

    /** @return array<string, mixed> */
    public function find(string $query, ?string $kind = null, ?string $path = null): array
    {
        $this->execution = ['mode' => 'invalid_input', 'reason' => null];
        $supported = CatalogKinds::all();
        sort($supported, SORT_STRING);
        $query = trim($query);
        if ($query === '' || $kind !== null && ! in_array($kind, $supported, true)
            || $path !== null && ! self::safePrefix($path)) {
            return ['status' => 'invalid_input', 'candidates' => [], 'match_count' => 0, 'supported_kinds' => $supported];
        }
        $needle = strtolower($query);
        $state = $this->index->searchState();
        $elements = $this->index->elements;
        $this->execution = $state->execution($query);
        $matches = [];
        foreach ($state->ranks($needle) as $id => $rank) {
            $element = $elements[$id];
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
            $matches[] = [...$element, 'match' => ['exact', 'prefix', 'substring'][$rank], '_rank' => $rank];
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
