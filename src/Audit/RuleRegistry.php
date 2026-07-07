<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use InvalidArgumentException;

final class RuleRegistry
{
    /**
     * @param  array<int, class-string<AuditRule>|AuditRule>  $customRules
     */
    public function __construct(
        private readonly array $customRules = [],
    ) {}

    /**
     * @return array<int, AuditRule>
     */
    public function customRules(): array
    {
        return array_map(
            fn (string|AuditRule $rule): AuditRule => $this->resolve($rule),
            $this->customRules,
        );
    }

    /**
     * @param  class-string<AuditRule>|AuditRule  $rule
     */
    private function resolve(string|AuditRule $rule): AuditRule
    {
        if ($rule instanceof AuditRule) {
            return $rule;
        }

        if (! class_exists($rule)) {
            throw new InvalidArgumentException("Architecture Kit audit rule [{$rule}] does not exist.");
        }

        $instance = app($rule);

        if (! $instance instanceof AuditRule) {
            throw new InvalidArgumentException("Architecture Kit audit rule [{$rule}] must implement ".AuditRule::class.'.');
        }

        return $instance;
    }
}
