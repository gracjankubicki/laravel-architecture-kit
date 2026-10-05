<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\LayerPolicy;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;

/** Exact direct witnesses first; additional paths only provide context. */
final class LayerFindingContext
{
    /** @return array<string, mixed> */
    public function inspect(ProjectGraphSnapshot $graph, string $path, ?int $line): array
    {
        $out = $reported = [];
        $limited = false;
        $policy = new LayerPolicy;
        foreach ($graph->edges as $edge) {
            if (ImpactExtractor::sourceLimit(0) !== null) {
                $limited = true;
                break;
            }
            $row = ['from' => $edge->from, 'to' => $edge->to, 'path' => $edge->path, 'line' => $edge->line, 'kind' => $edge->kind, 'strength' => $edge->strong ? 'strong' : 'weak', 'certainty' => 'declared', 'conditions' => []];
            $out[strtolower($edge->from)][] = $row;
            $a = $graph->symbol($edge->from);
            $b = $graph->symbol($edge->to);
            if ($line !== null && $edge->path === $path && $edge->line === $line && $edge->strong && $a !== null && $b !== null && ! $policy->allows($a->role, $b->role)) {
                if (count($reported) < 20) {
                    $reported[] = $row;
                } else {
                    $limited = true;
                }
            }
        }
        $context = [];
        $pairs = [];
        foreach ($reported as $edge) {
            $pair = strtolower($edge['from'].'|'.$edge['to']);
            if (isset($pairs[$pair])) {
                continue;
            }
            $pairs[$pair] = true;
            $paths = (new PathTraversal)->find([$edge['from']], [$edge['to']], $out, [], 5, 8);
            $limited = $limited || $paths['limited'];
            array_push($context, ...$paths['paths']);
        }

        return ['status' => $limited ? 'limit' : ($reported === [] ? 'unresolved' : 'matched'), 'reported_edges' => $reported, 'context_paths' => $context, 'limited' => $limited, 'limitations' => ['Only direct forbidden strong edges at the exact reported path and line are matched.', 'Several witnesses at one line remain separate; path or line alone may not identify a unique finding.', 'Context paths are not additional findings. Cutting one path does not prove all violations are removed.', 'Strength classifies a dependency; it does not estimate repair cost.']];
    }
}
