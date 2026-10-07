<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Snapshot composition resolves named declarations again after each per-file rebuild. */
final class CatalogIndex
{
    /** Source-known Laravel 12/13 ancestry; source shadows disable these facts. */
    public const EXTERNAL_PARENTS = [
        'saloon\\http\\solorequest' => ['Saloon\\Http\\Request', 'Saloon\\Traits\\Request\\HasConnector'],
        'saloon\\http\\connectors\\nullconnector' => ['Saloon\\Http\\Connector'],
        'illuminate\\database\\eloquent\\relations\\pivot' => ['Illuminate\\Database\\Eloquent\\Model'],
        'illuminate\\database\\eloquent\\relations\\morphpivot' => ['Illuminate\\Database\\Eloquent\\Relations\\Pivot', 'Illuminate\\Database\\Eloquent\\Model'],
    ];

    public const CONTRACT_ROLES = [
        'illuminate\\routing\\controller' => 'controller',
        'illuminate\\foundation\\http\\formrequest' => 'form-request',
        'illuminate\\contracts\\validation\\validationrule' => 'validation-rule',
        'illuminate\\contracts\\validation\\rule' => 'validation-rule',
        'illuminate\\contracts\\validation\\invokablerule' => 'validation-rule',
        'illuminate\\http\\resources\\json\\jsonresource' => 'resource',
        'illuminate\\http\\resources\\json\\resourcecollection' => 'resource-collection',
        'illuminate\\support\\serviceprovider' => 'provider',
        'illuminate\\contracts\\auth\\guard' => 'guard',
        'illuminate\\contracts\\auth\\statefulguard' => 'guard',
        'illuminate\\contracts\\auth\\userprovider' => 'user-provider',
        'illuminate\\database\\eloquent\\model' => 'model',
        'illuminate\\database\\eloquent\\relations\\pivot' => 'pivot',
        'illuminate\\database\\eloquent\\builder' => 'builder',
        'illuminate\\database\\eloquent\\scope' => 'scope',
        'illuminate\\contracts\\database\\eloquent\\castsattributes' => 'cast',
        'illuminate\\contracts\\database\\eloquent\\castsinboundattributes' => 'cast',
        'illuminate\\contracts\\database\\eloquent\\castable' => 'cast',
        'illuminate\\foundation\\bus\\dispatchable' => 'job',
        'illuminate\\console\\command' => 'command',
        'illuminate\\notifications\\notification' => 'notification',
        'illuminate\\mail\\mailable' => 'mailable',
        'illuminate\\contracts\\broadcasting\\shouldbroadcast' => 'broadcast-event',
        'illuminate\\contracts\\broadcasting\\shouldbroadcastnow' => 'broadcast-event',
        'illuminate\\view\\component' => 'blade-component',
        'illuminate\\database\\migrations\\migration' => 'migration',
        'illuminate\\database\\seeder' => 'seeder',
        'illuminate\\database\\eloquent\\factories\\factory' => 'factory',
        'phpunit\\framework\\testcase' => 'test',
        'exception' => 'exception',
        'error' => 'exception',
        'throwable' => 'exception',
        'saloon\\http\\connector' => 'connector',
        'saloon\\http\\request' => 'sdk-request',
    ];

    private const PLACEMENT_ROLES = ['Controllers' => 'controller', 'Middleware' => 'middleware', 'Actions' => 'action',
        'Services' => 'service', 'Queries' => 'query', 'Data' => 'dto', 'DTOs' => 'dto', 'ValueObjects' => 'value-object',
        'Contracts' => 'port', 'Ports' => 'port', 'Adapters' => 'adapter', 'Gateways' => 'gateway',
        'Policies' => 'policy', 'Events' => 'event', 'Listeners' => 'listener', 'Observers' => 'observer',
        'View/Composers' => 'view-composer', 'View/Creators' => 'view-creator', 'Notifications/Channels' => 'notification-channel'];

    /** @var array<string, array<string, mixed>> */
    public array $elements = [];

    /** @var list<array<string, mixed>> */
    public array $relations = [];

    /** @var list<array<string, mixed>> */
    public array $diagnostics = [];

    /** @var array<string, list<string>> */
    public array $names = [];

    /** @var array<string, list<int>> */
    public array $out = [];

    /** @var array<string, list<int>> */
    public array $in = [];

    /** @var array<string, bool> */
    private array $hierarchyLimits = [];

