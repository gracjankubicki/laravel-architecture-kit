<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph;

final readonly class DependencyEdge
{
    public function __construct(
        public string $from,
        public string $to,
        public string $path,
        public int $line,
        public string $kind,
        public bool $strong,
    ) {}
}
