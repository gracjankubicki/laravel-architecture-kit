<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Revision;

use Generator;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use Illuminate\Support\Str;

/** Difference witnesses are facts, not architecture quality or runtime compatibility verdicts. */
final readonly class RevisionChanges
{
    /** @param array<string, string> $manual
     * @return array<string, mixed> */
    public static function compare(RevisionFacts $before, RevisionFacts $after, array $manual = []): array
    {
        $pairing = new SymbolPairs($before->symbols, $after->symbols, $manual);
        $paths = [];
        $symbolPaths = [];
        $pathTargets = [];
        foreach ($pairing->pairs as $from => $to) {
            $old = $before->symbols[$from];
            $new = $after->symbols[$to];
            $pathTargets[$old['path']][$new['path']] = true;
            $symbolPaths[$from] = [$old['path'] => $new['path']];
        }
        foreach ($pathTargets as $from => $targets) {
            if (count($targets) === 1) {
                $paths[$from] = array_key_first($targets);
            }
        }
        $reportedPairs = [];
        foreach ($pairing->pairs as $from => $to) {
            if ($from !== $to || $before->symbols[$from]['path'] !== $after->symbols[$to]['path'] || $pairing->basis[$from] === 'manual') {
                $reportedPairs[$from] = $to;
            }
        }
        $used = array_flip($pairing->pairs);
        $symbols = [];
        foreach ($before->symbols as $id => $old) {
            $target = $pairing->pairs[$id] ?? null;
            $new = $target === null ? null : $after->symbols[$target];
            if ($new === null) {
                $symbols[] = ['change' => self::absence($after, $old['path'], 'removed'), 'before' => $old, 'after' => null];

                continue;
            }
            $dimensions = [];
            foreach (['path', 'role', 'application_kind', 'module', 'module_parents', 'php_kind', 'signature', 'shape'] as $field) {
                if (($old[$field] ?? null) !== ($new[$field] ?? null)) {
                    $dimensions[] = $field === 'shape' ? 'code' : $field;
                }
            }
            if ($id !== $target) {
                $dimensions[] = 'name';
            }
            if ($dimensions !== []) {
                $symbols[] = ['change' => $id !== $target || $old['path'] !== $new['path'] ? 'moved_or_renamed' : 'changed',
                    'dimensions' => $dimensions, 'pair_basis' => $pairing->basis[$id], 'before' => $old, 'after' => $new];
            }
        }
        foreach ($after->symbols as $id => $new) {
            if (! isset($used[$id])) {
                $symbols[] = ['change' => self::absence($before, $new['path'], 'added'), 'before' => null, 'after' => $new];
            }
        }
        $channels = [];
        $comparisonNotices = [];
        foreach (['structure', 'execution', 'http', 'data', 'transitions', 'rule_sources'] as $channel) {
            $beforeLimited = $afterLimited = false;
            $beforeRows = self::rows($before, $channel, $comparisonNotices, $beforeLimited, 'before');
            $afterRows = self::rows($after, $channel, $comparisonNotices, $afterLimited, 'after');
            $rows = RevisionRows::compare($beforeRows, $afterRows, $pairing->pairs, $paths, $symbolPaths);
            foreach ($rows as &$row) {
                $path = ($row['before'] ?? $row['after'])['path'] ?? ($row['before'] ?? $row['after'])['source']['path'] ?? '';
                $row['change'] = self::absence($row['after'] === null ? $after : $before, $path, $row['change'], in_array($channel, ['structure', 'transitions'], true), $channel);
                if ($row['change'] === 'removed' && $afterLimited) {
                    $row['change'] = 'not_observed_after';
                } elseif ($row['change'] === 'added' && $beforeLimited) {
                    $row['change'] = 'not_observed_before';
                }
            }
            unset($row);
            $channels[$channel] = $rows;
        }
        $configuration = [];
        foreach (['scope' => ['audit', 'paths'], 'excludes' => ['audit', 'exclude'], 'classification' => ['audit', 'classification'], 'rules' => ['rules'], 'missing_test' => ['audit', 'missing_test'], 'enabled' => ['enabled']] as $kind => $keys) {
            $old = $before->configuration->values;
            $new = $after->configuration->values;
            foreach ($keys as $key) {
                $old = $old[$key] ?? null;
                $new = $new[$key] ?? null;
            }
            if ($old !== $new || ($before->configuration->values === null) !== ($after->configuration->values === null)) {
                $configuration[] = ['dimension' => $kind, 'before' => $old, 'after' => $new,
                    'before_source' => $before->configuration->origin, 'after_source' => $after->configuration->origin,
                    'certainty' => $before->configuration->values === null || $after->configuration->values === null ? 'unresolved' : 'declared'];
            }
        }

        return ['symbols' => $symbols, ...$channels, 'configuration' => $configuration,
            'pairs' => $reportedPairs, 'candidates' => $pairing->candidates,
            'notices' => [...$comparisonNotices, ...($pairing->limited ? [['code' => 'E_REVISION_PAIR_LIMIT', 'path' => '', 'message' => 'Candidate pairing budget reached; candidate list is partial.']] : [])]];
    }

    private static function absence(RevisionFacts $facts, string $path, string $change, bool $scopeRelevant = true, string $channel = 'structure'): string
    {
        $scope = $facts->configuration->scope();
        if (in_array($path, $facts->source->paths, true) && (($scopeRelevant && $scope !== null && ! $scope->covers($path)) || Str::is($facts->configuration->excludes(), $path))) {
            return $change === 'removed' ? 'scope_left' : 'scope_entered';
        }
        if (! $facts->channels[$channel]) {
            return $change === 'removed' ? 'not_observed_after' : 'not_observed_before';
        }

        return $change;
    }

    /** @param list<array<string, mixed>> $notices
     * @return list<array<string, mixed>> */
    private static function rows(RevisionFacts $facts, string $channel, array &$notices, bool &$limited, string $side): array
    {
        $rows = [];
        foreach (self::rowStream($facts, $channel) as $row) {
            if (count($rows) >= 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                $limited = true;
                $notices[] = ['code' => 'E_REVISION_ROW_LIMIT', 'channel' => $channel, 'side' => $side, 'path' => '', 'message' => 'Revision '.$channel.' comparison budget reached; known rows are partial.'];
                break;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return Generator<int, array<string, mixed>> */
    private static function rowStream(RevisionFacts $facts, string $channel): Generator
    {
        if ($channel === 'rule_sources') {
            yield from array_values($facts->ruleSources);

            return;
        }
        if ($channel === 'http') {
            yield from $facts->http['routes'];

            return;
        }
        if ($channel === 'execution') {
            foreach ($facts->data->links->out as $rows) {
                yield from $rows;
            }
            foreach ($facts->data->links->unknown as $from => $unknown) {
                foreach ($unknown as $witness) {
                    yield ['from' => $from, 'to' => null, 'kind' => 'unresolved-call', 'certainty' => 'unresolved', ...$witness];
                }
            }

            return;
        }
        if ($channel === 'data') {
            yield from $facts->data->extractor->effects;

            return;
        }
        foreach ($facts->graph->edges as $edge) {
            $row = get_object_vars($edge);
            if ($channel === 'transitions') {
                $from = $facts->symbols[strtolower($edge->from)] ?? null;
                $to = $facts->symbols[strtolower($edge->to)] ?? null;
                if ($from === null || $to === null || ($from['role'] === $to['role'] && $from['module'] === $to['module'])) {
                    continue;
                }
                $row = [...$row, 'from_role' => $from['role'], 'to_role' => $to['role'], 'from_module' => $from['module'], 'to_module' => $to['module']];
            }
            yield $row;
        }
        if ($channel === 'structure') {
            foreach ($facts->graph->impactFacts as $file) {
                foreach ($file->calls as $call) {
                    yield ['path' => $file->path, ...$call];
                }
            }
        }

    }
}