    /** @param list<CatalogFacts> $files
     * @param  (\Closure(string): ?string)|null  $sourceContents
     */
    public function __construct(array $files, public readonly ?\Closure $sourceContents = null)
    {
        $pendingCalls = [];
        $pendingBindings = [];
        $pendingHttp = [];
        $pendingExecution = [];
        foreach ($files as $file) {
            foreach ($file->elements as $element) {
                $row = [...$element->toArray(), 'path' => $file->path, 'knowledge' => 'static', 'role_evidence' => []];
                $row['sources'] = [...($this->elements[$element->id]['sources'] ?? []), ['path' => $file->path, 'line' => $element->line, 'end_line' => $element->endLine]];
                $this->elements[$element->id] = $row;
                if (! in_array($element->id, $this->names[strtolower($element->name)] ?? [], true)) {
                    $this->names[strtolower($element->name)][] = $element->id;
                }
            }
            foreach ($file->diagnostics as $diagnostic) {
                $this->diagnostics[] = [...$diagnostic->toArray(), 'path' => $file->path];
            }
        }
        foreach ($files as $file) {
            foreach ($file->relations as $relation) {
                $row = [...$relation->toArray(), 'path' => $file->path, 'knowledge' => 'static'];
                if (str_starts_with($row['to'], 'execution:')) {
                    $pendingExecution[] = $row;

                    continue;
                }
                if (str_starts_with($row['to'], 'http:')) {
                    $pendingHttp[] = $row;

                    continue;
                }
                if (str_starts_with($row['to'], 'container:')) {
                    $pendingBindings[] = $row;

                    continue;
                }
                if (str_starts_with($row['to'], 'dispatch:')) {
                    $pendingCalls[] = $row;

                    continue;
                }
                if (str_starts_with($row['to'], 'include:')) {
                    $candidates = [];
                    foreach ($row['metadata']['candidate_paths'] ?? [] as $path) {
                        $id = CatalogElement::identity($path, 'file', $path);
                        if (isset($this->elements[$id])) {
                            $candidates[] = $id;
                        }
                    }
                    if ($candidates === []) {
                        $this->addRelation($row);
                        $this->diagnostics[] = ['code' => 'include_outside_graph', 'message' => 'No relative include candidate is present in the declared source graph.',
                            'path' => $file->path, 'line' => $row['line'], 'subject' => $row['from']];
                    } else {
                        foreach ($candidates as $id) {
                            $candidate = [...$row, 'to' => $id, 'resolution' => 'conditional'];
                            $candidate['metadata']['candidate_count'] = count($candidates);
                            $this->addRelation($candidate);
                        }
                    }

                    continue;
                }
                if (str_starts_with($row['to'], 'file:')) {
                    $path = substr($row['to'], 5);
                    $id = CatalogElement::identity($path, 'file', $path);
                    if (isset($this->elements[$id])) {
                        $row['to'] = $id;
                    } else {
                        $row['resolution'] = 'conditional';
                        $this->diagnostics[] = ['code' => 'include_outside_graph', 'message' => 'Included file is absent or outside the declared source graph: '.$path,
                            'path' => $file->path, 'line' => $row['line'], 'subject' => $row['from']];
                    }
                }
                if (str_starts_with($row['to'], 'php:')) {
                    $target = substr($row['to'], 4);
                    $ids = $this->namedTypes($target);
                    if (count($ids) === 1) {
                        $row['to'] = $ids[0];
                    } else {
                        $row['resolution'] = 'conditional';
                        $row['metadata']['target_name'] = $target;
                        $row['metadata']['external'] = $ids === [];
                        if (count($ids) > 1) {
                            $this->diagnostics[] = ['path' => $file->path, 'line' => $row['line'], 'code' => 'ambiguous_type',
                                'message' => 'Several declarations match type '.$target.'.', 'subject' => $row['from']];
                        }
                    }
                }
                $this->addRelation($row);
            }
        }
        $this->composerStates();
        $this->classify();
        (new CatalogResourceResolver($this))->resolve();
        $calls = new CatalogCallResolver($this);
        (new CatalogDataResolver($this, $calls))->resolve();
        (new CatalogContainerResolver($this))->resolve($pendingBindings);
        foreach ($pendingCalls as &$call) {
            $receiver = $call['metadata']['receiver'];
            for ($depth = 0; str_starts_with($receiver, '@after-argument:'); $depth++) {
                $receiver = $calls->argumentReceiver($receiver, $depth);
                if ($receiver === null) {
                    unset($call['metadata']['receiver_origin'], $call['metadata']['receiver_instance_origin']);
                    $call['metadata']['this_receiver'] = false;
                    $call['metadata']['bound_callable'] = null;
                    break;
                }
            }
        }
        unset($call);
        $calls->resolve($pendingCalls);
        (new CatalogModelResolver($this, $calls))->resolve();
        (new CatalogAttributeResolver($this, $calls))->resolve();
        (new CatalogAttributeAccessResolver($this, $calls))->resolve();
        (new CatalogDatabaseLifecycleResolver($this, $calls))->resolve($pendingCalls);
        (new CatalogSaloonResolver($this, $calls))->resolve();
        (new CatalogSaloonPoolResolver($this, $calls))->resolve();
        (new CatalogCommunicationResolver($this, $calls))->resolve();
        (new CatalogPresentationResolver($this, $calls))->resolve();
        $http = new CatalogHttpResolver($this);
        $http->resolve($pendingHttp);
        (new CatalogModelSerializationResolver($this, $calls))->resolve();
        (new CatalogExecutionResolver($this, $calls))->resolve($pendingExecution, $http->routes);
        (new CatalogBroadcastResolver($this, $calls))->resolve();
        (new CatalogChannelAuthorizationResolver($this, $calls))->resolve();
        (new CatalogTestResolver($this))->resolve();
        (new CatalogMcpResolver($this, $calls))->resolve();
        (new CatalogInertiaResolver($this, $calls))->resolve();
        (new CatalogFortifyResolver($this, $calls))->resolve();
        (new CatalogAiResolver($this, $calls))->resolve();
        (new CatalogAiProviderResolver($this))->resolve();
        (new CatalogAiGatewayResolver($this))->resolve();
        (new CatalogPennantResolver($this, $calls))->resolve();
        (new CatalogScoutResolver($this, $calls))->resolve();
        (new CatalogSocialiteResolver($this, $calls))->resolve();
        (new CatalogCashierResolver($this, $calls))->resolve($http->routes);
        (new CatalogHorizonResolver($this))->resolve();
        (new CatalogReverbResolver($this, $calls))->resolve();
        (new CatalogLivewireResolver($this, $calls))->resolve();
    }

