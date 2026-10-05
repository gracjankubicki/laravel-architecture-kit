<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Classification;

use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectSymbol;

final readonly class ClassificationReport
{
    /** @return array<string, mixed> */
    public static function symbol(RoleClassifier $roles, ProjectSymbol $symbol): array
    {
        return ['name' => $symbol->name, 'path' => $symbol->path, 'line' => $symbol->line, ...$roles->describe($symbol->path, $symbol->name, $symbol->kind, $symbol->hasMethods)];
    }

    /** @param list<string>|null $names
     * @return array<string, mixed>
     */
    public static function graph(RoleClassifier $roles, ProjectGraphSnapshot $graph, int $limit = 20, ?array $names = null): array
    {
        $selected = $names === null ? null : array_fill_keys(array_map(static fn ($name) => strtolower(explode('::', $name, 2)[0]), $names), true);
        $symbols = [];
        $symbolTotal = 0;
        foreach ($graph->symbols as $symbol) {
            if ($selected !== null && ! isset($selected[strtolower($symbol->name)])) {
                continue;
            }
            $symbolTotal++;
            if (count($symbols) < $limit) {
                $symbols[] = self::symbol($roles, $symbol);
            }
        }
        $relations = [];
        $relationTotal = 0;
        foreach ($graph->edges as $edge) {
            if ($selected !== null && ! isset($selected[strtolower($edge->from)]) && ! isset($selected[strtolower($edge->to)])) {
                continue;
            }
            $relationTotal++;
            if (count($relations) >= $limit) {
                continue;
            }
            $from = $graph->symbol($edge->from);
            $to = $graph->symbol($edge->to);
            $fromModule = $from === null ? null : $roles->mappings->module($from->path, $from->name)['module'];
            $toModule = $to === null ? null : $roles->mappings->module($to->path, $to->name)['module'];
            $relations[] = ['from' => $edge->from, 'to' => $edge->to, 'from_module' => $fromModule, 'to_module' => $toModule,
                'relation' => $fromModule === null || $toModule === null ? 'unassigned' : ($fromModule === $toModule ? 'intra_module' : 'inter_module'),
                'kind' => $edge->kind, 'path' => $edge->path, 'line' => $edge->line, 'is_violation' => false];
        }

        return ['symbols' => $symbols, 'module_relations' => $relations, 'totals' => ['symbols' => $symbolTotal, 'module_relations' => $relationTotal], 'truncated' => $symbolTotal > $limit || $relationTotal > $limit, 'unknown_role_level' => $roles->mappings->unknownLevel,
            'limitations' => ['Module ownership comes only from project declarations. Unassigned models may be shared by multiple modules.', 'Module relationships are source witnesses, not module dependency policy or runtime proof.', 'Role, application kind, PHP kind and module are separate. A declaration does not enable a profile or prove code conforms.']];
    }
}
