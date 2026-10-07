<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Selected attribute declarations and callbacks are structural, never hydration execution. */
final class CatalogAttributeResolver
{
    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(): void
    {
        if ($this->index->namedTypes('Illuminate\\Database\\Eloquent\\Model') !== []) {
            return;
        }
        $names = [];
        foreach ($this->index->elements as $element) {
            if (isset($element['metadata']['eloquent_attribute'])) {
                $names[substr($element['name'], strrpos($element['name'], '::') + 2)] = true;
            }
        }
        foreach ($this->index->elements as $model) {
            if ($model['kind'] !== 'class' || count($this->index->namedTypes($model['name'])) !== 1 || ! $this->index->hasContract($model['id'], 'Illuminate\\Database\\Eloquent\\Model')) {
                continue;
            }
            foreach (array_keys($names) as $name) {
                $knownFactory = $this->index->hasContract($model['id'], 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory')
                    && $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Factories\\HasFactory') === [];
                $selection = $this->calls->sourceMethodCandidate($model['name'], $name, knownHasFactory: $knownFactory);
                $method = $selection['method'];
                if ($method === null || ! isset($method['metadata']['eloquent_attribute'])) {
                    continue;
                }
                $method = $this->index->elements[$method['id']];
                $descriptor = $method['metadata']['eloquent_attribute'];
                if ($selection['unresolved'] || $selection['limited'] || ($method['metadata']['visibility'] ?? null) === 'private' || ($method['metadata']['abstract'] ?? false) || ($method['metadata']['static'] ?? false)
                    || $descriptor['style'] === 'attribute' && $this->index->namedTypes(CatalogAttributes::TYPE) !== []) {
                    $this->diagnostic($method, 'Attribute declaration is inaccessible, ambiguous, static or shadows the framework Attribute class.');

                    continue;
                }
                $this->relation($model, $method['id'], 'declares-model-attribute', $descriptor['attribute']);
                if (! $descriptor['resolved']) {
                    $this->diagnostic($method, 'Attribute return or callback is dynamic or requires source callable resolution.');
                }
                foreach ($descriptor['roles'] as $role) {
                    $this->index->elements[$method['id']]['roles'] = array_values(array_unique([...$this->index->elements[$method['id']]['roles'], $role]));
                    $this->index->elements[$method['id']]['role_evidence'][] = ['role' => $role, 'basis' => 'eloquent-attribute-declaration', 'model' => $model['id'], 'path' => $method['path'], 'line' => $method['line']];
                    $this->relation($model, $method['id'], 'declares-'.$role, $descriptor['attribute']);
                }
                foreach (['get' => 'accessor', 'set' => 'mutator'] as $direction => $role) {
                    $callback = $descriptor[$direction];
                    if ($descriptor['resolved'] && is_string($callback) && ($this->index->elements[$callback]['kind'] ?? null) === 'closure') {
                        $this->relation($method, $callback, 'registers-'.$role.'-callback', $descriptor['attribute']);
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $source */
    private function relation(array $source, string $to, string $kind, string $attribute): void
    {
        $this->index->addRelation(['from' => $source['id'], 'to' => $to, 'kind' => $kind, 'path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['end_line'],
            'knowledge' => 'static', 'resolution' => 'resolved', 'metadata' => ['attribute' => $attribute, 'execution_proven' => false, 'attribute_access_proven' => false]]);
    }

    /** @param array<string, mixed> $source */
    private function diagnostic(array $source, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'eloquent_attribute_analysis', 'message' => $message, 'path' => $source['path'], 'line' => $source['line'], 'subject' => $source['id']];
    }
}
