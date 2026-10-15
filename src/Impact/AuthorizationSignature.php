<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;

/** Laravel guest eligibility follows ReflectionParameter::allowsNull and null defaults. */
final readonly class AuthorizationSignature
{
    public static function allowsGuests(Node $parameter): bool
    {
        return self::allowsNull($parameter->type) || Node::is($parameter->default, 'expression.const-fetch') && strtolower($parameter->default->name->toString()) === 'null';
    }

    public static function routeResolvable(?Node $type): bool
    {
        if (Node::is($type, 'name')) {
            return true;
        }
        if (Node::is($type, 'nullable-type')) {
            return Node::is($type->type, 'name');
        }
        // PHP normalizes Class|null to a nullable ReflectionNamedType.
        if (Node::is($type, 'union-type') && count($type->types) === 2) {
            $classes = array_filter($type->types, fn ($member) => Node::is($member, 'name'));
            $nulls = array_filter($type->types, fn ($member) => Node::is($member, 'identifier') && strtolower($member->toString()) === 'null');

            return count($classes) === 1 && count($nulls) === 1;
        }

        return false;
    }

    private static function allowsNull(?Node $type): bool
    {
        if (Node::is($type, 'nullable-type')) {
            return true;
        }
        if (Node::is($type, 'identifier')) {
            return in_array(strtolower($type->toString()), ['mixed', 'null'], true);
        }
        if (Node::is($type, 'union-type')) {
            foreach ($type->types as $member) {
                if (self::allowsNull($member)) {
                    return true;
                }
            }
        }

        return false;
    }
}
