<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Rechecks facade/helper ownership against the current snapshot, including cached callers. */
final class CatalogResourceResolver
{
    public function __construct(private readonly CatalogIndex $index) {}

    public function resolve(): void
    {
        foreach ($this->index->relations as &$edge) {
            $receiver = $edge['metadata']['api_receiver'] ?? null;
            $helper = $edge['metadata']['api_helper'] ?? null;
            $shadow = is_string($receiver) && $this->index->namedTypes($receiver) !== [];
            if (is_string($helper)) {
                foreach ($edge['metadata']['api_functions'] ?? [$helper] as $candidate) {
                    foreach ($this->index->names[strtolower($candidate)] ?? [] as $id) {
                        $shadow = $shadow || $this->index->elements[$id]['kind'] === 'function';
                    }
                }
            }
            if (! $shadow) {
                continue;
            }
            $edge['kind'] = 'references-'.$edge['kind'];
            $edge['resolution'] = 'structural';
            $this->index->diagnostics[] = ['code' => 'resource_source_shadow', 'message' => 'Source declaration shadows the framework resource API; no effect or callback execution is inferred.', 'path' => $edge['path'], 'line' => $edge['line'], 'subject' => $edge['from']];
        }
        unset($edge);
    }
}
