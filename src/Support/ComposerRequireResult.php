<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

final readonly class ComposerRequireResult
{
    public function __construct(
        public bool $successful,
        public int $exitCode,
        public string $output,
    ) {}
}
