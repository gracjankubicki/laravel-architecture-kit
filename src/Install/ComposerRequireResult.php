<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install;

final readonly class ComposerRequireResult
{
    public function __construct(
        public bool $successful,
        public int $exitCode,
        public string $output,
    ) {}
}
