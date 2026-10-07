<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use Illuminate\Support\Str;

/** Relationship declarations are structural; declaring a relation never proves a query. */
final class CatalogModelResolver
{
    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    /** @param array<string, mixed> $model */
    private function knownHasFactory(array $model): bool
    {
        return $this->index->hasContract($model['id'], 'Illuminate\Database\Eloquent\Factories\HasFactory')
            && $this->index->namedTypes('Illuminate\Database\Eloquent\Factories\HasFactory') === [];
    }

    public function resolve(): void
    {
        if ($this->index->namedTypes('Illuminate\\Database\\Eloquent\\Model') !== []) {
            return;
        }
        $names = [];
        foreach ($this->index->elements as $element) {
            if (isset($element['metadata']['eloquent_relation'])) {
                $names[substr($element['name'], strrpos($element['name'], '::') + 2)] = true;
            }
        }
        foreach ($this->index->elements as $model) {
            if ($model['kind'] !== 'class' || count($this->index->namedTypes($model['name'])) !== 1 || ! $this->index->hasContract($model['id'], 'Illuminate\\Database\\Eloquent\\Model')) {
                continue;
            }
            foreach (array_keys($names) as $name) {
                $selection = $this->calls->sourceMethodCandidate($model['name'], $name, knownHasFactory: $this->knownHasFactory($model));
                $method = $selection['method'];
                if ($method === null || ! isset($method['metadata']['eloquent_relation'])) {
                    continue;
                }
                $method = $this->index->elements[$method['id']];
                if ($selection['unresolved'] || $selection['limited'] || ($method['metadata']['visibility'] ?? null) !== 'public' || ($method['metadata']['abstract'] ?? false)) {
                    $this->diagnostic($method, 'Relation declaration is ambiguous, inaccessible or exceeds the hierarchy budget.');

                    continue;
                }
                $descriptor = $method['metadata']['eloquent_relation'];
                $override = $this->calls->sourceMethodCandidate($model['name'], $descriptor['type'], knownHasFactory: $this->knownHasFactory($model));
                if ($override['method'] !== null || $override['unresolved'] || $override['limited']) {
                    $this->diagnostic($method, 'Source method overrides the framework relationship factory.');

                    continue;
                }
                if (! $descriptor['resolved']) {
                    $this->diagnostic($method, 'Relation return is dynamic, polymorphic or uses an unsupported receiver chain.');

                    continue;
                }
                $related = $this->model($descriptor['related']);
                if ($related === null) {
                    $this->diagnostic($method, 'Related type is absent, ambiguous or has no Eloquent contract.');

                    continue;
                }
                $this->relation($model, $method['id'], 'declares-model-relation', ['relation_type' => $descriptor['type']]);
                $this->relation($method, $related['id'], 'references-related-model', ['relation_type' => $descriptor['type']]);
                $connection = $this->connection($model, $related);
                foreach ($this->tables($related['id']) as $table) {
                    if ($connection !== null) {
                        $tableId = $this->tableResource($table['name'], $connection, $method);
                        $this->relation($method, $tableId, 'references-relation-table', ['relation_type' => $descriptor['type'], 'table_role' => 'related']);
                    }
                }
                if ($descriptor['through'] !== null) {
                    $through = $this->model($descriptor['through']);
                    if ($through === null) {
                        $this->diagnostic($method, 'Through model is absent, ambiguous or unresolved.');
                    } else {
                        $this->relation($method, $through['id'], 'references-through-model', []);
                        foreach ($this->tables($through['id']) as $table) {
                            if ($connection !== null) {
                                $this->relation($method, $this->tableResource($table['name'], $connection, $method), 'references-relation-table', ['table_role' => 'through']);
                            }
                        }
                    }
                }
                if (in_array($descriptor['type'], ['belongstomany', 'morphtomany', 'morphedbymany'], true)) {
                    $this->pivot($model, $related, $method, $descriptor);
                }
            }
        }
    }

