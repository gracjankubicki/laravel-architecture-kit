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
     * @param  array<int, array{path: string, coverage: string, via: string|null}>  $tests
     */
    public function __construct(
        public ProjectSymbol $subject,
        public array $dependencies,
        public array $dependents,
        public array $violations,
        public array $inspect,
        public array $next,
        public bool $truncated,
        // Appended, not inserted: an existing positional construction of this result
        // would otherwise start passing tests where inspect is expected.
        public array $tests = [],
    ) {}
}
