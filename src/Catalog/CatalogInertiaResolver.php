<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Page/prop candidates refer to source declarations, never a rendered response. */
final class CatalogInertiaResolver
{
    private int $operations = 0;

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(): void
    {
        $profile = CatalogPackageVersions::verified($this->index, 'inertiajs/inertia-laravel');
        $renders = $shares = [];
        foreach ($this->index->elements as $site) {
            if ($site['kind'] !== 'inertia-operation') {
                continue;
            }
            if (! $this->room($site)) {
                return;
            }
            if ($site['metadata']['form'] === 'method') {
                $types = $this->index->namedTypes($site['metadata']['receiver']);
                if (count($types) !== 1 || ! $this->index->hasContract($types[0], 'Inertia\\Middleware')) {
                    continue;
                }
                $method = $this->index->elements[$site['parent']] ?? null;
                $handler = $this->calls->sourceMethodCandidate($site['metadata']['receiver'], 'handle');
                if (($method['metadata']['visibility'] ?? null) !== 'public' || ($method['metadata']['static'] ?? false)
                    || $handler['limited'] || $handler['unresolved'] || $handler['method'] !== null) {
                    $this->notice($site, 'Inertia middleware share declaration or source handle override requires inspection.');

                    continue;
                }
            }
            $shadow = false;
            foreach (['Inertia\\Inertia', 'Inertia\\ResponseFactory', 'Inertia\\Response', 'Inertia\\PropsResolver', 'Inertia\\Middleware'] as $type) {
                $shadow = $shadow || $this->index->namedTypes($type) !== [];
            }
            foreach ($site['metadata']['functions'] as $function) {
                foreach ($this->index->names[strtolower($function)] ?? [] as $id) {
                    $shadow = $shadow || $this->index->elements[$id]['kind'] === 'function';
                }
            }
            if ($profile === null || $shadow || ! $site['metadata']['resolved']) {
                $this->notice($site, 'Inertia package profile, source contract/helper or component selector is unresolved.');

                continue;
            }
            if (! $site['metadata']['callbacks_resolved']) {
                $this->notice($site, 'Some Inertia prop values or callable shapes require source inspection.');
            }
            if (in_array($site['metadata']['method'], ['share', 'shareonce'], true)) {
                $shares[] = $site;
                $this->edge($site['parent'], $site['id'], $site['metadata']['form'] === 'method' ? 'declares-inertia-middleware-shared-props' : 'registers-inertia-shared-props',
                    $site, $profile, ['runtime_registration_required' => true, 'runtime_middleware_active_required' => $site['metadata']['form'] === 'method']);

                continue;
            }
            $renders[] = $site;
        }
        if ($profile === null) {
            return;
        }
        foreach ($renders as $site) {
            if (! $this->room($site)) {
                return;
            }
            $pageId = CatalogElement::identity($site['path'], 'inertia-page', $site['metadata']['selector'], $site['offset']);
            $page = new CatalogElement($pageId, $site['metadata']['selector'], 'inertia-page', $site['line'], $site['end_line'], $site['offset'], $site['parent'],
                metadata: ['package' => 'inertiajs/inertia-laravel', 'version' => $profile['version'], 'source_component' => true, 'frontend_file_resolved' => false, 'execution_proven' => false]);
            $this->index->elements[$pageId] = [...$page->toArray(), 'path' => $site['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => $this->sources($site)];
            $this->index->names[strtolower($page->name)][] = $pageId;
            $this->edge($site['parent'], $pageId, 'renders-inertia-page', $site, $profile,
                ['runtime_standard_factory_required' => true, 'runtime_component_resolution_required' => true]);
            if (! $this->callbacks($pageId, $site, $site, $profile, false)) {
                return;
            }
            foreach ($shares as $share) {
                if (! $this->room($share) || ! $this->callbacks($pageId, $share, $site, $profile, true)) {
                    return;
                }
            }
        }
    }

    /** @param array<string, mixed> $site
     * @param  array<string, mixed>  $render
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     */
    private function callbacks(string $page, array $site, array $render, array $profile, bool $shared): bool
    {
        foreach ($site['metadata']['callbacks'] as $callback) {
            if (! $this->room($site)) {
                return false;
            }
            if (($this->index->elements[$callback['target']]['kind'] ?? null) !== 'closure') {
                $this->notice($site, 'Inertia source prop callback body is unavailable.');

                continue;
            }
            if (! ($this->index->elements[$callback['target']]['metadata']['return_sites']['complete'] ?? false)) {
                $this->notice($site, 'Inertia prop callback is a source generator or its body analysis is incomplete; response resolution does not prove consumption.');

                continue;
            }
            $this->edge($page, $callback['target'], $shared ? 'inertia-shared-callback' : 'inertia-prop-callback',
                [...$site, 'line' => $callback['line'], 'end_line' => $callback['end_line']], $profile,
                ['mode' => $callback['mode'], 'response_resolution_required' => true, 'request_prop_selection_required' => true,
                    'runtime_shared_registration_required' => $shared, 'runtime_standard_prop_resolution_required' => true,
                    'runtime_middleware_active_required' => $site['metadata']['form'] === 'method',
                    'render_site' => $render['id'], 'render_sources' => $this->sources($render)]);
        }

        return true;
    }

    /** @param array<string, mixed> $site */
    private function room(array $site): bool
    {
        if (++$this->operations <= 4096 && ($this->operations % 128 !== 0 || ImpactExtractor::sourceLimit(0) === null)) {
            return true;
        }
        $this->notice($site, 'Inertia composition reached its operation or memory budget.', 'catalog_limit');

        return false;
    }

    /** @param array<string, mixed> $site */
    private function notice(array $site, string $message, string $code = 'package_inertia_analysis'): void
    {
        $this->index->diagnostics[] = ['code' => $code, 'message' => $message, 'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['id']];
    }

    /** @param array<string, mixed> $site
     * @return list<array<string, mixed>>
     */
    private function sources(array $site): array
    {
        return [['path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line']]];
    }

    /** @param array<string, mixed> $site
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     * @param  array<string, mixed>  $metadata
     */
    private function edge(string $from, string $to, string $kind, array $site, array $profile, array $metadata): void
    {
        $this->index->addRelation(['from' => $from, 'to' => $to, 'kind' => $kind, 'path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line'],
            'knowledge' => 'static', 'resolution' => 'conditional', 'metadata' => ['package' => 'inertiajs/inertia-laravel', 'version' => $profile['version'],
                'package_sources' => $profile['sources'], ...$metadata, 'execution_proven' => false]]);
    }
}
