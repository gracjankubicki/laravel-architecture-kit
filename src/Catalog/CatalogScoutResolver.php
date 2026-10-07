<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Searches and index updates are source candidates, never runtime proof. */
final class CatalogScoutResolver
{
    private const TRAIT = 'Laravel\\Scout\\Searchable';

    private const READS = ['raw', 'keys', 'first', 'get', 'cursor', 'simplepaginate', 'simplepaginateraw', 'paginate', 'paginateraw'];

    private const WRITES = ['searchable', 'unsearchable', 'searchablesync', 'unsearchablesync', 'removeallfromsearch'];

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(): void
    {
        $profile = CatalogPackageVersions::verified($this->index, 'laravel/scout');
        $models = [];
        $selectors = [];
        foreach ($this->index->elements as $element) {
            if ($element['kind'] === 'scout-index-selector') {
                $selectors[$element['parent']][] = $element;
            }
        }
        foreach ($this->index->elements as $id => $element) {
            if ($element['kind'] !== 'class' || ! $this->index->hasContract($id, self::TRAIT)) {
                continue;
            }
            if ($profile === null || $this->index->namedTypes(self::TRAIT) !== []
                || ! $this->index->hasContract($id, 'Illuminate\\Database\\Eloquent\\Model')
                || $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Model') !== []) {
                $this->notice($element, 'Scout package version or source model/trait contract is unresolved.');

                continue;
            }
            $models[$id] = true;
            $this->index->elements[$id]['roles'][] = 'searchable-model';
            $this->index->elements[$id]['role_evidence'][] = ['role' => 'searchable-model', 'basis' => 'source-contract', 'contract' => self::TRAIT,
                'package' => 'laravel/scout', 'version' => $profile['version'], 'path' => $element['path'], 'line' => $element['line'], 'package_sources' => $profile['sources']];
            foreach (['scout-index', 'scout-engine'] as $kind) {
                $resourceId = CatalogElement::resourceIdentity($kind, $element['name']);
                $selection = $kind === 'scout-index' ? $this->indexNames($element['name'], $selectors) : [];
                $resource = new CatalogElement($resourceId, $kind.' for '.$element['name'], $kind, $element['line'], $element['end_line'], $element['offset'],
                    metadata: ['logical_resource' => true, 'model' => $element['name'], 'runtime_selection_known' => false, ...$selection]);
                $this->index->elements[$resourceId] = [...$resource->toArray(), 'path' => $element['path'], 'knowledge' => 'static', 'role_evidence' => [],
                    'sources' => [['path' => $element['path'], 'line' => $element['line'], 'end_line' => $element['end_line']]]];
                $this->index->names[strtolower($resource->name)][] = $resourceId;
                $this->index->addRelation(['from' => $id, 'to' => $resourceId, 'kind' => 'declares-'.$kind, 'path' => $element['path'], 'line' => $element['line'],
                    'end_line' => $element['end_line'], 'knowledge' => 'static', 'resolution' => 'conditional',
                    'metadata' => ['package' => 'laravel/scout', 'version' => $profile['version'], 'package_sources' => $profile['sources'], 'runtime_selection_required' => true, 'execution_proven' => false]]);
            }
        }
        if ($profile === null) {
            return;
        }
        $sites = [];
        foreach ($this->index->elements as $element) {
            if ($element['kind'] === 'scout-call-site') {
                $sites[$element['path']][$element['offset']][$element['metadata']['method']] = $element;
            }
        }
        $operations = 0;
        $edges = $this->index->relations;
        foreach ($edges as $call) {
            $method = strtolower($call['metadata']['method'] ?? '');
            if ($call['kind'] !== 'calls' || ! in_array($method, ['search', ...self::READS, ...self::WRITES], true)) {
                continue;
            }
            if (++$operations > 4096 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->notice($call, 'Scout composition reached its source budget.', 'catalog_limit');

                return;
            }
            $builder = in_array($method, self::READS, true);
            if ($builder && $this->index->namedTypes('Laravel\\Scout\\Builder') !== []) {
                $this->notice($call, 'Source Scout builder prevents inference of standard terminal behavior.');

                continue;
            }
            $types = $builder ? $this->builders($call['metadata']['receiver'], $call) : $this->calls->receiverCandidates($call['metadata']['receiver'])['types'];
            foreach ($types as $type) {
                $ids = $this->index->namedTypes($type);
                if (count($ids) !== 1 || ! isset($models[$ids[0]])) {
                    continue;
                }
                $site = $sites[$call['path']][$call['metadata']['offset'] ?? -1][$method] ?? null;
                if ($site === null || ! $site['metadata']['valid']) {
                    $this->notice($call, 'Scout invocation arguments are unsupported.');

                    continue;
                }
                if ($builder && $site['metadata']['root_offset'] !== null) {
                    $root = $sites[$call['path']][$site['metadata']['root_offset']]['search'] ?? null;
                    if ($root === null || ! $root['metadata']['valid']) {
                        $this->notice($call, 'Scout search builder arguments are unsupported.');

                        continue;
                    }
                }
                if (($builder || in_array($method, ['searchable', 'unsearchable', 'searchablesync', 'unsearchablesync'], true)) && ($call['metadata']['form'] ?? null) !== 'instance') {
                    $this->notice($call, 'Scout operation requires instance dispatch.');

                    continue;
                }
                if (! $builder && ! $this->standard($type, $method, $call)) {
                    continue;
                }
                if ($site['metadata']['index_override_supplied'] && $site['metadata']['index_override'] === null) {
                    $this->notice($call, 'Scout index override is dynamic or is not a supported identifier.');
                }
                $queued = in_array($method, ['searchable', 'unsearchable'], true);
                $remove = in_array($method, ['unsearchable', 'unsearchablesync', 'removeallfromsearch'], true);
                $kind = $method === 'search' ? 'prepares-scout-search' : ($builder ? 'executes-scout-search' : ($remove ? 'removes-scout-index' : 'updates-scout-index'));
                $this->index->addRelation(['from' => $call['from'], 'to' => $ids[0], 'kind' => $kind, 'path' => $call['path'], 'line' => $call['line'], 'end_line' => $call['end_line'],
                    'knowledge' => 'static', 'resolution' => 'conditional', 'metadata' => ['package' => 'laravel/scout', 'version' => $profile['version'], 'package_sources' => $profile['sources'],
                        'method' => $method, 'runtime_standard_scout_required' => true, 'engine_selection_required' => true, 'index_selection_required' => $site['metadata']['index_override'] === null,
                        'index_override_supplied' => $site['metadata']['index_override_supplied'], 'index_override' => $site['metadata']['index_override'],
                        'queue_configuration_required' => $queued, 'queue_delivery_required_if_configured' => $queued, 'stream_consumption_required' => $method === 'cursor', 'execution_proven' => false]]);
                if ($builder) {
                    if ($site['metadata']['root_offset'] === null || ! $site['metadata']['callbacks_resolved']) {
                        $this->notice($call, 'Scout callback state requires source builder identity or callable resolution.');
                    } else {
                        foreach ($site['metadata']['callbacks'] as $callback) {
                            $this->index->addRelation(['from' => $call['from'], 'to' => $callback['target'], 'kind' => 'scout-search-callback', 'path' => $call['path'],
                                'line' => $call['line'], 'end_line' => $call['end_line'], 'knowledge' => 'static', 'resolution' => 'conditional',
                                'metadata' => ['package' => 'laravel/scout', 'version' => $profile['version'], 'package_sources' => $profile['sources'],
                                    'hook' => $callback['hook'], 'method' => $method, 'root_offset' => $site['metadata']['root_offset'],
                                    'runtime_engine_uses_callback_required' => true, 'runtime_standard_scout_required' => true, 'execution_proven' => false]]);
                        }
                    }
                }
                if ($method !== 'search') {
                    foreach (['searchableUsing', $builder ? 'searchableAs' : 'indexableAs'] as $hook) {
                        $target = $this->calls->sourceMethodCandidate($type, $hook, knownTraits: [self::TRAIT]);
                        if ($target['method'] === null || $target['limited'] || $target['unresolved']
                            || ($target['method']['metadata']['visibility'] ?? null) !== 'public' || ($target['method']['metadata']['static'] ?? false)) {
                            continue;
                        }
                        $this->index->addRelation(['from' => $call['from'], 'to' => $target['method']['id'], 'kind' => 'scout-source-hook', 'path' => $call['path'],
                            'line' => $call['line'], 'end_line' => $call['end_line'], 'knowledge' => 'static', 'resolution' => 'conditional',
                            'metadata' => ['package' => 'laravel/scout', 'version' => $profile['version'], 'package_sources' => $profile['sources'],
                                'hook' => $hook, 'method' => $method, 'runtime_engine_uses_hook_required' => true,
                                'queue_delivery_required_if_configured' => $queued, 'runtime_standard_scout_required' => true, 'execution_proven' => false]]);
                    }
                }
            }
        }
    }

