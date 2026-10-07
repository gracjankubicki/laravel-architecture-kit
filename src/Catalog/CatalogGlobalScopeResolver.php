<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\DataOperations;

/** Global scope registration and deferred query application are distinct source facts. */
final class CatalogGlobalScopeResolver
{
    /** @var array<string, list<array<string, mixed>>|null> */
    private array $registrations = [];

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    /** @param array<string, mixed> $site
     * @param  array<string, mixed>  $model
     */
    public function apply(array $site, array $model): bool
    {
        if (! array_key_exists($model['id'], $this->registrations)) {
            $this->registrations[$model['id']] = $this->collect($model);
        }
        $rows = $this->registrations[$model['id']];
        $exclusions = $site['metadata']['excluded_global_scopes'];
        if (in_array($site['metadata']['operation'], ['create', 'createquietly', 'forcecreate', 'save', 'forcedelete', 'truncate', 'updateorinsert'], true)) {
            return true;
        }
        if ($rows === null) {
            $this->notice($site, 'Global scope registration requires inspection before exclusion.');

            return false;
        }
        if ($exclusions['all'] && $exclusions['resolved']) {
            return true;
        }
        if (! $exclusions['resolved']) {
            $this->notice($site, 'Global scope registration or exclusions require inspection.');

            return false;
        }
        $active = array_values(array_filter($rows, fn ($row) => $row['key'] === null || ! in_array($row['key'], $exclusions['keys'], true)));
        if ($active === []) {
            return true;
        }
        // Reads reach get(), cursor() or toBase(). These bulk writes also use toBase(),
        // even when their SQL statement does not use the scope's WHERE constraints.
        if (! in_array($site['metadata']['operation'], [...DataOperations::READ, 'update', 'delete', 'increment', 'decrement', 'insert', 'insertorignore', 'insertusing', 'upsert', 'firstorcreate', 'createorfirst', 'updateorcreate', 'incrementorcreate'], true)) {
            $this->notice($site, 'Global scope application for this data terminal is not yet resolved.');

            return false;
        }
        foreach ($active as $row) {
            if (! $row['preserves_query']) {
                $this->notice($site, 'Global scope body can change query selectors; terminal effects require composition.');

                return false;
            }
        }
        foreach ($active as $row) {
            $conditions = ['Source model boot registration must run, the scope must remain installed, and the terminal must apply scopes.'];
            if ($site['metadata']['operation'] === 'createorfirst') {
                $conditions[] = 'A unique constraint violation must trigger the fallback lookup; successful creation does not apply query scopes.';
            } elseif (in_array($site['metadata']['operation'], ['firstorcreate', 'updateorcreate', 'incrementorcreate'], true)) {
                $conditions[] = 'Scopes apply to the record lookup, not to model persistence.';
            }
            $this->edge($site['parent'], $row['target'], 'applies-global-scope', $site,
                ['registration_source' => $row['source'], 'execution_stage' => 'query-global-scope-application', 'execution_proven' => false,
                    'conditions' => $conditions]);
        }

        return true;
    }

