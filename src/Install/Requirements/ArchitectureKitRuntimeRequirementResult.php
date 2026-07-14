<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install\Requirements;

final readonly class ArchitectureKitRuntimeRequirementResult
{
    public function __construct(
        public bool $satisfied,
        public ?string $section,
        public string $message,
        public string $remediation,
    ) {}
}
