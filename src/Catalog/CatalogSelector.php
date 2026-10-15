<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;

/** Bounded literal selector evaluation never executes expressions or retains payload arrays. */
final class CatalogSelector
{
    public static function literal(?Node $node, int $depth = 0): ?string
    {
        if ($depth > 16) {
            return null;
        }
        if (Node::is($node, 'scalar.string')) {
            return strlen($node->value) <= 500 ? $node->value : null;
        }
        if (Node::is($node, 'expression.binary-op.concat')) {
            $left = self::literal($node->left, $depth + 1);
            $right = self::literal($node->right, $depth + 1);

            return $left !== null && $right !== null && strlen($left) + strlen($right) <= 500 ? $left.$right : null;
        }

        return null;
    }
}
