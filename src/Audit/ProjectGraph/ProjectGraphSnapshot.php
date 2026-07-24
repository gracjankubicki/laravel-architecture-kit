<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph;

final readonly class ProjectGraphSnapshot
{
    /** @var array<string, ProjectSymbol> */
    private array $symbolsByName;

    /**
     * @param  array<int, ProjectSymbol>  $symbols
     * @param  array<int, DependencyEdge>  $edges
     */
    public function __construct(
        public array $symbols,
        public array $edges,
    ) {
        $byName = [];

        foreach ($symbols as $symbol) {
            $byName[strtolower($symbol->name)] = $symbol;
        }

        $this->symbolsByName = $byName;
    }

    public function symbol(string $name): ?ProjectSymbol
    {
        return $this->symbolsByName[strtolower(ltrim($name, '\\'))] ?? null;
    }

    /** @return array<int, ProjectSymbol> */
    public function symbolsAt(string $path): array
    {
        return array_values(array_filter(
            $this->symbols,
            fn (ProjectSymbol $symbol): bool => $symbol->path === $path,
        ));
    }

    /** @return array<int, DependencyEdge> */
    public function dependenciesOf(string $name): array
    {
        return array_values(array_filter(
            $this->edges,
            fn (DependencyEdge $edge): bool => strcasecmp($edge->from, $name) === 0,
        ));
    }

    /** @return array<int, DependencyEdge> */
    public function dependentsOf(string $name): array
    {
        return array_values(array_filter(
            $this->edges,
            fn (DependencyEdge $edge): bool => strcasecmp($edge->to, $name) === 0,
        ));
    }
}
