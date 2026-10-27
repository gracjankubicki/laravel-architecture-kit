<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog\Search;

use ReflectionReference;

/** Admission check: COW alone cannot isolate a pre-existing PHP reference. */
final class SearchReferences
{
    /** Traversal stops at the first alias, including a cyclic array reference.
     * @param  array<mixed>  $values
     */
    public static function contains(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (ReflectionReference::fromArrayElement($values, $key) !== null
                || is_array($value) && self::contains($value)) {
                return true;
            }
        }

        return false;
    }
}
