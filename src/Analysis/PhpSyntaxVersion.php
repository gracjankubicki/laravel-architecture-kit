<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use PhpParser\Modifiers;
use PhpParser\Node;

/** php-parser accepts some future grammar for recovery. Do not call it valid for an older target. */
final class PhpSyntaxVersion
{
    /** @param list<Node> $nodes
     * @return list<array{category: string, message: string, start: int, end: int}> */
    public static function diagnostics(array $nodes, SourceInput $source): array
    {
        $diagnostics = [];
        $stack = $nodes;
        while ($stack !== []) {
            $node = array_pop($stack);
            $minimum = match (true) {
                $node instanceof Node\Expr\BinaryOp\Pipe => '8.5',
                $node instanceof Node\PropertyHook => '8.4',
                $node instanceof Node\Stmt\Property && (($node->flags & (Modifiers::PUBLIC_SET | Modifiers::PROTECTED_SET | Modifiers::PRIVATE_SET)) !== 0) => '8.4',
                $node instanceof Node\Param && (($node->flags & (Modifiers::PUBLIC_SET | Modifiers::PROTECTED_SET | Modifiers::PRIVATE_SET)) !== 0) => '8.4',
                $node instanceof Node\Stmt\ClassConst && $node->type !== null => '8.3',
                $node instanceof Node\Stmt\TraitUseAdaptation\Alias && (($node->newModifier ?? 0) & Modifiers::FINAL) !== 0 => '8.3',
                $node instanceof Node\Expr\ClassConstFetch && ! $node->name instanceof Node\Identifier => '8.3',
                $node instanceof Node\Stmt\Class_ && $node->isReadonly() => '8.2',
                $node instanceof Node\Stmt\Enum_, $node instanceof Node\IntersectionType => '8.1',
                $node instanceof Node\Stmt\Property && $node->isReadonly() => '8.1',
                $node instanceof Node\Param && ($node->flags & Modifiers::READONLY) !== 0 => '8.1',
                $node instanceof Node\Expr\CallLike && $node->isFirstClassCallable() => '8.1',
                $node instanceof Node\AttributeGroup, $node instanceof Node\UnionType,
                $node instanceof Node\Expr\Match_, $node instanceof Node\Expr\NullsafeMethodCall,
                $node instanceof Node\Expr\NullsafePropertyFetch,
                $node instanceof Node\Arg && $node->name !== null,
                $node instanceof Node\Param && $node->flags !== 0 => '8.0',
                $node instanceof Node\Expr\ArrowFunction => '7.4',
                $node instanceof Node\Stmt\Property && $node->type !== null => '7.4',
                default => null,
            };
            if ($minimum !== null && version_compare($source->phpVersion, $minimum, '<')) {
                $diagnostics[] = ['category' => 'syntax_error',
                    'message' => 'This syntax requires PHP '.$minimum.' or newer; project target is '.$source->phpVersion.'.',
                    'start' => max(0, $node->getStartFilePos()), 'end' => max(0, $node->getEndFilePos() + 1)];
            }
            foreach ($node->getSubNodeNames() as $field) {
                foreach (is_array($node->$field) ? $node->$field : [$node->$field] as $child) {
                    if ($child instanceof Node) {
                        $stack[] = $child;
                    }
                }
            }
        }

        return $diagnostics;
    }
}
