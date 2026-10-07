<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ExecutionLinks;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Static HasMiddleware declarations are attached to the selected controller action. */
final class CatalogControllerMiddlewareResolver
{
    private int $visits = 0;

    private bool $unresolved = false;

    /** @var array<string, list<array<string, mixed>>> */
    private array $controllerOperations = [];

    /** @var array<string, array<string, mixed>> */
    private array $controllerOptions = [];

    private bool $controllerIndexedPartial = false;

    public function __construct(private readonly CatalogIndex $index) {}

    /** @param array<string, mixed> $facts
     * @param  list<array<string, mixed>>  $routes
     * @return list<array<string, mixed>>
     */
    public function resolve(array $facts, array $routes, ExecutionLinks $links): array
    {
        $this->visits = 0;
        $this->controllerOperations = $this->controllerOptions = [];
        $this->controllerIndexedPartial = false;
        foreach ($facts['operations'] as $op) {
            if (in_array($op['kind'], ['middleware_controller', 'middleware_controller_options'], true) && (++$this->visits > 10000 || ImpactExtractor::sourceLimit(0) !== null)) {
                $this->notice($op['source'], 'Legacy controller middleware indexing reached its operation or memory budget.');
                $this->controllerIndexedPartial = true;
                break;
            }
            if ($op['kind'] === 'middleware_controller') {
                $this->controllerOperations[$op['from']][] = $op;
            } elseif ($op['kind'] === 'middleware_controller_options') {
                $key = serialize($op['registration_site']);
                $existing = $this->controllerOptions[$key] ?? ['options' => [], 'conditions' => [], 'sources' => []];
                $this->controllerOptions[$key] = ['options' => [...$existing['options'], ...$op['options']],
                    'conditions' => [...$existing['conditions'], ...$op['conditions']], 'sources' => [...$existing['sources'], $op['source']]];
            }
        }
        foreach ($routes as &$route) {
            $this->unresolved = false;
            $owner = $route['handler']['class'] ?? null;
            if (! is_string($owner)) {
                continue;
            }
            $attributes = $this->attributes($owner, $route['handler']['method'], $facts['classes'], $links, $route['source']);
            if ($this->unresolved) {
                $route['reasons'][] = 'Controller middleware attribute analysis is incomplete.';
            }
            $this->unresolved = false;
            if ($links->inherits($owner, 'Illuminate\Routing\Controllers\HasMiddleware')) {
                $method = $this->declaration($owner, $facts['classes']);
                if ($method !== null && (! ($method['static'] ?? false) || ($method['visibility'] ?? null) !== 'public' || $method['abstract'])) {
                    $this->notice($method['source'], 'HasMiddleware requires a public static concrete middleware declaration.');
                    $route['reasons'][] = 'Controller middleware declaration does not satisfy its framework contract.';

                    continue;
                }
            } elseif ($links->inherits($owner, 'Illuminate\Routing\Controller') && $this->declaration($owner, $facts['classes']) === null && ! $this->unresolved) {
                $method = $this->legacy($owner, $facts);
            } else {
                $method = ['returns' => [], 'conditional_return' => false, 'source' => $route['source']];
                if ($attributes === []) {
                    continue;
                }
            }
            if ($method === null || $method['returns'] === [] && $attributes === []) {
                $this->notice($route['source'], 'Controller middleware declaration is unresolved.');

                continue;
            }
            if ($method['analysis_incomplete'] ?? false) {
                $route['reasons'][] = 'Legacy controller middleware analysis is incomplete because a source limit was reached.';
            }
            if ($method['conditional_return']) {
                $route['reasons'][] = 'Controller middleware returns depend on runtime conditions.';
            }
            foreach ([...$method['returns'], $attributes] as $values) {
                foreach (is_array($values) && array_is_list($values) ? $values : [$values] as $value) {
                    $filterSources = is_array($value) ? ($value['filter_sources'] ?? []) : [];
                    $source = is_array($value) ? ($value['source'] ?? $method['source']) : $method['source'];
                    if (is_array($value) && ($value['conditions'] ?? []) !== []) {
                        $route['reasons'] = array_values(array_unique([...$route['reasons'], ...$value['conditions']]));
                    }
                    if (is_array($value) && ($value['type'] ?? null) === 'controller_middleware') {
                        $only = array_key_exists('only', $value) ? $value['only'] : [];
                        $except = array_key_exists('except', $value) ? $value['except'] : [];
                        $only = is_string($only) ? [$only] : $only;
                        $except = is_string($except) ? [$except] : $except;
                        if (! is_array($only) || ! array_is_list($only) || ! is_array($except) || ! array_is_list($except)
                            || array_filter($only, fn ($item) => ! is_string($item)) !== [] || array_filter($except, fn ($item) => ! is_string($item)) !== []) {
                            $this->notice($source, 'Controller middleware action filters are dynamic.');
                            $route['reasons'][] = 'Controller middleware applicability is unresolved.';
                        } elseif ($only !== [] && ! in_array($route['handler']['method'], $only, true) || in_array($route['handler']['method'], $except, true)) {
                            continue;
                        }
                        $value = $value['middleware'];
                    }
                    foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $member) {
                        if (is_string($member) && is_array($route['middleware'])) {
                            $route['middleware'][] = $member;
                        } elseif (is_array($member) && ($member['type'] ?? null) === 'callback') {
                            $symbol = '(closure) '.substr($member['symbol'], 11);
                            $ids = $this->index->names[strtolower($symbol)] ?? [];
                            if (count($ids) === 1) {
                                $this->index->addRelation(['from' => $route['id'], 'to' => $ids[0], 'kind' => 'http-controller-middleware',
                                    'path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['line'], 'resolution' => 'conditional', 'knowledge' => 'static',
                                    'metadata' => ['registration' => $source, 'execution_proven' => false, 'conditions' => $route['reasons']]]);
                            } else {
                                $this->notice($source, 'Controller middleware callback source is ambiguous.');
                            }
                        } elseif (is_array($member) && ($member['type'] ?? null) === 'reference' && ($member['first_class'] ?? false)) {
                            $target = $this->callback($member, $facts['classes'], $links, $source);
                            if ($target !== null) {
                                $this->index->addRelation(['from' => $route['id'], 'to' => $target, 'kind' => 'http-controller-middleware',
                                    'path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['line'], 'resolution' => 'conditional', 'knowledge' => 'static',
                                    'metadata' => ['registration' => $source, 'execution_proven' => false, 'conditions' => $route['reasons']]]);
                            }
                        } else {
                            $this->notice($source, 'Controller middleware member is dynamic or unresolved.');
                        }
                    }
                    $this->index->elements[$route['id']]['metadata']['controller_middleware_sources'][] = [...$source,
                        ...($filterSources !== [] ? ['filter_sources' => $filterSources] : [])];
                }
            }
        }
        unset($route);

