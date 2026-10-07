<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/** Bounded source operations retain selector hashes, never raw field names. */
final class CatalogSerializationChanges
{
    public const METHODS = ['sethidden', 'mergehidden', 'setvisible', 'mergevisible', 'makevisible', 'makehidden', 'makevisibleif', 'makehiddenif', 'setappends', 'mergeappends', 'append'];

    /** @return array{method: string, keys: list<string>, resolved: bool, condition: bool|null, line: int, offset: int} */
    public static function extract(Expr\MethodCall|Expr\NullsafeMethodCall $node): array
    {
        $method = strtolower($node->name instanceof Node\Identifier ? $node->name->toString() : '');
        $conditional = str_ends_with($method, 'if');
        $parameter = str_contains($method, 'hidden') && ! str_starts_with($method, 'make') ? 'hidden'
            : (str_contains($method, 'visible') && ! str_starts_with($method, 'make') ? 'visible'
                : (in_array($method, ['setappends', 'mergeappends'], true) ? 'appends' : 'attributes'));
        $keys = [];
        $resolved = ! $node instanceof Expr\NullsafeMethodCall && count($node->args) <= 128 && $node->args !== [];
        $condition = true;
        $values = [];
        $seen = [];
        foreach ($node->getArgs() as $position => $arg) {
            $name = $arg->name?->toString() ?? ($conditional && $position === 0 ? 'condition' : $parameter);
            $resolved = $resolved && ! $arg->unpack && in_array($name, $conditional ? ['condition', $parameter] : [$parameter], true);
            if ($arg->name !== null && isset($seen[$name])) {
                $resolved = false;
            }
            $seen[$name] = true;
            if ($name === 'condition') {
                $literal = $arg->value instanceof Expr\ConstFetch ? strtolower($arg->value->name->toString()) : '';
                $condition = in_array($literal, ['true', 'false'], true) ? $literal === 'true' : null;
            } else {
                $values[] = $arg->value;
            }
        }
        $resolved = $resolved && (! $conditional || isset($seen['condition'])) && $values !== [];
        $first = $values[0] ?? null;
        if ($first instanceof Expr\Array_) {
            $resolved = $resolved && count($first->items) <= 128 && ($conditional ? count($values) === 1 : true);
            foreach ($first->items as $item) {
                if ($item === null || $item->unpack || ! $item->value instanceof Scalar\String_) {
                    $resolved = false;
                } else {
                    $keys[] = hash('sha256', $item->value->value);
                }
            }
        } elseif (in_array($method, ['append', 'makehidden', 'makevisible', 'makehiddenif', 'makevisibleif'], true)) {
            foreach ($values as $value) {
                if (! $value instanceof Scalar\String_) {
                    $resolved = false;
                } else {
                    $keys[] = hash('sha256', $value->value);
                }
            }
        } else {
            $resolved = false;
        }
        if (str_starts_with($method, 'set') || str_starts_with($method, 'merge') || $conditional) {
            $resolved = $resolved && count($values) === 1;
        }
        if (count($keys) > 128) {
            $keys = [];
            $resolved = false;
        }

        return ['method' => $method, 'keys' => array_values(array_unique($keys)), 'resolved' => $resolved,
            'condition' => $condition, 'line' => max(1, $node->getStartLine()), 'offset' => max(0, $node->getStartFilePos())];
    }

    public static function validate(mixed $steps): void
    {
        if (! is_array($steps) || ! array_is_list($steps) || count($steps) > 128) {
            throw new InvalidArgumentException('Invalid serialization changes.');
        }
        foreach ($steps as $step) {
            if (! is_array($step) || array_keys($step) !== ['method', 'keys', 'resolved', 'condition', 'line', 'offset']
                || ! in_array($step['method'], self::METHODS, true) || ! is_bool($step['resolved'])
                || $step['condition'] !== null && ! is_bool($step['condition'])
                || ! is_int($step['line']) || $step['line'] < 1 || ! is_int($step['offset']) || $step['offset'] < 0) {
                throw new InvalidArgumentException('Invalid serialization change.');
            }
            CatalogSerializationSelectors::validate(['resolved' => $step['resolved'], 'keys' => $step['keys']]);
        }
    }
}