    /** @param array<string, mixed> $owner
     * @param  array<string, mixed>  $related
     * @param  array<string, mixed>  $method
     * @param  array<string, mixed>  $descriptor
     */
    private function pivot(array $owner, array $related, array $method, array $descriptor): void
    {
        $name = $descriptor['pivot']['name'];
        if ($descriptor['pivot']['mode'] === 'default') {
            if ($descriptor['type'] === 'belongstomany') {
                // Custom methods can change conventions; never fall back after such an override.
                $custom = $this->calls->sourceMethodCandidate($owner['name'], 'joiningTable', knownHasFactory: $this->knownHasFactory($owner));
                if ($custom['method'] !== null || $custom['unresolved'] || $custom['limited']) {
                    $this->diagnostic($method, 'Custom joiningTable requires source return resolution.');

                    return;
                }
                $segments = [];
                foreach ([$owner, $related] as $model) {
                    $custom = $this->calls->sourceMethodCandidate($model['name'], 'joiningTableSegment', knownHasFactory: $this->knownHasFactory($model));
                    if ($custom['method'] !== null || $custom['unresolved'] || $custom['limited']) {
                        $this->diagnostic($method, 'Custom joiningTableSegment prevents conventional pivot inference.');

                        return;
                    }
                    $segments[] = Str::snake(class_basename($model['name']));
                }
                sort($segments);
                $name = strtolower(implode('_', $segments));
            } elseif ($descriptor['morph'] !== null) {
                $name = Str::plural($descriptor['morph']);
            }
        }
        $connection = $this->connection($owner, $related);
        if (! is_string($name) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.-]{0,255}\z/D', $name) !== 1 || ! is_array($connection) || ! in_array($connection['kind'], ['default', 'named'], true)) {
            $this->diagnostic($method, 'Pivot table selector or effective connection is unresolved.');

            return;
        }
        $id = $this->tableResource($name, $connection, $method);
        $this->relation($method, $id, 'references-relation-table', ['table_role' => 'pivot', 'selector_basis' => $descriptor['pivot']['mode'] === 'literal' ? 'source-literal' : 'framework-convention']);
        if ($descriptor['pivot_class'] !== null) {
            $pivot = $this->model($descriptor['pivot_class']);
            if ($pivot !== null && $this->index->hasContract($pivot['id'], 'Illuminate\\Database\\Eloquent\\Relations\\Pivot')
                && $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Relations\\Pivot') === []
                && $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Relations\\MorphPivot') === []) {
                $this->relation($method, $pivot['id'], 'references-pivot-model', []);
            } else {
                $this->diagnostic($method, 'Custom pivot type has no verified Pivot contract.');
            }
        }
    }

    /** @param array<string, mixed> $owner
     * @param  array<string, mixed>  $related
     * @return array<string, mixed>|null
     */
    private function connection(array $owner, array $related): ?array
    {
        $selection = $this->calls->sourceMethodCandidate($owner['name'], 'newRelatedInstance', knownHasFactory: $this->knownHasFactory($owner));
        if ($selection['method'] !== null || $selection['unresolved'] || $selection['limited']) {
            $this->diagnostic($owner, 'Custom related-instance factory makes relationship database selectors unresolved.');

            return null;
        }
        $relatedTables = $this->tables($related['id']);
        $ownerTables = $this->tables($owner['id']);
        $connection = $relatedTables[0]['metadata']['connection'] ?? null;
        if (($connection['kind'] ?? null) === 'default') {
            $connection = $ownerTables[0]['metadata']['connection'] ?? null;
        }

        return is_array($connection) && in_array($connection['kind'], ['default', 'named'], true) ? $connection : null;
    }

    /** @param array<string, mixed> $connection
     * @param  array<string, mixed>  $method
     */
    private function tableResource(string $name, array $connection, array $method): string
    {
        $id = CatalogElement::resourceIdentity('table', ($connection['name'] ?? '(default)').'::'.$name);
        if (! isset($this->index->elements[$id])) {
            $element = new CatalogElement($id, $name, 'table', $method['line'], $method['end_line'], $method['offset'], metadata: ['logical_resource' => true, 'connection' => $connection, 'schema_state_known' => false]);
            $this->index->elements[$id] = [...$element->toArray(), 'path' => $method['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => []];
            $this->index->names[strtolower($name)][] = $id;
        }
        $this->index->elements[$id]['sources'][] = ['path' => $method['path'], 'line' => $method['line'], 'end_line' => $method['end_line']];

        return $id;
    }

    /** @return array<string, mixed>|null */
    private function model(?string $name): ?array
    {
        $ids = $name === null ? [] : $this->index->namedTypes($name);

        return count($ids) === 1 && $this->index->hasContract($ids[0], 'Illuminate\\Database\\Eloquent\\Model') ? $this->index->elements[$ids[0]] : null;
    }

    /** @return list<array<string, mixed>> */
    private function tables(string $model): array
    {
        $tables = [];
        foreach ($this->index->out[$model] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if ($edge['kind'] === 'maps-table') {
                $tables[] = $this->index->elements[$edge['to']];
            }
        }

        return $tables;
    }

    /** @param array<string, mixed> $source
     * @param  array<string, mixed>  $metadata
     */
    private function relation(array $source, string $target, string $kind, array $metadata): void
    {
        $this->index->addRelation(['from' => $source['id'], 'to' => $target, 'kind' => $kind, 'path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['end_line'],
            'resolution' => 'resolved', 'knowledge' => 'static', 'metadata' => [...$metadata, 'execution_proven' => false, 'query_executed' => false]]);
    }

    /** @param array<string, mixed> $source */
    private function diagnostic(array $source, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'eloquent_relation_analysis', 'message' => $message, 'path' => $source['path'], 'line' => $source['line'], 'subject' => $source['id']];
    }
}