    private function composerStates(): void
    {
        $packages = [];
        foreach ($this->elements as $element) {
            if ($element['kind'] === 'composer-package') {
                $packages[$element['name']][$element['metadata']['state']] = $element;
                $reference = CatalogPackageVersions::inspectedReference($element['name'], $element['metadata']['normalized_version'] ?? '');
                if ($reference !== null
                    && ($element['metadata']['reference_state'] ?? null) === 'known'
                    && $element['metadata']['source_reference'] !== $reference) {
                    $this->diagnostics[] = ['code' => 'composer_profile_reference_mismatch', 'message' => 'Package source reference differs from the inspected contract baseline for '.$element['name'].'.',
                        'path' => $element['path'], 'line' => $element['line'], 'subject' => $element['id']];
                }
            }
        }
        foreach ($packages as $states) {
            if (! isset($states['locked'], $states['installed'])) {
                continue;
            }
            $locked = $states['locked'];
            $installed = $states['installed'];
            $left = $locked['metadata']['normalized_version'] ?? null;
            $right = $installed['metadata']['normalized_version'] ?? null;
            if ($left !== null && $right !== null && $left !== $right) {
                $this->diagnostics[] = ['code' => 'composer_version_mismatch', 'message' => 'Locked and installed versions differ for '.$installed['name'].'.',
                    'path' => $installed['path'], 'line' => $installed['line'], 'subject' => $installed['id'],
                    'sources' => [...$locked['sources'], ...$installed['sources']]];
            }
            if (($locked['metadata']['reference_state'] ?? null) === 'known' && ($installed['metadata']['reference_state'] ?? null) === 'known'
                && $locked['metadata']['source_reference'] !== $installed['metadata']['source_reference']) {
                $this->diagnostics[] = ['code' => 'composer_reference_mismatch', 'message' => 'Locked and installed source references differ for '.$installed['name'].'.',
                    'path' => $installed['path'], 'line' => $installed['line'], 'subject' => $installed['id'],
                    'sources' => [...$locked['sources'], ...$installed['sources']]];
            }
        }
        foreach ($this->elements as $element) {
            if ($element['kind'] !== 'composer-dependency') {
                continue;
            }
            foreach ($packages[$element['name']] ?? [] as $package) {
                $this->addRelation(['from' => $element['id'], 'to' => $package['id'], 'kind' => 'references-package-version',
                    'path' => $element['path'], 'line' => $element['line'], 'end_line' => $element['end_line'], 'resolution' => 'conditional', 'knowledge' => 'static',
                    'metadata' => ['state' => $package['metadata']['state'], 'constraint_evaluated' => false, 'execution_proven' => false]]);
            }
        }
    }

