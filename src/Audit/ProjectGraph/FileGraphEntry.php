<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph;

/**
 * What one file contributed to the graph.
 *
 * The graph is otherwise a flat pair of lists, which is enough to answer questions but
 * not to rebuild part of it: there is no way to remove what a single file put there. This
 * keeps the contribution attributable, so an edited file can be reparsed while the rest
 * of the project is restored from the previous run.
 */
final readonly class FileGraphEntry
{
    /**
     * @param  array<int, ProjectSymbol>  $symbols
     * @param  array<int, DependencyEdge>  $edges
     */
    public function __construct(
        public array $symbols,
        public array $edges,
    ) {}
}
