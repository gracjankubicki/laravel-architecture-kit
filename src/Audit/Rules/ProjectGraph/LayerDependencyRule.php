<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\LayerPolicy;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectAuditRule;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;

final readonly class LayerDependencyRule implements ProjectAuditRule
{
    public function __construct(private LayerPolicy $policy = new LayerPolicy) {}

    public function check(ProjectGraphSnapshot $graph, array $enabled, ?array $focusPaths = null): array
    {
        $findings = [];

        foreach ($graph->edges as $edge) {
            if (! $edge->strong || ($focusPaths !== null && ! in_array($edge->path, $focusPaths, true))) {
                continue;
            }

            $source = $graph->symbol($edge->from);
            $target = $graph->symbol($edge->to);

            if ($source === null || $target === null || $this->policy->allows($source->role, $target->role)) {
                continue;
            }

            $findings[] = new AuditFinding(
                severity: 'error',
                rule: 'layer-dependency',
                path: $edge->path,
                line: $edge->line,
                message: "{$source->name} ({$source->role}) must not depend on {$target->name} ({$target->role}).",
                code: 'E_LAYER_DEPENDENCY',
            );
        }

        return $findings;
    }
}
