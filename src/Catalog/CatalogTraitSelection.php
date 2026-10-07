<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Selects source trait declarations without creating synthetic alias method bodies. */
final class CatalogTraitSelection
{
    /** @param list<string> $traits
     * @return list<array{string, string}>|null Null means adaptation metadata is unavailable.
     */
    public static function candidates(CatalogIndex $index, string $owner, string $method, array $traits): ?array
    {
        $metadata = $index->elements[$owner]['metadata'];
        if (($metadata['trait_adaptations'] ?? false) && ! isset($metadata['trait_rules'])) {
            return null;
        }
        $rules = $metadata['trait_rules'] ?? [];
        $aliases = array_values(array_filter($rules, fn ($rule) => $rule['alias'] === $method));
        $result = [];
        foreach ([['trait' => null, 'method' => $method], ...$aliases] as $alias) {
            foreach ($traits as $trait) {
                $name = $index->elements[$trait]['name'];
                if ($alias['trait'] !== null && strcasecmp($alias['trait'], $name) !== 0) {
                    continue;
                }
                $excluded = false;
                // An explicitly qualified alias may expose a method excluded from its original name.
                if ($alias['trait'] === null) {
                    foreach ($rules as $rule) {
                        if ($rule['method'] === $alias['method'] && in_array(strtolower($name), array_map('strtolower', $rule['excluded']), true)) {
                            $excluded = true;
                            break;
                        }
                    }
                }
                if (! $excluded) {
                    $result[] = [$trait, $alias['method']];
                }
            }
        }

        return $result;
    }
}
