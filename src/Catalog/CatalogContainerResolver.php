<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Source bindings are candidates, never the effective runtime container configuration. */
final class CatalogContainerResolver
{
    private const CONTRACTS = ['Illuminate\\Contracts\\Container\\Container', 'Illuminate\\Container\\Container', 'Illuminate\\Contracts\\Foundation\\Application', 'Illuminate\\Foundation\\Application'];

    public function __construct(private readonly CatalogIndex $index) {}

    /** @param list<array<string, mixed>> $registrations */
    public function resolve(array $registrations): void
    {
        $bindings = [];
        foreach ($registrations as $row) {
            $metadata = $row['metadata'];
            if (! $this->receiver($metadata['receiver'])) {
                continue;
            }
            $abstracts = $metadata['abstracts'];
            if (count($abstracts) !== 1 && $metadata['callback'] === null || $abstracts === [] || $metadata['context_unknown']) {
                $this->diagnostic($row, 'dynamic_binding', 'Container abstract or contextual consumer cannot be resolved from source.');
                $this->index->addRelation($row);

                continue;
            }
            foreach ($abstracts as $abstract) {
                $id = CatalogElement::identity($row['path'], 'container-binding', $abstract, $metadata['offset']);
                $element = new CatalogElement($id, $abstract, 'container-binding', $row['line'], $row['end_line'], $metadata['offset'], $row['from'], metadata: [
                    'logical_resource' => true, 'mode' => $metadata['mode'], 'contexts' => $metadata['contexts'], 'activation_unknown' => true,
                    'factory_unknown' => $metadata['factory_unknown'], 'execution_proven' => false,
                ]);
                $this->index->elements[$id] = [...$element->toArray(), 'path' => $row['path'], 'knowledge' => 'static', 'role_evidence' => [],
                    'sources' => [['path' => $row['path'], 'line' => $row['line'], 'end_line' => $row['end_line']]]];
                $this->index->names[strtolower($abstract)][] = $id;
                $this->index->addRelation([...$row, 'to' => $id, 'kind' => 'registers-binding', 'metadata' => ['execution_proven' => false]]);
                foreach ($this->targets($abstract) as $target) {
                    $this->index->addRelation([...$row, 'from' => $id, 'to' => $target, 'kind' => 'binding-contract', 'metadata' => []]);
                }
                $implementations = $metadata['implementations'];
                if (count($implementations) > 1 || $metadata['factory_unknown']) {
                    $this->diagnostic($row, 'ambiguous_binding_factory', 'Factory return has multiple candidates or an unresolved branch.');
                }
                if ($implementations === []) {
                    $this->diagnostic($row, 'dynamic_binding_implementation', 'Container implementation cannot be resolved from source.');
                }
                foreach ($implementations as $implementation) {
                    foreach ($this->targets($implementation) as $target) {
                        $this->index->addRelation([...$row, 'from' => $id, 'to' => $target, 'kind' => 'provides', 'metadata' => ['execution_proven' => false, 'factory_unknown' => $metadata['factory_unknown']]]);
                    }
                }
                if (is_string($metadata['callback'])) {
                    $this->index->addRelation([...$row, 'from' => $id, 'to' => $metadata['callback'], 'kind' => 'container-factory', 'metadata' => ['execution_proven' => false]]);
                }
                $bindings[strtolower($abstract)][] = ['id' => $id, 'contexts' => $metadata['contexts'], 'row' => $row];
            }
        }
        foreach ($bindings as $candidates) {
            if (count($candidates) > 1) {
                // Different contexts need not compete, but activation remains unknown for each.
                $counts = [];
                foreach ($candidates as $candidate) {
                    $key = serialize($candidate['contexts']);
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
                foreach ($candidates as $candidate) {
                    if ($counts[serialize($candidate['contexts'])] > 1) {
                        $this->diagnostic($candidate['row'], 'competing_bindings', 'Several source registrations may bind this abstract; no runtime winner is selected.');
                    }
                }
            }
        }
        foreach ($this->index->elements as $element) {
            if ($element['kind'] !== 'method' || ! str_ends_with(strtolower($element['name']), '::__construct') || ! isset($this->index->elements[$element['parent']])) {
                continue;
            }
            $consumer = $this->index->elements[$element['parent']]['name'];
            foreach ($element['metadata']['parameters'] ?? [] as $parameter) {
                foreach ($parameter['types'] as $type) {
                    foreach ($bindings[strtolower($type)] ?? [] as $candidate) {
                        if ($candidate['contexts'] !== [] && ! in_array(strtolower($consumer), array_map('strtolower', $candidate['contexts']), true)) {
                            continue;
                        }
                        $this->index->addRelation(['from' => $element['id'], 'to' => $candidate['id'], 'kind' => 'injected-binding', 'line' => $parameter['line'],
                            'end_line' => $parameter['line'], 'resolution' => 'conditional', 'metadata' => ['execution_proven' => false, 'activation_unknown' => true], 'path' => $element['path'], 'knowledge' => 'static']);
                    }
                }
            }
        }
    }

    private function receiver(string $receiver): bool
    {
        if ($receiver === 'framework') {
            return true;
        }
        if ($receiver === 'helper') {
            return array_filter($this->index->names['app'] ?? [], fn ($id) => $this->index->elements[$id]['kind'] === 'function') === [];
        }
        $provider = str_starts_with($receiver, 'provider:');
        $name = substr($receiver, $provider ? 9 : 5);
        $ids = $this->index->namedTypes($name);
        if ($provider) {
            return count($ids) === 1 && $this->index->hasContract($ids[0], 'Illuminate\\Support\\ServiceProvider');
        }
        foreach (self::CONTRACTS as $contract) {
            if (strtolower($name) === strtolower($contract) || count($ids) === 1 && $this->index->hasContract($ids[0], $contract)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function targets(string $name): array
    {
        $ids = $this->index->namedTypes($name);

        return $ids === [] ? ['php:'.$name] : $ids;
    }

    /** @param array<string, mixed> $row */
    private function diagnostic(array $row, string $code, string $message): void
    {
        $this->index->diagnostics[] = ['code' => $code, 'message' => $message, 'path' => $row['path'], 'line' => $row['line'], 'subject' => $row['from']];
    }
}