    /** @param array<string, list<array<string, mixed>>> $selectors
     * @return array{search_index_name: ?string, write_index_name: ?string}
     */
    private function indexNames(string $type, array $selectors): array
    {
        $names = ['search_index_name' => null, 'write_index_name' => null];
        foreach (['searchableAs' => 'search_index_name', 'indexableAs' => 'write_index_name'] as $hook => $key) {
            $target = $this->calls->sourceMethodCandidate($type, $hook, knownTraits: [self::TRAIT]);
            if ($hook === 'indexableAs' && $target['method'] === null && ! $target['unresolved'] && ! $target['limited']) {
                $names[$key] = $names['search_index_name'];

                continue;
            }
            $facts = $target['method'] !== null ? ($selectors[$target['method']['id']] ?? []) : [];
            if (count($facts) === 1 && $facts[0]['metadata']['resolved'] && ! $target['unresolved'] && ! $target['limited']
                && ($target['method']['metadata']['visibility'] ?? null) === 'public' && ! ($target['method']['metadata']['static'] ?? false)) {
                $names[$key] = $facts[0]['metadata']['selector'];
            }
        }

        return $names;
    }

    /** @param array<string, mixed> $site
     * @return list<string>
     */
    private function builders(string $receiver, array $site, int $depth = 0): array
    {
        if ($depth >= 16 || ! str_starts_with($receiver, '@return:')) {
            return [];
        }
        $descriptor = json_decode(substr($receiver, 8), true, 32);
        if (! is_array($descriptor) || count($descriptor) !== 3 || ! is_string($descriptor[0]) || ! is_string($descriptor[1])) {
            return [];
        }
        $method = strtolower($descriptor[1]);
        if ($method === 'search') {
            return array_values(array_filter($this->calls->receiverCandidates($descriptor[0])['types'], fn ($type) => $this->standard($type, 'search', $site)));
        }

        return in_array($method, CatalogScoutBuilderState::FLUENT, true) ? $this->builders($descriptor[0], $site, $depth + 1) : [];
    }

    /** @param array<string, mixed> $site */
    private function standard(string $type, string $method, array $site): bool
    {
        $selection = $this->calls->sourceMethodCandidate($type, $method, knownTraits: [self::TRAIT]);
        if ($selection['unresolved'] || $selection['limited'] || $selection['method'] !== null) {
            $this->notice($site, 'Scout source override or unresolved trait prevents standard SDK inference.');

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $site */
    private function notice(array $site, string $message, string $code = 'package_scout_analysis'): void
    {
        $this->index->diagnostics[] = ['code' => $code, 'message' => $message, 'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['id'] ?? $site['from']];
    }
}
