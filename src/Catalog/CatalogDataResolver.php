<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\DataCatalog;

/** Composes current declarations, including reused cache entries, without source reads. */
final class CatalogDataResolver
{
    private readonly CatalogGlobalScopeResolver $globalScopes;

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls)
    {
        $this->globalScopes = new CatalogGlobalScopeResolver($index, $calls);
    }

    public function resolve(): void
    {
        $catalog = new DataCatalog;
        foreach ($this->index->elements as $element) {
            if (! isset($element['metadata']['data_declaration'])) {
                continue;
            }
            $parents = [];
            foreach ($this->index->out[$element['id']] ?? [] as $position) {
                $relation = $this->index->relations[$position];
                if (in_array($relation['kind'], ['extends', 'uses-trait'], true)) {
                    $parent = $this->index->elements[$relation['to']]['name'] ?? substr($relation['to'], 4);
                    $parents[] = $parent;
                    $ancestors = CatalogIndex::EXTERNAL_PARENTS[strtolower($parent)] ?? [];
                    if (! isset($this->index->elements[$relation['to']]) && array_filter($ancestors, fn ($name) => $this->index->namedTypes($name) !== []) === []) {
                        array_push($parents, ...$ancestors);
                    }
                }
            }
            $catalog->classes[strtolower($element['name'])] = ['name' => $element['name'], 'parents' => $parents,
                ...$element['metadata']['data_declaration'], 'ambiguous' => count($this->index->namedTypes($element['name'])) !== 1];
        }
        foreach ($this->index->elements as $element) {
            if ($element['kind'] !== 'class' || ! isset($element['metadata']['data_declaration'])
                || count($this->index->namedTypes($element['name'])) !== 1
                || ! $catalog->inherits($element['name'], 'Illuminate\\Database\\Eloquent\\Model')) {
                continue;
            }
            // A source declaration shadowing Laravel's Model does not prove the framework contract.
            if ($this->index->namedTypes('Illuminate\\Database\\Eloquent\\Model') !== []) {
                $this->diagnostic($element, 'Source declaration shadows the Eloquent Model contract.');

                continue;
            }
            $sources = $this->declarationSources($element, $catalog);
            if ($sources === null) {
                $this->diagnostic($element, 'Model hierarchy is ambiguous, cyclic or too deep; selectors require inspection.');

                continue;
            }
            $this->casts($element, $sources);
            $model = $catalog->model($element['name']);
            if (! $this->selectedModelSelectors($element, $model, $sources)) {
                continue;
            }
            $table = $model['tables'][0]['table'];
            $connection = $model['connection'];
            if ($table === null || $connection['kind'] === 'dynamic') {
                $this->diagnostic($element, 'Model table or connection selector is dynamic; no conventional table is inferred.');

                continue;
            }
            $connectionName = $connection['name'] ?? '(default)';
            $connectionId = $this->resource('database-connection', $connectionName, $element, ['selector' => $connection, 'runtime_configuration_known' => false]);
            $tableId = $this->resource('table', $table, $element, ['connection' => $connection, 'schema_state_known' => false], $connectionName.'::'.$table);
            $this->relation($element, $tableId, 'maps-table', ['connection' => $connection, 'execution_proven' => false, 'selector_sources' => $sources]);
            $this->relation($element, $connectionId, 'uses-database-connection', ['execution_proven' => false, 'selector_sources' => $sources]);
            $this->relation($element, $connectionId, 'table-connection', ['execution_proven' => false], $tableId);
        }
        foreach ($this->index->elements as $element) {
            if ($element['kind'] === 'data-operation') {
                $this->operation($element);
            }
        }
    }

    /** @param array<string, mixed> $element
     * @param  array<string, mixed>  $model
     * @param  list<array<string, mixed>>  $sources
     */
    private function selectedModelSelectors(array $element, array &$model, array &$sources): bool
    {
        $factory = $this->index->hasContract($element['id'], 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory')
            && $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Factories\\HasFactory') === [];
        foreach (['getTable', 'getConnectionName'] as $name) {
            $selection = $this->calls->sourceMethodCandidate($element['name'], $name, knownHasFactory: $factory);
            $method = $selection['method'];
            if ($selection['unresolved'] || $selection['limited'] || $method !== null && ($method['metadata']['visibility'] !== 'public' || $method['metadata']['static'] || $method['metadata']['abstract'])) {
                $this->diagnostic($element, 'Model selector method selection is unresolved or invalid.');

                return false;
            }
            if ($method === null) {
                continue;
            }
            $method = [...$this->index->elements[$method['id']], 'metadata' => $method['metadata']];
            $original = strtolower(substr($method['name'], (int) strrpos($method['name'], '::') + 2));
            $declaration = $this->index->elements[$method['parent']];
            $value = $declaration['metadata']['data_declaration']['methods'][$original]['literal_return'] ?? ['dynamic' => true];
            if (! isset($declaration['metadata']['data_declaration']['methods'][$original])) {
                $value = $this->deferredReturn($method);
                if ($value !== null && (! is_string($value) || preg_match('/\A[a-zA-Z0-9_.-]{1,256}\z/D', $value) !== 1)) {
                    $value = ['dynamic' => true];
                }
            }
            // Null is a valid default connection selector, but not a table name.
            if ($name === 'getConnectionName' && isset($declaration['metadata']['data_declaration']['methods'][$original])
                && $declaration['metadata']['data_declaration']['methods'][$original]['literal_return'] === null) {
                $value = null;
            }
            if ($name === 'getTable') {
                $model['tables'][0]['table'] = is_string($value) ? $value : null;
            } else {
                $model['connection'] = DataCatalog::connection($value);
            }
            $sources[] = ['path' => $method['path'], 'line' => $method['line'], 'end_line' => $method['end_line'], 'symbol' => $method['name'], 'selector' => $name];
        }

        return true;
    }

    /** @param array<string, mixed> $method */
    private function deferredReturn(array $method): mixed
    {
        $row = $method['metadata']['source_return'] ?? null;
        if ($row === null || $this->index->sourceContents === null) {
            return ['dynamic' => true];
        }
        try {
            $source = ($this->index->sourceContents)($method['path']);
        } catch (\Throwable) {
            $this->diagnostic($method, 'Selected alias source could not be read.');

            return ['dynamic' => true];
        }
        if (! is_string($source) || strlen($source) > 2 * 1024 * 1024) {
            $this->diagnostic($method, 'Selected alias source is unavailable or exceeds its byte budget.');

            return ['dynamic' => true];
        }
        if (! hash_equals($row['hash'], hash('sha256', $source))) {
            $this->index->diagnostics[] = ['code' => 'changed_inputs', 'message' => 'Selected alias source changed after extraction.', 'path' => $method['path'], 'line' => $method['line']];

            return ['dynamic' => true];
        }

        return CatalogSourceReturn::decode($row['value'], $source);
    }

    /** @param array<string, mixed> $model
     * @param  list<array<string, mixed>>  $sources
     */
    private function casts(array $model, array $sources): void
    {
        $selected = ['property' => null, 'method' => null];
        $proofs = [];
        foreach ($sources as $source) {
            $ids = $this->index->namedTypes($source['symbol']);
            if (count($ids) !== 1) {
                continue;
            }
            $declaration = $this->index->elements[$ids[0]];
            $descriptor = $declaration['metadata']['data_casts']['property'] ?? null;
            if ($selected['property'] === null && $descriptor !== null) {
                $selected['property'] = $descriptor;
                $memberIds = $this->index->names[strtolower($source['symbol'].'::$casts')] ?? [];
                $member = count($memberIds) === 1 ? $this->index->elements[$memberIds[0]] : $declaration;
                $proofs['property'] = ['path' => $member['path'], 'line' => $member['line'], 'end_line' => $member['end_line'], 'symbol' => $member['name']];
            }
        }
        $factory = $this->index->hasContract($model['id'], 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory')
            && $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Factories\\HasFactory') === [];
        $selection = $this->calls->sourceMethodCandidate($model['name'], 'casts', knownHasFactory: $factory);
        $candidate = $selection['method'];
        if ($selection['unresolved'] || $selection['limited'] || $candidate !== null && ($candidate['metadata']['visibility'] === 'private' || $candidate['metadata']['static'] || $candidate['metadata']['abstract'])) {
            $selected['method'] = ['resolved' => false, 'entries' => []];
        } elseif ($candidate !== null) {
            $member = $this->index->elements[$candidate['id']];
            $owner = $this->index->elements[$member['parent']];
            $selected['method'] = str_ends_with(strtolower($member['name']), '::casts')
                ? ($owner['metadata']['data_casts']['method'] ?? ['resolved' => false, 'entries' => []])
                : CatalogCasts::describe($this->deferredReturn($member));
            $proofs['method'] = ['path' => $member['path'], 'line' => $member['line'], 'end_line' => $member['end_line'], 'symbol' => $member['name']];
        }
        if ($selected['property'] !== null && ! $selected['property']['resolved'] || $selected['method'] !== null && ! $selected['method']['resolved']) {
            $this->diagnostic($model, 'Model cast declarations are dynamic or partially resolved; the effective cast map is unknown.');
            $this->index->elements[$model['id']]['metadata']['casts_resolved'] = false;

            return;
        }
        $effective = [];
        foreach ($selected as $kind => $descriptor) {
            foreach ($descriptor['entries'] ?? [] as $entry) {
                $effective[$entry['attribute']] = [...$entry, 'source' => $proofs[$kind]];
            }
        }
        $this->index->elements[$model['id']]['metadata']['declared_casts'] = array_values($effective);
        $this->index->elements[$model['id']]['metadata']['casts_resolved'] = true;
        $this->index->elements[$model['id']]['metadata']['runtime_cast_mutations_known'] = false;
        foreach ($effective as $entry) {
            if ($entry['kind'] !== 'class') {
                continue;
            }
            $ids = $this->index->namedTypes($entry['cast']);
            $verified = count($ids) === 1 && $this->index->elements[$ids[0]]['kind'] === 'enum';
            foreach (['Illuminate\\Contracts\\Database\\Eloquent\\CastsAttributes', 'Illuminate\\Contracts\\Database\\Eloquent\\CastsInboundAttributes',
                'Illuminate\\Contracts\\Database\\Eloquent\\Castable'] as $contract) {
                if (count($ids) === 1 && $this->index->namedTypes($contract) === [] && $this->index->hasContract($ids[0], $contract)) {
                    $verified = true;
                }
            }
            $source = [...$model, ...$entry['source']];
            $this->relation($source, count($ids) === 1 ? $ids[0] : 'php:'.$entry['cast'], $verified ? 'uses-cast' : 'references-cast',
                ['attribute' => $entry['attribute'], 'contract_verified' => $verified, 'execution_proven' => false]);
            if (! $verified) {
                $this->diagnostic($source, 'Configured cast class contract is unresolved or ambiguous.');
            }
        }
    }

    /** @param array<string, mixed> $site */
    private function operation(array $site): void
    {
        $meta = $site['metadata'];
        $receiver = $meta['receiver'];
        $facade = in_array($receiver, ['Illuminate\\Support\\Facades\\DB', 'Illuminate\\Support\\Facades\\Schema'], true);
        $model = null;
        $selectorSources = [];
        if ($facade) {
            if ($this->index->namedTypes($receiver) !== []) {
                $this->diagnostic($site, 'Source declaration shadows the database facade; no operation is inferred.');

                return;
            }
        } else {
            $ids = $this->index->namedTypes($receiver);
            if (count($ids) !== 1 || ! $this->index->hasContract($ids[0], 'Illuminate\\Database\\Eloquent\\Model')) {
                return;
            }
            foreach ($this->index->out[$ids[0]] ?? [] as $position) {
                $edge = $this->index->relations[$position];
                if ($edge['kind'] === 'maps-table') {
                    $model = $this->index->elements[$edge['to']];
                    $selectorSources = $edge['metadata']['selector_sources'];
                    break;
                }
            }
            if ($model === null) {
                $this->diagnostic($site, 'Model database selectors are unresolved.');

                return;
            }
            if (! $this->queryScopes($site, $this->index->elements[$ids[0]], $meta) || ! $this->globalScopes->apply($site, $this->index->elements[$ids[0]])) {
                return;
            }

        }
        $tables = $meta['tables'];
        $connection = $meta['explicit_connection'] || $model === null ? $meta['connection'] : $model['metadata']['connection'];
        if ($tables === [] && $model !== null && ! $meta['sql']) {
            $tables = [['table' => $model['name'], 'role' => 'primary']];
        }
        if ($meta['unsupported'] !== [] || $tables === [] || $connection['kind'] === 'dynamic') {
            $this->diagnostic($site, 'Data operation is partially resolved; unsupported chain or dynamic database selectors require inspection.');
        }
        if ($connection['kind'] === 'dynamic' || $site['parent'] === null || array_diff($meta['unsupported'], ['partial-sql']) !== []) {
            return;
        }
        foreach ($tables as $table) {
            if (! is_string($table['table'])) {
                $this->diagnostic($site, 'Data operation table is dynamic.');

                continue;
            }
            $name = $connection['name'] ?? '(default)';
            $tableId = $this->resource('table', $table['table'], $site, ['connection' => $connection, 'schema_state_known' => false], $name.'::'.$table['table']);
            $connectionId = $this->resource('database-connection', $name, $site, ['selector' => $connection, 'runtime_configuration_known' => false]);
            $this->relation($site, $connectionId, 'table-connection', ['execution_proven' => false], $tableId);
            $kinds = $table['role'] === 'primary' ? $meta['kinds'] : [$table['role']];
            foreach ($kinds as $kind) {
                $this->relation($site, $tableId, ['read' => 'reads', 'write' => 'writes', 'schema' => 'changes-schema', 'schema-read' => 'reads-schema'][$kind],
                    ['operation' => $meta['operation'], 'conditions' => $meta['conditions'], 'connection' => $connection,
                        'table_role' => $table['role'], 'selector_sources' => $selectorSources, 'operation_source' => $site['id'],
                        'partial' => $meta['unsupported'] !== [], 'execution_proven' => false, 'schema_state_known' => false], $site['parent']);
            }
        }
    }

    /** @param array<string, mixed> $site
     * @param  array<string, mixed>  $model
     * @param  array<string, mixed>  $meta
     */
    private function queryScopes(array $site, array $model, array &$meta): bool
    {
        $knownFactory = $this->index->hasContract($model['id'], 'Illuminate\Database\Eloquent\Factories\HasFactory')
            && $this->index->namedTypes('Illuminate\Database\Eloquent\Factories\HasFactory') === [];
        $builder = $this->queryBuilder($model, $knownFactory);
        if (! $builder['resolved']) {
            $this->diagnostic($site, 'Source query builder selection is unresolved or custom.');

            return false;
        }
        foreach (['hasNamedScope', 'callNamedScope', 'newQuery', 'newQueryWithoutScopes', 'newModelQuery', 'newBaseQueryBuilder', '__call', '__callStatic'] as $hook) {
            $selected = $this->calls->sourceMethodCandidate($model['name'], $hook, knownHasFactory: $knownFactory);
            if ($selected['method'] !== null || $selected['unresolved'] || $selected['limited']) {
                $this->diagnostic($site, 'Source query dispatch override prevents standard Eloquent inference.');

                return false;
            }
        }
        if (in_array($meta['operation'], ['firstorcreate', 'createorfirst', 'updateorcreate', 'incrementorcreate'], true)) {
            $builderHooks = ['first', 'get', 'create', 'createOrFirst', 'newModelInstance', 'withSavepointIfNeeded'];
            if ($meta['operation'] !== 'createorfirst') {
                $builderHooks[] = 'firstOrCreate';
            }
            foreach ($builder['type'] === null ? [] : $builderHooks as $hook) {
                $selected = $this->calls->sourceMethodCandidate($builder['type'], $hook);
                if ($selected['method'] !== null || $selected['unresolved'] || $selected['limited']) {
                    $this->diagnostic($site, 'Source compound query builder pipeline requires composition.');

                    return false;
                }
            }
            $modelHooks = ['newInstance', 'fill', 'save'];
            if ($meta['operation'] === 'incrementorcreate') {
                $modelHooks[] = 'increment';
            }
            foreach ($modelHooks as $hook) {
                $selected = $this->calls->sourceMethodCandidate($model['name'], $hook, knownHasFactory: $knownFactory);
                if ($selected['method'] !== null || $selected['unresolved'] || $selected['limited']) {
                    $this->diagnostic($site, 'Source compound model persistence pipeline requires composition.');

                    return false;
                }
            }
        }
        $scopeEdges = [];
        $builderEdges = [];
        foreach ($meta['chain_methods'] as $position => $name) {
            $direct = $this->calls->sourceMethodCandidate($model['name'], $name, knownHasFactory: $knownFactory);
            if ($direct['unresolved'] || $direct['limited']) {
                $this->diagnostic($site, 'Source query member selection is unresolved.');

                return false;
            }
            if ($builder['type'] !== null && ! ($position === 0 && in_array($name, ['query', 'on', 'onwriteconnection'], true))) {
                $selected = $this->calls->sourceMethodCandidate($builder['type'], $name);
                $member = $selected['method'];
                if ($selected['unresolved'] || $selected['limited']) {
                    $this->diagnostic($site, 'Custom builder member selection is unresolved.');

                    return false;
                }
                if ($member !== null) {
                    if (($member['metadata']['visibility'] ?? null) !== 'public' || $member['metadata']['static'] || $member['metadata']['abstract']
                        || ! ($member['metadata']['query_transform'] ?? false) || $position === count($meta['chain_methods']) - 1
                        || $direct['method'] !== null && $position === 0 && $direct['method']['metadata']['visibility'] === 'public') {
                        $this->diagnostic($site, 'Custom builder method return, visibility or terminal override requires inspection.');

                        return false;
                    }
                    $builderEdges[] = $member['id'];
                    $meta['unsupported'] = array_values(array_diff($meta['unsupported'], [$name]));

                    continue;
                }
            }
            if (! in_array($name, $meta['unsupported'], true) || $position === count($meta['chain_methods']) - 1) {
                if ($direct['method'] !== null) {
                    $this->diagnostic($site, 'Source method overrides query behavior; its data effects require composition.');

                    return false;
                }

                continue;
            }
            $legacy = $this->calls->sourceMethodCandidate($model['name'], 'scope'.$name, knownHasFactory: $knownFactory);
            $selection = ($direct['method']['metadata']['eloquent_scope']['style'] ?? null) === 'attribute' ? $direct : $legacy;
            $method = $selection['method'];
            $scope = $method['metadata']['eloquent_scope'] ?? null;
            if ($method === null || $selection['unresolved'] || $selection['limited'] || $scope === null
                || ! $scope['preserves_query'] || ($method['metadata']['visibility'] ?? null) === 'private' || ($method['metadata']['abstract'] ?? false)
                || $scope['style'] === 'attribute' && $this->index->namedTypes('Illuminate\Database\Eloquent\Attributes\Scope') !== []
                || $position === 0 && $scope['style'] === 'attribute' && $method['metadata']['visibility'] === 'public' && ! $method['metadata']['static']
                || $direct['method'] !== null && $scope['style'] !== 'attribute') {
                $this->diagnostic($site, 'Local scope return, visibility or framework dispatch requires inspection.');

                return false;
            }
            $scopeEdges[] = $method['id'];
            $meta['unsupported'] = array_values(array_diff($meta['unsupported'], [$name]));
        }
        foreach ($builderEdges as $target) {
            $this->relation($site, $target, 'invokes-query-builder', ['execution_proven' => false, 'builder_type' => $builder['type'], 'selector_source' => $builder['source'], 'conditions' => ['The source-selected Eloquent builder and arguments must be valid.']], $site['parent']);
        }
        foreach ($scopeEdges as $target) {
            $this->relation($site, $target, 'invokes-query-scope', ['execution_proven' => false, 'conditions' => ['Standard Eloquent dispatch must select this scope and its arguments must be valid; runtime macros can replace dispatch.']], $site['parent']);
            $this->index->elements[$target]['roles'][] = 'scope';
        }

        return true;
    }

    /** @param array<string, mixed> $model
     * @return array{resolved: bool, type: ?string, source: ?array<string, mixed>}
     */
    private function queryBuilder(array $model, bool $knownFactory): array
    {
        $failure = ['resolved' => false, 'type' => null, 'source' => null];
        $default = ['resolved' => true, 'type' => null, 'source' => null];
        if ($this->index->namedTypes('Illuminate\Database\Eloquent\Builder') !== []) {
            return $failure;
        }
        $method = $this->calls->sourceMethodCandidate($model['name'], 'newEloquentBuilder', knownHasFactory: $knownFactory);
        if ($method['unresolved'] || $method['limited']) {
            return $failure;
        }
        $source = $model;
        if ($method['method'] !== null) {
            $source = $this->index->elements[$method['method']['id']];
            $selector = $this->index->elements[$source['parent']]['metadata']['query_builder']['method'] ?? null;
        } else {
            $hook = $this->calls->sourceMethodCandidate($model['name'], 'resolveCustomBuilderClass', knownHasFactory: $knownFactory);
            if ($hook['method'] !== null || $hook['unresolved'] || $hook['limited']) {
                return $failure;
            }
            $selector = $model['metadata']['query_builder']['attribute'] ?? null;
            if ($selector !== null && $this->index->namedTypes('Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder') !== []) {
                return $failure;
            }
            if ($selector === null) {
                $properties = $this->builderProperties($model['id']);
                if ($properties === null || count($properties) > 1) {
                    return $failure;
                }
                if ($properties === []) {
                    return $default;
                }
                $selector = $properties[0]['selector'];
                $source = $this->index->elements[$properties[0]['id']];
            }
        }
        if ($selector === null || ! $selector['resolved'] || $selector['type'] === null) {
            return $failure;
        }
        if ($selector['type'] === 'Illuminate\Database\Eloquent\Builder') {
            return [...$default, 'source' => ['path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['end_line']]];
        }
        $ids = $this->index->namedTypes($selector['type']);
        if (count($ids) !== 1 || ! $this->index->hasContract($ids[0], 'Illuminate\Database\Eloquent\Builder')) {
            return $failure;
        }
        foreach (['__construct', '__call', 'setModel', 'getModel', 'toBase', 'getQuery', 'applyScopes', 'withGlobalScope', 'withoutGlobalScope', 'withoutGlobalScopes', ...CatalogQueryScopes::CONSTRAINTS] as $hook) {
            $selection = $this->calls->sourceMethodCandidate($selector['type'], $hook);
            if ($selection['method'] !== null || $selection['unresolved'] || $selection['limited']) {
                return $failure;
            }
        }

        return ['resolved' => true, 'type' => $selector['type'], 'source' => ['path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['end_line']]];
    }

    /** @param array<string, true> $seen
     * @return list<array{id: string, selector: array<string, mixed>}>|null
     */
    private function builderProperties(string $id, array $seen = []): ?array
    {
        if (isset($seen[$id]) || count($seen) >= 32) {
            return null;
        }
        $seen[$id] = true;
        $own = $this->index->elements[$id]['metadata']['query_builder']['property'] ?? null;
        if ($own !== null) {
            return [['id' => $id, 'selector' => $own]];
        }
        $values = [];
        foreach ($this->index->out[$id] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if (in_array($edge['kind'], ['extends', 'uses-trait'], true) && isset($this->index->elements[$edge['to']])) {
                $items = $this->builderProperties($edge['to'], $seen);
                if ($items === null) {
                    return null;
                }
                array_push($values, ...$items);
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $element
     * @return list<array<string, mixed>>|null
     */
    private function declarationSources(array $element, DataCatalog $catalog): ?array
    {
        $sources = [];
        $active = [];
        $seen = [];
        $visit = function (string $name, int $depth) use (&$visit, &$sources, &$active, &$seen, $catalog): bool {
            $key = strtolower($name);
            if ($depth > 32 || isset($active[$key])) {
                return false;
            }
            if (isset($seen[$key]) || ! isset($catalog->classes[$key])) {
                return true;
            }
            $ids = $this->index->namedTypes($name);
            if (count($ids) !== 1) {
                return false;
            }
            $active[$key] = true;
            $owner = $this->index->elements[$ids[0]];
            $sources[] = ['path' => $owner['path'], 'line' => $owner['line'], 'end_line' => $owner['end_line'], 'symbol' => $owner['name']];
            foreach (['::$table', '::$connection', '::getTable', '::getConnectionName', '::$casts', '::casts'] as $suffix) {
                foreach ($this->index->names[strtolower($name.$suffix)] ?? [] as $id) {
                    $member = $this->index->elements[$id];
                    $sources[] = ['path' => $member['path'], 'line' => $member['line'], 'end_line' => $member['end_line'], 'symbol' => $member['name']];
                }
            }
            foreach ($catalog->classes[$key]['parents'] as $parent) {
                if (! $visit($parent, $depth + 1)) {
                    return false;
                }
            }
            unset($active[$key]);
            $seen[$key] = true;

            return true;
        };

        return $visit($element['name'], 0) ? $sources : null;
    }

    /** @param array<string, mixed> $source
     * @param  array<string, mixed>  $metadata
     */
    private function resource(string $kind, string $name, array $source, array $metadata, ?string $key = null): string
    {
        $id = CatalogElement::resourceIdentity($kind, $key ?? $name);
        $proof = ['path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['end_line']];
        if (! isset($this->index->elements[$id])) {
            $this->index->elements[$id] = [...(new CatalogElement($id, $name, $kind, $source['line'], $source['end_line'], 0, metadata: $metadata))->toArray(),
                'path' => $source['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => []];
            $this->index->names[strtolower($name)][] = $id;
        }
        $this->index->elements[$id]['sources'][] = $proof;

        return $id;
    }

    /** @param array<string, mixed> $source
     * @param  array<string, mixed>  $metadata
     */
    private function relation(array $source, string $target, string $kind, array $metadata, ?string $from = null): void
    {
        $this->index->addRelation(['from' => $from ?? $source['id'], 'to' => $target, 'kind' => $kind,
            'path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['end_line'],
            'knowledge' => 'static', 'resolution' => 'conditional', 'metadata' => $metadata]);
    }

    /** @param array<string, mixed> $source */
    private function diagnostic(array $source, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'data_analysis', 'message' => $message,
            'path' => $source['path'], 'line' => $source['line'], 'subject' => $source['id']];
    }
}
