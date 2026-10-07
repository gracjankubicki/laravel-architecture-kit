<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\Rules\Fortify\FortifyContractMap;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Fortify bindings and callback definitions are conditional extension candidates. */
final class CatalogFortifyResolver
{
    private int $operations = 0;

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(): void
    {
        $profile = CatalogPackageVersions::verified($this->index, 'laravel/fortify');
        $routes = [];
        foreach ($this->index->relations as $edge) {
            if ($edge['kind'] !== 'route-handler') {
                continue;
            }
            $receiver = $edge['metadata']['receiver'] ?? null;
            $method = $edge['metadata']['method'] ?? null;
            if (is_string($receiver) && is_string($method)) {
                $routes[strtolower($receiver.'::'.$method)][] = $edge;
            }
        }
        $registrations = [];
        foreach ($this->index->elements as $site) {
            if ($site['kind'] !== 'fortify-operation') {
                continue;
            }
            if (! $this->room($site)) {
                return;
            }
            if ($profile === null || $this->index->namedTypes('Laravel\\Fortify\\Fortify') !== [] || ! $site['metadata']['resolved']) {
                $this->notice($site, 'Fortify package profile, source facade or registration target requires inspection.');

                continue;
            }
            $contract = $site['metadata']['contract'];
            if ($contract !== null && $this->index->namedTypes($contract) !== []) {
                $this->notice($site, 'A source declaration shadows the expected Fortify contract.');

                continue;
            }
            $registrations[$site['metadata']['method']][] = $site;
            $this->edge($site['parent'], $site['id'], 'registers-fortify-extension', $site, $profile, ['runtime_registration_required' => true]);
            if ($site['metadata']['view'] !== null) {
                $viewId = CatalogElement::resourceIdentity('view', $site['metadata']['view']);
                if (! isset($this->index->elements[$viewId])) {
                    $view = new CatalogElement($viewId, $site['metadata']['view'], 'view', $site['line'], $site['end_line'], 0, metadata: ['logical_resource' => true, 'execution_proven' => false]);
                    $this->index->elements[$viewId] = [...$view->toArray(), 'path' => $site['path'], 'knowledge' => 'static', 'role_evidence' => [],
                        'sources' => [['path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line']]]];
                    $this->index->names[strtolower($view->name)][] = $viewId;
                }
                $this->edge($site['id'], $viewId, 'fortify-renders-view', $site, $profile, ['runtime_extension_selected_required' => true, 'request_required' => true]);
            }
            $callbackId = $site['metadata']['callback'];
            $action = FortifyContractMap::actionContractForRegistration($site['metadata']['method']);
            $targets = $site['metadata']['target'] === null ? [] : [$site['metadata']['target']];
            if ($callbackId !== null) {
                $callback = $this->index->elements[$callbackId] ?? null;
                if (($callback['kind'] ?? null) !== 'closure') {
                    $this->notice($site, 'Fortify callback source body is unavailable.');

                    continue;
                }
                $this->edge($site['id'], $callbackId, $action !== null ? 'fortify-action-factory' : 'fortify-request-callback', $site, $profile,
                    ['runtime_extension_selected_required' => true, 'container_resolution_required' => $action !== null, 'request_required' => true]);
                if ($action !== null) {
                    $returned = $this->calls->returnCandidates('@return:'.json_encode(['@closure:'.$callback['name'], '__invoke', true], JSON_THROW_ON_ERROR));
                    $complete = ! $returned['limited'] && $returned['values'] !== [];
                    foreach ($returned['values'] as $value) {
                        $receiver = $this->calls->receiverCandidates($value['receiver']);
                        $complete = $complete && ! $receiver['limited'] && $receiver['types'] !== [];
                        $targets = [...$targets, ...$receiver['types']];
                    }
                    if (! $complete || count(array_unique($targets)) !== 1) {
                        $this->notice($site, 'Fortify source action factory return is partial or has several candidates.');
                    }
                }
            }
            foreach (array_unique($targets) as $target) {
                if (! $this->room($site)) {
                    return;
                }
                if ($action !== null) {
                    $this->handler($site, $profile, $target, FortifyContractMap::methodFor($action) ?? '', 'fortify-action-handler', $action);
                } elseif (in_array($site['metadata']['method'], CatalogFortifyRegistrations::CALLBACKS, true)) {
                    $this->handler($site, $profile, $target, '__invoke', 'fortify-request-callback');
                }
            }
            if (! $site['metadata']['pipeline_resolved']) {
                $this->notice($site, 'Fortify authentication pipeline is partial or dynamic.');
            }
            foreach ($site['metadata']['pipeline'] as $position => $target) {
                if (! $this->room($site)) {
                    return;
                }
                $this->handler($site, $profile, $target, 'handle', 'fortify-authentication-stage', metadata: ['pipeline_position' => $position, 'runtime_pipeline_selected_required' => true]);
            }
            $controller = 'Laravel\\Fortify\\Http\\Controllers\\'.CatalogFortifyRegistrations::CONTROLLERS[$site['metadata']['method']];
            $class = substr($controller, 0, strrpos($controller, '::'));
            if ($this->index->namedTypes($class) !== []) {
                $this->notice($site, 'Source Fortify endpoint override prevents inference of standard extension dispatch.');

                continue;
            }
            foreach ($routes[strtolower($controller)] ?? [] as $route) {
                if (! $this->room($site)) {
                    return;
                }
                $this->edge($route['from'], $site['id'], 'fortify-extension-dispatch', $site, $profile,
                    ['runtime_extension_selected_required' => true, 'runtime_standard_controller_required' => true,
                        'route_sources' => [['path' => $route['path'], 'line' => $route['line'], 'end_line' => $route['end_line']]]]);
            }
        }
        foreach ($registrations as $sites) {
            if (count($sites) > 1) {
                foreach ($sites as $site) {
                    $this->notice($site, 'Several Fortify source registrations compete; the runtime selection is unknown.');
                }
            }
        }
    }

    /** @param array<string, mixed> $site
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     * @param  array<string, mixed>  $metadata
     */
    private function handler(array $site, array $profile, string $target, string $name, string $kind, ?string $contract = null, array $metadata = []): void
    {
        $ids = $this->index->namedTypes($target);
        if (count($ids) !== 1 || ($this->index->elements[$ids[0]]['metadata']['abstract'] ?? false)
            || $contract !== null && ! $this->index->hasContract($ids[0], $contract)) {
            $this->notice($site, 'Fortify target does not resolve to the required concrete source contract.');

            return;
        }
        $handler = $this->calls->sourceMethodCandidate($target, $name);
        if ($handler['limited'] || $handler['unresolved'] || $handler['method'] === null
            || ($handler['method']['metadata']['visibility'] ?? null) !== 'public' || ($handler['method']['metadata']['static'] ?? false)) {
            $this->notice($site, 'Fortify source handler is missing, ambiguous or inaccessible.');

            return;
        }
        $this->edge($site['id'], $handler['method']['id'], $kind, $site, $profile,
            ['contract' => $contract, 'runtime_extension_selected_required' => true, 'request_required' => true, ...$metadata]);
    }

    /** @param array<string, mixed> $site */
    private function room(array $site): bool
    {
        if (++$this->operations <= 4096 && ($this->operations % 128 !== 0 || ImpactExtractor::sourceLimit(0) === null)) {
            return true;
        }
        $this->notice($site, 'Fortify composition reached its operation or memory budget.', 'catalog_limit');

        return false;
    }

    /** @param array<string, mixed> $site */
    private function notice(array $site, string $message, string $code = 'package_fortify_analysis'): void
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
            'knowledge' => 'static', 'resolution' => 'conditional', 'metadata' => ['package' => 'laravel/fortify', 'version' => $profile['version'],
                'package_sources' => $profile['sources'], ...$metadata, 'execution_proven' => false]]);
    }
}
