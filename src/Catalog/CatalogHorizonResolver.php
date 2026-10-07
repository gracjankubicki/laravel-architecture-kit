<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Source environment candidates are not running supervisors or job-to-job transitions. */
final class CatalogHorizonResolver
{
    public function __construct(private readonly CatalogIndex $index) {}

    public function resolve(): void
    {
        $profile = CatalogPackageVersions::verified($this->index, 'laravel/horizon');
        $defaults = $environments = [];
        foreach ($this->index->elements as $element) {
            if (! in_array($element['kind'], ['horizon-definition', 'horizon-environment'], true)) {
                continue;
            }
            if ($profile === null || $this->index->namedTypes('Laravel\\Horizon\\ProvisioningPlan') !== []) {
                $this->notice($element, 'Horizon package version or source provisioning contract is unresolved.');

                continue;
            }
            $metadata = $element['metadata'];
            if ($element['kind'] === 'horizon-environment') {
                if ($metadata['resolved']) {
                    $environments[$metadata['environment']] ??= [];
                }

                continue;
            }
            if ($metadata['section'] === 'defaults') {
                $defaults[$metadata['supervisor']] = $element;
            } else {
                $environments[$metadata['environment']][$metadata['supervisor']] = $element;
            }
        }
        if ($profile === null) {
            return;
        }
        $count = 0;
        foreach ($environments as $environment => $definitions) {
            foreach (array_unique([...array_keys($defaults), ...array_keys($definitions)]) as $name) {
                if (++$count > 1024 || ImpactExtractor::sourceLimit(0) !== null) {
                    $this->index->diagnostics[] = ['code' => 'catalog_limit', 'message' => 'Horizon composition reached its supervisor or memory budget.', 'path' => 'config/horizon.php', 'line' => 1];

                    return;
                }
                $default = $defaults[$name] ?? null;
                $override = $definitions[$name] ?? null;
                $site = $override ?? $default;
                if ($site === null) {
                    continue;
                }
                // ProvisioningPlan uses array_replace_recursive; numeric queue entries retain the default tail.
                $options = array_replace_recursive($default['metadata']['options'] ?? [], $override['metadata']['options'] ?? []);
                $unknownDefinition = in_array('definition', [...($default['metadata']['unresolved_fields'] ?? []), ...($override['metadata']['unresolved_fields'] ?? [])], true);
                $id = CatalogElement::resourceIdentity('horizon-supervisor', $environment.':'.$name);
                $sources = array_map(fn ($definition) => ['path' => $definition['path'], 'line' => $definition['line'], 'end_line' => $definition['end_line']], array_values(array_filter([$default, $override])));
                $resource = new CatalogElement($id, $name.' in '.$environment, 'horizon-supervisor', $site['line'], $site['end_line'], $site['offset'],
                    metadata: ['logical_resource' => true, 'environment' => $environment, 'supervisor' => $name, 'options' => $options,
                        'definition_resolved' => ! $unknownDefinition, 'runtime_activation_known' => false]);
                $this->index->elements[$id] = [...$resource->toArray(), 'path' => $site['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => $sources];
                $this->index->names[strtolower($resource->name)][] = $id;
                foreach (array_filter([$default, $override]) as $definition) {
                    $this->edge($id, $definition['id'], 'horizon-supervisor-options', $definition, $profile);
                }
                $connection = $options['connection'] ?? null;
                $queues = $options['queue'] ?? null;
                if ($unknownDefinition || ! is_string($connection) || ! is_array($queues) && ! is_string($queues)) {
                    $this->notice($site, 'Horizon supervisor queue or connection selection is unresolved.');

                    continue;
                }
                foreach (is_string($queues) ? explode(',', $queues) : $queues as $queue) {
                    $queueId = CatalogElement::resourceIdentity('queue', $connection.':'.$queue);
                    if (! isset($this->index->elements[$queueId])) {
                        $element = new CatalogElement($queueId, $queue, 'queue', $site['line'], $site['end_line'], $site['offset'], metadata: ['logical_resource' => true, 'connection' => $connection]);
                        $this->index->elements[$queueId] = [...$element->toArray(), 'path' => $site['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => []];
                        $this->index->names[strtolower($queue)][] = $queueId;
                    }
                    array_push($this->index->elements[$queueId]['sources'], ...$sources);
                    $this->edge($id, $queueId, 'horizon-supervises-queue', $site, $profile);
                }
            }
        }
    }

    /** @param array<string, mixed> $site */
    private function notice(array $site, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'package_horizon_analysis', 'message' => $message, 'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['id']];
    }

    /** @param array<string, mixed> $site
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     */
    private function edge(string $from, string $to, string $kind, array $site, array $profile): void
    {
        $this->index->addRelation(['from' => $from, 'to' => $to, 'kind' => $kind, 'path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line'],
            'knowledge' => 'static', 'resolution' => 'conditional', 'metadata' => ['package' => 'laravel/horizon', 'version' => $profile['version'], 'package_sources' => $profile['sources'],
                'first_matching_environment_required' => true, 'max_processes_positive_required' => true, 'runtime_horizon_provisioning_required' => true, 'execution_proven' => false]]);
    }
}
