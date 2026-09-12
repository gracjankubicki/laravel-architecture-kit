<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph;

final readonly class ProjectSymbol
{
    public function __construct(
        public string $name,
        public string $path,
        public int $line,
        public string $namespace,
        public string $kind,
        public string $role,
        /** Whether the symbol declares methods of its own; an enum without any is a plain set of cases. */
        public bool $hasMethods = true,
    ) {}
}
