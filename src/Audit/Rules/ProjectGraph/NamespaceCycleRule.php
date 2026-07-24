<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\DependencyEdge;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectAuditRule;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;

final class NamespaceCycleRule implements ProjectAuditRule
{
    public function check(ProjectGraphSnapshot $graph, array $enabled, ?array $focusPaths = null): array
    {
        $edges = $this->namespaceEdges($graph);
        $findings = [];

        foreach ($this->components($edges) as $component) {
            if (count($component) < 2) {
                continue;
            }

            $members = array_fill_keys($component, true);
            $cycleEdges = array_values(array_filter(
                $edges,
                fn (array $edge): bool => isset($members[$edge['from']], $members[$edge['to']]),
            ));
            $focused = $focusPaths === null ? $cycleEdges : array_values(array_filter(
                $cycleEdges,
                fn (array $edge): bool => in_array($edge['edge']->path, $focusPaths, true),
            ));

            if ($focused === []) {
                continue;
            }

            usort($focused, fn (array $left, array $right): int => [
                $left['edge']->path,
                $left['edge']->line,
                $left['edge']->from,
                $left['edge']->to,
            ] <=> [
                $right['edge']->path,
                $right['edge']->line,
                $right['edge']->from,
                $right['edge']->to,
            ]);
            sort($component);
            /** @var DependencyEdge $anchor */
            $anchor = $focused[0]['edge'];
            $findings[] = new AuditFinding(
                severity: 'warn',
                rule: 'namespace-cycle',
                path: $anchor->path,
                line: $anchor->line,
                message: 'Namespace cycle detected between ['.implode(', ', $component).'].',
                code: 'W_NAMESPACE_CYCLE',
            );
        }

        return $findings;
    }

    /**
     * @return array<int, array{from: string, to: string, edge: DependencyEdge}>
     */
    private function namespaceEdges(ProjectGraphSnapshot $graph): array
    {
        $edges = [];

        foreach ($graph->edges as $edge) {
            if (! $edge->strong || $edge->kind === 'eloquent-relation') {
                continue;
            }

            $source = $graph->symbol($edge->from);
            $target = $graph->symbol($edge->to);

            if ($source === null || $target === null || $source->namespace === '' || $source->namespace === $target->namespace) {
                continue;
            }

            $edges[] = ['from' => $source->namespace, 'to' => $target->namespace, 'edge' => $edge];
        }

        return $edges;
    }

    /**
     * @param  array<int, array{from: string, to: string, edge: DependencyEdge}>  $edges
     * @return array<int, array<int, string>>
     */
    private function components(array $edges): array
    {
        $adjacency = [];

        foreach ($edges as $edge) {
            $adjacency[$edge['from']][] = $edge['to'];
            $adjacency[$edge['to']] ??= [];
        }

        foreach ($adjacency as &$targets) {
            $targets = array_values(array_unique($targets));
            sort($targets);
        }
        unset($targets);
        ksort($adjacency);

        $index = 0;
        $indices = [];
        $low = [];
        $stack = [];
        $onStack = [];
        $components = [];

        $visit = function (string $node) use (&$visit, &$index, &$indices, &$low, &$stack, &$onStack, &$components, $adjacency): void {
            $indices[$node] = $index;
            $low[$node] = $index;
            $index++;
            $stack[] = $node;
            $onStack[$node] = true;

            foreach ($adjacency[$node] as $target) {
                if (! isset($indices[$target])) {
                    $visit($target);
                    $low[$node] = min($low[$node], $low[$target]);
                } elseif ($onStack[$target] ?? false) {
                    $low[$node] = min($low[$node], $indices[$target]);
                }
            }

            if ($low[$node] !== $indices[$node]) {
                return;
            }

            $component = [];

            do {
                $member = array_pop($stack);

                if (! is_string($member)) {
                    break;
                }

                $onStack[$member] = false;
                $component[] = $member;
            } while ($member !== $node);

            $components[] = $component;
        };

        foreach (array_keys($adjacency) as $node) {
            if (! isset($indices[$node])) {
                $visit($node);
            }
        }

        return $components;
    }
}
