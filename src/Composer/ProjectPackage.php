<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Composer;

final readonly class ProjectPackage
{
    public function __construct(
        public string $name,
        public ?string $section,
        public ?string $declaredConstraint,
        public ?string $installedVersion,
        public ?string $lockedVersion,
    ) {}
}
