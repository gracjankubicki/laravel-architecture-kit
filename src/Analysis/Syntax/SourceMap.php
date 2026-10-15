<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis\Syntax;

use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;

/** Rebind transformed template syntax and derived names to the original snapshot. */
final readonly class SourceMap
{
    /** @param \Closure(int): int $position */
    public function __construct(private SourceInput $source, private \Closure $position) {}

    public function node(Node $node): Node
    {
        $start = ($this->position)($node->start);
        $end = $node->end === $node->start ? $start : ($this->position)($node->end - 1) + 1;

        return new Node($node->kind, array_map($this->value(...), $node->fields()), $this->source, $start, max($start, $end),
            array_map($this->value(...), $node->getAttributes()), $node->getComments());
    }

    private function value(mixed $value): mixed
    {
        if ($value instanceof Node) {
            return $this->node($value);
        }

        return is_array($value) ? array_map($this->value(...), $value) : $value;
    }
}
