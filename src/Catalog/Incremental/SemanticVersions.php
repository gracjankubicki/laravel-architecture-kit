<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog\Incremental;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;

/** Conservative connected declaration groups cover direct array reads and trait selection.
 * Groups include lexical owners, parents, traits and descendants, including cycles.
 */
final class SemanticVersions
{
    /** @var array<string, string> */
    private array $versions = [];

    /** @var array<string, list<string>> */
    private array $candidates;

    /** @param list<array<string, mixed>> $pendingCalls */
    public function __construct(CatalogIndex $index, array $pendingCalls = [])
    {
        $this->candidates = $index->names;
        // PHP coerces numeric string map keys to int; record IDs and parent values remain strings.
        $parents = [];
        foreach ($index->elements as $element) {
            $parents[$element['id']] = $element['id'];
        }
        foreach ($index->elements as $element) {
            $id = $element['id'];
            if (isset($parents[$element['parent'] ?? ''])) {
                self::join($parents, $id, $element['parent']);
            }
        }
        foreach ($index->relations as $edge) {
            if (in_array($edge['kind'], ['extends', 'implements', 'uses-trait'], true) && isset($parents[$edge['to']], $parents[$edge['from']])) {
                self::join($parents, $edge['from'], $edge['to']);
            }
        }
        $groups = [];
        foreach ($index->elements as $element) {
            $id = $element['id'];
            $groups[self::root($parents, $id)]['elements'][$id] = $element;
        }
        foreach ($index->relations as $edge) {
            if (isset($parents[$edge['from']])) {
                $groups[self::root($parents, $edge['from'])]['relations'][] = $edge;
            }
        }
        $digests = [];
        foreach ($groups as $key => $group) {
            $digests[$key] = self::digest($group);
        }
        foreach ($index->elements as $element) {
            $id = $element['id'];
            $this->versions['element:'.$id] = $digests[self::root($parents, $id)];
        }
        foreach ($index->names as $name => $ids) {
            $this->versions['name:'.$name] = self::digest(array_map(fn (string $id) => [$id, $this->versions['element:'.$id]], $ids));
        }
        $this->versions['descriptors:*'] = self::digest($index->descriptorReceivers);
        $this->versions['declarations:*'] = self::digest([$index->exportState(), $pendingCalls]);
    }

    /** Ordered raw candidates preserve empty earlier lookups before fallback.
     * @return list<string>
     */
    public function candidates(string $name): array
    {
        return $this->candidates[$name] ?? [];
    }

    public function version(string $key): string
    {
        return $this->versions[$key] ?? self::digest(null);
    }

    public static function digest(mixed $value): string
    {
        $hash = hash_init('sha256');
        self::hashValue($hash, $value, 0);

        return hash_final($hash);
    }

    /** The same ordered JSON identity without a second full serialized graph. */
    private static function hashValue(\HashContext $hash, mixed $value, int $depth): void
    {
        if ($depth > 64) {
            throw new \JsonException('Maximum digest depth exceeded.');
        }
        if (! is_array($value)) {
            hash_update($hash, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

            return;
        }
        $list = array_is_list($value);
        hash_update($hash, $list ? '[' : '{');
        $first = true;
        foreach ($value as $key => $item) {
            if (! $first) {
                hash_update($hash, ',');
            }
            $first = false;
            if (! $list) {
                hash_update($hash, json_encode((string) $key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).':');
            }
            self::hashValue($hash, $item, $depth + 1);
        }
        hash_update($hash, $list ? ']' : '}');
    }

    /** @param array<int|string, string> $parents */
    private static function root(array &$parents, string $id): string
    {
        $root = $id;
        while ($parents[$root] !== $root) {
            $root = $parents[$root];
        }
        while ($parents[$id] !== $id) {
            $next = $parents[$id];
            $parents[$id] = $root;
            $id = $next;
        }

        return $root;
    }

    /** @param array<int|string, string> $parents */
    private static function join(array &$parents, string $left, string $right): void
    {
        $parents[self::root($parents, $left)] = self::root($parents, $right);
    }
}
