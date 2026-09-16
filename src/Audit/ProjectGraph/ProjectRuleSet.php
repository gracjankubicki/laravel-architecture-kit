<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ProjectGraph\LayerDependencyRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ProjectGraph\MissingTestRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ProjectGraph\NamespaceCycleRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ProjectGraph\PortBypassRule;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\TestReachabilityResult;

final readonly class ProjectRuleSet
{
    public function __construct(private MissingTestLevel $missingTestLevel = MissingTestLevel::Off, private ?TestReachabilityResult $reachability = null) {}

    /**
     * @return array<int, ProjectAuditRule>
     */
    public function rules(): array
    {
        return [
            new PortBypassRule,
            new LayerDependencyRule,
            new NamespaceCycleRule,
            new MissingTestRule($this->missingTestLevel, $this->reachability),
        ];
    }
}
