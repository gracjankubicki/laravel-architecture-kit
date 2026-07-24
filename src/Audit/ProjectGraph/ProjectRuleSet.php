<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Audit\Rules\ProjectGraph\LayerDependencyRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ProjectGraph\NamespaceCycleRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ProjectGraph\PortBypassRule;

final readonly class ProjectRuleSet
{
    /**
     * @return array<int, ProjectAuditRule>
     */
    public function rules(): array
    {
        return [
            new PortBypassRule,
            new LayerDependencyRule,
            new NamespaceCycleRule,
        ];
    }
}
