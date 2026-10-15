<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use GracjanKubicki\ArchitectureKit\Analysis\EngineDescriptor;
use InvalidArgumentException;

final class RuleRegistry
{
    /**
     * @param  array<int, class-string<AuditRule>|AuditRule>  $customRules
     */
    public function __construct(
        private readonly array $customRules = [],
        ?EngineDescriptor $engine = null,
    ) {
        if ($engine?->engine === 'mago') {
            foreach ($customRules as $rule) {
                if (is_string($rule)) {
                    if (! class_exists($rule)) {
                        throw new InvalidArgumentException("Architecture Kit audit rule [{$rule}] does not exist.");
                    }
                    if (! is_a($rule, AuditRule::class, true)) {
                        throw new InvalidArgumentException("Architecture Kit audit rule [{$rule}] must implement ".AuditRule::class.'.');
                    }
                }
                if (! ($rule instanceof PortableAuditRule || is_string($rule) && is_a($rule, PortableAuditRule::class, true))) {
                    $name = is_string($rule) ? $rule : $rule::class;
                    throw new InvalidArgumentException('Custom rule ['.$name.'] requires the PHP engine. Select PHP explicitly or migrate to PortableAuditRule and FileContext::syntax before using Mago.');
                }
            }
        }
    }

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
