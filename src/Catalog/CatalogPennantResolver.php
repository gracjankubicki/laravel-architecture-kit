<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Feature resolvers run only if Pennant needs a new value for the selected scope. */
final class CatalogPennantResolver
{
    private int $operations = 0;

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(): void
    {
        $profile = CatalogPackageVersions::verified($this->index, 'laravel/pennant');
        $definitions = [];
        foreach ($this->index->elements as $site) {
            if ($site['kind'] !== 'pennant-operation') {
                continue;
            }
            if (! $this->room($site)) {
                return;
            }
            if ($profile === null || ! $site['metadata']['resolved'] || $this->shadow()) {
                $this->notice($site, 'Pennant package profile, source contract, chain or feature selector is unresolved.');

                continue;
            }
            if (! $site['metadata']['callbacks_resolved']) {
                $this->notice($site, 'Pennant callback or resolver shape requires source inspection.');
            }
            $method = $site['metadata']['method'];
            foreach ($site['metadata']['features'] as $selector) {
                if (! $this->room($site)) {
                    return;
                }
                $key = ($site['metadata']['store'] ?? '(configured store)').'::'.$selector['name'];
                $id = CatalogElement::resourceIdentity('feature-flag', $key);
                if (! isset($this->index->elements[$id])) {
                    $feature = new CatalogElement($id, $selector['name'], 'feature-flag', $site['line'], $site['end_line'], 0,
                        metadata: ['package' => 'laravel/pennant', 'version' => $profile['version'], 'store' => $site['metadata']['store'], 'class_selector' => $selector['class'], 'execution_proven' => false]);
                    $this->index->elements[$id] = [...$feature->toArray(), 'path' => $site['path'], 'knowledge' => 'static', 'role_evidence' => [],
                        'sources' => [['path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line']]]];
                    $this->index->names[strtolower($selector['name'])][] = $id;
                }
                $kind = $method === 'define' ? 'defines-feature' : (in_array($method, CatalogPennantOperations::WRITES, true) ? 'changes-feature' : 'reads-feature');
                $this->edge($site['parent'], $id, $kind, $site, $profile,
                    ['method' => $method, 'scope_supplied' => $site['metadata']['scope_supplied'], 'runtime_scope_resolution_required' => true,
                        'runtime_store_selection_required' => $site['metadata']['store'] === null, 'runtime_feature_name_resolution_required' => $selector['class']]);
                if ($method === 'define') {
                    $definitions[$id][] = $site;
                }
                foreach ($site['metadata']['callbacks'] as $callback) {
                    if (($this->index->elements[$callback['target']]['kind'] ?? null) !== 'closure') {
                        $this->notice($site, 'Pennant callback source body is unavailable.');

                        continue;
                    }
                    if ($callback['condition'] === 'resolver') {
                        $this->edge($id, $callback['target'], 'feature-value-resolver', $site, $profile,
                            ['runtime_definition_selected_required' => true, 'uncached_value_required' => true, 'compatible_scope_required' => true]);
                    } else {
                        $this->edge($site['parent'], $callback['target'], 'feature-conditional-callback', $site, $profile,
                            ['feature' => $id, 'feature_condition' => $callback['condition'], 'runtime_scope_resolution_required' => true]);
                    }
                }
                $class = ($method === 'define' ? $site['metadata']['class_definition'] : $selector['class']) ? $selector['name'] : null;
                if ($class !== null) {
                    $ids = $this->index->namedTypes($class);
                    if (count($ids) !== 1 || ($this->index->elements[$ids[0]]['metadata']['abstract'] ?? false)) {
                        $this->notice($site, 'Pennant class feature is absent, ambiguous or abstract.');

                        continue;
                    }
                    $handler = $this->calls->sourceMethodCandidate($class, 'resolve');
                    if ($handler['method'] === null && ! $handler['unresolved'] && ! $handler['limited']) {
                        $handler = $this->calls->sourceMethodCandidate($class, '__invoke');
                    }
                    if ($handler['limited'] || $handler['unresolved'] || $handler['method'] === null
                        || ($handler['method']['metadata']['visibility'] ?? null) !== 'public' || ($handler['method']['metadata']['static'] ?? false)) {
                        $this->notice($site, 'Pennant source feature resolver is inaccessible or unresolved.');

                        continue;
                    }
                    if (! in_array('feature-definition', $this->index->elements[$ids[0]]['roles'], true)) {
                        $this->index->elements[$ids[0]]['roles'][] = 'feature-definition';
                    }
                    $this->index->elements[$ids[0]]['role_evidence'][] = ['role' => 'feature-definition', 'basis' => 'pennant-class-selector', 'package' => 'laravel/pennant',
                        'version' => $profile['version'], 'path' => $site['path'], 'line' => $site['line'], 'package_sources' => $profile['sources']];
                    $this->edge($id, $handler['method']['id'], 'feature-value-resolver', $site, $profile,
                        ['runtime_class_feature_selected_required' => true, 'uncached_value_required' => true, 'compatible_scope_required' => true, 'source_before_hook_may_override' => true]);
                }
            }
        }
        foreach ($definitions as $sites) {
            if (count($sites) > 1) {
                foreach ($sites as $site) {
                    $this->notice($site, 'Pennant source definitions compete; their runtime order is unknown.');
                }
            }
        }
    }

    private function shadow(): bool
    {
        foreach (['Laravel\\Pennant\\Feature', 'Laravel\\Pennant\\FeatureManager', 'Laravel\\Pennant\\Drivers\\Decorator', 'Laravel\\Pennant\\PendingScopedFeatureInteraction'] as $type) {
            if ($this->index->namedTypes($type) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $site */
    private function room(array $site): bool
    {
        if (++$this->operations <= 4096 && ($this->operations % 128 !== 0 || ImpactExtractor::sourceLimit(0) === null)) {
            return true;
        }
        $this->notice($site, 'Pennant composition reached its operation or memory budget.', 'catalog_limit');

        return false;
    }

    /** @param array<string, mixed> $site */
    private function notice(array $site, string $message, string $code = 'package_pennant_analysis'): void
    {
        $this->index->diagnostics[] = ['code' => $code, 'message' => $message, 'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['id']];
    }

    /** @param array<string, mixed> $site
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     * @param  array<string, mixed>  $metadata
     */
    private function edge(string $from, string $to, string $kind, array $site, array $profile, array $metadata): void
    {
        $this->index->addRelation(['from' => $from, 'to' => $to, 'kind' => $kind, 'path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line'],
            'knowledge' => 'static', 'resolution' => 'conditional', 'metadata' => ['package' => 'laravel/pennant', 'version' => $profile['version'],
                'package_sources' => $profile['sources'], ...$metadata, 'execution_proven' => false]]);
    }
}
