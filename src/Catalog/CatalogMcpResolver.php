<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** MCP registrations are candidates; listing a tool does not call its handle method. */
final class CatalogMcpResolver
{
    private const CONTRACTS = ['Laravel\\Mcp\\Server' => 'mcp-server', 'Laravel\\Mcp\\Server\\Tool' => 'mcp-tool',
        'Laravel\\Mcp\\Server\\Resource' => 'mcp-resource', 'Laravel\\Mcp\\Server\\Prompt' => 'mcp-prompt'];

    private int $operations = 0;

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(): void
    {
        $profile = CatalogPackageVersions::verified($this->index, 'laravel/mcp');
        $servers = $members = [];
        foreach ($this->index->elements as $id => $element) {
            if ($element['kind'] === 'package-operation' && $element['metadata']['method'] === 'members') {
                $members[$element['parent']][$element['metadata']['receiver']][] = $element;
            }
            if ($element['kind'] !== 'class') {
                continue;
            }
            foreach (self::CONTRACTS as $contract => $role) {
                if (! $this->index->hasContract($id, $contract)) {
                    continue;
                }
                if (! $this->room($element)) {
                    return;
                }
                if ($profile === null || $this->index->namedTypes($contract) !== []) {
                    $this->notice($element, 'MCP package version is absent, conflicting, unsupported or its framework contract is shadowed.');

                    continue;
                }
                $this->index->elements[$id]['roles'][] = $role;
                $this->index->elements[$id]['role_evidence'][] = ['role' => $role, 'basis' => 'package-contract', 'contract' => $contract,
                    'package' => 'laravel/mcp', 'version' => $profile['version'], 'path' => $element['path'], 'line' => $element['line'], 'package_sources' => $profile['sources']];
                if ($role === 'mcp-server') {
                    $servers[$id] = $element;
                }
            }
        }
        foreach ($this->index->elements as $site) {
            if ($site['kind'] !== 'package-operation' || $site['metadata']['method'] === 'members') {
                continue;
            }
            if (! $this->room($site)) {
                return;
            }
            if ($profile === null || $this->index->namedTypes('Laravel\\Mcp\\Facades\\Mcp') !== [] || ! $site['metadata']['resolved']) {
                $this->notice($site, 'MCP entrypoint version, source facade or registration selector is unresolved.');

                continue;
            }
            foreach ($site['metadata']['targets'] as $target) {
                $ids = $this->index->namedTypes($target);
                if (count($ids) !== 1 || ! isset($servers[$ids[0]]) || ($this->index->elements[$ids[0]]['metadata']['abstract'] ?? false)) {
                    $this->notice($site, 'MCP registration does not select a concrete source Server declaration.');

                    continue;
                }
                $endpointId = CatalogElement::identity($site['path'], 'mcp-endpoint', $site['metadata']['method'].':'.$site['metadata']['selector'], $site['offset']);
                $endpoint = new CatalogElement($endpointId, $site['metadata']['selector'], 'mcp-endpoint', $site['line'], $site['end_line'], $site['offset'], $site['parent'],
                    metadata: ['transport' => $site['metadata']['method'], 'package' => 'laravel/mcp', 'version' => $profile['version'], 'execution_proven' => false]);
                $this->index->elements[$endpointId] = [...$endpoint->toArray(), 'path' => $site['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => $this->sources($site)];
                $this->edge($site['parent'], $endpointId, 'registers-mcp-entrypoint', $site, $profile);
                $this->edge($endpointId, $ids[0], 'starts-mcp-server', $site, $profile, ['transport' => $site['metadata']['method'], 'runtime_registration_required' => true]);
            }
        }
        if ($profile === null) {
            return;
        }
        $descriptors = [];
        foreach ($this->index->elements as $descriptor) {
            if ($descriptor['kind'] === 'package-descriptor') {
                $descriptors[$descriptor['parent']][$descriptor['metadata']['attribute']][] = $descriptor;
            }
        }
        foreach ($this->index->elements as $id => $element) {
            if ($element['kind'] !== 'class' || array_intersect($element['roles'], ['mcp-tool', 'mcp-resource', 'mcp-prompt']) === []) {
                continue;
            }
            foreach (['Name' => 'name', 'Description' => 'description'] as $attribute => $method) {
                if (! $this->room($element)) {
                    return;
                }
                $type = 'Laravel\\Mcp\\Server\\Attributes\\'.$attribute;
                $descriptor = $this->descriptor($id, $type, $descriptors, []);
                if ($descriptor === null) {
                    continue;
                }
                $override = $this->calls->sourceMethodCandidate($element['name'], $method);
                $reader = $this->calls->sourceMethodCandidate($element['name'], 'resolveAttribute');
                if (! $descriptor['metadata']['resolved'] || $this->index->namedTypes($type) !== []
                    || $override['limited'] || $override['unresolved'] || $override['method'] !== null
                    || $reader['limited'] || $reader['unresolved'] || $reader['method'] !== null) {
                    $this->notice($descriptor, 'MCP attribute descriptor or source metadata reader override requires inspection.');

                    continue;
                }
                $this->edge($id, $descriptor['id'], 'declares-mcp-'.$method, $descriptor, $profile,
                    ['selector' => $descriptor['metadata']['selector'], 'value_hash' => $descriptor['metadata']['value_hash'], 'runtime_standard_metadata_required' => true]);
            }
        }
        foreach ($servers as $serverId => $server) {
            foreach (['tools' => 'Laravel\\Mcp\\Server\\Tool', 'resources' => 'Laravel\\Mcp\\Server\\Resource', 'prompts' => 'Laravel\\Mcp\\Server\\Prompt'] as $name => $contract) {
                if (! $this->room($server)) {
                    return;
                }
                $selection = $this->calls->receiverCandidates('@property:'.$server['name'].'#'.$name);
                if ($selection['limited'] || count($selection['sources']) > 1) {
                    $this->notice($server, 'MCP member property selection is ambiguous or limited.');

                    continue;
                }
                if ($selection['sources'] === []) {
                    continue;
                }
                $symbol = $selection['sources'][0]['symbol'];
                $properties = $this->index->names[strtolower($symbol)] ?? [];
                $property = count($properties) === 1 ? $this->index->elements[$properties[0]] : null;
                $sites = $property === null ? [] : ($members[$property['parent']][$name] ?? []);
                $sites = array_values(array_filter($sites, fn ($site) => $site['offset'] === $property['offset'] && $site['path'] === $property['path']));
                $context = $this->calls->sourceMethodCandidate($server['name'], 'createContext');
                if (count($sites) !== 1 || ! $sites[0]['metadata']['resolved'] || $context['limited'] || $context['unresolved'] || $context['method'] !== null) {
                    $this->notice($server, 'MCP source member list or createContext override requires inspection.');

                    continue;
                }
                $site = $sites[0];
                $grouped = $site['metadata']['selector'] === 'tool-search-group';
                $groupVersion = version_compare($profile['version'], '0.9.6.0', '>=');
                if ($grouped && (! $groupVersion || $this->index->namedTypes('Laravel\\Mcp\\Server\\Tools\\ToolSearch') !== []
                    || $this->index->namedTypes('Laravel\\Mcp\\Server\\Tools\\ExecuteTools') !== []
                    || $this->index->namedTypes('Laravel\\Mcp\\Server\\ToolInvoker') !== []
                    || $this->index->namedTypes('Laravel\\Mcp\\Server\\ServerContext') !== [])) {
                    $this->notice($site, 'MCP ToolSearch grouping requires its inspected package version and unshadowed contract.');

                    continue;
                }
                if ($name === 'tools' && in_array($site['metadata']['selector'], ['tool-search-key', 'unknown-member-key'], true) && $groupVersion) {
                    $this->notice($site, 'MCP ToolSearch requires a supported array group; scalar entries cannot dispatch members.');

                    continue;
                }
                if ($grouped) {
                    $invalid = count(array_unique($site['metadata']['grouped_targets'])) !== count($site['metadata']['grouped_targets']);
                    foreach ($site['metadata']['grouped_targets'] as $target) {
                        $ids = $this->index->namedTypes($target);
                        $invalid = $invalid || count($ids) !== 1 || ! $this->index->hasContract($ids[0], $contract)
                            || $this->index->namedTypes($contract) !== [] || ($this->index->elements[$ids[0]]['metadata']['abstract'] ?? false);
                    }
                    if ($invalid) {
                        $this->notice($site, 'MCP ToolSearch group contains unresolved, invalid or repeated tool declarations.');

                        continue;
                    }
                    $this->index->elements[$site['id']]['name'] = 'MCP execute_tools dispatch';
                    $this->edge($serverId, $site['id'], 'registers-mcp-member', $site, $profile,
                        ['member_kind' => $name, 'dispatch' => 'execute_tools', 'runtime_unique_tool_names_required' => true]);
                }
                foreach ([...array_map(fn ($target) => [$target, false], $site['metadata']['targets']),
                    ...array_map(fn ($target) => [$target, true], $site['metadata']['grouped_targets'])] as [$target, $viaGroup]) {
                    if (! $this->room($site)) {
                        return;
                    }
                    $ids = $this->index->namedTypes($target);
                    if (count($ids) !== 1 || ! $this->index->hasContract($ids[0], $contract) || $this->index->namedTypes($contract) !== [] || ($this->index->elements[$ids[0]]['metadata']['abstract'] ?? false)) {
                        $this->notice($site, 'MCP member does not have the required concrete source package contract.');

                        continue;
                    }
                    $this->edge($serverId, $ids[0], 'registers-mcp-member', $site, $profile, ['member_kind' => $name, 'runtime_member_eligible_required' => true, 'execution_proven' => false]);
                    $handler = $this->calls->sourceMethodCandidate($target, 'handle');
                    if ($handler['limited'] || $handler['unresolved'] || $handler['method'] === null || ($handler['method']['metadata']['visibility'] ?? null) !== 'public' || ($handler['method']['metadata']['static'] ?? false)) {
                        $this->notice($site, 'MCP source handle declaration is absent, ambiguous or inaccessible.');

                        continue;
                    }
                    $this->edge($viaGroup ? $site['id'] : $serverId, $handler['method']['id'], 'mcp-member-handler', $site, $profile,
                        ['member' => $ids[0], 'member_kind' => $name, 'request_selects_member_required' => true, 'runtime_member_eligible_required' => true, 'runtime_standard_dispatch_required' => true,
                            'dispatch' => $viaGroup ? 'execute_tools' : 'direct', 'runtime_unique_tool_names_required' => $grouped,
                            'runtime_group_call_reached_required' => $viaGroup]);
                }
            }
        }
    }

    /** @param array<string, array<string, list<array<string, mixed>>>> $descriptors
     * @param  array<string, bool>  $seen
     * @return array<string, mixed>|null
     */
    private function descriptor(string $id, string $type, array $descriptors, array $seen): ?array
    {
        $element = $this->index->elements[$id] ?? null;
        if ($element === null || $element['kind'] !== 'class') {
            return null;
        }
        if (isset($seen[$id]) || count($seen) >= 128 || ! $this->room($element)) {
            $this->notice($element, 'MCP descriptor inheritance is cyclic or limited.');

            return null;
        }
        $seen[$id] = true;
        $local = $descriptors[$id][$type] ?? [];
        if ($local !== []) {
            if (count($local) !== 1) {
                $this->notice($element, 'Repeated MCP attributes cannot provide a valid runtime descriptor.');

                return null;
            }

            return $local[0];
        }
        $parents = [];
        foreach ($this->index->out[$id] ?? [] as $edgeId) {
            $edge = $this->index->relations[$edgeId];
            if ($edge['kind'] === 'extends') {
                $parents[] = $edge['to'];
            }
        }
        if (count($parents) > 1) {
            $this->notice($element, 'MCP descriptor ancestor is ambiguous.');

            return null;
        }

        return $parents === [] ? null : $this->descriptor($parents[0], $type, $descriptors, $seen);
    }

    /** @param array<string, mixed> $site */
    private function room(array $site): bool
    {
        if (++$this->operations <= 4096 && ($this->operations % 128 !== 0 || ImpactExtractor::sourceLimit(0) === null)) {
            return true;
        }
        $this->index->diagnostics[] = ['code' => 'catalog_limit', 'message' => 'MCP composition reached its operation or memory budget.', 'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['id']];

        return false;
    }

    /** @param array<string, mixed> $site */
    private function notice(array $site, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'package_mcp_analysis', 'message' => $message, 'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['id']];
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
    private function edge(string $from, string $to, string $kind, array $site, array $profile, array $metadata = []): void
    {
        $this->index->addRelation(['from' => $from, 'to' => $to, 'kind' => $kind, 'path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line'],
            'knowledge' => 'static', 'resolution' => 'conditional', 'metadata' => ['package' => 'laravel/mcp', 'version' => $profile['version'], 'package_sources' => $profile['sources'], ...$metadata, 'execution_proven' => false]]);
    }
}
