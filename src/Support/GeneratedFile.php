<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

final readonly class GeneratedFile
{
    public function __construct(
        public string $path,
        public string $contents,
        public bool $removable = false,
    ) {
    }
}
