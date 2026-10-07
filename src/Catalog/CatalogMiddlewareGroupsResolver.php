<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ExecutionLinks;

/** Expands declared middleware groups per route while retaining registration witnesses. */
final class CatalogMiddlewareGroupsResolver
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $groups = [];

    private int $visits = 0;

    public function __construct(private readonly CatalogIndex $index) {}

    /** @param array<string, mixed> $facts
     * @param  list<array<string, mixed>>  $routes
     * @return list<array<string, mixed>>
     */
    public function resolve(array $facts, array $routes, ExecutionLinks $links): array
    {
        $active = [];
        foreach ($facts['classes'] as $class) {
            if ($links->inherits($class['name'], 'Illuminate\Foundation\Http\Kernel')) {
                foreach ((array) ($class['properties']['middlewareGroups'] ?? []) as $name => $values) {
                    if (is_string($name)) {
                        $this->groups[$name][$class['name']] = ['values' => $this->names($values, $class), 'sources' => [['path' => $class['path'], 'line' => $class['line']]], 'conditions' => []];
                    }
                }
            }
        }
        foreach ($facts['operations'] as $op) {
            if ($op['kind'] === 'middleware_registration' && ($op['args']['callback']['type'] ?? null) === 'callback'
                && (! str_starts_with($op['from'], '(callback)') || isset($active[$op['from']]))) {
                $active[$op['args']['callback']['symbol']] = true;
            }
        }
        foreach ($facts['operations'] as $op) {
            if ($op['kind'] !== 'middleware_group') {
                continue;
            }
            if (! isset($active[$op['from']]) || ! is_string($op['args']['group'])) {
                $this->notice($op['source'], 'Middleware group name or activation is unresolved.');

                continue;
            }
            $name = $op['args']['group'];
            $prior = $this->groups[$name][$op['from']] ?? ['values' => [], 'sources' => [], 'conditions' => []];
            $values = $prior['values'];
            if (in_array($op['method'], ['web', 'api'], true)) {
                $this->notice($op['source'], 'Framework default group members outside source are not enumerated.');
                $values = [...$this->names($op['args']['prepend'], $op['source']), ...$values, ...$this->names($op['args']['append'], $op['source'])];
                $values = array_values(array_diff($values, $this->names($op['args']['remove'], $op['source'])));
                foreach ((array) $op['args']['replace'] as $search => $replacement) {
                    if (is_string($search) && is_string($replacement)) {
                        $values = array_map(fn ($value) => $value === $search ? $replacement : $value, $values);
                    }
                }
            } else {
                $members = $this->names($op['args']['middleware'], $op['source']);
                if ($op['conditions'] !== [] && in_array($op['method'], ['group', 'removefromgroup', 'replaceingroup'], true)) {
                    $this->notice($op['source'], 'Conditional group mutation leaves several possible member sets.');
                    $values = [...$values, ...($op['method'] === 'removefromgroup' ? [] : ($op['method'] === 'replaceingroup' ? $this->names($op['args']['replace'], $op['source']) : $members))];
                } else {
                    $values = match ($op['method']) {
                        'group' => $members,
                        'prependtogroup' => [...$members, ...$values],
                        'removefromgroup' => array_values(array_diff($values, $members)),
                        'replaceingroup' => array_map(fn ($value) => in_array($value, $members, true) && is_string($op['args']['replace']) ? $op['args']['replace'] : $value, $values),
                        default => [...$values, ...$members],
                    };
                }
            }
            $this->groups[$name][$op['from']] = ['values' => $values, 'sources' => [...$prior['sources'], $op['source']], 'conditions' => [...$prior['conditions'], ...$op['conditions']]];
        }
        foreach ($routes as &$route) {
            if (! is_array($route['middleware']) || ! is_array($route['excluded_middleware'])) {
                continue;
            }
            $evidence = [];
            $members = [];
            foreach (array_diff($route['middleware'], $route['excluded_middleware']) as $name) {
                array_push($members, ...$this->expand($name, $route['source'], $evidence, excluded: $route['excluded_middleware']));
            }
            $route['middleware'] = array_values(array_unique($members));
            $this->index->elements[$route['id']]['metadata']['middleware_group_sources'] = $evidence;
        }
        unset($route);

        return $routes;
    }

    /** @param array<string, mixed> $source
     * @param  list<array<string, mixed>>  $evidence
     * @param  list<string>  $stack
     * @param  list<string>  $excluded
     * @return list<string>
     */
    private function expand(string $name, array $source, array &$evidence, array $stack = [], array $excluded = []): array
    {
        if (in_array($name, $excluded, true)) {
            return [];
        }
        if (++$this->visits > 10000 || count($stack) >= 12 || in_array($name, $stack, true)) {
            $this->notice($source, 'Middleware group expansion reached a cycle or traversal limit.');

            return [];
        }
        if (! isset($this->groups[$name])) {
            return [$name];
        }
        if (count($this->groups[$name]) > 1) {
            $this->notice($source, 'Several middleware group registrations are source candidates; runtime selection is unresolved.');
        }
        $result = [];
        foreach ($this->groups[$name] as $registration) {
            foreach ($registration['sources'] as $witness) {
                $evidence[] = ['path' => $witness['path'], 'line' => $witness['line'], 'offset' => $witness['offset'] ?? null,
                    'group' => $name, 'conditions' => $registration['conditions']];
            }
            foreach ($registration['values'] as $member) {
                array_push($result, ...$this->expand($member, $source, $evidence, [...$stack, $name], $excluded));
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $source
     * @return list<string>
     */
    private function names(mixed $values, array $source): array
    {
        $result = [];
        foreach (is_array($values) && array_is_list($values) ? $values : [$values] as $value) {
            if (is_string($value)) {
                $result[] = $value;
            } else {
                $this->notice($source, 'Middleware group member is dynamic or unresolved.');
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $source */
    private function notice(array $source, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'middleware_group_analysis', 'message' => $message, 'path' => $source['path'], 'line' => $source['line'], 'subject' => null];
    }
}
