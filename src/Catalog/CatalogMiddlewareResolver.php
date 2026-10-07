<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ExecutionLinks;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Source global HTTP stacks, without constructing the consumer application. */
final class CatalogMiddlewareResolver
{
    private int $visits = 0;

    public function __construct(private readonly CatalogIndex $index) {}

    /** @param array<string, mixed> $facts
     * @param  list<array<string, mixed>>  $routes
     */
    public function resolve(array $facts, array $routes, ExecutionLinks $links): void
    {
        $stacks = $callbacks = [];
        foreach ($facts['classes'] as $class) {
            if (! $class['abstract'] && $links->inherits($class['name'], 'Illuminate\Foundation\Http\Kernel')) {
                $derived = array_filter($facts['classes'], fn ($candidate) => ! $candidate['abstract'] && $candidate['name'] !== $class['name'] && $links->inherits($candidate['name'], $class['name']));
                if ($derived !== []) {
                    continue;
                }
                $declaration = $this->middlewareProperty($class['name'], $facts['classes']);
                if ($declaration !== null) {
                    $source = ['path' => $declaration['path'], 'line' => $declaration['line'], 'offset' => $declaration['offset']];
                    $stacks[$class['name']] = $this->entries($declaration['properties']['middleware'], $source);
                }
            }
        }
        foreach ($facts['operations'] as $op) {
            if ($op['kind'] === 'middleware_registration' && ($op['args']['callback']['type'] ?? null) === 'callback') {
                if (str_starts_with($op['from'], '(callback)') && ! isset($callbacks[$op['from']])) {
                    $this->notice($op['source'], 'withMiddleware registration is inside an unactivated callback.');

                    continue;
                }
                $callbacks[$op['args']['callback']['symbol']] = $op['conditions'];
            }
        }
        foreach ($facts['operations'] as $op) {
            if ($op['kind'] !== 'middleware_global') {
                continue;
            }
            if (! isset($callbacks[$op['from']])) {
                $this->notice($op['source'], 'Global middleware declaration has no recognized withMiddleware activation.');

                continue;
            }
            $stack = $stacks[$op['from']] ?? [];
            $entries = $this->entries($op['args'][0] ?? $op['args']['search'] ?? null, $op['source']);
            $conditions = [...$callbacks[$op['from']], ...$op['conditions']];
            foreach ($entries as &$entry) {
                $entry['conditions'] = $conditions;
            }
            unset($entry);
            if ($op['conditions'] !== [] && in_array($op['method'], ['use', 'remove', 'replace'], true)) {
                $this->notice($op['source'], 'Conditional middleware mutation leaves several possible stacks.');
                $entries = $op['method'] === 'replace' ? $this->entries($op['args']['replace'], $op['source']) : ($op['method'] === 'remove' ? [] : $entries);
                $stacks[$op['from']] = [...$stack, ...$entries];

                continue;
            }
            if (in_array($op['method'], ['remove', 'replace'], true)) {
                $names = array_column($entries, 'name');
                $found = false;
                $updated = [];
                foreach ($stack as $entry) {
                    if (in_array($entry['name'], $names, true)) {
                        $found = true;
                        if ($op['method'] === 'replace') {
                            $updated = [...$updated, ...$this->entries($op['args']['replace'], $op['source'])];
                        }
                    } else {
                        $updated[] = $entry;
                    }
                }
                if (! $found) {
                    $this->notice($op['source'], 'Middleware mutation may target a framework default outside the declared source stack.');
                }
                $stacks[$op['from']] = $updated;
            } else {
                $stacks[$op['from']] = match ($op['method']) {
                    'use' => $entries,
                    'prepend' => [...$entries, ...$stack],
                    default => [...$stack, ...$entries],
                };
            }
        }
        $dispatch = [];
        foreach ($routes as $route) {
            foreach ($stacks as $stack) {
                foreach ($stack as $position => $entry) {
                    $source = $entry['source'];
                    if (++$this->visits > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                        $this->notice($source, 'Global middleware composition reached its operation or memory budget.');
                        (new CatalogCallResolver($this->index))->resolve($dispatch);

                        return;
                    }
                    $dispatch[] = ['from' => $route['id'], 'to' => 'dispatch:'.hash('xxh128', $entry['name'].'::handle'), 'kind' => 'http-global-middleware',
                        'path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['line'], 'resolution' => 'conditional', 'knowledge' => 'static',
                        'metadata' => ['receiver' => $entry['name'], 'method' => 'handle', 'exact_receiver' => true, 'form' => 'middleware', 'execution_proven' => false,
                            'conditions' => [...($entry['conditions'] ?? []), 'Source registration and HTTP kernel must be active; preceding middleware may stop the request.'], 'stack_position' => $position, 'registration' => $source]];
                }
            }
        }
        (new CatalogCallResolver($this->index))->resolve($dispatch);
    }

    /** @param array<string, array<string, mixed>> $classes
     * @param  array<string, bool>  $seen
     * @return array<string, mixed>|null
     */
    private function middlewareProperty(string $name, array $classes, array $seen = []): ?array
    {
        $key = strtolower($name);
        if (isset($seen[$key]) || count($seen) >= 32 || ! isset($classes[$key])) {
            return null;
        }
        $seen[$key] = true;
        $class = $classes[$key];
        if ($class['ambiguous'] ?? false) {
            $this->notice(['path' => $class['path'], 'line' => $class['line']], 'Kernel middleware declaration is ambiguous.');

            return null;
        }
        if (array_key_exists('middleware', $class['properties'])) {
            return $class;
        }
        foreach ([...$class['traits'], ...$class['parents']] as $ancestor) {
            $declaration = $this->middlewareProperty($ancestor, $classes, $seen);
            if ($declaration !== null) {
                return $declaration;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $source
     * @return list<array{name: string, source: array<string, mixed>}>
     */
    private function entries(mixed $values, array $source): array
    {
        $entries = [];
        foreach (is_array($values) && array_is_list($values) ? $values : [$values] as $value) {
            if (is_string($value)) {
                $entries[] = ['name' => $value, 'source' => $source];
            } else {
                $this->notice($source, 'Global middleware class is dynamic or unresolved.');
            }
        }

        return $entries;
    }

    /** @param array<string, mixed> $source */
    private function notice(array $source, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'middleware_analysis', 'message' => $message, 'path' => $source['path'], 'line' => $source['line'], 'subject' => null];
    }
}
