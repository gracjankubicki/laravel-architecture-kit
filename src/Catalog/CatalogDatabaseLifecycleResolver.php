<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use Illuminate\Support\Str;

/** Source factory declarations and conditional seeder/migration entrypoints. */
final class CatalogDatabaseLifecycleResolver
{
    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    /** @param list<array<string, mixed>> $sourceCalls */
    public function resolve(array $sourceCalls): void
    {
        $customNaming = false;
        foreach ($sourceCalls as $call) {
            if (strcasecmp($call['metadata']['receiver'] ?? '', 'Illuminate\\Database\\Eloquent\\Factories\\Factory') === 0 && in_array(strtolower($call['metadata']['method'] ?? ''), ['usenamespace', 'guessmodelnamesusing', 'guessfactorynamesusing'], true)) {
                $customNaming = true;
            }
        }
        $models = [];
        foreach ($this->index->elements as $element) {
            if ($element['kind'] !== 'class' || count($this->index->namedTypes($element['name'])) !== 1) {
                continue;
            }
            foreach (['Illuminate\\Database\\Seeder' => ['database-seeder', ['run']], 'Illuminate\\Database\\Migrations\\Migration' => ['database-migration', ['up', 'down']]] as $contract => [$kind, $methods]) {
                if ($this->index->hasContract($element['id'], $contract) && $this->index->namedTypes($contract) === []) {
                    foreach ($methods as $method) {
                        $this->entry($element, $kind, $method);
                    }
                }
            }
            if (! $this->index->hasContract($element['id'], 'Illuminate\\Database\\Eloquent\\Factories\\Factory') || $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Factories\\Factory') !== []) {
                continue;
            }
            $type = $this->factoryModel($element, $customNaming);
            $ids = $type === null ? [] : $this->index->namedTypes($type);
            if (count($ids) !== 1 || ! $this->index->hasContract($ids[0], 'Illuminate\\Database\\Eloquent\\Model') || $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Model') !== []) {
                $this->notice($element, 'Factory model selector is dynamic, absent, ambiguous or lacks an Eloquent contract.');

                continue;
            }
            $models[$element['id']] = $ids[0];
            $this->edge($element, $ids[0], 'references-factory-model', ['selector_basis' => 'source-factory-declaration']);
        }
        $this->seederCalls();
        $factoriesByModel = [];
        $modelsByInvocation = [];
        foreach ($this->index->elements as $model) {
            if ($model['kind'] !== 'class' || count($this->index->namedTypes($model['name'])) !== 1
                || ! $this->index->hasContract($model['id'], 'Illuminate\\Database\\Eloquent\\Model')
                || ! $this->index->hasContract($model['id'], 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory')
                || $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Model') !== []
                || $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Factories\\HasFactory') !== []) {
                continue;
            }
            $type = $this->modelFactory($model, $customNaming);
            $ids = $type === null ? [] : $this->index->namedTypes($type);
            if (count($ids) !== 1 || ! $this->index->hasContract($ids[0], 'Illuminate\\Database\\Eloquent\\Factories\\Factory')
                || $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Factories\\Factory') !== []) {
                $this->notice($model, 'Model factory selector is dynamic, absent, ambiguous or lacks a Factory contract.');

                continue;
            }
            $factoriesByModel[$model['id']] = $ids[0];
            $factory = $this->index->elements[$ids[0]];
            $factoryMethod = $this->calls->sourceMethodCandidate($factory['name'], 'modelName');
            $factoryProperties = $this->properties($factory['id']);
            $newFactory = $this->calls->sourceMethodCandidate($model['name'], 'newFactory', knownHasFactory: true);
            $modelProperties = $this->properties($model['id'], key: 'model_factory');
            if ($newFactory['method'] === null && ! $newFactory['unresolved'] && ! $newFactory['limited']
                && ($modelProperties === [] || count($modelProperties ?? []) === 1 && $modelProperties[0]['resolved'] && $modelProperties[0]['null_default'])
                && ($model['metadata']['model_factory']['attribute']['resolved'] ?? false)
                && $factoryMethod['method'] === null && ! $factoryMethod['unresolved'] && ! $factoryMethod['limited']
                && ($factory['metadata']['factory_model']['attribute'] ?? null) === null
                && ($factoryProperties === [] || count($factoryProperties ?? []) === 1 && $factoryProperties[0]['resolved'] && $factoryProperties[0]['null_default'])) {
                // HasFactory::getUseFactoryAttribute installs an instance-context model-name resolver.
                $modelsByInvocation[$model['id']] = $model['id'];
            } else {
                $modelsByInvocation[$model['id']] = $models[$factory['id']] ?? null;
            }
            $this->edge($model, $ids[0], 'references-model-factory', ['selector_basis' => 'source-model-declaration']);
        }
        $configurations = [];
        foreach ($this->index->elements as $element) {
            if (in_array($element['kind'], ['factory-configuration', 'factory-state'], true)) {
                $configurations[$element['parent']][] = $element;
            }
        }
        $operations = array_values(array_filter($this->index->elements, fn ($row) => $row['kind'] === 'factory-operation'));
        for ($cursor = 0; $cursor < count($operations); $cursor++) {
            $operation = $operations[$cursor];
            if ($cursor >= 4096 || $cursor % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
                $this->index->diagnostics[] = ['code' => 'catalog_limit', 'message' => 'Factory relationship composition reached its operation budget.', 'path' => $operation['path'], 'line' => $operation['line'], 'subject' => $operation['id']];
                break;
            }

            $factories = $this->index->namedTypes($operation['metadata']['receiver']);
            $createdModel = count($factories) === 1 ? ($models[$factories[0]] ?? null) : null;
            if ($operation['metadata']['chain_methods'][0] === 'factory' && count($factories) === 1) {
                $createdModel = $modelsByInvocation[$factories[0]] ?? null;
                $selected = $factoriesByModel[$factories[0]] ?? null;
                $factories = $selected === null ? [] : [$selected];
            }
            if (count($factories) !== 1 || ! $this->index->hasContract($factories[0], 'Illuminate\\Database\\Eloquent\\Factories\\Factory')
                || $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Factories\\Factory') !== []) {
                continue;
            }
            $factory = $this->index->elements[$factories[0]];
            $overridden = false;
            foreach (array_unique(['__construct', 'newInstance', 'new', 'create', 'make', 'store', 'newModel', 'makeInstance', 'getRawAttributes', 'getExpandedAttributes', 'expandAttributes', 'createChildren', 'parentResolvers', 'callAfterMaking', 'callAfterCreating', 'guessModelNamesUsing', $operation['metadata']['operation']]) as $method) {
                $selection = $this->calls->sourceMethodCandidate($factory['name'], $method);
                $overridden = $overridden || $selection['method'] !== null || $selection['unresolved'] || $selection['limited'];
            }
            if ($overridden) {
                $this->notice($operation, 'Source factory lifecycle override prevents default persistence inference.');

                continue;
            }
            $owner = $this->index->elements[$operation['parent']];
            $pipeline = (new CatalogFactoryPipeline($this->calls))->resolve($factory, $operation, $configurations);
            if ($pipeline['failure'] !== null) {
                $this->notice($operation, $pipeline['failure']);
                if ($pipeline['limited']) {
                    $this->index->diagnostics[] = ['code' => 'catalog_limit', 'message' => $pipeline['failure'], 'path' => $operation['path'], 'line' => $operation['line'], 'subject' => $operation['id']];
                }

                continue;
            }
            $callbacks = $pipeline['callbacks'];
            $instances = $pipeline['instances_possible'];
            foreach ($pipeline['invocations'] as $invocation) {
                $site = [...$operation, 'path' => $invocation['path'], 'line' => $invocation['line'], 'end_line' => $invocation['end_line']];
                $this->edge($owner, $invocation['target'], $invocation['stage'] === 'configuration' ? 'invokes-factory-configuration' : 'invokes-factory-state',
                    ['execution_stage' => 'factory-'.$invocation['stage'], 'conditions' => ['Factory construction and preceding transformations must succeed.']], $site);
            }
            $creates = str_starts_with($operation['metadata']['operation'], 'create');
            if ($instances && $createdModel !== null) {
                foreach ($pipeline['relationships'] as $relationship) {
                    if ($relationship['api'] !== 'for' && ! $creates) {
                        continue;
                    }
                    $this->relatedFactory($operation, $owner, $createdModel, $relationship, $pipeline, $models, $modelsByInvocation, $operations);
                }
            }

            foreach ($callbacks as $callback) {
                if (($this->index->elements[$callback['target']]['kind'] ?? null) !== 'closure') {
                    $this->notice($operation, 'Factory callback source identity is unavailable.');

                    continue;
                }
                $site = [...$operation, 'path' => $this->index->elements[$callback['target']]['path'], 'line' => $callback['line'], 'end_line' => $callback['end_line']];
                $metadata = ['operation' => $operation['metadata']['operation'], 'execution_stage' => 'factory-'.$callback['stage'], 'conditions' => ['Factory construction and attribute evaluation must succeed and produce at least one model.']];
                $this->edge($owner, $callback['target'], 'registers-factory-callback', $metadata, $site);
                if ($instances && ($callback['stage'] !== 'aftercreating' || $creates)) {
                    if ($callback['stage'] === 'aftercreating') {
                        $metadata['conditions'][] = 'Persistence must succeed before the afterCreating callback.';
                    }
                    $this->edge($owner, $callback['target'], 'invokes-factory-callback', $metadata, $site);
                }
            }
            if (! $instances) {
                continue;
            }
            $definition = $this->calls->sourceMethodCandidate($factory['name'], 'definition');
            if ($definition['method'] !== null && ! $definition['unresolved'] && ! $definition['limited']
                && ($definition['method']['metadata']['visibility'] ?? null) === 'public' && ! ($definition['method']['metadata']['static'] ?? false) && ! ($definition['method']['metadata']['abstract'] ?? false)) {
                $this->edge($owner, $definition['method']['id'], 'invokes-factory-definition', ['execution_stage' => 'factory-attribute-evaluation', 'conditions' => ['Factory construction must succeed and produce at least one model.']], $operation);
            }
            if (isset($operation['_pivot'])) {
                $pivot = $operation['_pivot'];
                $this->edge($owner, $pivot['table'], 'writes', ['operation' => 'factory-attach', 'execution_stage' => 'factory-pivot-persistence-candidate', 'conditions' => ['Parent and related model persistence must succeed before attachment.']], $pivot['site']);
            }
            if (! $creates || $createdModel === null) {
                continue;
            }
            if (($pipeline['connection']['kind'] ?? null) === 'named') {
                $setConnection = $this->calls->sourceMethodCandidate($this->index->elements[$createdModel]['name'], 'setConnection', knownHasFactory: true);
                if ($setConnection['method'] !== null || $setConnection['unresolved'] || $setConnection['limited']) {
                    $this->notice($operation, 'Source model connection override prevents factory connection inference.');

                    continue;
                }
            }
            foreach ($this->index->out[$createdModel] ?? [] as $position) {
                $table = $this->index->relations[$position];
                if ($table['kind'] === 'maps-table') {
                    $target = $table['to'];
                    $connection = $table['metadata']['connection'];
                    $connectionSources = $pipeline['connection_source'] === null ? [] : [$pipeline['connection_source']];
                    if (($pipeline['connection']['kind'] ?? null) === 'named') {
                        $connection = $pipeline['connection'];
                        $connectionSite = [...$operation, ...$pipeline['connection_source']];
                        $connectionId = $this->resource('database-connection', $connection['name'], $connectionSite, ['selector' => $connection, 'runtime_configuration_known' => false]);
                        $tableName = $this->index->elements[$target]['name'];
                        $target = $this->resource('table', $tableName, $operation, ['connection' => $connection, 'schema_state_known' => false], $connection['name'].'::'.$tableName);
                        $this->edge($this->index->elements[$target], $connectionId, 'table-connection', [], $operation);
                        $this->edge($owner, $connectionId, 'uses-database-connection', ['connection' => $connection], $connectionSite);
                    }
                    $this->edge($owner, $target, 'writes', ['operation' => 'factory-create', 'factory_api' => $operation['metadata']['operation'], 'connection' => $connection, 'connection_sources' => $connectionSources, 'model_events_suppressed' => str_ends_with($operation['metadata']['operation'], 'quietly'), 'execution_stage' => 'factory-persistence-candidate', 'conditions' => ['Factory creation and model persistence must succeed.']], $operation);
                }
            }
        }
    }

    /** @param array<string, mixed> $operation
     * @param  array<string, mixed>  $owner
     * @param  array<string, mixed>  $relationship
     * @param  array<string, mixed>  $pipeline
     * @param  array<string, string>  $models
     * @param  array<string, ?string>  $modelsByInvocation
     * @param  list<array<string, mixed>>  $operations
     */
    private function relatedFactory(array $operation, array $owner, string $modelId, array $relationship, array $pipeline, array $models, array $modelsByInvocation, array &$operations): void
    {
        $site = [...$operation, 'path' => $relationship['path'], 'line' => $relationship['line'], 'end_line' => $relationship['end_line']];
        $child = $this->index->elements[$relationship['factory']] ?? null;
        if ($child === null || $child['kind'] !== 'factory-related') {
            $this->notice($site, 'Related factory source is absent or invalid.');

            return;
        }
        $ancestry = $operation['_ancestry'] ?? [];
        if (isset($ancestry[$child['id']]) || count($ancestry) >= 32) {
            $this->notice($site, 'Related factory graph is cyclic or exceeds its depth budget.');
            $this->index->diagnostics[] = ['code' => 'catalog_limit', 'message' => 'Related factory graph is cyclic or exceeds its depth budget.', 'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['id']];

            return;
        }
        $ids = $this->index->namedTypes($child['metadata']['receiver']);
        $childModel = count($ids) === 1 ? ($models[$ids[0]] ?? null) : null;
        if ($child['metadata']['chain_methods'][0] === 'factory' && count($ids) === 1) {
            $childModel = $modelsByInvocation[$ids[0]] ?? null;
        }
        if ($childModel === null || ! $child['metadata']['selector_resolved']) {
            $this->notice($site, 'Related factory model or source pipeline is unresolved.');

            return;
        }
        $model = $this->index->elements[$modelId];
        $name = $relationship['name'];
        if ($name === null) {
            $basename = class_basename($this->index->elements[$childModel]['name']);
            $name = Str::camel($relationship['api'] === 'for' ? $basename : Str::plural($basename));
            if ($relationship['api'] === 'has') {
                $guess = $this->calls->sourceMethodCandidate($model['name'], $name, knownHasFactory: true);
                if ($guess['method'] === null && ! $guess['unresolved'] && ! $guess['limited']) {
                    $name = Str::singular($name);
                }
            }
        }
        $selection = $this->calls->sourceMethodCandidate($model['name'], $name, knownHasFactory: true);
        $method = $selection['method'];
        $declared = false;
        foreach ($this->index->out[$modelId] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            $declared = $declared || $edge['kind'] === 'declares-model-relation' && $edge['to'] === ($method['id'] ?? null);
        }
        $descriptor = $method['metadata']['eloquent_relation'] ?? null;
        $allowed = match ($relationship['api']) {
            'for' => ['belongsto'], 'hasattached' => ['belongstomany', 'morphtomany', 'morphedbymany'],
            default => ['hasone', 'hasmany', 'morphone', 'morphmany', 'belongstomany', 'morphtomany', 'morphedbymany'],
        };
        if ($selection['unresolved'] || $selection['limited'] || ! $declared || $descriptor === null
            || ! in_array($descriptor['type'], $allowed, true) || $method['metadata']['static']
            || strcasecmp($descriptor['related'], $this->index->elements[$childModel]['name']) !== 0) {
            $this->notice($site, 'Factory relationship method, type or related model is unresolved or incompatible.');

            return;
        }
        $stage = $relationship['api'] === 'for' ? 'factory-parent-before-attributes' : 'factory-child-after-persistence';
        $this->edge($owner, $method['id'], 'invokes-factory-relation', ['relationship_api' => $relationship['api'], 'execution_stage' => $stage, 'conditions' => ['Factory attribute evaluation and preceding persistence must succeed.']], $site);
        $this->edge($owner, $child['id'], 'creates-related-factory', ['relationship_api' => $relationship['api'], 'execution_stage' => $stage, 'conditions' => ['No recycled instance supplies this relationship, and preceding factory stages succeed.']], $site);
        $child['parent'] = $child['id'];
        $child['_ancestry'] = [...$ancestry, $child['id'] => true];
        if (in_array($descriptor['type'], ['belongstomany', 'morphtomany', 'morphedbymany'], true)) {
            foreach ($this->index->out[$method['id']] ?? [] as $position) {
                $edge = $this->index->relations[$position];
                if ($edge['kind'] === 'references-relation-table' && ($edge['metadata']['table_role'] ?? null) === 'pivot') {
                    $pivot = $edge['to'];
                    if (($pipeline['connection']['kind'] ?? null) === 'named') {
                        $name = $this->index->elements[$pivot]['name'];
                        $pivot = $this->resource('table', $name, $site, ['connection' => $pipeline['connection'], 'schema_state_known' => false], $pipeline['connection']['name'].'::'.$name);
                    }
                    $child['_pivot'] = ['table' => $pivot, 'site' => $site];
                }
            }
            if (! isset($child['_pivot'])) {
                $this->notice($site, 'Factory pivot table is unresolved; related model creation remains a candidate.');
            }
        }
        $operations[] = $child;
    }

    private function seederCalls(): void
    {
        foreach ($this->index->elements as $site) {
            if ($site['kind'] !== 'seeder-operation') {
                continue;
            }
            $ids = $this->index->namedTypes($site['metadata']['receiver']);
            if (count($ids) !== 1 || ! $this->index->hasContract($ids[0], 'Illuminate\\Database\\Seeder') || $this->index->namedTypes('Illuminate\\Database\\Seeder') !== []) {
                continue;
            }
            if (! $site['metadata']['selector_resolved']) {
                $this->notice($site, 'Seeder call has dynamic, unpacked, keyed or invalid target arguments.');

                continue;
            }
            $overridden = false;
            foreach (array_unique([$site['metadata']['operation'], 'call', 'resolve']) as $method) {
                $selection = $this->calls->sourceMethodCandidate($site['metadata']['receiver'], $method);
                $overridden = $overridden || $selection['method'] !== null || $selection['unresolved'] || $selection['limited'];
            }
            if ($overridden) {
                $this->notice($site, 'Source seeder call or container resolution override prevents default invocation inference.');

                continue;
            }
            foreach ($site['metadata']['targets'] as $type) {
                $targets = $this->index->namedTypes($type);
                if (count($targets) !== 1 || ! $this->index->hasContract($targets[0], 'Illuminate\\Database\\Seeder')) {
                    $this->notice($site, 'Called seeder target is absent, ambiguous or lacks a Seeder contract.');

                    continue;
                }
                $invoke = $this->calls->sourceMethodCandidate($type, '__invoke');
                $run = $this->calls->sourceMethodCandidate($type, 'run');
                if ($invoke['method'] !== null || $invoke['unresolved'] || $invoke['limited'] || $run['method'] === null || $run['unresolved'] || $run['limited']
                    || ($run['method']['metadata']['visibility'] ?? null) !== 'public' || ($run['method']['metadata']['abstract'] ?? false)) {
                    $this->notice($site, 'Called seeder entry is inaccessible, unresolved or overrides default invocation.');

                    continue;
                }
                $conditions = ['The parent seeder must execute this call and container resolution must yield the declared seeder type.'];
                if ($site['metadata']['operation'] === 'callonce') {
                    $conditions[] = 'This seeder must not already have been called in the current process.';
                }
                $this->edge($this->index->elements[$site['parent']], $run['method']['id'], 'calls-seeder',
                    ['operation' => $site['metadata']['operation'], 'execution_stage' => 'seeder-invocation-candidate', 'container_selection_known' => false, 'conditions' => $conditions], $site);
            }
        }
    }

    /** @param array<string, mixed> $model */
    private function modelFactory(array $model, bool $customNaming): ?string
    {
        foreach (['factory', 'getUseFactoryAttribute'] as $name) {
            $method = $this->calls->sourceMethodCandidate($model['name'], $name, knownHasFactory: true);
            if ($method['method'] !== null || $method['unresolved'] || $method['limited']) {
                return null;
            }
        }
        $method = $this->calls->sourceMethodCandidate($model['name'], 'newFactory', knownHasFactory: true);
        if ($method['unresolved'] || $method['limited']) {
            return null;
        }
        if ($method['method'] !== null) {
            $parent = $this->index->elements[$method['method']['id']]['parent'];
            $selector = $this->index->elements[$parent]['metadata']['model_factory']['method'] ?? null;
            if (! ($selector['resolved'] ?? false)) {
                return null;
            }
            if (! $selector['null_default']) {
                return $selector['type'];
            }
            // A source null override bypasses the default property and attribute logic.
        } else {
            $properties = $this->properties($model['id'], key: 'model_factory');
            if ($properties === null || count($properties) > 1 || $properties !== [] && ! $properties[0]['resolved']) {
                return null;
            }
            if ($properties !== [] && ! $properties[0]['null_default']) {
                return $properties[0]['type'];
            }
            $attribute = $model['metadata']['model_factory']['attribute'] ?? null;
            if ($attribute !== null) {
                return $attribute['resolved'] && $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Attributes\\UseFactory') === [] ? $attribute['type'] : null;
            }
        }
        if ($customNaming) {
            return null;
        }
        $namespace = $this->appNamespace();
        if ($namespace === null || ! str_starts_with($model['name'], $namespace)) {
            return null;
        }
        $relative = substr($model['name'], strlen($namespace));
        if (str_starts_with($relative, 'Models\\')) {
            $relative = substr($relative, strlen('Models\\'));
        }

        return 'Database\\Factories\\'.$relative.'Factory';
    }

    private function appNamespace(): ?string
    {
        $namespaces = [];
        foreach ($this->index->elements as $mapping) {
            if ($mapping['kind'] === 'autoload-mapping' && $mapping['path'] === 'composer.json' && $mapping['metadata']['mode'] === 'psr-4'
                && ! $mapping['metadata']['development'] && trim($mapping['metadata']['source_path'], '/') === 'app') {
                $namespaces[] = $mapping['metadata']['namespace'];
            }
        }
        $namespaces = array_values(array_unique($namespaces));

        return count($namespaces) === 1 ? $namespaces[0] : null;
    }

    /** @param array<string, mixed> $factory */
    private function factoryModel(array $factory, bool $customNaming): ?string
    {
        $method = $this->calls->sourceMethodCandidate($factory['name'], 'modelName');
        if ($method['method'] !== null) {
            $parent = $this->index->elements[$method['method']['id']]['parent'];
            $selector = $this->index->elements[$parent]['metadata']['factory_model']['method'] ?? null;

            return ! $method['unresolved'] && ! $method['limited'] && ($selector['resolved'] ?? false) ? ($selector['type'] ?? null) : null;
        }
        if ($method['unresolved'] || $method['limited']) {
            return null;
        }
        $attribute = $factory['metadata']['factory_model']['attribute'] ?? null;
        if ($attribute !== null) {
            return $attribute['resolved'] && $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Factories\\Attributes\\UseModel') === [] ? $attribute['type'] : null;
        }
        $properties = $this->properties($factory['id']);
        if ($properties === null || count($properties) > 1) {
            return null;
        }
        if ($properties !== [] && ! $properties[0]['resolved']) {
            return null;
        }
        if ($properties !== [] && ! $properties[0]['null_default']) {
            return $properties[0]['resolved'] ? $properties[0]['type'] : null;
        }
        if ($customNaming || ! str_starts_with($factory['name'], 'Database\\Factories\\') || ! str_ends_with($factory['name'], 'Factory')) {
            return null;
        }
        $namespaces = [];
        foreach ($this->index->elements as $mapping) {
            if ($mapping['kind'] === 'autoload-mapping' && $mapping['path'] === 'composer.json' && $mapping['metadata']['mode'] === 'psr-4'
                && ! $mapping['metadata']['development'] && trim($mapping['metadata']['source_path'], '/') === 'app') {
                $namespaces[] = $mapping['metadata']['namespace'];
            }
        }
        $namespaces = array_values(array_unique($namespaces));
        if (count($namespaces) !== 1) {
            return null;
        }
        $relative = substr($factory['name'], strlen('Database\\Factories\\'), -strlen('Factory'));
        foreach ([$namespaces[0].'Models\\'.$relative, $namespaces[0].class_basename($relative)] as $candidate) {
            if ($this->index->namedTypes($candidate) !== []) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<string, true> $seen
     * @return list<array<string, mixed>>|null
     */
    private function properties(string $id, array $seen = [], string $key = 'factory_model'): ?array
    {
        if (isset($seen[$id]) || count($seen) >= 32) {
            return null;
        }
        $seen[$id] = true;
        $element = $this->index->elements[$id];
        if (($element['metadata']['trait_rules'] ?? []) !== []) {
            return null;
        }
        $own = $element['metadata'][$key]['property'] ?? null;
        if ($own !== null) {
            return [$own];
        }
        $result = [];
        foreach ($this->index->out[$id] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if (in_array($edge['kind'], ['extends', 'uses-trait'], true) && isset($this->index->elements[$edge['to']])) {
                $values = $this->properties($edge['to'], $seen, $key);
                if ($values === null) {
                    return null;
                }
                array_push($result, ...$values);
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $class */
    private function entry(array $class, string $kind, string $method): void
    {
        if ($kind === 'database-seeder') {
            $invoke = $this->calls->sourceMethodCandidate($class['name'], '__invoke');
            if ($invoke['method'] !== null || $invoke['unresolved'] || $invoke['limited']) {
                $this->notice($class, 'Source seeder invocation override prevents default run entry inference.');

                return;
            }
        }
        $selection = $this->calls->sourceMethodCandidate($class['name'], $method);
        $target = $selection['method'];
        if ($target === null || $selection['unresolved'] || $selection['limited'] || ($target['metadata']['visibility'] ?? null) !== 'public' || ($target['metadata']['abstract'] ?? false)) {
            $this->notice($class, 'Database lifecycle method is absent, ambiguous or inaccessible.');

            return;
        }
        $id = CatalogElement::identity($class['path'], $kind, $class['name'].'::'.$method, $class['offset']);
        $element = new CatalogElement($id, $class['name'].'::'.$method, $kind, $class['line'], $class['end_line'], $class['offset'], $class['id'], metadata: ['direction' => $method, 'runtime_activation_known' => false, 'schema_state_known' => false]);
        $this->index->elements[$id] = [...$element->toArray(), 'path' => $class['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => [['path' => $class['path'], 'line' => $class['line'], 'end_line' => $class['end_line']]]];
        $this->index->names[strtolower($element->name)][] = $id;
        $this->edge($this->index->elements[$id], $target['id'], $kind === 'database-seeder' ? 'seeder-entry' : 'migration-entry', ['conditions' => ['The seeder or migration direction must be selected for execution.']]);
    }

    /** @param array<string, mixed> $from
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $site
     */
    private function edge(array $from, string $to, string $kind, array $metadata, ?array $site = null): void
    {
        $site ??= $from;
        $this->index->addRelation(['from' => $from['id'], 'to' => $to, 'kind' => $kind, 'path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line'],
            'knowledge' => 'static', 'resolution' => 'conditional', 'metadata' => [...$metadata, 'execution_proven' => false, 'schema_state_known' => false]]);
    }

    /** @param array<string, mixed> $source
     * @param  array<string, mixed>  $metadata
     */
    private function resource(string $kind, string $name, array $source, array $metadata, ?string $key = null): string
    {
        $id = CatalogElement::resourceIdentity($kind, $key ?? $name);
        if (! isset($this->index->elements[$id])) {
            $this->index->elements[$id] = [...(new CatalogElement($id, $name, $kind, $source['line'], $source['end_line'], 0, metadata: $metadata))->toArray(),
                'path' => $source['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => []];
            $this->index->names[strtolower($name)][] = $id;
        }
        $this->index->elements[$id]['sources'][] = ['path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['end_line']];

        return $id;
    }

    /** @param array<string, mixed> $site */
    private function notice(array $site, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'database_lifecycle_analysis', 'message' => $message, 'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['id']];
    }
}
