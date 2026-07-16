<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Planning;

use GracjanKubicki\ArchitectureKit\Resources\ManagedResourcePlan;

final readonly class ArchitecturePlan
{
    /**
     * @param  array<int, ArchitectureRecommendation>  $recommendations
     * @param  array<int, array{name: string, satisfied: bool, message: string, remediation: string}>  $requirements
     */
    public function __construct(
        public bool $configured,
        public array $recommendations,
        public array $requirements,
        public ManagedResourcePlan $changes,
    ) {}
}
