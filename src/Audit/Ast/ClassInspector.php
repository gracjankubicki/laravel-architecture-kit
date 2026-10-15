<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Ast;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;

final readonly class ClassInspector
{
    /**
     * @param  array<int, Node>  $nodes
     */
    public static function firstClass(array $nodes): ?Node
    {
        foreach ($nodes as $node) {
            if (Node::is($node, 'statement.class')) {
                return $node;
            }

            if (Node::is($node, 'statement.namespace')) {
                foreach ($node->stmts as $statement) {
                    if (Node::is($statement, 'statement.class')) {
                        return $statement;
                    }
                }
            }
        }

        return null;
    }

    public static function className(Node $class): ?string
    {
        return $class->name?->toString();
    }

    /**
     * @param  array<int, string>  $expected
     */
    public static function classExtendsAny(Node $class, array $expected): bool
    {
        if (! Node::is($class->extends, 'name')) {
            return false;
        }

        $extends = $class->extends->toString();

        foreach ($expected as $name) {
            if ($extends === $name || str_ends_with($extends, '\\'.$name)) {
                return true;
            }
        }

        return false;
    }

    public static function classUsesTrait(Node $class, string $trait): bool
    {
        foreach ($class->stmts as $statement) {
            if (! Node::is($statement, 'statement.trait-use')) {
                continue;
            }

            foreach ($statement->traits as $usedTrait) {
                $name = $usedTrait->toString();

                if ($name === $trait || str_ends_with($name, '\\'.$trait)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function classHasProperty(Node $class, string $property): bool
    {
        foreach ($class->stmts as $statement) {
            if (! Node::is($statement, 'statement.property')) {
                continue;
            }

            foreach ($statement->props as $propertyNode) {
                if ($propertyNode->name->toString() === $property) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function classHasMethod(Node $class, string $method): bool
    {
        return self::classMethod($class, $method) !== null;
    }

    public static function classMethod(Node $class, string $method): ?Node
    {
        foreach ($class->getMethods() as $classMethod) {
            if ($classMethod->name->toString() === $method) {
                return $classMethod;
            }
        }

        return null;
    }
}
