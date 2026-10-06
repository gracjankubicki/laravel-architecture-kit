<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use PhpParser\Node;
use PhpParser\Node\Expr;

/** Laravel guest eligibility follows ReflectionParameter::allowsNull and null defaults. */
final readonly class AuthorizationSignature
{
    public static function allowsGuests(Node\Param $parameter): bool
    {
        return self::allowsNull($parameter->type) || $parameter->default instanceof Expr\ConstFetch && strtolower($parameter->default->name->toString()) === 'null';
    }

    public static function routeResolvable(?Node $type): bool
    {
        if ($type instanceof Node\Name) {
            return true;
        }
        if ($type instanceof Node\NullableType) {
            return $type->type instanceof Node\Name;
        }
        // PHP normalizes Class|null to a nullable ReflectionNamedType.
        if ($type instanceof Node\UnionType && count($type->types) === 2) {
            $classes = array_filter($type->types, fn ($member) => $member instanceof Node\Name);
            $nulls = array_filter($type->types, fn ($member) => $member instanceof Node\Identifier && strtolower($member->toString()) === 'null');

            return count($classes) === 1 && count($nulls) === 1;
        }

        return false;
    }

    private static function allowsNull(?Node $type): bool
    {
        if ($type instanceof Node\NullableType) {
            return true;
        }
        if ($type instanceof Node\Identifier) {
            return in_array(strtolower($type->toString()), ['mixed', 'null'], true);
        }
        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $member) {
                if (self::allowsNull($member)) {
                    return true;
                }
            }
        }

        return false;
    }
}
