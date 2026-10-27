<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache;

use GracjanKubicki\ArchitectureKit\Catalog\ReceiverDescriptors;
use InvalidArgumentException;

/** Linear checks of ready state. External symbolic targets remain legal. */
final class CatalogState
{
    public const FIELDS = ['elements', 'relations', 'diagnostics', 'descriptorReceivers', 'names', 'out', 'in', 'hierarchyLimits'];

    /** @param array<string, mixed> $state */
    public static function validate(array $state): void
    {
        self::check(array_keys($state) === self::FIELDS, 'fields');
        foreach ($state as $values) {
            self::check(is_array($values), 'field_type');
        }
        self::check(array_is_list($state['relations']) && array_is_list($state['diagnostics']), 'lists');
        $elements = $state['elements'];
        foreach ($elements as $id => $element) {
            // Normalize only the PHP map key, never the stored textual identity.
            self::check(is_array($element) && ($element['id'] ?? null) === (string) $id
                && is_string($element['name'] ?? null) && is_string($element['kind'] ?? null)
                && is_string($element['path'] ?? null) && is_int($element['line'] ?? null) && $element['line'] > 0
                && is_int($element['end_line'] ?? null) && $element['end_line'] >= $element['line']
                && array_key_exists('parent', $element) && is_int($element['offset'] ?? null) && $element['offset'] >= 0
                && is_array($element['roles'] ?? null) && array_is_list($element['roles'])
                && count(array_filter($element['roles'], 'is_string')) === count($element['roles'])
                && is_array($element['metadata'] ?? null) && is_array($element['sources'] ?? null)
                && is_array($element['role_evidence'] ?? null) && ($element['knowledge'] ?? null) === 'static', 'element');
            self::check($element['parent'] === null || is_string($element['parent']) && $element['parent'] !== $element['id'] && isset($elements[$element['parent']]), 'parent');
            // The field container is depth 0, so every complete record starts at depth 1.
            self::values($element, $state['descriptorReceivers'], 1);
        }
        $named = [];
        foreach ($state['names'] as $name => $ids) {
            self::check(is_string($name) && is_array($ids) && array_is_list($ids) && $ids !== [], 'names');
            foreach ($ids as $id) {
                self::check(is_string($id) && isset($elements[$id]) && strtolower($elements[$id]['name']) === $name && ! isset($named[$id]), 'name_reference');
                $named[$id] = true;
            }
        }
        self::check(count($named) === count($elements), 'missing_name');
        unset($named);
        foreach ($state['hierarchyLimits'] as $id => $limited) {
            self::check(isset($elements[$id]) && $limited === true, 'hierarchy_reference');
        }
        self::validateFragment($state['relations'], $state['diagnostics'], $elements, $state['descriptorReceivers']);
        // addRelation appends the current ordinal to each list, so each is strictly increasing.
        foreach (['out' => 'from', 'in' => 'to'] as $direction => $endpoint) {
            $total = 0;
            foreach ($state[$direction] as $id => $indices) {
                self::check(is_array($indices) && array_is_list($indices) && $indices !== [], 'adjacency');
                $previous = -1;
                foreach ($indices as $ordinal) {
                    self::check(is_int($ordinal) && $ordinal > $previous && ($state['relations'][$ordinal][$endpoint] ?? null) === (string) $id, 'adjacency_reference');
                    $previous = $ordinal;
                }
                $total += count($indices);
            }
            self::check($total === count($state['relations']), 'adjacency_records');
        }
        foreach ($state['descriptorReceivers'] as $hash => $receiver) {
            self::check(is_string($hash) && is_string($receiver) && strlen($receiver) <= 16384
                && hash_equals(hash('sha256', $receiver), $hash)
                && preg_match('/^@(return|method-callable|after-argument|property):/', $receiver) === 1, 'descriptor');
            self::values($receiver, $state['descriptorReceivers'], 1);
        }
    }

    /** Validate operation fragments without rescanning the complete element/name tables.
     * @param  list<array<string, mixed>>  $relations
     * @param  list<array<string, mixed>>  $diagnostics
     * @param  array<string, array<string, mixed>>  $elements
     * @param  array<string, string>  $receivers
     */
    public static function validateFragment(array $relations, array $diagnostics, array $elements, array $receivers): void
    {
        self::check(array_is_list($relations) && array_is_list($diagnostics), 'fragment_lists');
        foreach ($relations as $relation) {
            self::check(is_array($relation) && is_string($relation['from'] ?? null) && is_string($relation['to'] ?? null)
                && is_string($relation['kind'] ?? null) && is_string($relation['path'] ?? null)
                && is_int($relation['line'] ?? null) && $relation['line'] > 0
                && is_int($relation['end_line'] ?? null) && $relation['end_line'] >= $relation['line']
                && in_array($relation['resolution'] ?? null, ['resolved', 'conditional', 'structural'], true)
                && is_array($relation['metadata'] ?? null) && (! array_key_exists('knowledge', $relation) || $relation['knowledge'] === 'static'), 'relation');
            self::check(isset($elements[$relation['from']]), 'relation_owner');
            self::check(isset($elements[$relation['to']]) || preg_match('/^(php|value|file|dispatch|container|http|execution|include):/', $relation['to']) === 1, 'relation_target');
            self::values($relation, $receivers, 1);
        }
        foreach ($diagnostics as $diagnostic) {
            self::check(is_array($diagnostic) && is_string($diagnostic['code'] ?? null) && is_string($diagnostic['message'] ?? null), 'diagnostic');
            if (is_string($diagnostic['subject'] ?? null) && str_starts_with($diagnostic['subject'], 'element:')) {
                self::check(isset($elements[$diagnostic['subject']]), 'diagnostic_subject');
            }
            self::values($diagnostic, $receivers, 1);
        }
    }

    /** @param array<string, string> $receivers */
    private static function values(mixed $value, array $receivers, int $depth = 0): void
    {
        if ($depth >= 32 || is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Invalid ready catalog state: plain_values');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                switch (gettype($item)) {
                    case 'array':
                        self::values($item, $receivers, $depth + 1);
                        break;
                    case 'string':
                        if (isset($item[0]) && $item[0] === '@') {
                            self::values($item, $receivers, $depth + 1);
                        }
                        break;
                    case 'object':
                    case 'resource':
                        throw new InvalidArgumentException('Invalid ready catalog state: plain_values');
                }
            }
        } elseif (is_string($value) && str_starts_with($value, '@')) {
            if (str_starts_with($value, '@property:')) {
                self::check(ReceiverDescriptors::property($value, $receivers) !== null, 'property_descriptor');
            }
            foreach (['@return:', '@method-callable:', '@after-argument:'] as $prefix) {
                if (str_starts_with($value, $prefix)) {
                    self::check(ReceiverDescriptors::decode(substr($value, strlen($prefix)), $receivers) !== null, 'descriptor_reference');
                }
            }
            if (str_starts_with($value, ReceiverDescriptors::PREFIX)) {
                self::check(isset($receivers[substr($value, strlen(ReceiverDescriptors::PREFIX))]), 'descriptor_reference');
            }
        }
    }

    private static function check(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new InvalidArgumentException('Invalid ready catalog state: '.$reason);
        }
    }
}