        return $routes;
    }

    /** @param array<string, array<string, mixed>> $classes
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     *
     * @phpstan-impure
     */
    private function attributes(string $owner, string $action, array $classes, ExecutionLinks $links, array $source): array
    {
        $owners = $this->classAttributeOwners($owner, $source);
        $method = $this->declaration($owner, $classes, name: strtolower($action));
        if ($method !== null) {
            $ids = $this->index->names[strtolower($method['symbol'])] ?? [];
            if (count($ids) === 1) {
                $owners[] = $ids[0];
            }
        }
        $values = [];
        foreach ($owners as $id) {
            foreach ($this->index->out[$id] ?? [] as $position) {
                $edge = $this->index->relations[$position];
                $attribute = $this->index->elements[$edge['to']] ?? null;
                if ($edge['kind'] !== 'contains' || $attribute === null || $attribute['kind'] !== 'attribute') {
                    continue;
                }
                $name = 'Illuminate\Routing\Attributes\Controllers\Middleware';
                if ($attribute['name'] !== $name && ! $links->inherits($attribute['name'], $name)) {
                    continue;
                }
                $site = ['path' => $attribute['path'], 'line' => $attribute['line'], 'offset' => $attribute['offset']];
                $descriptor = $attribute['metadata']['controller_middleware'] ?? null;
                if ($descriptor === null || ! $descriptor['shape_resolved']) {
                    $this->notice($site, 'Controller middleware attribute arguments or custom attribute constructor are unresolved.');

                    continue;
                }
                $values[] = ['type' => 'controller_middleware', 'middleware' => $descriptor['middleware'],
                    'only' => $descriptor['filters_resolved'] ? $descriptor['only'] : null,
                    'except' => $descriptor['filters_resolved'] ? $descriptor['except'] : null,
                    'source' => $site, 'conditions' => []];
            }
        }

        return $values;
    }

    /** Parent class attributes are inherited; trait class attributes are not.
     * @param  array<string, mixed>  $source
     * @param  list<string>  $seen
     * @return list<string>
     */
    private function classAttributeOwners(string $owner, array $source, array $seen = []): array
    {
        if (in_array(strtolower($owner), $seen, true) || count($seen) >= 32 || ++$this->visits > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->notice($source, 'Controller middleware attribute lookup reached a cycle, depth, operation or memory limit.');

            return [];
        }
        $ids = $this->index->namedTypes($owner);
        if (count($ids) !== 1) {
            return [];
        }
        $id = $ids[0];
        $result = [];
        foreach ($this->index->out[$id] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if ($edge['kind'] === 'extends' && isset($this->index->elements[$edge['to']])) {
                array_push($result, ...$this->classAttributeOwners($this->index->elements[$edge['to']]['name'], $source, [...$seen, strtolower($owner)]));
            }
        }
        $result[] = $id;

        return $result;
    }

    /** @param array<string, array<string, mixed>> $classes
     * @param  list<string>  $seen
     * @return array<string, mixed>|null
     *
     * @phpstan-impure
     */
    private function declaration(string $owner, array $classes, array $seen = [], string $name = 'middleware'): ?array
    {
        $key = strtolower($owner);
        $ids = $this->index->namedTypes($owner);
        if (count($ids) !== 1 || ! isset($classes[$key]) || ($classes[$key]['ambiguous'] ?? false)) {
            return null;
        }
        $element = $this->index->elements[$ids[0]];
        $source = ['path' => $element['path'], 'line' => $element['line']];
        if (in_array($key, $seen, true) || count($seen) >= 32 || ++$this->visits > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->notice($source, 'Controller middleware lookup reached a cycle, inheritance, operation or memory limit.');

            return null;
        }
        $class = $classes[$key];
        if ($class['kind'] === 'interface') {
            return null;
        }
        if (isset($class['methods'][$name])) {
            $method = $class['methods'][$name];
            $members = $this->index->names[strtolower($method['symbol'])] ?? [];
            if (count($members) !== 1) {
                return null;
            }

            if ($name !== 'middleware' && $method['middleware_candidates'] !== []) {
                $method['returns'] = $method['middleware_candidates'];
            }

            return [...$method, ...array_intersect_key($this->index->elements[$members[0]]['metadata'], ['static' => true, 'visibility' => true]), 'scope' => $owner];
        }
        $seen[] = $key;
        $traits = [];
        foreach ($class['traits'] as $trait) {
            $traitIds = $this->index->namedTypes($trait);
            if (count($traitIds) !== 1) {
                $this->notice($source, 'Controller middleware trait source is absent or ambiguous.');

                return null;
            }
            $traits[] = $traitIds[0];
        }
        $selection = CatalogTraitSelection::candidates($this->index, $ids[0], $name, $traits);
        if ($selection === null) {
            $this->notice($source, 'Controller middleware trait adaptations are unresolved.');

            return null;
        }
        $candidates = [];
        foreach ($selection as [$trait, $traitName]) {
            $method = $this->declaration($this->index->elements[$trait]['name'], $classes, $seen, $traitName);
            if ($method !== null) {
                foreach ($element['metadata']['trait_rules'] ?? [] as $rule) {
                    if ($rule['method'] === $traitName && ($rule['alias'] ?? $traitName) === $name
                        && ($rule['trait'] === null || strcasecmp($rule['trait'], $this->index->elements[$trait]['name']) === 0)
                        && $rule['visibility'] !== null) {
                        $method['visibility'] = $rule['visibility'];
                    }
                }
                $candidates[$method['symbol']] = $method;
                $candidates[$method['symbol']]['scope'] = $owner;
            }
        }
        if ($this->unresolved) {
            return null;
        }
        if (count($candidates) > 1) {
            $this->notice($source, 'Controller middleware trait declaration is ambiguous.');

            return null;
        }
        if ($candidates !== []) {
            return array_values($candidates)[0];
        }
        foreach ($class['parents'] as $ancestor) {
            $method = $this->declaration($ancestor, $classes, $seen, $name);
            if ($this->unresolved) {
                return null;
            }
            if ($method !== null) {
                return $method;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $facts
     * @return array<string, mixed>|null
     */
    private function legacy(string $owner, array $facts): ?array
    {
        $method = $this->declaration($owner, $facts['classes'], name: '__construct');
        if ($method === null) {
            return null;
        }
        if (($method['visibility'] ?? null) !== 'public' || ($method['static'] ?? false) || $method['abstract']) {
            $this->notice($method['source'], 'Legacy controller constructor cannot be instantiated through its public framework contract.');

            return null;
        }
        $values = [];
        $constructors = [$method['symbol'] => []];
        $queue = [$method['symbol']];
        $traversed = 0;
        while ($queue !== [] && $traversed++ < 32) {
            $symbol = array_shift($queue);
            foreach ($this->index->names[strtolower($symbol)] ?? [] as $id) {
                foreach ($this->index->out[$id] ?? [] as $position) {
                    if (++$this->visits > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                        $this->notice($method['source'], 'Legacy controller constructor traversal reached its operation or memory budget.');

                        return [...$method, 'returns' => [], 'conditional_return' => true];
                    }
                    $edge = $this->index->relations[$position];
                    $target = $this->index->elements[$edge['to']] ?? null;
                    if (in_array($edge['kind'], ['calls', 'references-call'], true) && ($edge['metadata']['form'] ?? null) === 'parent' && $target !== null
                        && str_ends_with(strtolower($target['name']), '::__construct') && ! isset($constructors[$target['name']])) {
                        if (($target['metadata']['visibility'] ?? null) === 'private' || ($target['metadata']['abstract'] ?? false)) {
                            $this->notice(['path' => $edge['path'], 'line' => $edge['line']], 'Parent constructor source call targets a private declaration or abstract body.');

                            continue;
                        }
                        if ($edge['kind'] === 'references-call') {
                            continue;
                        }
                        $constructors[$target['name']] = ['Parent constructor call is a source path; runtime branch execution is not established.'];
                        $queue[] = $target['name'];
                    }
                }
            }
        }
        $partial = $queue !== [] || $this->controllerIndexedPartial;
        if ($queue !== []) {
            $this->notice($method['source'], 'Legacy controller constructor traversal reached its 32 constructor budget.');
        }
        foreach ($constructors as $symbol => $conditions) {
            foreach ($this->controllerOperations[$symbol] ?? [] as $op) {
                if (++$this->visits > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                    $this->notice($op['source'], 'Legacy controller middleware composition reached its operation or memory budget.');
                    $partial = true;
                    break 2;
                }
                $options = $this->controllerOptions[serialize($op['source'])] ?? ['options' => [], 'conditions' => [], 'sources' => []];
                $filters = [...$op['args']['options'], ...$options['options']];
                $values[] = ['type' => 'controller_middleware', 'middleware' => $op['args']['middleware'],
                    'only' => $options['conditions'] !== [] ? null : (array_key_exists('only', $filters) ? $filters['only'] : []),
                    'except' => $options['conditions'] !== [] ? null : (array_key_exists('except', $filters) ? $filters['except'] : []), 'source' => $op['source'], 'filter_sources' => $options['sources'],
                    'conditions' => [...$op['conditions'], ...$conditions, ...$options['conditions'], ...($partial ? ['Legacy constructor analysis is incomplete because a source limit was reached.'] : [])]];
            }
        }

        return [...$method, 'returns' => [$values], 'conditional_return' => false, 'analysis_incomplete' => $partial];
    }

    /** @param array<string, mixed> $callback
     * @param  array<string, array<string, mixed>>  $classes
     * @param  array<string, mixed>  $source
     */
    private function callback(array $callback, array $classes, ExecutionLinks $links, array $source): ?string
    {
        $symbol = $callback['symbol'];
        if (str_contains($symbol, '::')) {
            [$owner, $name] = explode('::', $symbol, 2);
            $method = $this->declaration($owner, $classes, name: strtolower($name));
            $scope = $method['scope'] ?? '';
            $visibility = $method['visibility'] ?? null;
            $creator = $callback['creator_class'] ?? '';
            $accessible = $visibility === 'public' || $visibility === 'private' && strcasecmp($scope, $creator) === 0
                || $visibility === 'protected' && $links->inherits($creator, $scope);
            if ($method === null || $method['abstract'] || ! $accessible
                || ($callback['callable_form'] ?? null) === 'static-first-class' && ! ($method['static'] ?? false)) {
                $this->notice($source, 'Controller middleware first-class method is absent, ambiguous or inaccessible.');

                return null;
            }
            $symbol = $method['symbol'];
        } else {
            foreach ($callback['function_names'] ?? [$symbol] as $name) {
                $ids = array_values(array_filter($this->index->names[strtolower($name)] ?? [], fn ($id) => $this->index->elements[$id]['kind'] === 'function'));
                if ($ids !== []) {
                    if (count($ids) === 1) {
                        return $ids[0];
                    }
                    break;
                }
            }
            $this->notice($source, 'Controller middleware first-class function source is absent or ambiguous.');

            return null;
        }
        $ids = $this->index->names[strtolower($symbol)] ?? [];

        return count($ids) === 1 ? $ids[0] : null;
    }

    /** @param array<string, mixed> $source */
    private function notice(array $source, string $message): void
    {
        $this->unresolved = true;
        $this->index->diagnostics[] = ['code' => 'controller_middleware_analysis', 'message' => $message, 'path' => $source['path'], 'line' => $source['line'], 'subject' => null];
    }
}
