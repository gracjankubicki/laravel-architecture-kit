<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\PublicApi;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;

/** A bounded literal reader, never PHP evaluation or application constant resolution. */
final class StaticValue
{
    /** @return array{known: bool, value: mixed} */
    public static function read(Node $expr, int $depth = 0): array
    {
        if ($depth > 32) {
            return ['known' => false, 'value' => null];
        }
        if (Node::is($expr, 'scalar.string') || Node::is($expr, 'scalar.int') || Node::is($expr, 'scalar.float')) {
            return ['known' => true, 'value' => $expr->value];
        }
        if (Node::is($expr, 'expression.const-fetch')) {
            return match (strtolower($expr->name->toString())) {
                'true' => ['known' => true, 'value' => true], 'false' => ['known' => true, 'value' => false],
                'null' => ['known' => true, 'value' => null], default => ['known' => false, 'value' => null],
            };
        }
        if (Node::is($expr, 'expression.class-const-fetch') && Node::is($expr->class, 'name') && Node::is($expr->name, 'identifier') && strtolower($expr->name->toString()) === 'class') {
            $name = $expr->class->getAttribute('resolvedName');

            return ['known' => true, 'value' => (Node::is($name, 'name') ? $name : $expr->class)->toString()];
        }
        if (Node::is($expr, 'expression.array')) {
            $result = [];
            foreach ($expr->items as $item) {
                if ($item === null || $item->unpack) {
                    return ['known' => false, 'value' => null];
                }
                $value = self::read($item->value, $depth + 1);
                $key = $item->key === null ? ['known' => true, 'value' => null] : self::read($item->key, $depth + 1);
                if (! $value['known'] || ! $key['known'] || ($item->key !== null && ! is_int($key['value']) && ! is_string($key['value']))) {
                    return ['known' => false, 'value' => null];
                }
                if ($item->key === null) {
                    $result[] = $value['value'];
                } else {
                    $result[$key['value']] = $value['value'];
                }
            }

            return ['known' => true, 'value' => $result];
        }
        if (Node::is($expr, 'expression.binary-op.concat')) {
            $left = self::read($expr->left, $depth + 1);
            $right = self::read($expr->right, $depth + 1);
            if ($left['known'] && $right['known'] && is_string($left['value']) && is_string($right['value'])) {
                return ['known' => true, 'value' => $left['value'].$right['value']];
            }
        }
        if (Node::is($expr, 'expression.unary-minus') || Node::is($expr, 'expression.unary-plus')) {
            $value = self::read($expr->expr, $depth + 1);
            if ($value['known'] && (is_int($value['value']) || is_float($value['value']))) {
                return ['known' => true, 'value' => Node::is($expr, 'expression.unary-minus') ? -$value['value'] : $value['value']];
            }
        }

        return ['known' => false, 'value' => null];
    }
}
