<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use PhpParser\Node;
use PhpParser\Node\Expr;

/** Bounded literal selector evaluation never executes expressions or retains payload arrays. */
final class CatalogSelector
{
    public static function literal(?Node $node, int $depth = 0): ?string
    {
        if ($depth > 16) {
            return null;
        }
        if ($node instanceof Node\Scalar\String_) {
            return strlen($node->value) <= 500 ? $node->value : null;
        }
        if ($node instanceof Expr\BinaryOp\Concat) {
            $left = self::literal($node->left, $depth + 1);
            $right = self::literal($node->right, $depth + 1);

            return $left !== null && $right !== null && strlen($left) + strlen($right) <= 500 ? $left.$right : null;
        }

        return null;
    }
}
