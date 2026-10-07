<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\HttpRouteExtractor;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Composes cached declarations under current source registration contexts. */
final class CatalogHttpResolver
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $templates = [];

    /** @var array<string, bool> */
    private array $loaded = [];

    /** @var array<string, bool> */
    private array $visited = [];

    private int $visits = 0;

    private int $operations = 0;

    private bool $limited = false;

    /** @var array<string, bool> */
    private array $providerSourceCandidates = [];

    /** @var list<array<string, mixed>> */
    private array $dispatch = [];

    /** @var list<array<string, mixed>> */
    public array $routes = [];

    public function __construct(private readonly CatalogIndex $index) {}

    /** @param list<array<string, mixed>> $operations */
    public function resolve(array $operations): void
    {
        $bootstrapEnabled = true;
        $discoveryExclusions = [];
        foreach ($this->index->elements as $element) {
            if ($element['kind'] === 'composer-manifest') {
                $discoveryExclusions = $element['metadata']['discovery_exclusions'];
            }
        }
        foreach ($operations as $row) {
            if ($row['metadata']['operation'] === 'provider-settings') {
                if ($row['path'] === 'bootstrap/app.php' && ($row['metadata']['provider_scope'] ?? null) === null) {
                    $bootstrapEnabled = $row['metadata']['context']['possible'] ? null : $row['metadata']['bootstrap_enabled'];
                }

                continue;
            }
            $package = $row['metadata']['package_name'] ?? null;
            if (is_string($package) && (in_array('*', $discoveryExclusions, true) || in_array($package, $discoveryExclusions, true))) {
                continue;
            }
            $this->templates[$row['path']][] = $row;
        }
        $context = HttpRouteExtractor::context();
        foreach (['bootstrap/app.php', 'bootstrap/providers.php', 'composer.lock', 'vendor/composer/installed.json'] as $root) {
            $rootContext = $context;
            if ($root === 'bootstrap/providers.php') {
                if ($bootstrapEnabled === false) {
                    $this->notice($root, 1, 'http_bootstrap_providers_disabled', 'withProviders explicitly disables bootstrap provider-file registration.');

                    continue;
                }
                if ($bootstrapEnabled === null) {
                    $rootContext['possible'] = true;
                    $rootContext['reasons'][] = 'Bootstrap provider-file registration depends on a dynamic or conditional withProviders setting.';
                }
            }
            $this->load($root, $rootContext);
        }
        foreach ($this->templates as $path => $rows) {
            if ($this->limited) {
                break;
            }
            if ($path === 'bootstrap/providers.php' && $bootstrapEnabled === false) {
                continue;
            }
            if (! isset($this->loaded[$path])) {
                $fallback = $context;
                $fallback['possible'] = true;
                $fallback['reasons'][] = 'Source declaration found; its loading registration is not established.';
                $this->load($path, $fallback);
            }
        }
        (new CatalogCallResolver($this->index))->resolve($this->dispatch);
    }

    /** @param array<string, mixed> $parent
     * @param  list<string>  $stack
     */
    private function load(string $path, array $parent, array $stack = []): void
    {
        if ($this->limited) {
            return;
        }
        if (in_array($path, $stack, true)) {
            $this->notice($path, 1, 'http_source_cycle', 'Route source includes form a cycle.');

            return;
        }
        $key = $path.'#'.hash('xxh128', serialize($parent));
        if (isset($this->visited[$key])) {
            return;
        }
        if (count($stack) >= 12 || ++$this->visits > 10000) {
            $this->notice($path, 1, 'http_composition_limit', 'Route composition reached its source/context/depth budget.');

            return;
        }
        $this->visited[$key] = true;
        $this->loaded[$path] = true;
        foreach ($this->templates[$path] ?? [] as $row) {
            if ($this->limited) {
                break;
            }
            if (++$this->operations > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->limited = true;
                $this->notice($path, $row['line'], 'http_composition_limit', 'Route composition reached its operation or memory budget.');
                break;
            }
            $op = $row['metadata'];
            if (isset($parent['provider_scopes']) && is_string($op['provider_scope'] ?? null) && ! isset($parent['provider_scopes'][strtolower($op['provider_scope'])])) {
                continue;
            }
            if (is_string($op['provider_owner'] ?? null)) {
                $owners = $this->index->namedTypes($op['provider_owner']);
                if (count($owners) !== 1 || ! isset($this->providerSourceCandidates[$owners[0]]) && ! $this->index->hasContract($owners[0], 'illuminate\\support\\serviceprovider') && ! $this->index->hasContract($owners[0], 'illuminate\\foundation\\support\\providers\\routeserviceprovider')) {
                    $this->notice($path, $row['line'], 'http_provider_contract', 'Provider route-loading candidate has no unambiguous ServiceProvider contract in source.');

                    continue;
                }
                if (is_string($op['provider_method'] ?? null) && $this->declaredMethod($owners[0], $op['provider_method'])) {
                    $this->notice($path, $row['line'], 'http_provider_override', 'Source provider overrides the route-loading method; framework loading is not inferred.');

                    continue;
                }
            }
            $context = $this->combine($parent, $op['context']);
            $context['registration'] = [...($parent['registration'] ?? []), ['path' => $row['path'], 'line' => $row['line'], 'offset' => $op['offset']]];
            if ($op['operation'] === 'provider') {
                $providers = $this->index->namedTypes($op['provider']);
                if ($providers === []) {
                    $this->notice($path, $row['line'], 'http_missing_provider', 'Registered provider declaration is absent from the declared source graph.');
                } elseif (count($providers) > 1) {
                    $this->notice($path, $row['line'], 'http_ambiguous_provider', 'Registered provider has several source declarations; candidates are not a runtime selection.');
                }
                foreach ($providers as $id) {
                    if (! $this->index->hasContract($id, 'illuminate\\support\\serviceprovider') && ! $this->index->hasContract($id, 'illuminate\\foundation\\support\\providers\\routeserviceprovider')) {
                        $this->notice($path, $row['line'], 'http_provider_contract', 'Registered provider has no ServiceProvider contract in source.');

                        continue;
                    }
                    $provider = $this->index->elements[$id];
                    $finalParent = false;
                    foreach ($this->index->out[$id] ?? [] as $parentPosition) {
                        $parentEdge = $this->index->relations[$parentPosition];
                        if ($parentEdge['kind'] === 'extends' && ($this->index->elements[$parentEdge['to']]['metadata']['final'] ?? false)) {
                            $finalParent = true;
                        }
                    }
                    if ($finalParent) {
                        $this->notice($path, $row['line'], 'http_provider_final_parent', 'Registered provider extends a final source class.');

                        continue;
                    }
                    if ($provider['kind'] !== 'class' || ($provider['metadata']['abstract'] ?? false)) {
                        $this->notice($path, $row['line'], 'http_provider_uninstantiable', 'Registered provider is not a concrete source class.');

                        continue;
                    }
                    $this->providerSourceCandidates[$id] = true;
                    $context['possible'] = true;
                    $context['reasons'][] = 'Provider activation remains a source candidate.';
                    $context['reasons'][] = 'Provider lifecycle helper calls are possible source paths; branch conditions and dynamic dispatch are not executed.';
                    $scopes = $this->providerScopes($id);
                    $registered = $context;
                    $registered['provider_scopes'] = $scopes;
                    $sources = [$this->index->elements[$id]['path'] => true];
                    foreach ($scopes as $symbol => $methodId) {
                        $sources[$this->index->elements[$methodId]['path']] = true;
                    }
                    foreach (array_keys($sources) as $source) {
                        $this->load($source, $registered, [...$stack, $path]);
                    }
                }
            } elseif ($op['operation'] === 'load') {
                $found = false;
                foreach ($op['load_paths'] as $loadPath) {
                    $fileId = CatalogElement::identity($loadPath, 'file', $loadPath);
                    if (isset($this->index->elements[$fileId])) {
                        $found = true;
                        $this->load($loadPath, $context, [...$stack, $path]);
                    }
                }
                if (! $found && $op['load_paths'] !== []) {
                    $this->notice($path, $row['line'], 'http_missing_source', 'Registered route source candidates are absent from the declared graph.');
                }
            } else {
                $this->route($row, $context);
            }
        }
    }

    /** @param array<string, bool> $seen */
    private function declaredMethod(string $id, string $method, array $seen = []): bool
    {
        if (++$this->operations > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;
            $element = $this->index->elements[$id];
            $this->notice($element['path'], $element['line'], 'http_composition_limit', 'Provider method lookup reached its traversal or memory budget.');

            return false;
        }
        if (isset($seen[$id])) {
            $element = $this->index->elements[$id];
            $this->notice($element['path'], $element['line'], 'http_provider_inheritance_cycle', 'Provider inheritance or trait use contains a source cycle.');

            return false;
        }
        if (count($seen) >= 32) {
            $this->limited = true;
            $element = $this->index->elements[$id];
            $this->notice($element['path'], $element['line'], 'http_composition_limit', 'Provider method lookup reached its inheritance depth budget.');

            return false;
        }
        $seen[$id] = true;
        foreach ($this->index->out[$id] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            $target = $this->index->elements[$edge['to']] ?? null;
            if ($target === null) {
                continue;
            }
            if ($edge['kind'] === 'contains' && $target['kind'] === 'method' && str_ends_with(strtolower($target['name']), '::'.$method)) {
                return true;
            }
            if (in_array($edge['kind'], ['extends', 'uses-trait'], true) && $this->declaredMethod($target['id'], $method, $seen)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function providerScopes(string $id): array
    {
        $types = [$id];
        $ancestry = [];
        for ($position = 0; $position < count($types); $position++) {
            $typeId = $types[$position];
            if (isset($ancestry[$typeId])) {
                continue;
            }
            $ancestry[$typeId] = true;
            if (++$this->operations > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->limited = true;
                $type = $this->index->elements[$typeId];
                $this->notice($type['path'], $type['line'], 'http_composition_limit', 'Provider source ancestry reached its traversal or memory budget.');

                return [];
            }
            $this->providerSourceCandidates[$typeId] = true;
            foreach ($this->index->out[$typeId] ?? [] as $edgePosition) {
                $edge = $this->index->relations[$edgePosition];
                if (in_array($edge['kind'], ['extends', 'uses-trait'], true) && isset($this->index->elements[$edge['to']])) {
                    $types[] = $edge['to'];
                }
            }
        }
        $queue = [...$this->providerInvocations($id, 'boot', null), ...$this->providerInvocations($id, 'register', null)];
        $seen = [];
        $scopes = [];
        $creatorConsumers = [];
        for ($position = 0; $position < count($queue); $position++) {
            [$methodId, $consumingType] = $queue[$position];
            $deferred = $queue[$position][2] ?? null;
            $invocationKey = $methodId.'#'.$consumingType;
            if ($deferred === null && isset($seen[$invocationKey])) {
                continue;
            }
            $seen[$invocationKey] = true;
            if ($deferred === null) {
                $creatorConsumers[$methodId][$consumingType] = true;
            }
            $method = $this->index->elements[$methodId];
            if (++$this->operations > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->limited = true;
                $this->notice($method['path'], $method['line'], 'http_composition_limit', 'Provider activation reached its call or memory budget.');
                break;
            }
            $scopes[strtolower($method['name'])] = $methodId;
            $edges = $deferred === null ? array_map(fn ($edgePosition) => $this->index->relations[$edgePosition], $this->index->out[$methodId] ?? []) : [$deferred];
            foreach ($edges as $edge) {
                if ($edge['kind'] === 'references-call') {
                    $this->notice($edge['path'], $edge['line'], 'http_provider_method_inaccessible', 'Provider helper source call is inaccessible or incompatible with its invocation form; its body is not activated.');

                    continue;
                }
                if ($edge['kind'] === 'calls') {
                    if (isset($edge['metadata']['bound_callable'])) {
                        $binding = $edge['metadata']['bound_callable'];
                        if (isset($binding['creator']) && ! isset($creatorConsumers[$binding['creator']])) {
                            if ($deferred === null) {
                                $queue[] = [$methodId, $consumingType, $edge];
                            } else {
                                $this->notice($edge['path'], $edge['line'], 'http_provider_callable_creator_inactive', 'Returned callable creator has no activated provider source scope.');
                            }

                            continue;
                        }
                        $edge['metadata']['method'] = $binding['method'];
                        $edge['metadata']['this_receiver'] = $binding['this'];
                        $edge['metadata']['late_static_receiver'] = $binding['late'];
                        $edge['metadata']['lexical_static_receiver'] = $binding['lexical'];
                    }
                    $lexicalReceiver = $edge['metadata']['lexical_static_receiver'] ?? null;
                    $binding = $edge['metadata']['bound_callable'] ?? null;
                    $creatorId = ($binding['scope_bound'] ?? true) ? ($binding['creator'] ?? $methodId) : $methodId;
                    $ownerId = $this->lexicalType($creatorId) ?? '';
                    $creatorTypes = array_keys($creatorConsumers[$creatorId] ?? []);
                    $bindingConsumer = count($creatorTypes) === 1 ? $creatorTypes[0] : $consumingType;
                    $callerType = ($this->index->elements[$ownerId]['kind'] ?? null) === 'trait'
                        ? ($this->usesTrait($bindingConsumer, $ownerId) ? $bindingConsumer : $this->traitConsumer($types, $ownerId)) : $ownerId;
                    if ($lexicalReceiver !== null && isset($ancestry[$ownerId])) {
                        $consumer = $callerType;
                        if ($consumer === null) {
                            $this->notice($edge['path'], $edge['line'], 'http_provider_trait_unresolved', 'Trait lexical receiver has no unique consuming class in the registered provider ancestry.');

                            continue;
                        }
                        $receivers = [$consumer];
                        if ($lexicalReceiver === 'parent') {
                            $receivers = [];
                            foreach ($this->index->out[$consumer] ?? [] as $parentPosition) {
                                $parentEdge = $this->index->relations[$parentPosition];
                                if ($parentEdge['kind'] === 'extends' && isset($this->index->elements[$parentEdge['to']])) {
                                    $receivers[] = $parentEdge['to'];
                                }
                            }
                        }
                        foreach ($receivers as $receiverId) {
                            array_push($queue, ...$this->providerInvocations($receiverId, strtolower($edge['metadata']['method']), $consumer));
                        }
                        if ($receivers === []) {
                            $this->notice($edge['path'], $edge['line'], 'http_provider_trait_parent_unknown', 'Trait parent receiver is absent or outside the declared source graph.');
                        }

                        continue;
                    }
                    if ((($edge['metadata']['this_receiver'] ?? false) || ($edge['metadata']['late_static_receiver'] ?? false)) && isset($ancestry[$ownerId])) {
                        // Private methods bind to their lexical class; virtual helpers bind to the registered provider.
                        $name = strtolower($edge['metadata']['method']);
                        $lexical = [];
                        if (($edge['metadata']['this_receiver'] ?? false) && $callerType !== null) {
                            $lexical = array_values(array_filter($this->providerMethods($callerType, $name), fn ($targetId) => ($this->providerAccess($callerType, $name, $targetId)['visibility'] ?? null) === 'private' && ($this->providerAccess($callerType, $name, $targetId)['owner'] ?? null) === $callerType));
                        }
                        array_push($queue, ...($lexical !== [] ? $this->providerInvocations($callerType, $name, $callerType) : $this->providerInvocations($id, $name, $callerType)));

                        continue;
                    }
                    if (isset($this->index->elements[$edge['to']]) && in_array($this->index->elements[$edge['to']]['kind'], ['method', 'function', 'closure'], true)) {
                        $target = $this->index->elements[$edge['to']];
                        $targetOwner = $this->lexicalType($target['id']);
                        if ($target['kind'] === 'method' && $targetOwner !== null) {
                            $name = strtolower(substr($target['name'], (int) strrpos($target['name'], '::') + 2));
                            if (! in_array($target['id'], $this->accessibleProviderMethods($targetOwner, $name, $callerType === '' ? null : $callerType), true)) {
                                continue;
                            }
                        }
                        $queue[] = [$edge['to'], ($this->index->elements[$targetOwner ?? '']['kind'] ?? null) === 'class' ? $targetOwner : $consumingType];
                    }
                }
            }
        }

        return $scopes;
    }

    /** @return list<array{string, string}> */
    private function providerInvocations(string $receiver, string $name, ?string $caller): array
    {
        $result = [];
        foreach ($this->accessibleProviderMethods($receiver, $name, $caller) as $target) {
            $owner = $this->providerAccess($receiver, $name, $target)['owner'] ?? $receiver;
            $result[] = [$target, $owner];
        }

        return $result;
    }

    private function lexicalType(string $id): ?string
    {
        $seen = [];
        while (isset($this->index->elements[$id]) && ! isset($seen[$id])) {
            if (count($seen) >= 32 || ++$this->operations > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->limited = true;
                $element = $this->index->elements[$id];
                $this->notice($element['path'], $element['line'], 'http_composition_limit', 'Provider lexical scope lookup reached its traversal or memory budget.');

                return null;
            }
            $seen[$id] = true;
            $element = $this->index->elements[$id];
            if (in_array($element['kind'], ['class', 'trait'], true)) {
                return $id;
            }
            if (! is_string($element['parent'])) {
                return null;
            }
            $id = $element['parent'];
        }

        return null;
    }

    /** @return list<string> */
    private function accessibleProviderMethods(string $receiver, string $name, ?string $caller): array
    {
        $result = [];
        foreach ($this->providerMethods($receiver, $name) as $target) {
            $access = $this->providerAccess($receiver, $name, $target);
            $element = $this->index->elements[$target];
            if (($element['metadata']['abstract'] ?? false) || $access === null
                || $caller === null && $access['visibility'] !== 'public'
                || $access['visibility'] === 'private' && $access['owner'] !== $caller
                || $access['visibility'] === 'protected' && ! $this->relatedScope($caller, $access['owner'])) {
                $this->notice($element['path'], $element['line'], 'http_provider_method_inaccessible', 'Provider method is abstract or its effective visibility does not permit this invocation.');

                continue;
            }
            $result[] = $target;
        }

        return $result;
    }

    private function relatedScope(?string $caller, string $owner): bool
    {
        if ($caller === null || ! isset($this->index->elements[$caller], $this->index->elements[$owner])) {
            return false;
        }

        return $caller === $owner || $this->index->hasContract($caller, $this->index->elements[$owner]['name'])
            || $this->index->hasContract($owner, $this->index->elements[$caller]['name']);
    }

    /** @param array<string, bool> $seen
     * @return array{owner: string, visibility: string, final: bool}|null
     */
    private function providerAccess(string $id, string $name, string $target, array $seen = []): ?array
    {
        if (isset($seen[$id]) || count($seen) >= 32 || $this->limited) {
            return null;
        }
        if (++$this->operations > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;
            $element = $this->index->elements[$id];
            $this->notice($element['path'], $element['line'], 'http_composition_limit', 'Provider visibility lookup reached its traversal or memory budget.');

            return null;
        }
        $seen[$id] = true;
        $traits = $parents = [];
        foreach ($this->index->out[$id] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if (! isset($this->index->elements[$edge['to']])) {
                continue;
            }
            if ($edge['kind'] === 'contains' && $edge['to'] === $target) {
                return ['owner' => $id, 'visibility' => $this->index->elements[$target]['metadata']['visibility'] ?? 'public', 'final' => $this->index->elements[$target]['metadata']['final'] ?? false];
            }
            if ($edge['kind'] === 'uses-trait') {
                $traits[] = $edge['to'];
            } elseif ($edge['kind'] === 'extends') {
                $parents[] = $edge['to'];
            }
        }
        foreach (CatalogTraitSelection::candidates($this->index, $id, $name, $traits) ?? [] as [$trait, $traitName]) {
            $access = $this->providerAccess($trait, $traitName, $target, $seen);
            if ($access === null) {
                continue;
            }
            if ($this->index->elements[$id]['kind'] === 'class') {
                $access['owner'] = $id;
            }
            foreach ($this->index->elements[$id]['metadata']['trait_rules'] ?? [] as $rule) {
                if (($rule['alias'] === $name || $rule['alias'] === null && $rule['method'] === $name)
                    && ($rule['trait'] === null || strcasecmp($rule['trait'], $this->index->elements[$trait]['name']) === 0)) {
                    if ($rule['visibility'] !== null) {
                        $access['visibility'] = $rule['visibility'];
                    }
                    $access['final'] = $access['final'] || ($rule['final'] ?? false);
                }
            }

            return $access;
        }
        foreach ($parents as $parent) {
            $access = $this->providerAccess($parent, $name, $target, $seen);
            if ($access !== null) {
                return $access;
            }
        }

        return null;
    }

    /** @param list<string> $types */
    private function traitConsumer(array $types, string $trait): ?string
    {
        foreach ($types as $type) {
            if ($this->index->elements[$type]['kind'] === 'class' && $this->usesTrait($type, $trait)) {
                return $type;
            }
        }

        return null;
    }

    /** @param array<string, bool> $seen */
    private function usesTrait(string $id, string $trait, array $seen = []): bool
    {
        if (isset($seen[$id])) {
            $element = $this->index->elements[$id];
            $this->notice($element['path'], $element['line'], 'http_provider_inheritance_cycle', 'Trait consuming-class lookup encountered a source cycle.');

            return false;
        }
        if (count($seen) >= 32) {
            $this->limited = true;
            $element = $this->index->elements[$id];
            $this->notice($element['path'], $element['line'], 'http_composition_limit', 'Trait consuming-class lookup reached its inheritance depth budget.');

            return false;
        }
        if (++$this->operations > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;
            $element = $this->index->elements[$id];
            $this->notice($element['path'], $element['line'], 'http_composition_limit', 'Trait consuming-class lookup reached its traversal or memory budget.');

            return false;
        }
        $seen[$id] = true;
        foreach ($this->index->out[$id] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if ($edge['kind'] === 'uses-trait' && isset($this->index->elements[$edge['to']]) && ($edge['to'] === $trait || $this->usesTrait($edge['to'], $trait, $seen))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, bool> $seen
     * @return list<string>
     */
    private function providerMethods(string $id, string $method, array $seen = []): array
    {
        if ($this->limited) {
            return [];
        }
        $element = $this->index->elements[$id];
        if (isset($seen[$id])) {
            $this->notice($element['path'], $element['line'], 'http_provider_inheritance_cycle', 'Provider lifecycle lookup encountered an inheritance or trait source cycle.');

            return [];
        }
        if (count($seen) >= 32) {
            $this->limited = true;
            $this->notice($element['path'], $element['line'], 'http_composition_limit', 'Provider lifecycle lookup reached its inheritance depth budget.');

            return [];
        }
        if (++$this->operations > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;
            $this->notice($element['path'], $element['line'], 'http_composition_limit', 'Provider lifecycle lookup reached its inheritance or memory budget.');

            return [];
        }
        $this->providerSourceCandidates[$id] = true;
        $seen[$id] = true;
        $own = $traits = $parents = [];
        foreach ($this->index->out[$id] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            $target = $this->index->elements[$edge['to']] ?? null;
            if ($target === null) {
                continue;
            }
            if ($edge['kind'] === 'contains' && $target['kind'] === 'method' && str_ends_with(strtolower($target['name']), '::'.$method)) {
                $own[] = $target['id'];
            } elseif ($edge['kind'] === 'uses-trait') {
                $traits[] = $target['id'];
            } elseif ($edge['kind'] === 'extends') {
                $parents[] = $target['id'];
            }
        }
        if ($own !== []) {
            return $this->overridesFinal($id, $method, $parents, $seen) ? [] : $own;
        }
        $result = [];
        $selection = CatalogTraitSelection::candidates($this->index, $id, $method, $traits);
        if ($selection === null) {
            $this->notice($element['path'], $element['line'], 'http_provider_trait_unresolved', 'Provider trait adaptation metadata is unavailable.');

            return [];
        }
        foreach ($selection as [$trait, $traitMethod]) {
            array_push($result, ...$this->providerMethods($trait, $traitMethod, $seen));
        }
        if ($result !== []) {
            if ($this->overridesFinal($id, $method, $parents, $seen)) {
                return [];
            }
            if (count(array_unique($result)) > 1) {
                $this->notice($element['path'], $element['line'], 'http_provider_trait_ambiguous', 'Several trait methods match this provider call; candidates do not establish a valid runtime selection.');
            }

            return array_values(array_unique($result));
        }
        foreach ($parents as $parent) {
            array_push($result, ...$this->providerMethods($parent, $method, $seen));
        }

        return array_values(array_unique($result));
    }

    /** @param list<string> $parents
     * @param  array<string, bool>  $seen
     */
    private function overridesFinal(string $id, string $name, array $parents, array $seen): bool
    {
        foreach ($parents as $parent) {
            foreach ($this->providerMethods($parent, $name, $seen) as $target) {
                $access = $this->providerAccess($parent, $name, $target);
                if (($access['final'] ?? false) && ($access['visibility'] !== 'private' || $name === '__construct')) {
                    $element = $this->index->elements[$id];
                    $this->notice($element['path'], $element['line'], 'http_provider_final_override', 'Provider source method overrides a final inherited declaration or trait alias.');

                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $row
     * @param  array<string, mixed>  $context
     */
    private function route(array $row, array $context): void
    {
        $op = $row['metadata'];
        $uri = $op['uri'] === null || $context['prefix'] === null ? null : '/'.trim($context['prefix'].'/'.$op['uri'], '/');
        $name = implode('|', $op['verbs'] ?? []).' '.($uri ?? '(dynamic route)');
        $id = CatalogElement::identity($row['path'], 'route', $name.'#'.hash('xxh128', serialize($context)), $op['offset']);
        $element = new CatalogElement($id, $name, 'route', $row['line'], $row['end_line'], $op['offset'], $row['from'], metadata: [
            'uri' => $uri, 'verbs' => $op['verbs'], 'route_name' => $context['name'] ?: null, 'domain' => $context['domain'],
            'middleware' => $context['middleware'], 'excluded_middleware' => $context['excluded_middleware'], 'constraints' => $context['constraints'] ?? null,
            'activation_unknown' => $context['possible'], 'reasons' => array_values(array_unique($context['reasons'])),
            'registration' => $context['registration'],
            'resource' => $op['resource'], 'execution_proven' => false,
        ]);
        $this->index->elements[$id] = [...$element->toArray(), 'path' => $row['path'], 'knowledge' => 'static', 'role_evidence' => [],
            'sources' => [['path' => $row['path'], 'line' => $row['line'], 'end_line' => $row['end_line']]]];
        $this->index->names[strtolower($name)][] = $id;
        $this->index->addRelation([...$row, 'to' => $id, 'kind' => 'registers-route', 'metadata' => ['execution_proven' => false]]);
        $handler = $op['handler'];
        if (isset($handler['callback']) && isset($this->index->elements[$handler['callback']])) {
            $this->routes[] = $this->authorizationRoute($id, $row, $context, $handler);
            $this->index->addRelation([...$row, 'from' => $id, 'to' => $handler['callback'], 'kind' => 'route-handler', 'metadata' => ['execution_proven' => false]]);

            return;
        }
        if (isset($handler['string'])) {
            $value = $handler['string'];
            if (is_string($context['controller']) && ! str_contains($value, '@')) {
                $handler = ['class' => $context['controller'], 'method' => $value];
            } elseif ($context['controller'] === null && isset($context['specified']['controller']) && ! str_contains($value, '@') && $this->index->namedTypes(ltrim($value, '\\')) === []) {
                $handler = null;
            } else {
                [$class, $method] = array_pad(explode('@', $value, 2), 2, '__invoke');
                if (! str_starts_with($class, '\\') && is_string($context['namespace']) && $context['namespace'] !== ''
                    && ! str_starts_with($class, $context['namespace'].'\\') && $this->index->namedTypes($class) === []) {
                    $class = $context['namespace'].'\\'.$class;
                }
                $handler = ['class' => $class, 'method' => $method];
            }
        }
        if (! isset($handler['class'], $handler['method'])) {
            $this->notice($row['path'], $row['line'], 'dynamic_route_handler', 'Route handler cannot be resolved from source.');

            return;
        }
        $class = $handler['class'];
        if (! str_starts_with($class, '\\') && ! str_contains($class, '\\') && is_string($context['namespace']) && $context['namespace'] !== '') {
            $class = $context['namespace'].'\\'.$class;
        }
        if ($this->index->namedTypes(ltrim($class, '\\')) === []) {
            $this->notice($row['path'], $row['line'], 'http_missing_handler', 'Route handler class is absent from the declared source graph.');
        }
        $this->dispatch[] = [...$row, 'from' => $id, 'to' => 'dispatch:'.hash('xxh128', $class.'::'.$handler['method']), 'kind' => 'route-handler',
            'metadata' => ['receiver' => ltrim($class, '\\'), 'method' => $handler['method'], 'exact_receiver' => true, 'form' => 'route', 'execution_proven' => false]];
        $this->routes[] = $this->authorizationRoute($id, $row, $context, ['class' => ltrim($class, '\\'), 'method' => $handler['method']]);
    }

    /** @param array<string, mixed> $row
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $handler
     * @return array<string, mixed>
     */
    private function authorizationRoute(string $id, array $row, array $context, array $handler): array
    {
        return ['id' => $id, 'handler' => $handler, 'source' => ['path' => $row['path'], 'line' => $row['line'], 'offset' => $row['metadata']['offset']],
            'middleware' => $context['middleware'], 'excluded_middleware' => $context['excluded_middleware'], 'reasons' => $context['reasons']];
    }

    /** @param array<string, mixed> $parent
     * @param  array<string, mixed>  $local
     * @return array<string, mixed>
     */
    private function combine(array $parent, array $local): array
    {
        $result = $local;
        foreach (['prefix', 'namespace'] as $key) {
            $separator = $key === 'prefix' ? '/' : '\\';
            $result[$key] = $parent[$key] === null || $local[$key] === null ? null : trim($parent[$key].$separator.$local[$key], $separator);
        }
        $result['name'] = $parent['name'] === null || $local['name'] === null ? null : $parent['name'].$local['name'];
        foreach (['middleware', 'excluded_middleware'] as $key) {
            $result[$key] = $parent[$key] === null || $local[$key] === null ? null : array_values(array_unique([...$parent[$key], ...$local[$key]], SORT_REGULAR));
        }
        foreach (['domain', 'controller'] as $key) {
            $result[$key] = isset($local['specified'][$key]) ? $local[$key] : $parent[$key];
        }
        $result['constraints'] = ($parent['constraints'] ?? null) === null || ($local['constraints'] ?? null) === null ? null : [...$parent['constraints'], ...$local['constraints']];
        $result['specified'] = [...$parent['specified'], ...$local['specified']];
        $result['possible'] = $parent['possible'] || $local['possible'];
        $result['reasons'] = [...$parent['reasons'], ...$local['reasons']];

        return $result;
    }

    private function notice(string $path, int $line, string $code, string $message): void
    {
        $this->index->diagnostics[] = ['code' => $code, 'message' => $message, 'path' => $path, 'line' => $line, 'subject' => null];
    }
}
