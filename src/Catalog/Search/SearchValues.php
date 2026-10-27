<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog\Search;

/** One byte-wise normalization contract for the shared value dictionary. */
final class SearchValues
{
    /** Keys carry a marker so PHP cannot coerce numeric values to integer keys.
     * @param  array<string, mixed>  $element
     * @return list<string>
     */
    public static function keys(array $element): array
    {
        $fields = [$element['id'], $element['name'], $element['path'], ...array_column($element['sources'], 'path'), $element['kind'], ...$element['roles']];
        foreach (['name', 'uri', 'table', 'route_name'] as $field) {
            if (is_string($element['metadata'][$field] ?? null)) {
                $fields[] = $element['metadata'][$field];
            }
        }

        return array_values(array_unique(array_map(static fn (string $value): string => 'v'.strtolower($value), $fields)));
    }

    /** @return \Generator<string> Hex keys preserve arbitrary byte trigrams in JSON. */
    public static function grams(string $value): \Generator
    {
        for ($i = 0, $end = strlen($value) - 2; $i < $end; $i++) {
            yield bin2hex(substr($value, $i, 3));
        }
    }
}
