<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Ast;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

final readonly class ClassInspector
{
    /**
     * @param  array<int, Node>  $nodes
     */
    public static function firstClass(array $nodes): ?Stmt\Class_
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Class_) {
                return $node;
            }

            if ($node instanceof Stmt\Namespace_) {
                foreach ($node->stmts as $statement) {
                    if ($statement instanceof Stmt\Class_) {
                        return $statement;
                    }
                }
            }
        }

        return null;
    }

    public static function className(Stmt\Class_ $class): ?string
    {
        return $class->name?->toString();
    }

    /**
     * @param  array<int, string>  $expected
     */
    public static function classExtendsAny(Stmt\Class_ $class, array $expected): bool
    {
        if (! $class->extends instanceof Name) {
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

    public static function classUsesTrait(Stmt\Class_ $class, string $trait): bool
    {
        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Stmt\TraitUse) {
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

    public static function classHasProperty(Stmt\Class_ $class, string $property): bool
    {
        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Stmt\Property) {
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

    public static function classHasMethod(Stmt\Class_ $class, string $method): bool
    {
        return self::classMethod($class, $method) !== null;
    }

    public static function classMethod(Stmt\Class_ $class, string $method): ?Stmt\ClassMethod
    {
        foreach ($class->getMethods() as $classMethod) {
            if ($classMethod->name->toString() === $method) {
                return $classMethod;
            }
        }

        return null;
    }
}
