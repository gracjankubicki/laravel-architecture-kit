<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Context;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectSymbol;

final readonly class ArchitectureContextResult
{
    /**
     * @param  array<int, array<string, mixed>>  $dependencies
     * @param  array<int, array<string, mixed>>  $dependents
     * @param  array<int, array<string, mixed>>  $violations
     * @param  array<int, string>  $inspect
     * @param  array<int, string>  $next
     */
    public function __construct(
        public ProjectSymbol $subject,
        public array $dependencies,
        public array $dependents,
        public array $violations,
        public array $inspect,
        public array $next,
        public bool $truncated,
    ) {}
}
