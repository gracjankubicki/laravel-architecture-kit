<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

final class PhpAst
{
    /**
     * @return array<int, Node>|null
     */
    public static function parse(string $contents): ?array
    {
        try {
            return (new ParserFactory)->createForHostVersion()->parse($contents);
        } catch (Error) {
            return null;
        }
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    public static function traverse(array $nodes, NodeVisitor $visitor): void
    {
        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse($nodes);
    }

    /**
     * @param  callable(Node): bool  $predicate
     */
    public static function contains(Node $node, callable $predicate): bool
    {
        $state = new class
        {
            public bool $found = false;
        };

        self::traverse([$node], new class($predicate, $state) extends NodeVisitorAbstract
        {
            /**
             * @param  callable(Node): bool  $predicate
             */
            public function __construct(
                private $predicate,
                private object $state,
            ) {}

            public function enterNode(Node $node): ?int
            {
                if (($this->predicate)($node)) {
                    $this->state->found = true;

                    return NodeTraverser::STOP_TRAVERSAL;
                }

                return null;
            }
        });

        return $state->found;
    }
}
