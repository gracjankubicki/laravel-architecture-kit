<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\DependencyEdge;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectAuditRule;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;

final readonly class PortBypassRule implements ProjectAuditRule
{
    public function check(ProjectGraphSnapshot $graph, array $enabled, ?array $focusPaths = null): array
    {
        if (! in_array(Architecture::PortsAndAdapters, $enabled, true)) {
            return [];
        }

        $findings = [];

        foreach ($graph->edges as $edge) {
            if (! $edge->strong || ! $this->inFocus($edge, $focusPaths)) {
                continue;
            }

            $source = $graph->symbol($edge->from);
            $target = $graph->symbol($edge->to);

            if ($source?->role !== 'application' || $target?->role !== 'infrastructure') {
                continue;
            }

            $ports = [];

            foreach ($graph->dependenciesOf($target->name) as $implementation) {
                if ($implementation->kind !== 'implements' || $graph->symbol($implementation->to)?->role !== 'port') {
                    continue;
                }

                $ports[] = $implementation->to;
            }

            if ($ports === []) {
                continue;
            }

            sort($ports);
            $port = $ports[0];
            $findings[] = new AuditFinding(
                severity: 'error',
                rule: 'ports-and-adapters',
                path: $edge->path,
                line: $edge->line,
                message: "{$source->name} depends directly on adapter {$target->name}; depend on port {$port} instead.",
                code: 'E_PORT_BYPASS',
            );
        }

        return $findings;
    }

    /** @param array<int, string>|null $focusPaths */
    private function inFocus(DependencyEdge $edge, ?array $focusPaths): bool
    {
        return $focusPaths === null || in_array($edge->path, $focusPaths, true);
    }
}
