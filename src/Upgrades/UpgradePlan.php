<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Upgrades;

use GracjanKubicki\ArchitectureKit\Composer\ProjectPackage;

final readonly class UpgradePlan
{
    /**
     * @param  array<int, UpgradePlanStep>  $route
     * @param  array<int, string>  $next
     */
    public function __construct(
        public ProjectPackage $package,
        public string $target,
        public string $status,
        public string $message,
        public array $route = [],
        public array $next = [],
    ) {}

    public function ok(): bool
    {
        return in_array($this->status, ['ready', 'complete'], true);
    }

    public function activeStep(): ?UpgradePlanStep
    {
        foreach ($this->route as $step) {
            if ($step->status === 'ready') {
                return $step;
            }
        }

        return null;
    }
}
