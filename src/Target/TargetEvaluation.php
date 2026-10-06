<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Target;

use GracjanKubicki\ArchitectureKit\Revision\RevisionFacts;

/** Expected declarations never overwrite the facts or rules of the current architecture. */
final readonly class TargetEvaluation
{
    /** @param array<string, mixed> $audit
     * @return array<string, array<string, mixed>> */
    public static function evaluate(TargetDefinition $target, RevisionFacts $facts, array $audit): array
    {
        $elements = [];
        $classPaths = [];
        foreach ($facts->symbols as $current) {
            if (! in_array($current['php_kind'], ['file', 'method'], true)) {
                $classPaths[$current['path']] = true;
            }
        }
        $executionNotices = [...$facts->data->facts['notices'], ...$facts->data->links->notices];
        foreach ($facts->symbols as $id => $current) {
            if ($current['php_kind'] === 'method' || ($current['php_kind'] === 'file' && isset($classPaths[$current['path']]))) {
                continue;
            }
            $expected = self::expected($target, $current);
            $covered = $target->covers($current['path']);
            $issues = [];
            if ($covered) {
                foreach (['role', 'application_kind', 'module'] as $dimension) {
                    if ($expected[$dimension] !== null && $current[$dimension] !== $expected[$dimension]) {
                        $issue = self::issue('E_TARGET_CLASSIFICATION', $id, $dimension, $current[$dimension], $expected[$dimension], $current);
                        if ($facts->configuration->values === null || ($current['ambiguous'] ?? false) || ($current['provenance']['classification'] ?? '') === 'conflict') {
                            $issue['certainty'] = 'possible';
                        }
                        $issues[] = $issue;
                    }
                }
                if ($expected['paths'] !== [] && ! self::inPaths($current['path'], $expected['paths'])) {
                    $issues[] = self::issue('E_TARGET_PLACEMENT', $id, 'path', $current['path'], $expected['paths'], $current);
                }
            }
            $related = array_values(array_filter($audit['findings'], static fn (array $finding): bool => $finding['path'] === $current['path']));
            $uncertainty = array_values(array_filter($executionNotices, static fn (array $notice): bool => ($notice['path'] ?? '') === $current['path'] || ($notice['path'] ?? '') === ''));
            $unknown = $facts->data->limited || $uncertainty !== [] || ($current['ambiguous'] ?? false) || $current['role'] === null || $current['role'] === 'unknown';
            foreach ($facts->data->links->unknown as $from => $rows) {
                if (strtolower(explode('::', $from)[0]) === $id && $rows !== []) {
                    $unknown = true;
                    array_push($uncertainty, ...$rows);
                }
            }
            $elements[$id] = ['symbol' => $current['name'], 'path' => $current['path'], 'line' => $current['line'],
                'current' => $current, 'expected' => $expected, 'covered' => $covered, 'issues' => $issues,
                'audit_findings' => $related, 'audit_witness_scope' => 'file', 'uncertainty' => $uncertainty, 'uncertainty_scope' => 'file', 'requires_check' => $unknown];
        }
        $operations = 0;
        $limited = false;
        foreach ($facts->graph->edges as $edge) {
            $from = strtolower($edge->from);
            $to = strtolower($edge->to);
            if (! isset($elements[$from]) || ! $elements[$from]['covered']) {
                continue;
            }
            if (! isset($elements[$to])) {
                $elements[$from]['requires_check'] = true;

                continue;
            }
            foreach ($target->values['dependencies'] ?? [] as $rule) {
                if (++$operations > 100000) {
                    $limited = true;
                    break 2;
                }
                if (! TargetDefinition::matches($rule['from'], $elements[$from]['expected'])) {
                    continue;
                }
                $allowed = count(array_filter($rule['allow'], static fn (array $selector): bool => TargetDefinition::matches($selector, $elements[$to]['expected']))) > 0;
                if ($allowed) {
                    continue;
                }
                $issue = self::issue('E_TARGET_DEPENDENCY', $from, 'dependency', $edge->to, $rule['allow'], get_object_vars($edge));
                $issue['to'] = $edge->to;
                $issue['kind'] = $edge->kind;
                $issue['certainty'] = $edge->strong ? 'declared' : 'possible';
                $elements[$from]['issues'][] = $issue;
                if (! $edge->strong) {
                    $elements[$from]['requires_check'] = true;
                }
            }
        }
        foreach ($elements as &$element) {
            $element['evaluation_limited'] = $limited;
            $element['requires_check'] = $element['requires_check'] || $limited;
            $definite = array_filter($element['issues'], static fn (array $issue): bool => $issue['certainty'] === 'declared');
            $element['status'] = ! $element['covered'] ? 'outside_scope' : ($definite !== [] ? 'migration' : ($element['requires_check'] || ! $facts->channels['structure'] ? 'requires_check' : 'conformant'));
            $element['audit_divergence'] = array_map(static fn (array $finding): array => ['rule' => $finding['rule'], 'code' => $finding['code'] ?? null, 'path' => $finding['path'], 'line' => $finding['line'], 'suppression' => $finding['suppression'], 'target_status' => $element['status'], 'reason' => 'Current audit rule remains unresolved and requires a separate decision; target declarations never suppress it.'], $element['audit_findings']);
            $occurrences = [];
            foreach ($element['issues'] as &$issue) {
                $identity = hash('sha256', serialize([$issue['code'], strtolower($element['symbol']), $issue['dimension'], $issue['current'], $issue['expected'], $issue['to'] ?? null, $issue['kind'] ?? null, $issue['certainty']]));
                $occurrence = ($occurrences[$identity] ?? 0) + 1;
                $occurrences[$identity] = $occurrence;
                $issue['id'] = $identity.':'.$occurrence;
            }
            unset($issue);
        }
        unset($element);

        return $elements;
    }

    /** Also used for a file that has not been written yet.
     * @param array<string, mixed> $current
     * @return array<string, mixed> */
    public static function expected(TargetDefinition $target, array $current): array
    {
        $mapping = $target->classification->roleMapping($current['path'], $current['name'] ?? '');
        $module = $target->classification->module($current['path'], $current['name'] ?? '')['module'] ?? $current['module'] ?? null;
        $kind = $mapping['kind'] ?? $current['application_kind'] ?? null;
        $role = $mapping['role'] ?? $current['role'] ?? null;
        $selected = null;
        foreach ($target->values['placements'] ?? [] as $placement) {
            if ($placement['kind'] === $kind && (! isset($placement['module']) || $placement['module'] === $module)) {
                if ($selected === null || isset($placement['module'])) {
                    if (isset($placement['role'], $mapping['role']) && $placement['role'] !== $mapping['role']) {
                        throw new \InvalidArgumentException('Conflicting target placement/classification role for '.$current['path']);
                    }
                    $selected = $placement;
                }
            }
        }

        return ['role' => $selected['role'] ?? $role, 'application_kind' => $kind, 'module' => $module,
            'paths' => $selected['paths'] ?? [], 'mapping' => $mapping['source'] ?? null];
    }

    /** @param list<string> $paths */
    private static function inPaths(string $path, array $paths): bool
    {
        foreach ($paths as $prefix) {
            if (str_starts_with($path, rtrim($prefix, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $source
     * @return array<string, mixed> */
    private static function issue(string $code, string $from, string $dimension, mixed $current, mixed $expected, array $source): array
    {
        return ['code' => $code, 'from' => $from, 'dimension' => $dimension, 'current' => $current, 'expected' => $expected,
            'path' => $source['path'], 'line' => $source['line'], 'certainty' => 'declared'];
    }
}
