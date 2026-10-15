<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use PhpParser\Node;

/** Check initializer grammar without evaluating constants or loading project code. */
final class PhpConstantSyntax
{
    /** @param list<Node> $nodes
     * @return list<array{category: string, message: string, start: int, end: int}> */
    public static function diagnostics(array $nodes, SourceInput $source): array
    {
        $errors = [];
        $stack = $nodes;
        while ($stack !== []) {
            $node = array_pop($stack);
            $expressions = [];
            if ($node instanceof Node\Stmt\Property) {
                foreach ($node->props as $property) {
                    if ($property->default !== null) {
                        $expressions[] = [$property->default, false];
                    }
                }
            } elseif ($node instanceof Node\Attribute) {
                foreach ($node->args as $argument) {
                    if ($argument->unpack) {
                        $errors[] = self::error($argument, 'Cannot use argument unpacking in attribute arguments.');
                    }
                    $expressions[] = [$argument->value, true];
                }
            } elseif ($node instanceof Node\Param && $node->default !== null) {
                $expressions[] = [$node->default, true];
            }
            foreach ($expressions as [$expression, $allowNew]) {
                if (! self::constant($expression, $allowNew, version_compare($source->phpVersion, '8.2', '>='))) {
                    $errors[] = self::error($expression, 'Initializer contains a non-constant expression.');
                }
            }
            foreach ($node->getSubNodeNames() as $field) {
                foreach (is_array($node->$field) ? $node->$field : [$node->$field] as $child) {
                    if ($child instanceof Node) {
                        $stack[] = $child;
                    }
                }
            }
        }

        return $errors;
    }

    private static function constant(Node\Expr $expression, bool $allowNew, bool $allowPropertyFetch): bool
    {
        $stack = [$expression];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node instanceof Node\Expr) {
                $allowed = $node instanceof Node\Scalar && ! $node instanceof Node\Scalar\InterpolatedString
                    || $node instanceof Node\Expr\ConstFetch || $node instanceof Node\Expr\ClassConstFetch
                    || $node instanceof Node\Expr\BinaryOp && ! $node instanceof Node\Expr\BinaryOp\Pipe
                    || $node instanceof Node\Expr\Ternary || $node instanceof Node\Expr\Array_
                    || $node instanceof Node\Expr\ArrayDimFetch || $node instanceof Node\Expr\UnaryPlus
                    || $node instanceof Node\Expr\UnaryMinus || $node instanceof Node\Expr\BooleanNot
                    || $node instanceof Node\Expr\BitwiseNot
                    || $allowPropertyFetch && ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch)
                    || $allowNew && $node instanceof Node\Expr\New_ && $node->class instanceof Node\Name;
                if (! $allowed) {
                    return false;
                }
            }
            if ($node instanceof Node\ArrayItem && $node->byRef || $node instanceof Node\Arg && $node->unpack) {
                return false;
            }
            foreach ($node->getSubNodeNames() as $field) {
                foreach (is_array($node->$field) ? $node->$field : [$node->$field] as $child) {
                    if ($child instanceof Node) {
                        $stack[] = $child;
                    }
                }
            }
        }

        return true;
    }

    /** @return array{category: string, message: string, start: int, end: int} */
    private static function error(Node $node, string $message): array
    {
        return ['category' => 'syntax_error', 'message' => $message,
            'start' => max(0, $node->getStartFilePos()), 'end' => max(0, $node->getEndFilePos() + 1)];
    }
}
