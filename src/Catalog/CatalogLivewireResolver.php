<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Public source methods are candidates, never proof that a browser called every action. */
final class CatalogLivewireResolver
{
    private int $visited = 0;

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(): void
    {
        $this->visited = 0;
        $profile = CatalogPackageVersions::verified($this->index, 'livewire/livewire');
        $components = $forms = $computed = $dispatches = [];
        foreach ($this->index->elements as $element) {
            if (! $this->room($element)) {
                return;
            }
            if ($element['kind'] === 'livewire-attribute' && $element['metadata']['hook'] === 'computed' && $this->index->namedTypes($element['metadata']['attribute']) === []) {
                $computed[$element['parent']] = true;
            }
            if ($element['kind'] !== 'class' || count($this->index->namedTypes($element['name'])) !== 1) {
                continue;
            }
            foreach (['Livewire\\Component' => 'livewire-component', 'Livewire\\Form' => 'livewire-form'] as $contract => $role) {
                if (! $this->index->hasContract($element['id'], $contract)) {
                    continue;
                }
                if ($profile === null || $this->index->namedTypes($contract) !== []) {
                    $this->notice($element, 'Livewire package profile or source base contract is unresolved.');

                    continue;
                }
                $this->role($element['id'], $role, $element);
                if ($role === 'livewire-component') {
                    $components[$element['id']] = true;
                } else {
                    $forms[$element['id']] = true;
                }
            }
        }
        if ($profile === null) {
            return;
        }
        foreach ($this->index->elements as $element) {
            if (! $this->room($element)) {
                return;
            }
            if ($element['kind'] === 'livewire-registration') {
                $ids = is_string($element['metadata']['class']) ? $this->index->namedTypes($element['metadata']['class']) : [];
                if (! $element['metadata']['resolved'] || count($ids) !== 1 || ! isset($components[$ids[0]])
                    || $this->index->namedTypes('Livewire\\Livewire') !== [] || $this->index->namedTypes('Livewire\\LivewireManager') !== []) {
                    $this->notice($element, 'Livewire component registration selector or source class is unresolved.');

                    continue;
                }
                $name = $element['metadata']['name'];
                $id = CatalogElement::resourceIdentity('livewire-component-name', $name);
                if (! isset($this->index->elements[$id])) {
                    $resource = new CatalogElement($id, $name, 'livewire-component-name', $element['line'], $element['end_line'], 0,
                        metadata: ['logical_resource' => true, 'runtime_activation_known' => false]);
                    $this->index->elements[$id] = [...$resource->toArray(), 'path' => $element['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => []];
                    $this->index->names[strtolower($name)][] = $id;
                }
                $this->index->elements[$id]['sources'][] = ['path' => $element['path'], 'line' => $element['line'], 'end_line' => $element['end_line']];
                $this->edge($element['parent'], $id, 'registers-livewire-component', $element, $profile);
                $this->edge($id, $ids[0], 'references-livewire-component-class', $element, $profile);

                continue;
            }
            if ($element['kind'] === 'livewire-dispatch') {
                $dispatches[] = $element;

                continue;
            }
        }
        $this->attributeListeners($components, $forms, $computed, $profile);
        $this->methodListeners($components, $computed, $profile);
        $this->sourceViews($components, $profile);
        $this->viewActions($components, $profile);
        foreach ($dispatches as $site) {
            if (! $this->room($site)) {
                return;
            }
            $this->dispatch($site, $components, $profile);
        }
    }

    /** @param array<string, bool> $components
     * @param  array<string, bool>  $forms
     * @param  array<string, bool>  $computed
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     */
    private function attributeListeners(array $components, array $forms, array $computed, array $profile): void
    {
        $attributes = $members = [];
        foreach ($this->index->elements as $element) {
            if ($element['kind'] === 'livewire-attribute') {
                $attributes[$element['parent']][] = $element;
            } elseif (in_array($element['kind'], ['method', 'property'], true)) {
                $members[$element['parent']][] = $element;
            }
        }
        foreach ($components + $forms as $id => $_) {
            $component = $this->index->elements[$id];
            $contexts = $this->attributeContexts($component, $attributes, $members);
            $methodEvents = $eventMethods = [];
            foreach ($contexts as [$owner, $sites, $memberName]) {
                if ($owner['kind'] !== 'method') {
                    continue;
                }
                foreach ($sites as $site) {
                    if ($site['metadata']['hook'] === 'on' && $site['metadata']['resolved']) {
                        foreach ($site['metadata']['events'] as $event) {
                            $methodEvents[$event] = true;
                        }
                    }
                }
            }
            foreach ($contexts as [$owner, $sites, $memberName]) {
                foreach ($sites as $site) {
                    if (! $this->room($site)) {
                        return;
                    }
                    if ($this->index->namedTypes($site['metadata']['attribute']) !== []
                        || $this->index->namedTypes('Livewire\\Features\\SupportAttributes\\AttributeCollection') !== []) {
                        $this->notice($site, 'Source declaration shadows Livewire attribute collection.');

                        continue;
                    }
                    $context = [...$site, 'listener_component' => $id, 'listener_member' => $memberName];
                    $hook = $site['metadata']['hook'];
                    if ($hook !== 'on') {
                        $this->edge($owner['id'], $site['id'], 'declares-livewire-'.$hook, $context, $profile);
                        if ($hook === 'computed' && $owner['kind'] === 'method') {
                            $this->role($owner['id'], 'livewire-computed', $site);
                        }

                        continue;
                    }
                    if (! $site['metadata']['resolved'] || ! isset($components[$id]) || ($component['metadata']['abstract'] ?? false)
                        || $owner['kind'] !== 'class' && ($owner['kind'] !== 'method' || ($owner['metadata']['visibility'] ?? null) !== 'public'
                            || ($owner['metadata']['static'] ?? false) || ($owner['metadata']['abstract'] ?? false) || isset($computed[$owner['id']]))) {
                        $this->notice($site, 'Livewire event selector or reflected listener method requires inspection.');

                        continue;
                    }
                    foreach ($site['metadata']['events'] as $name) {
                        if ($owner['kind'] === 'class' && isset($methodEvents[$name])) {
                            continue; // Method attributes are booted after recursively collected root attributes.
                        }
                        if (! $this->room($site)) {
                            return;
                        }
                        if ($owner['kind'] === 'method') {
                            if (isset($eventMethods[$name]) && $eventMethods[$name] !== $memberName) {
                                $this->notice($site, 'Several reflected Livewire attributes map this event to different methods; runtime attribute order selects the listener.');
                            }
                            $eventMethods[$name] = $memberName;
                        }
                        $eventId = CatalogElement::resourceIdentity('livewire-event', $name);
                        if (! isset($this->index->elements[$eventId])) {
                            $event = new CatalogElement($eventId, $name, 'livewire-event', $site['line'], $site['end_line'], 0,
                                metadata: ['logical_resource' => true, 'execution_proven' => false]);
                            $this->index->elements[$eventId] = [...$event->toArray(), 'path' => $site['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => []];
                            $this->index->names[strtolower($name)][] = $eventId;
                        }
                        $this->index->elements[$eventId]['sources'][] = ['path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line']];
                        $this->edge($owner['id'], $eventId, 'registers-livewire-listener', $context, $profile);
                        $this->edge($eventId, $owner['id'], $owner['kind'] === 'class' ? 'livewire-refreshes-component' : 'livewire-event-listener', $context, $profile, true);
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $component
     * @param  array<string, list<array<string, mixed>>>  $attributes
     * @param  array<string, list<array<string, mixed>>>  $members
     * @return list<array{array<string, mixed>, list<array<string, mixed>>, ?string}>
     */
    private function attributeContexts(array $component, array $attributes, array $members): array
    {
        $contexts = $names = $properties = $seen = [];
        $pending = [$component['id']];
        while ($pending !== []) {
            $id = array_pop($pending);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (! $this->room($component)) {
                return $contexts;
            }
            $declaration = $this->index->elements[$id];
            // Reflection recursively reads class attributes; attributes on the trait itself are not inherited.
            if ($declaration['kind'] === 'class' && isset($attributes[$id])) {
                $contexts[] = [$component, $attributes[$id], null];
            }
            foreach ($members[$id] ?? [] as $member) {
                if (! $this->room($member)) {
                    return $contexts;
                }
                $name = substr($member['name'], strrpos($member['name'], '::') + 2);
                if ($member['kind'] === 'method') {
                    $names[strtolower($name)] = $name;
                } else {
                    $properties[substr($name, 1)] = true;
                }
            }
            foreach ($declaration['metadata']['trait_rules'] ?? [] as $rule) {
                if ($rule['alias'] !== null) {
                    $names[$rule['alias']] = $rule['alias'];
                }
            }
            foreach ($this->index->out[$id] ?? [] as $position) {
                $edge = $this->index->relations[$position];
                if (in_array($edge['kind'], ['extends', 'uses-trait'], true) && isset($this->index->elements[$edge['to']])) {
                    $pending[] = $edge['to'];
                }
            }
        }
        foreach ($names as $name) {
            if (! $this->room($component)) {
                return $contexts;
            }
            $selected = $this->calls->sourceMethodCandidate($component['name'], $name);
            $method = $selected['method'];
            if ($selected['unresolved'] || $selected['limited']) {
                $this->notice($component, 'Livewire reflected method attributes have an unresolved source selector.');

                continue;
            }
            if ($method !== null && isset($attributes[$method['id']])) {
                $contexts[] = [[...$this->index->elements[$method['id']], 'metadata' => $method['metadata']], $attributes[$method['id']], $name];
            }
            if ($method !== null && ($method['metadata']['visibility'] ?? null) === 'public' && ! ($method['metadata']['static'] ?? false)
                && ! ($method['metadata']['abstract'] ?? false) && ! str_starts_with($name, '__')) {
                $computed = array_filter($attributes[$method['id']] ?? [], fn ($site) => $site['metadata']['hook'] === 'computed'
                    && $this->index->namedTypes($site['metadata']['attribute']) === []);
                $role = $computed !== [] ? 'livewire-computed' : (strtolower($name) === 'render' ? 'livewire-render' :
                    (in_array(strtolower($name), ['mount', 'boot', 'booted', 'hydrate', 'dehydrate', 'rendering', 'rendered', 'updating', 'updated', 'exception'], true) ? 'livewire-lifecycle' : 'livewire-action'));
                if ($this->index->hasContract($component['id'], 'Livewire\\Component')) {
                    $this->role($method['id'], $role, $component);
                }
            }
        }
        foreach (array_keys($properties) as $name) {
            if (! $this->room($component)) {
                return $contexts;
            }
            $selected = $this->calls->receiverCandidates('@property:'.$component['name'].'#'.$name);
            if ($selected['limited'] || count($selected['sources']) !== 1) {
                continue;
            }
            $ids = $this->index->names[strtolower($selected['sources'][0]['symbol'])] ?? [];
            if (count($ids) === 1 && isset($attributes[$ids[0]])) {
                $contexts[] = [$this->index->elements[$ids[0]], $attributes[$ids[0]], $name];
            }
        }

        return $contexts;
    }

    /** @param array<string, bool> $components
     * @param  array<string, bool>  $computed
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     */
    private function methodListeners(array $components, array $computed, array $profile): void
    {
        $maps = [];
        foreach ($this->index->elements as $element) {
            if ($element['kind'] === 'livewire-listeners') {
                $maps[$element['parent']][] = $element;
            }
        }
        foreach ($components as $id => $_) {
            $component = $this->index->elements[$id];
            if (! $this->room($component)) {
                return;
            }
            if (($component['metadata']['abstract'] ?? false) || $this->index->namedTypes('Livewire\\Features\\SupportEvents\\SupportEvents') !== []) {
                continue;
            }
            $selection = $this->calls->sourceMethodCandidate($component['name'], 'getListeners', knownTraits: ['Livewire\\Features\\SupportEvents\\HandlesEvents']);
            $method = $selection['method'];
            if ($selection['unresolved'] || $selection['limited']) {
                $this->notice($component, 'Livewire getListeners declaration cannot be selected from source.');

                continue;
            }
            $facts = $method === null ? [] : ($maps[$method['id']] ?? []);
            if ($method !== null && (($method['metadata']['static'] ?? false) || ($method['metadata']['abstract'] ?? false)
                || count($facts) !== 1 || ! $facts[0]['metadata']['resolved'])) {
                $this->notice($component, 'Livewire getListeners map is dynamic, overridden or unresolved.');

                continue;
            }
            $site = $facts[0] ?? null;
            if ($site === null || $site['metadata']['mode'] === 'property') {
                $site = $this->listenerProperty($component, $maps);
                if ($site === null) {
                    continue;
                }
            }
            foreach ($site['metadata']['listeners'] as $listener) {
                if (! $this->room($site)) {
                    return;
                }
                $eventId = CatalogElement::resourceIdentity('livewire-event', $listener['event']);
                // Attribute listeners overwrite matching string event keys in array_merge.
                $overridden = false;
                foreach ($this->index->in[$eventId] ?? [] as $position) {
                    $edge = $this->index->relations[$position];
                    $owner = $this->index->elements[$edge['from']] ?? null;
                    if ($edge['kind'] === 'registers-livewire-listener' && $owner !== null
                        && (($edge['metadata']['listener_component'] ?? null) === $id || $owner['id'] === $id || ($owner['parent'] ?? null) === $id)) {
                        $overridden = true;
                        break;
                    }
                }
                if ($overridden) {
                    continue;
                }
                $target = $component;
                $refresh = $listener['method'] === '$refresh';
                if (! $refresh) {
                    $callback = $this->calls->sourceMethodCandidate($component['name'], $listener['method']);
                    $target = $callback['method'];
                    if ($target === null || $callback['unresolved'] || $callback['limited'] || ($target['metadata']['visibility'] ?? null) !== 'public'
                        || ($target['metadata']['static'] ?? false) || ($target['metadata']['abstract'] ?? false) || isset($computed[$target['id']])
                        || strtolower($listener['method']) === 'render' || str_starts_with($listener['method'], '__')) {
                        $this->notice($site, 'Livewire mapped listener method is inaccessible or unresolved.');

                        continue;
                    }
                }
                if (! isset($this->index->elements[$eventId])) {
                    $event = new CatalogElement($eventId, $listener['event'], 'livewire-event', $site['line'], $site['end_line'], 0,
                        metadata: ['logical_resource' => true, 'execution_proven' => false]);
                    $this->index->elements[$eventId] = [...$event->toArray(), 'path' => $site['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => []];
                    $this->index->names[strtolower($listener['event'])][] = $eventId;
                }
                $this->index->elements[$eventId]['sources'][] = ['path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line']];
                $context = [...$site, 'listener_component' => $id];
                $this->edge($id, $eventId, 'registers-livewire-listener', $context, $profile);
                $this->edge($eventId, $target['id'], $refresh ? 'livewire-refreshes-component' : 'livewire-event-listener', $context, $profile, true);
            }
        }
    }

    /** @param array<string, mixed> $component
     * @param  array<string, list<array<string, mixed>>>  $maps
     * @return array<string, mixed>|null
     */
    private function listenerProperty(array $component, array $maps): ?array
    {
        // The member index supplies the actual inherited/trait declaration, not a path convention.
        // Unknown external ancestors or traits may replace it, so inspect that source boundary first.
        $pending = [$component['id']];
        $seen = [];
        while ($pending !== []) {
            $id = array_pop($pending);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (! $this->room($component)) {
                return null;
            }
            foreach ($this->index->out[$id] ?? [] as $position) {
                $edge = $this->index->relations[$position];
                if (! in_array($edge['kind'], ['extends', 'uses-trait'], true)) {
                    continue;
                }
                if (isset($this->index->elements[$edge['to']])) {
                    $pending[] = $edge['to'];
                } elseif (! in_array($edge['metadata']['target_name'] ?? '', ['Livewire\\Component', 'Livewire\\Features\\SupportEvents\\HandlesEvents'], true)) {
                    $this->notice($component, 'Livewire listeners property has an unknown source ancestor or trait.');

                    return null;
                }
            }
        }
        $selection = $this->calls->receiverCandidates('@property:'.$component['name'].'#listeners');
        if ($selection['limited'] || count($selection['sources']) > 1) {
            $this->notice($component, 'Livewire listeners property is ambiguous or exceeds the source budget.');

            return null;
        }
        if ($selection['sources'] === []) {
            return null; // The verified framework default is an empty listener map.
        }
        $ids = $this->index->names[strtolower($selection['sources'][0]['symbol'])] ?? [];
        $property = count($ids) === 1 ? $this->index->elements[$ids[0]] : null;
        $facts = $property === null ? [] : ($maps[$property['id']] ?? []);
        if ($property === null || $property['kind'] !== 'property' || ($property['metadata']['visibility'] ?? null) === 'private'
            || ($property['metadata']['static'] ?? false) || count($facts) !== 1 || $facts[0]['metadata']['form'] !== 'property' || ! $facts[0]['metadata']['resolved']) {
            $this->notice($component, 'Livewire listeners property is inaccessible, dynamic or unresolved.');

            return null;
        }

        return $facts[0];
    }

    /** @param array<string, bool> $components
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     */
    private function viewActions(array $components, array $profile): void
    {
        $owners = [];
        foreach ($this->index->relations as $edge) {
            if ($edge['kind'] === 'renders-livewire-view') {
                $owners[$edge['to']][] = $edge['from'];
            } elseif ($edge['kind'] === 'renders') {
                $method = $this->index->elements[$edge['from']] ?? null;
                if ($method !== null && $method['kind'] === 'method' && str_ends_with(strtolower($method['name']), '::render') && isset($components[$method['parent']])) {
                    $owners[$edge['to']][] = $method['parent'];
                }
            }
        }
        foreach ($this->index->elements as $site) {
            if ($site['kind'] !== 'livewire-view-action') {
                continue;
            }
            if (! $this->room($site)) {
                return;
            }
            $candidates = array_values(array_unique($owners[$site['parent']] ?? []));
            if (! $site['metadata']['resolved'] || $candidates === []) {
                $this->notice($site, 'Livewire action selector or owning component view is unresolved.');

                continue;
            }
            foreach ($candidates as $id) {
                if (! $this->room($site)) {
                    return;
                }
                $component = $this->index->elements[$id];
                $name = $site['metadata']['method'];
                $selection = $this->calls->sourceMethodCandidate($component['name'], $name);
                $method = $selection['method'];
                if ($method === null || $selection['unresolved'] || $selection['limited'] || ($method['metadata']['visibility'] ?? null) !== 'public'
                    || ($method['metadata']['static'] ?? false) || ($method['metadata']['abstract'] ?? false) || strtolower($name) === 'render' || str_starts_with($name, '__')
                    || in_array('livewire-computed', $this->index->elements[$method['id']]['roles'], true)) {
                    $this->notice($site, 'Livewire action is inaccessible, computed, rendering-only or unresolved.');

                    continue;
                }
                $this->index->addRelation(['from' => $site['parent'], 'to' => $method['id'], 'kind' => 'livewire-view-action', 'path' => $site['path'],
                    'line' => $site['line'], 'end_line' => $site['end_line'], 'knowledge' => 'static', 'resolution' => 'conditional',
                    'metadata' => ['package' => 'livewire/livewire', 'version' => $profile['version'], 'package_sources' => $profile['sources'],
                        'directive' => $site['metadata']['directive'], 'component' => $id, 'action_source' => $site['id'],
                        'runtime_component_view_selection_required' => true, 'nearest_component_scope_required' => true,
                        'standard_livewire_directive_required' => true, 'browser_expression_evaluation_required' => true,
                        'runtime_directive_trigger_required' => true, 'execution_proven' => false]]);
            }
        }
    }

    /** @param array<string, bool> $components
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     */
    private function sourceViews(array $components, array $profile): void
    {
        $views = $firstClasses = [];
        foreach ($this->index->elements as $element) {
            if ($element['kind'] === 'view' && ($element['metadata']['view_source'] ?? false)) {
                $views[$element['path']][] = $element;
            }
            if ($element['kind'] === 'class' && ($element['metadata']['anonymous'] ?? false)
                && ($this->index->elements[$element['parent'] ?? '']['kind'] ?? null) === 'file') {
                $firstClasses[$element['path']] = min($firstClasses[$element['path']] ?? PHP_INT_MAX, $element['offset']);
            }
        }
        foreach ($components as $id => $_) {
            $component = $this->index->elements[$id];
            if (! $this->room($component)) {
                return;
            }
            if (! ($component['metadata']['anonymous'] ?? false) || ($this->index->elements[$component['parent'] ?? '']['kind'] ?? null) !== 'file') {
                continue;
            }
            $path = $component['path'];
            if (($firstClasses[$path] ?? null) !== $component['offset']) {
                $this->notice($component, 'Livewire compiler does not select a later anonymous class in the same source file.');

                continue;
            }
            $form = str_ends_with($path, '.blade.php') ? 'single-file' : 'multi-file';
            if ($form === 'multi-file' && ! str_ends_with($path, '.php')) {
                continue;
            }
            if ($form === 'multi-file') {
                // MultiFileParser derives both companion filenames from the directory name.
                $directoryName = preg_replace('/⚡[\x{FE0E}\x{FE0F}]?/u', '', basename(dirname($path)));
                if (basename($path) !== $directoryName.'.php') {
                    $this->notice($component, 'Livewire multi-file class does not match its compiler directory name.');

                    continue;
                }
            }
            $viewPath = $form === 'single-file' ? $path : substr($path, 0, -4).'.blade.php';
            $candidates = $views[$viewPath] ?? [];
            if (count($candidates) !== 1) {
                $this->notice($component, 'Livewire anonymous component has no unique source view companion.');

                continue;
            }
            if ($form === 'single-file') {
                $portion = $candidates[0]['metadata']['livewire_class_portion'] ?? null;
                if ($portion === null || $component['offset'] < $portion['start'] || $component['offset'] >= $portion['end']) {
                    $this->notice($component, 'Livewire anonymous class is outside the compiler-selected first PHP portion.');

                    continue;
                }
            }
            // Compiler-injected view() is used only when there is no source render() override.
            $render = $this->calls->sourceMethodCandidate($component['name'], 'render');
            if ($render['method'] !== null || $render['unresolved'] || $render['limited']) {
                $this->notice($component, 'Livewire source render override prevents implicit companion view selection.');

                continue;
            }
            if ($this->index->namedTypes('Livewire\\Compiler\\Parser\\SingleFileParser') !== []
                || $this->index->namedTypes('Livewire\\Compiler\\Parser\\MultiFileParser') !== []) {
                $this->notice($component, 'Source Livewire compiler parser shadows prevent implicit view composition.');

                continue;
            }
            $this->index->elements[$id]['metadata']['livewire_source_form'] = $form;
            $view = $candidates[0];
            $this->index->addRelation(['from' => $id, 'to' => $view['id'], 'kind' => 'renders-livewire-view', 'path' => $path,
                'line' => $component['line'], 'end_line' => $component['end_line'], 'knowledge' => 'static', 'resolution' => 'conditional',
                'metadata' => ['package' => 'livewire/livewire', 'version' => $profile['version'], 'package_sources' => $profile['sources'],
                    'source_form' => $form, 'source_view_path' => $viewPath, 'source_locations_preserved' => true,
                    'runtime_compiler_component_selection_required' => true, 'mounted_component_required' => true,
                    'runtime_rendering_required' => true, 'execution_proven' => false]]);
        }
    }

    /** @param array<string, mixed> $site
     * @param  array<string, bool>  $components
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     */
    private function dispatch(array $site, array $components, array $profile): void
    {
        $scope = $this->index->elements[$site['parent']] ?? null;
        $visited = 0;
        while ($scope !== null && $scope['kind'] !== 'class' && ++$visited <= 32) {
            $scope = $this->index->elements[$scope['parent'] ?? ''] ?? null;
        }
        if ($scope === null || $scope['kind'] !== 'class' || ! isset($components[$scope['id']])) {
            return;
        }
        $selection = $this->calls->sourceMethodCandidate($scope['name'], 'dispatch');
        if ($selection['method'] !== null || $selection['unresolved'] || $selection['limited']
            || $this->index->namedTypes('Livewire\\Features\\SupportEvents\\HandlesEvents') !== []
            || $this->index->namedTypes('Livewire\\Features\\SupportEvents\\Event') !== [] || ! $site['metadata']['resolved']) {
            $this->notice($site, 'Livewire dispatch is overridden, dynamic or has an unresolved source contract.');

            return;
        }
        $name = $site['metadata']['event'];
        $mode = $site['metadata']['target_mode'];
        $targets = [];
        if ($mode === 'self') {
            $targets = [$scope['id']];
        } elseif ($mode === 'component') {
            $target = $site['metadata']['target'];
            $targets = $this->index->namedTypes($target);
            if ($targets === []) {
                $alias = CatalogElement::resourceIdentity('livewire-component-name', $target);
                foreach ($this->index->out[$alias] ?? [] as $position) {
                    $edge = $this->index->relations[$position];
                    if ($edge['kind'] === 'references-livewire-component-class') {
                        $targets[] = $edge['to'];
                    }
                }
                $targets = array_values(array_unique($targets));
            }
            if (count($targets) !== 1 || ! isset($components[$targets[0]])) {
                $this->notice($site, 'Targeted Livewire component is missing, dynamic or ambiguous.');

                return;
            }
        } elseif ($mode === 'runtime') {
            $this->notice($site, 'DOM or instance reference targeting requires browser state.');
        }
        $id = CatalogElement::resourceIdentity('livewire-event', $mode === 'global' ? $name : $name.'::'.$site['id']);
        if (! isset($this->index->elements[$id])) {
            $event = new CatalogElement($id, $name, 'livewire-event', $site['line'], $site['end_line'], 0,
                metadata: ['logical_resource' => true, 'execution_proven' => false, 'target_mode' => $mode]);
            $this->index->elements[$id] = [...$event->toArray(), 'path' => $site['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => []];
            $this->index->names[strtolower($name)][] = $id;
        }
        $this->index->elements[$id]['sources'][] = ['path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line']];
        $this->edge($site['parent'], $id, 'dispatches-livewire-event', $site, $profile, true);
        if ($targets === []) {
            return;
        }
        $global = CatalogElement::resourceIdentity('livewire-event', $name);
        $edges = $this->index->out[$global] ?? [];
        foreach ($edges as $position) {
            if (! $this->room($site)) {
                return;
            }
            $edge = $this->index->relations[$position];
            if (! in_array($edge['kind'], ['livewire-event-listener', 'livewire-refreshes-component'], true)) {
                continue;
            }
            $owner = $this->index->elements[$edge['to']];
            $class = $edge['metadata']['listener_component'] ?? ($owner['kind'] === 'class' ? $owner['id'] : $owner['parent']);
            if (in_array($class, $targets, true)) {
                $this->index->addRelation([...$edge, 'from' => $id, 'metadata' => [...$edge['metadata'], 'target_mode' => $mode,
                    'target_component' => $class, 'dispatch_source' => $site['id'], 'same_component_instance_required' => $mode === 'self']]);
            }
        }
    }

    /** @param array<string, mixed> $site
     * @phpstan-impure
     */
    private function room(array $site): bool
    {
        if (++$this->visited <= 25000 && ImpactExtractor::sourceLimit(0) === null) {
            return true;
        }
        $this->index->diagnostics[] = ['code' => 'catalog_limit', 'message' => 'Livewire composition reached its source budget.', 'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['id']];

        return false;
    }

    /** @param array<string, mixed> $source */
    private function role(string $id, string $role, array $source): void
    {
        $this->index->elements[$id]['roles'][] = $role;
        $this->index->elements[$id]['role_evidence'][] = ['role' => $role, 'basis' => 'livewire-source-contract', 'path' => $source['path'], 'line' => $source['line']];
    }

    /** @param array<string, mixed> $site */
    private function notice(array $site, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'package_livewire_analysis', 'message' => $message, 'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['id']];
    }

    /** @param array<string, mixed> $site
     * @param  array{version: string, sources: list<array<string, mixed>>}  $profile
     */
    private function edge(string $from, string $to, string $kind, array $site, array $profile, bool $delivery = false): void
    {
        $this->index->addRelation(['from' => $from, 'to' => $to, 'kind' => $kind, 'path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line'],
            'knowledge' => 'static', 'resolution' => $delivery ? 'conditional' : 'structural',
            'metadata' => ['package' => 'livewire/livewire', 'version' => $profile['version'], 'package_sources' => $profile['sources'], 'execution_proven' => false,
                'mounted_component_required' => true, 'browser_event_delivery_required' => $delivery, 'runtime_listener_selection_required' => $delivery, 'runtime_event_target_required' => $delivery,
                ...isset($site['listener_component']) ? ['listener_component' => $site['listener_component'], 'runtime_listener_map_evaluation_required' => true] : [],
                ...isset($site['listener_member']) ? ['listener_member' => $site['listener_member'], 'runtime_attribute_order_required' => true] : []]]);
    }
}
