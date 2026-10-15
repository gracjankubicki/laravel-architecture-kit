<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Ast;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Query;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Traverser;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Visitor;
use RuntimeException;

final class PhpAst
{
    /** @return list<Node>|null */
    public static function parse(string $contents): ?array
    {
        $document = AnalysisRuntime::session()->analyze('(source)', $contents);
        foreach ($document->diagnostics as $diagnostic) {
            if ($diagnostic['category'] !== 'syntax_error') {
                throw new RuntimeException($diagnostic['message']);
            }
        }

        return $document->complete() ? $document->syntax : null;
    }

    /** @param list<Node> $nodes
     * @return list<Node> */
    public static function traverse(array $nodes, Visitor $visitor): array
    {
        return (new Traverser($visitor))->traverse($nodes);
    }

    /** @param callable(Node): bool $predicate */
    public static function contains(Node $node, callable $predicate): bool
    {
        return self::containsAny([$node], $predicate);
    }

    /** @param list<Node> $nodes
     * @param callable(Node): bool $predicate */
    public static function containsAny(array $nodes, callable $predicate): bool
    {
        foreach (Query::walk($nodes) as $node) {
            if ($predicate($node)) {
                return true;
            }
        }

        return false;
    }
}
