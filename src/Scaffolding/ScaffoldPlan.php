<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Scaffolding;

final readonly class ScaffoldPlan
{
    /**
     * @param  array<int, ScaffoldFile>  $files
     * @param  array<int, string>  $existing
     */
    public function __construct(
        public string $architecture,
        public string $class,
        public string $namespace,
        public array $files,
        public array $existing,
    ) {}

    /**
     * @return array<int, string>
     */
    public function paths(): array
    {
        return array_map(static fn (ScaffoldFile $file): string => $file->path, $this->files);
    }
}
