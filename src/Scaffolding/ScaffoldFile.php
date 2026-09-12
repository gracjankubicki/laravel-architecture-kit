<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Scaffolding;

final readonly class ScaffoldFile
{
    public function __construct(
        public string $path,
        public string $contents,
        public string $purpose,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'purpose' => $this->purpose,
            'contents' => $this->contents,
        ];
    }
}