    /** @return list<string> */
    public function namedTypes(string $name): array
    {
        return array_values(array_filter($this->names[strtolower($name)] ?? [], fn ($id) => in_array($this->elements[$id]['kind'], ['class', 'interface', 'trait', 'enum'], true)));
    }

    public function hasContract(string $id, string $contract): bool
    {
        return strtolower($this->elements[$id]['name']) === strtolower($contract) || isset($this->contracts($id)[strtolower($contract)]);
    }

    /** @param array<string, mixed> $row */
    public function addRelation(array $row): void
    {
        $position = count($this->relations);
        $this->relations[] = $row;
        $this->out[$row['from']][] = $position;
        $this->in[$row['to']][] = $position;
    }

    private function classify(): void
    {
        foreach ($this->elements as $id => &$element) {
            if (! in_array($element['kind'], ['class', 'interface', 'trait', 'enum'], true)) {
                continue;
            }
            foreach (self::PLACEMENT_ROLES as $folder => $role) {
                if (str_contains('/'.$element['path'], '/'.$folder.'/')) {
                    $element['roles'][] = $role;
                    $element['role_evidence'][] = ['role' => $role, 'basis' => 'placement-convention', 'path' => $element['path'], 'line' => $element['line']];
                }
            }
            $contracts = $this->contracts($id);
            foreach ($contracts as $contract => $evidence) {
                if (($role = self::CONTRACT_ROLES[$contract] ?? null) !== null) {
                    $element['roles'][] = $role;
                    $element['role_evidence'][] = ['role' => $role, 'basis' => 'php-contract', 'contract' => $contract, ...$evidence];
                }
            }
            $element['roles'] = array_values(array_unique($element['roles']));
            if (isset($contracts['illuminate\\contracts\\queue\\shouldqueue']) || isset($contracts['illuminate\\contracts\\queue\\shouldqueueaftercommit'])) {
                $element['metadata']['queueable'] = true;
            }
        }
        unset($element);
    }

    /** @param array<string, bool> $seen
     * @return array<string, array{path: string, line: int}>
     */
    private function contracts(string $id, array $seen = []): array
    {
        if (isset($seen[$id]) || count($seen) >= 32) {
            if (count($seen) >= 32 && ! isset($this->hierarchyLimits[$id])) {
                $this->hierarchyLimits[$id] = true;
                $this->diagnostics[] = ['code' => 'inheritance_limit', 'message' => 'Inheritance and trait analysis reached its depth limit of 32.',
                    'path' => $this->elements[$id]['path'], 'line' => $this->elements[$id]['line'], 'subject' => $id];
            }

            return [];
        }
        $seen[$id] = true;
        $contracts = [];
        foreach ($this->out[$id] ?? [] as $position) {
            $edge = $this->relations[$position];
            if (! in_array($edge['kind'], ['extends', 'implements', 'uses-trait'], true)) {
                continue;
            }
            $target = $this->elements[$edge['to']] ?? null;
            $name = $target['name'] ?? $edge['metadata']['target_name'] ?? null;
            if (is_string($name)) {
                $contracts[strtolower($name)] = ['path' => $edge['path'], 'line' => $edge['line']];
                $ancestors = self::EXTERNAL_PARENTS[strtolower($name)] ?? [];
                if ($target === null && array_filter($ancestors, fn ($parent) => $this->namedTypes($parent) !== []) === []) {
                    foreach ($ancestors as $parent) {
                        $contracts[strtolower($parent)] = ['path' => $edge['path'], 'line' => $edge['line'], 'basis' => 'framework-declared-ancestry'];
                    }
                }
            }
            if ($target !== null) {
                $contracts += $this->contracts($edge['to'], $seen);
            }
        }

        return $contracts;
    }
}
