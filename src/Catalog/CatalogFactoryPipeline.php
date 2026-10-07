<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Replays source-declared transformations, without evaluating callbacks or state values. */
final class CatalogFactoryPipeline
{
    /** @var list<array<string, mixed>> */
    private array $callbacks = [];

    /** @var list<array<string, mixed>> */
    private array $invocations = [];

    /** @var list<array<string, mixed>> */
    private array $relationships = [];

    private bool $instances = true;

    /** @var array{kind: string, name: ?string}|null */
    private ?array $connection = null;

    /** @var array{path: string, line: int, end_line: int}|null */
    private ?array $connectionSource = null;

    private int $visited = 0;

    private ?string $failure = null;

    private bool $limited = false;

    public function __construct(private readonly CatalogCallResolver $calls) {}

    /** @param array<string, mixed> $factory
     * @param  array<string, mixed>  $operation
     * @param  array<string, list<array<string, mixed>>>  $pipelines
     * @return array{callbacks: list<array<string, mixed>>, invocations: list<array<string, mixed>>, instances_possible: bool, relationships: list<array<string, mixed>>, connection: ?array{kind: string, name: ?string}, connection_source: ?array{path: string, line: int, end_line: int}, failure: ?string, limited: bool}
     */
    public function resolve(array $factory, array $operation, array $pipelines): array
    {
        $this->callbacks = $this->invocations = $this->relationships = [];
        $this->instances = true;
        $this->connection = null;
        $this->connectionSource = null;
        $this->visited = 0;
        $this->failure = null;
        $this->limited = false;
        $steps = $operation['metadata']['steps'];
        $root = array_shift($steps);
        $terminal = array_pop($steps);
        if ($root === null || $terminal === null || ! $operation['metadata']['selector_resolved']) {
            $this->failure = 'Factory operation shape or callback selector is unresolved.';
        } else {
            if ($root['method'] === 'new') {
                $this->apply($root, $operation['path']);
            }
            $configure = [...$root, 'method' => 'configure', 'callbacks' => [], 'argument_count' => 0, 'clear_stage' => null, 'count_overridden' => false, 'connection' => null, 'relationship' => null];
            if ($this->transform($factory, $configure, $operation['path'], $pipelines)) {
                if ($root['method'] === 'factory') {
                    $this->apply($root, $operation['path']);
                }
                foreach ($steps as $step) {
                    if (! $this->transform($factory, $step, $operation['path'], $pipelines)) {
                        break;
                    }
                }
                if ($this->failure === null) {
                    $this->apply($terminal, $operation['path']);
                }
            }
        }

        return ['callbacks' => $this->callbacks, 'invocations' => $this->invocations, 'instances_possible' => $this->instances, 'relationships' => $this->relationships, 'connection' => $this->connection, 'connection_source' => $this->connectionSource, 'failure' => $this->failure, 'limited' => $this->limited];
    }

    /** @param array<string, mixed> $factory
     * @param  array<string, mixed>  $step
     * @param  array<string, list<array<string, mixed>>>  $pipelines
     * @param  array<string, true>  $seen
     */
    private function transform(array $factory, array $step, string $path, array $pipelines, ?string $scope = null, array $seen = []): bool
    {
        if (++$this->visited > 4096 || count($seen) >= 32 || $this->visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
            $this->failure = 'Factory source pipeline reached its depth, step or memory budget.';
            $this->limited = true;

            return false;
        }
        $selection = $this->calls->sourceMethodCandidate($factory['name'], $step['method']);
        if ($selection['unresolved'] || $selection['limited']) {
            $this->failure = 'Factory preparation member selection is unresolved.';
            $this->limited = $selection['limited'];

            return false;
        }
        if ($selection['method'] === null) {
            if (! in_array($step['method'], [...FactoryLifecycleCatalogExtractor::PREPARATIONS, 'configure'], true)) {
                $this->failure = 'Factory preparation method has no supported framework or source implementation.';

                return false;
            }
            $this->apply($step, $path);

            return true;
        }
        $method = $selection['method'];
        $visibility = $method['metadata']['visibility'] ?? null;
        $accessible = $visibility === 'public' || $scope !== null && $visibility === 'protected';
        $values = $pipelines[$method['id']] ?? [];
        if (! $accessible || ($method['metadata']['static'] ?? false) || count($values) !== 1 || ! $values[0]['metadata']['selector_resolved'] || $step['argument_count'] !== 0) {
            $this->failure = 'Factory source state return, arguments, callbacks or visibility require inspection.';

            return false;
        }
        if (isset($seen[$method['id']])) {
            $this->failure = 'Factory source state pipeline contains a recursive method cycle.';

            return false;
        }
        $seen[$method['id']] = true;
        $pipeline = $values[0];
        $this->invocations[] = ['target' => $method['id'], 'path' => $path, 'line' => $step['line'], 'end_line' => $step['end_line'], 'stage' => $step['method'] === 'configure' ? 'configuration' : 'state-method'];
        foreach ($pipeline['metadata']['steps'] as $child) {
            if (! $this->transform($factory, $child, $pipeline['path'], $pipelines, $method['scope'], $seen)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $step */
    private function apply(array $step, string $path): void
    {
        if ($step['clear_stage'] !== null) {
            $this->callbacks = array_values(array_filter($this->callbacks, fn ($row) => $row['stage'] !== $step['clear_stage']));
        }
        if ($step['count_overridden']) {
            $this->instances = $step['instances_possible'];
        }
        if ($step['connection'] !== null) {
            $this->connection = $step['connection'];
            $this->connectionSource = ['path' => $path, 'line' => $step['line'], 'end_line' => $step['end_line']];
        }
        if ($step['relationship'] !== null) {
            $this->relationships[] = [...$step['relationship'], 'api' => $step['method'], 'path' => $path, 'line' => $step['line'], 'end_line' => $step['end_line']];
        }
        array_push($this->callbacks, ...$step['callbacks']);
    }
}
