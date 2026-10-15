<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis\Syntax;

use InvalidArgumentException;

/** Iterative traversal keeps long fluent chains off the PHP call stack. */
final class Traverser
{
    public const DONT_TRAVERSE_CHILDREN = 1;

    public const STOP_TRAVERSAL = 2;

    /** @var list<Visitor> */
    private array $visitors;

    public function __construct(Visitor ...$visitors)
    {
        $this->visitors = $visitors;
    }

    public function addVisitor(Visitor $visitor): void
    {
        $this->visitors[] = $visitor;
    }

    /** @param list<Node> $nodes
     * @return list<Node> */
    public function traverse(array $nodes): array
    {
        $root = (object) ['fields' => ['nodes' => $nodes], 'changed' => false];
        $stack = [];
        foreach (array_reverse(array_keys($nodes)) as $index) {
            $stack[] = [$nodes[$index], false, $root, 'nodes', $index];
        }
        while ($stack !== []) {
            [$value, $leaving, $parent, $field, $index] = array_pop($stack);
            if ($leaving && ! $value instanceof Node) {
                $node = $value->changed ? $value->node->withFields($value->fields) : $value->node;
                foreach (array_reverse($this->visitors) as $visitor) {
                    $result = $visitor->leaveNode($node);
                    if ($result === self::STOP_TRAVERSAL) {
                        return $root->fields['nodes'];
                    }
                    if ($result instanceof Node) {
                        $node = $result;
                    } elseif ($result !== null) {
                        throw new InvalidArgumentException('Unsupported syntax leave action.');
                    }
                }
                $original = $index === null ? $parent->fields[$field] : $parent->fields[$field][$index];
                if ($node !== $original) {
                    $parent->changed = true;
                    if ($index === null) {
                        $parent->fields[$field] = $node;
                    } else {
                        $parent->fields[$field][$index] = $node;
                    }
                }

                continue;
            }
            if (! $value instanceof Node) {
                throw new InvalidArgumentException('Invalid traversal entry.');
            }
            $node = $value;
            $skip = false;
            foreach ($this->visitors as $visitor) {
                $result = $visitor->enterNode($node);
                if ($result === self::STOP_TRAVERSAL) {
                    return $root->fields['nodes'];
                }
                if ($result === self::DONT_TRAVERSE_CHILDREN) {
                    $skip = true;
                } elseif ($result instanceof Node) {
                    $node = $result;
                } elseif ($result !== null) {
                    throw new InvalidArgumentException('Unsupported syntax enter action.');
                }
            }
            $frame = (object) ['node' => $node, 'fields' => $node->fields(), 'changed' => false];
            $stack[] = [$frame, true, $parent, $field, $index];
            if ($skip) {
                continue;
            }
            $children = [];
            foreach ($frame->fields as $key => $child) {
                if ($child instanceof Node) {
                    $children[] = [$child, false, $frame, $key, null];
                } elseif (is_array($child)) {
                    foreach ($child as $offset => $item) {
                        if ($item instanceof Node) {
                            $children[] = [$item, false, $frame, $key, $offset];
                        }
                    }
                }
            }
            array_push($stack, ...array_reverse($children));
        }

        return $root->fields['nodes'];
    }
}
