<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis\Syntax;

/** Synthetic analysis expressions retain their original source witness. */
final class Derived
{
    /** @param array<string, mixed> $fields */
    public static function node(Node $witness, string $kind, array $fields): Node
    {
        return new Node($kind, $fields, $witness->source, $witness->start, $witness->end, $witness->getAttributes(), $witness->getComments());
    }

    public static function identifier(Node $witness, string $name): Node
    {
        return self::node($witness, 'identifier', ['name' => $name]);
    }

    public static function classContext(Node $witness, ?string $name = null): Node
    {
        return self::node($witness, 'statement.class', ['attrGroups' => [], 'flags' => 0,
            'name' => $name === null ? null : self::identifier($witness, $name), 'extends' => null, 'implements' => [], 'stmts' => []]);
    }

    public static function returnValue(Node $value): Node
    {
        return self::node($value, 'statement.return', ['expr' => $value]);
    }

    public static function classConstant(Node $name, string $constant): Node
    {
        return self::node($name, 'expression.class-const-fetch', ['class' => $name, 'name' => self::identifier($name, $constant)]);
    }

    public static function string(Node $witness, string $value): Node
    {
        return self::node($witness, 'scalar.string', ['value' => $value]);
    }
}
