<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis\Syntax;

/** Queries share the same source order on either backend. */
final class Finder
{
    /** @param list<Node>|Node $nodes
     * @return list<Node> */
    public function find(array|Node $nodes, callable $predicate): array
    {
        $result = [];
        foreach (Query::walk($nodes) as $node) {
            if ($predicate($node)) {
                $result[] = $node;
            }
        }

        return $result;
    }

    /** @param list<Node>|Node $nodes */
    public function findFirst(array|Node $nodes, callable $predicate): ?Node
    {
        foreach (Query::walk($nodes) as $node) {
            if ($predicate($node)) {
                return $node;
            }
        }

        return null;
    }

    /** @param list<Node>|Node $nodes
     * @return list<Node> */
    public function findOfKind(array|Node $nodes, string $kind): array
    {
        return Query::ofKind($nodes, $kind);
    }

    /** @param list<Node>|Node $nodes */
    public function findFirstOfKind(array|Node $nodes, string $kind): ?Node
    {
        return $this->findFirst($nodes, static fn (Node $node): bool => Node::is($node, $kind));
    }
}
