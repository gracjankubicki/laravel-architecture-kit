<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis\Syntax;

use Generator;

/** Queries visit structural fields only, never derived attributes or comments. */
final class Query
{
    /** @param list<Node>|Node $nodes
     * @return Generator<int, Node> */
    public static function walk(array|Node $nodes): Generator
    {
        $stack = array_reverse($nodes instanceof Node ? [$nodes] : $nodes);
        while ($stack !== []) {
            $node = array_pop($stack);
            yield $node;
            $children = [];
            foreach ($node->fields() as $value) {
                foreach (is_array($value) ? $value : [$value] as $child) {
                    if ($child instanceof Node) {
                        $children[] = $child;
                    }
                }
            }
            array_push($stack, ...array_reverse($children));
        }
    }

    /** @param list<Node>|Node $nodes
     * @return list<Node> */
    public static function ofKind(array|Node $nodes, string $kind): array
    {
        $result = [];
        foreach (self::walk($nodes) as $node) {
            if (Node::is($node, $kind)) {
                $result[] = $node;
            }
        }

        return $result;
    }
}