    /** @param array<string, mixed> $model
     * @return list<array<string, mixed>>|null
     */
    private function collect(array $model): ?array
    {
        $factory = $this->index->hasContract($model['id'], 'Illuminate\Database\Eloquent\Factories\HasFactory') && $this->index->namedTypes('Illuminate\Database\Eloquent\Factories\HasFactory') === [];
        $booted = $this->calls->sourceMethodCandidate($model['name'], 'booted', knownHasFactory: $factory);
        if ($booted['unresolved'] || $booted['limited']) {
            return null;
        }
        if ($booted['method'] !== null && (! $booted['method']['metadata']['static'] || $booted['method']['metadata']['visibility'] === 'private')) {
            return null;
        }
        $sources = $this->sources($model['id']);
        if ($sources === null) {
            return null;
        }
        $attributeSources = [];
        foreach ($sources as $id) {
            if ($this->index->elements[$id]['kind'] === 'class') {
                $attributeSources[$id] = true;
                foreach ($this->index->out[$id] ?? [] as $position) {
                    $edge = $this->index->relations[$position];
                    if ($edge['kind'] === 'uses-trait') {
                        $attributeSources[$edge['to']] = true;
                    }
                }
            }
        }
        $pending = [];
        foreach ($sources as $id) {
            $source = $this->index->elements[$id];
            foreach ($source['metadata']['global_scopes'] ?? [] as $row) {
                if ($row['registration'] === 'attribute' && ! isset($attributeSources[$id])) {
                    continue;
                }
                if ($row['registration'] === 'booted' && $row['method'] !== ($booted['method']['id'] ?? null)) {
                    continue;
                }
                if (! $row['resolved'] || $row['registration'] === 'attribute' && $this->index->namedTypes('Illuminate\Database\Eloquent\Attributes\ScopedBy') !== []) {
                    return null;
                }
                $proof = ['path' => $source['path'], 'line' => $row['line'], 'end_line' => $row['end_line']];
                $target = $row['callback'];
                $preserves = true;
                if ($row['type'] !== null) {
                    $ids = $this->index->namedTypes($row['type']);
                    if (count($ids) !== 1 || ! $this->index->hasContract($ids[0], 'Illuminate\Database\Eloquent\Scope') || $this->index->namedTypes('Illuminate\Database\Eloquent\Scope') !== []) {
                        return null;
                    }
                    $extension = $this->calls->sourceMethodCandidate($row['type'], 'extend');
                    if ($extension['method'] !== null || $extension['unresolved'] || $extension['limited']) {
                        return null;
                    }
                    $selection = $this->calls->sourceMethodCandidate($row['type'], 'apply');
                    $method = $selection['method'];
                    if ($method === null || $selection['unresolved'] || $selection['limited'] || $method['metadata']['visibility'] !== 'public' || $method['metadata']['static'] || $method['metadata']['abstract']) {
                        return null;
                    }
                    $target = $method['id'];
                    $preserves = $method['metadata']['global_scope_transform'] ?? false;
                }
                if ($target === null || ! isset($this->index->elements[$target])) {
                    return null;
                }
                $pending[] = [...$row, 'target' => $target, 'preserves_query' => $preserves, 'source' => $proof];
            }
        }
        if ($pending === []) {
            return [];
        }
        foreach (['boot', 'bootTraits', 'bootHasGlobalScopes', 'addGlobalScope', 'addGlobalScopes', 'getGlobalScopes', 'resolveGlobalScopeAttributes'] as $hook) {
            $selection = $this->calls->sourceMethodCandidate($model['name'], $hook, knownHasFactory: $factory);
            if ($selection['method'] !== null || $selection['unresolved'] || $selection['limited']) {
                return null;
            }
        }
        // Attribute registration runs during trait boot, followed by the selected booted method.
        usort($pending, fn ($left, $right) => ($left['registration'] === 'booted') <=> ($right['registration'] === 'booted'));
        $effective = [];
        foreach ($pending as $row) {
            $effective[$row['key'] ?? $row['target']] = $row;
            $this->edge($model['id'], $row['target'], 'registers-global-scope', $row['source'], ['execution_proven' => false, 'registration' => $row['registration']]);
        }

        return array_values($effective);
    }

    /** @param array<string, true> $seen
     * @return list<string>|null
     */
    private function sources(string $id, array $seen = []): ?array
    {
        if (isset($seen[$id]) || count($seen) >= 32) {
            return null;
        }
        $seen[$id] = true;
        $ids = [];
        foreach ($this->index->out[$id] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if (in_array($edge['kind'], ['extends', 'uses-trait'], true) && isset($this->index->elements[$edge['to']])) {
                $parents = $this->sources($edge['to'], $seen);
                if ($parents === null) {
                    return null;
                }
                array_push($ids, ...$parents);
            }
        }
        $ids[] = $id;

        return array_values(array_unique($ids));
    }

    /** @param array<string, mixed> $source
     * @param  array<string, mixed>  $metadata
     */
    private function edge(string $from, string $to, string $kind, array $source, array $metadata): void
    {
        $this->index->addRelation(['from' => $from, 'to' => $to, 'kind' => $kind, 'path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['end_line'], 'knowledge' => 'static', 'resolution' => 'conditional', 'metadata' => $metadata]);
    }

    /** @param array<string, mixed> $site */
    private function notice(array $site, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'global_scope_analysis', 'message' => $message, 'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['id']];
    }
}
