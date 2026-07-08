<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use GracjanKubicki\ArchitectureKit\Architecture;
use InvalidArgumentException;

final readonly class CustomRuleSet
{
    /**
     * @param  array<int, class-string<AuditRule>|AuditRule>  $globalRules
     * @param  array<string, array<int, class-string<AuditRule>|AuditRule>>  $scopedRules
     */
    public function __construct(
        private array $globalRules = [],
        private array $scopedRules = [],
    ) {}

    /**
     * @param  array<int|string, mixed>  $rules
     */
    public static function fromConfig(array $rules): self
    {
        $global = [];
        $scoped = [];

        foreach ($rules as $key => $value) {
            if (is_int($key)) {
                if (! is_string($value)) {
                    throw new InvalidArgumentException('config/architectures.php rules entries must be class-string values.');
                }

                $global[] = $value;

                continue;
            }

            if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key)) {
                throw new InvalidArgumentException("config/architectures.php rules key [{$key}] must be an architecture slug in kebab-case.");
            }

            if (! is_array($value)) {
                throw new InvalidArgumentException("config/architectures.php rules.{$key} must be an array of class-string values.");
            }

            foreach ($value as $rule) {
                if (! is_string($rule)) {
                    throw new InvalidArgumentException("config/architectures.php rules.{$key} entries must be class-string values.");
                }

                $scoped[$key][] = $rule;
            }
        }

        return new self($global, $scoped);
    }

    /**
     * @param  array<int, class-string<AuditRule>|AuditRule>  $rules
     */
    public static function fromGlobal(array $rules): self
    {
        return new self($rules);
    }

    /**
     * @return array<int, class-string<AuditRule>|AuditRule>
     */
    public function globalRules(): array
    {
        return $this->globalRules;
    }

    /**
     * @return array<string, array<int, class-string<AuditRule>|AuditRule>>
     */
    public function scopedRules(): array
    {
        return $this->scopedRules;
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, class-string<AuditRule>|AuditRule>
     */
    public function rulesFor(array $enabled): array
    {
        $rules = $this->globalRules;

        foreach ($this->enabledSlugs($enabled) as $slug) {
            array_push($rules, ...($this->scopedRules[$slug] ?? []));
        }

        return $this->unique($rules);
    }

    /**
     * @return array<int, class-string<AuditRule>>
     */
    public function knownRuleClasses(): array
    {
        $rules = [];

        foreach ($this->globalRules as $rule) {
            $rules[] = $rule instanceof AuditRule ? $rule::class : $rule;
        }

        foreach ($this->scopedRules as $scopedRules) {
            foreach ($scopedRules as $rule) {
                $rules[] = $rule instanceof AuditRule ? $rule::class : $rule;
            }
        }

        return array_values(array_unique($rules));
    }

    /**
     * @return array<int, class-string<AuditRule>|AuditRule>
     */
    public function architectureRuleClasses(string $slug): array
    {
        return $this->scopedRules[$slug] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public function architectureRuleBasenames(string $slug): array
    {
        return array_map(
            fn (string|AuditRule $rule): string => str($rule instanceof AuditRule ? $rule::class : $rule)->classBasename()->toString(),
            $this->architectureRuleClasses($slug),
        );
    }

    /**
     * @return array<int, string>
     */
    public function scopedSlugs(): array
    {
        return array_keys($this->scopedRules);
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, string>
     */
    public function inactiveScopedSlugs(array $enabled): array
    {
        return array_values(array_diff($this->scopedSlugs(), $this->enabledSlugs($enabled)));
    }

    /**
     * @param  array<int, string>  $knownSlugs
     * @return array<int, string>
     */
    public function unknownScopedSlugs(array $knownSlugs): array
    {
        return array_values(array_diff($this->scopedSlugs(), $knownSlugs));
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, string>
     */
    private function enabledSlugs(array $enabled): array
    {
        return array_map(
            fn (Architecture|string $architecture): string => $architecture instanceof Architecture ? $architecture->value : $architecture,
            $enabled,
        );
    }

    /**
     * @param  array<int, class-string<AuditRule>|AuditRule>  $rules
     * @return array<int, class-string<AuditRule>|AuditRule>
     */
    private function unique(array $rules): array
    {
        $unique = [];

        foreach ($rules as $rule) {
            $key = $rule instanceof AuditRule ? $rule::class : $rule;
            $unique[$key] = $rule;
        }

        return array_values($unique);
    }
}
