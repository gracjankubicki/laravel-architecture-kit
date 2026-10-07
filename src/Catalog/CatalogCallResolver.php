<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Resolves cached source dispatch descriptors against the current declaration set. */
final class CatalogCallResolver
{
    /** @var array<string, array<string, list<string>>> */
    private array $members = [];

    /** @var array<string, list<string>> */
    private array $subtypes = [];

    /** @var array<string, list<string>>|null */
    private ?array $aiProviderSubtypes = null;

    /** @var array<string, list<string>> */
    private array $memberCache = [];

    private bool $limited = false;

    private int $returnVisits = 0;

    private int $factoryVisits = 0;

    private int $argumentVisits = 0;

    private bool $methodSelectionFailed = false;

    private bool $receiverInspection = false;

    private int $receiverVisits = 0;

    /** @var list<array<string, mixed>> */
    private array $receiverSources = [];

    public function __construct(private readonly CatalogIndex $index)
    {
        foreach ($index->elements as $id => $element) {
            if (in_array($element['kind'], ['method', 'property'], true) && is_string($element['parent'])) {
                $name = substr($element['name'], (int) strrpos($element['name'], '::') + 2);
                $key = $element['kind'] === 'method' ? strtolower($name) : $name;
                // Promoted properties belong lexically to the constructor, semantically to its class.
                $parent = $element['parent'];
                if ($element['kind'] === 'property' && ($index->elements[$parent]['kind'] ?? null) === 'method') {
                    $parent = $index->elements[$parent]['parent'];
                }
                if (is_string($parent)) {
                    $this->members[$parent][$key][] = $id;
                }
            }
        }
        foreach ($index->elements as $id => $element) {
            if (! in_array($element['kind'], ['class', 'enum'], true)) {
                continue;
            }
            foreach ($this->ancestors($id) as $ancestor) {
                $this->subtypes[$ancestor][] = $id;
            }
        }
    }

    /** @param list<array<string, mixed>> $calls */
    public function resolve(array $calls): void
    {
        $expanded = [];
        foreach ($calls as $call) {
            $this->limited = false;
            $values = $call['kind'] === 'calls' && $call['metadata']['method'] === '__invoke'
                ? $this->returnedValues($call['metadata']['receiver'], call: $call['metadata']['receiver_factory_call'] ?? null) : [];
            if ($values === []) {
                $expanded[] = $call;

                continue;
            }
            foreach ($values as $value) {
                $candidate = $call;
                $candidate['metadata']['returned_receiver'] = $call['metadata']['receiver'];
                $candidate['metadata']['receiver'] = $value['receiver'];
                $candidate['metadata']['exact_receiver'] = $value['exact_receiver'];
                $candidate['metadata']['bound_callable'] = $value['bound_callable'];
                if (isset($value['callable_form'])) {
                    $candidate['metadata']['callable_form'] = $value['callable_form'];
                }
                $candidate['metadata']['return_sources'] = $value['sources'];
                $expanded[] = $candidate;
            }
            if ($this->limited) {
                $this->diagnostic($call, 'dispatch_limit', 'Returned callable resolution reached its source or depth budget.');
            }
        }
        foreach ($expanded as $call) {
            $this->limited = false;
            $this->argumentVisits = 0;
            $receiver = $call['metadata']['receiver'];
            $targets = $this->callables($receiver, $call['metadata']['method'], $call['metadata']['exact_receiver']);
            $targets = array_values(array_unique($targets));
            if ($targets !== [] && $call['kind'] === 'calls') {
                $reachable = $this->invocationTargets($call, $targets);
                if ($reachable !== $targets) {
                    $this->diagnostic($call, 'inaccessible_dispatch', 'Source method selection is inaccessible, abstract, incompatible with the invocation form, or unresolved.');
                    foreach (array_diff($targets, $reachable) as $target) {
                        $this->index->addRelation([...$call, 'to' => $target, 'kind' => 'references-call', 'resolution' => 'structural']);
                    }
                }
                $targets = $reachable;
            }
            if (count($targets) > 1000 || $this->limited) {
                $this->diagnostic($call, 'dispatch_limit', 'Dispatch exceeded its candidate, type-expression or inheritance budget.');
                $targets = array_slice($targets, 0, 1000);
            }
            if (count($targets) > 1 && str_starts_with($receiver, '@function:')) {
                $this->diagnostic($call, 'ambiguous_function', 'Several source declarations match this function call.');
            }
            if ($targets === []) {
                $call['metadata']['external'] = $this->external($receiver);
                if (! $call['metadata']['external']) {
                    $this->diagnostic($call, 'unresolved_dispatch', 'Known source types do not resolve this call to a unique implementation.');
                }
                $this->index->addRelation($call);

                continue;
            }
            foreach ($targets as $target) {
                $row = [...$call, 'to' => $target, 'resolution' => count($targets) === 1 && $call['metadata']['exact_receiver'] ? 'resolved' : 'conditional'];
                $row['metadata']['candidate_count'] = count($targets);
                $row['metadata']['execution_proven'] = false;
                if ($call['metadata']['method'] === '__construct' && in_array($this->index->elements[$target]['kind'], ['class', 'enum'], true)) {
                    $row['kind'] = 'constructs';
                    $unknownConstructor = $this->unknownConstructor($target);
                    $row['metadata']['implicit_constructor'] = ! $unknownConstructor;
                    if ($unknownConstructor) {
                        $row['resolution'] = 'conditional';
                        $row['metadata']['constructor_unresolved'] = true;
                        $this->diagnostic($call, 'unresolved_constructor', 'Object construction is known, but an external ancestor or trait may supply its constructor.');
                    }
                }
                $this->index->addRelation($row);
            }
        }
    }

    /** @param array<string, mixed> $call
     * @param  list<string>  $targets
     * @return list<string>
     */
    private function invocationTargets(array $call, array $targets): array
    {
        $metadata = $call['metadata'];
        $receiver = $metadata['receiver'];
        $method = $metadata['method'];
        $exact = $metadata['exact_receiver'];
        if (str_starts_with($receiver, '@return:') && isset($metadata['receiver_factory_call'])
            && $this->factoryProducers($receiver, $metadata['receiver_factory_call']) === []) {
            return [];
        }
        if (str_starts_with($receiver, '@function:') || str_starts_with($receiver, '@closure:')) {
            return $targets;
        }
        $bound = $metadata['bound_callable'] ?? null;
        $creator = ($bound['scope_bound'] ?? false) ? $bound['creator'] : $call['from'];
        $scope = '';
        for ($level = 0; $level < 16 && isset($this->index->elements[$creator]); $level++) {
            $element = $this->index->elements[$creator];
            if (in_array($element['kind'], ['class', 'trait', 'enum'], true)) {
                $scope = $element['name'];
                break;
            }
            $creator = $element['parent'] ?? '';
        }
        $form = $metadata['form'];
        $methodCallable = str_starts_with($receiver, '@method-callable:');
        if ($methodCallable) {
            $descriptor = $this->descriptor(substr($receiver, 17));
            if ($descriptor === null) {
                return [];
            }
            [$receiver, $method, $exact] = $descriptor;
            $form = $metadata['callable_form'] ?? 'instance';
        }
        $static = in_array($form, ['class', 'self', 'parent', 'static', 'bound-class'], true);
        // PHP permits related class-qualified instance calls when $this is available.
        $instanceBinding = $methodCallable
            ? $form === 'bound-class' && $scope !== '' && $this->relatedTypes($scope, $receiver)
            : ! ($metadata['static_context'] ?? true) && $scope !== ''
                && (in_array($form, ['self', 'parent', 'static'], true) || $this->relatedTypes($scope, $receiver));
        $context = ['creator' => $scope, 'form' => $static && ! $instanceBinding ? 'static' : 'instance',
            'binding' => ($bound['late'] ?? $metadata['late_static_receiver'] ?? false) ? 'static' : ($bound['lexical'] ?? $metadata['lexical_static_receiver'] ?? null)];
        $selected = $this->sourceCallableCandidates($receiver, $method, $exact, $context)['targets'];
        // An implicit constructor has no method body to validate.
        foreach ($targets as $target) {
            if ($method === '__construct' && in_array($this->index->elements[$target]['kind'], ['class', 'enum'], true)) {
                $selected[] = $target;
            }
        }

        return array_values(array_intersect($targets, $selected));
    }

    /** Resolve symbolic property and union receivers against the current source snapshot.
     * @return array{types: list<string>, sources: list<array<string, mixed>>, limited: bool}
     */
    public function receiverCandidates(string $receiver): array
    {
        $this->argumentVisits = 0;
        $prior = $this->limited;
        $this->limited = false;
        $this->receiverInspection = true;
        $this->receiverVisits = 0;
        $this->receiverSources = [];
        $types = $this->types($receiver);
        $this->receiverInspection = false;
        // Cached member results may already carry an earlier hierarchy limit.
        $limited = $prior || $this->limited || count($types) > 128 || ImpactExtractor::sourceLimit(0) !== null;
        $this->limited = $prior || $limited;

        return ['types' => array_slice($types, 0, 128), 'sources' => $this->receiverSources, 'limited' => $limited];
    }

    /** Source return candidates for framework factories, using this snapshot's existing member index.
     * @return array{values: list<array<string, mixed>>, limited: bool}
     */
    public function returnCandidates(string $receiver): array
    {
        return ['values' => $this->returnedValues($receiver), 'limited' => $this->limited];
    }

    /** Framework factories require a callable declaration in the lexical call scope.
     * @param  array{creator: string, form: string, binding: ?string}  $call
     * @return array{values: list<array<string, mixed>>, limited: bool}
     */
    public function factoryReturnCandidates(string $receiver, array $call, bool $complete = false): array
    {
        $this->factoryVisits = 0;
        $this->returnVisits = 0;
        $prior = $this->limited;
        $this->limited = false;
        $values = $this->returnedValues($receiver, call: $call, checked: true, complete: $complete);
        $limited = $this->limited;
        $this->limited = $prior || $limited;

        return ['values' => $values, 'limited' => $limited];
    }

    /** Resolve the source body reached by a first-class callable created in its lexical scope.
     * @param  array{creator: string, form: string, binding: ?string}  $call
     * @return array{targets: list<string>, limited: bool}
     */
    public function sourceCallableCandidates(string $receiver, string $method, bool $exact, array $call): array
    {
        $this->factoryVisits = 0;
        $prior = $this->limited;
        $this->limited = false;
        $targets = $this->factoryProducers('@return:'.json_encode([$receiver, $method, $exact], JSON_THROW_ON_ERROR), $call);
        $limited = $this->limited;
        $this->limited = $prior || $limited;

        return ['targets' => $targets, 'limited' => $limited];
    }

    /** @param array{creator: string, form: string, binding: ?string} $call
     * @return list<string>
     *
     * @phpstan-impure
     */
    private function factoryProducers(string $receiver, array $call, int $depth = 0): array
    {
        if ($depth >= 16) {
            $this->limited = true;

            return [];
        }
        $descriptor = str_starts_with($receiver, '@return:') ? $this->descriptor(substr($receiver, 8)) : null;
        if ($descriptor === null) {
            return [];
        }
        $producers = [];
        if (str_starts_with($descriptor[0], '@return:') && $descriptor[1] === '__invoke') {
            if (! is_array($call['receiver_call'] ?? null)) {
                return [];
            }
            $creators = $this->factoryProducers($descriptor[0], $call['receiver_call'], $depth + 1);
            if ($creators === []) {
                return [];
            }
            foreach ($creators as $creator) {
                if (! $this->completeReturns($creator)) {
                    return [];
                }
            }
            $values = $this->returnedValues($descriptor[0], $depth + 1, $call['receiver_call'], true, complete: true);
            foreach ($values as $value) {
                $candidate = $this->returnedCallable($value);
                if ($candidate === null) {
                    return [];
                }
                if ($candidate['call']['form'] !== 'function' && ! str_starts_with($candidate['receiver'], '@return:')) {
                    $types = $this->types($candidate['receiver'], $depth + 1);
                    if ($types === []) {
                        return [];
                    }
                    foreach ($types as $type) {
                        $implementation = $this->sourceMethodCandidate($type, $candidate['method']);
                        if ($implementation['limited'] || $implementation['unresolved'] || $implementation['method'] === null) {
                            return [];
                        }
                    }
                }
                $selected = $this->factoryProducers('@return:'.json_encode([$candidate['receiver'], $candidate['method'], $candidate['exact']], JSON_THROW_ON_ERROR),
                    $candidate['call'], $depth + 1);
                if ($selected === []) {
                    return [];
                }
                array_push($producers, ...$selected);
            }

            return array_values(array_unique($producers));
        }
        if ($call['form'] === 'function') {
            $producers = array_values(array_filter($this->callables($descriptor[0], $descriptor[1], $descriptor[2]),
                fn ($id) => in_array($this->index->elements[$id]['kind'], ['function', 'closure'], true)));
            if (count($producers) !== 1) {
                $producers = [];
            }
        } else {
            foreach ($this->types($descriptor[0]) as $type) {
                $bases = $this->index->namedTypes($type);
                if (count($bases) !== 1) {
                    continue;
                }
                $base = $bases[0];
                $trait = $this->index->elements[$base]['kind'] === 'trait';
                $classes = [$base, ...(! $descriptor[2] || $trait && $call['binding'] !== null ? ($this->subtypes[$base] ?? []) : [])];
                if (count($classes) > 1000) {
                    $this->limited = true;
                }
                foreach (array_slice($classes, 0, 1000) as $class) {
                    if ($trait && $call['binding'] !== null && $class === $base) {
                        continue;
                    }
                    $this->methodSelectionFailed = false;
                    $selected = $this->sourceMethod($class, strtolower($descriptor[1]));
                    if ($selected === null || $this->methodSelectionFailed) {
                        continue;
                    }
                    $metadata = $selected['metadata'];
                    $creator = $trait && $call['binding'] !== null ? $this->index->elements[$class]['name'] : $call['creator'];
                    $scope = $selected['scope'];
                    $visibility = $metadata['visibility'] ?? null;
                    $accessible = $visibility === 'public' || $visibility === 'private' && strcasecmp($creator, $scope) === 0
                        || $visibility === 'protected' && $this->relatedTypes($creator, $scope);
                    if ($accessible && ($call['form'] !== 'static' || ($metadata['static'] ?? false)) && ! ($metadata['abstract'] ?? false)) {
                        $producers[] = $selected['id'];
                    }
                }
            }
        }

        return array_values(array_unique($producers));
    }

    /** Select a source body with its effective trait alias visibility and declaring scope.
     * @param  list<string>  $knownTraits  Verified package traits; defaults remain conservative.
     * @param  array<string, list<string>>  $knownTraitMethods  Inspected method inventory for unqualified adaptations.
     * @return array{method: array{id: string, scope: string, metadata: array<string, mixed>}|null, unresolved: bool, limited: bool}
     */
    public function sourceMethodCandidate(string $type, string $name, bool $knownHasFactory = false, array $knownTraits = [], array $knownTraitMethods = []): array
    {
        $this->factoryVisits = 0;
        $this->methodSelectionFailed = false;
        $prior = $this->limited;
        $this->limited = false;
        $ids = $this->index->namedTypes($type);
        $method = count($ids) === 1 ? $this->sourceMethod($ids[0], strtolower($name), knownHasFactory: $knownHasFactory, knownTraits: $knownTraits, knownTraitMethods: $knownTraitMethods) : null;

        $limited = $this->limited;
        $this->limited = $prior || $limited;

        return ['method' => $method, 'unresolved' => $this->methodSelectionFailed || count($ids) !== 1, 'limited' => $limited];
    }

    private function relatedTypes(string $left, string $right): bool
    {
        if (strcasecmp($left, $right) === 0) {
            return true;
        }
        foreach ($this->index->namedTypes($left) as $id) {
            foreach ($this->ancestors($id) as $ancestor) {
                if (strcasecmp($this->index->elements[$ancestor]['name'], $right) === 0) {
                    return true;
                }
            }
        }
        foreach ($this->index->namedTypes($right) as $id) {
            foreach ($this->ancestors($id) as $ancestor) {
                if (strcasecmp($this->index->elements[$ancestor]['name'], $left) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, bool> $seen
     * @param  list<string>  $knownTraits
     * @param  array<string, list<string>>  $knownTraitMethods
     * @return array{id: string, scope: string, metadata: array<string, mixed>}|null
     *
     * @phpstan-impure
     */
    private function sourceMethod(string $class, string $name, array $seen = [], bool $knownHasFactory = false, array $knownTraits = [], array $knownTraitMethods = []): ?array
    {
        if (isset($seen[$class]) || count($seen) >= 32 || ++$this->factoryVisits > 20000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;

            return null;
        }
        $seen[$class] = true;
        $own = $this->members[$class][$name] ?? [];
        if ($own !== []) {
            if (count($own) !== 1 || $this->index->elements[$own[0]]['kind'] !== 'method') {
                $this->methodSelectionFailed = true;

                return null;
            }

            return ['id' => $own[0], 'scope' => $this->index->elements[$class]['name'], 'metadata' => $this->index->elements[$own[0]]['metadata']];
        }
        $traits = $parents = [];
        foreach ($this->index->out[$class] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if ($edge['kind'] === 'uses-trait') {
                if (! isset($this->index->elements[$edge['to']])) {
                    $external = $edge['metadata']['target_name'] ?? '';
                    $rules = $this->index->elements[$class]['metadata']['trait_rules'] ?? [];
                    $externalAdapted = false;
                    foreach ($rules as $rule) {
                        $unqualifiedMayApply = $rule['trait'] === null && (! isset($knownTraitMethods[$external]) || in_array($rule['method'], $knownTraitMethods[$external], true));
                        if ($unqualifiedMayApply || $rule['trait'] !== null && strcasecmp($rule['trait'], $external) === 0
                            || count(array_filter($rule['excluded'], fn ($excluded) => strcasecmp($excluded, $external) === 0)) > 0) {
                            $externalAdapted = true;
                            break;
                        }
                    }
                    if (in_array($external, $knownTraits, true) && $this->index->namedTypes($external) === []
                        && ! $externalAdapted) {
                        continue;
                    }
                    if ($knownHasFactory && strcasecmp($edge['metadata']['target_name'] ?? '', 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory') === 0
                        && $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Factories\\HasFactory') === []
                        && ($this->index->elements[$class]['metadata']['trait_rules'] ?? []) === []) {
                        continue;
                    }
                    $this->methodSelectionFailed = true;

                    return null;
                }
                $traits[] = $edge['to'];
            } elseif ($edge['kind'] === 'extends' && isset($this->index->elements[$edge['to']])) {
                $parents[] = $edge['to'];
            }
        }
        $selection = CatalogTraitSelection::candidates($this->index, $class, $name, $traits);
        if ($selection === null) {
            $this->methodSelectionFailed = true;

            return null;
        }
        $candidates = [];
        foreach ($selection as [$trait, $method]) {
            $selected = $this->sourceMethod($trait, $method, $seen, $knownHasFactory, $knownTraits, $knownTraitMethods);
            if ($selected === null) {
                continue;
            }
            foreach ($this->index->elements[$class]['metadata']['trait_rules'] ?? [] as $rule) {
                if ($rule['method'] === $method && ($rule['alias'] ?? $method) === $name
                    && ($rule['trait'] === null || strcasecmp($rule['trait'], $this->index->elements[$trait]['name']) === 0) && $rule['visibility'] !== null) {
                    $selected['metadata']['visibility'] = $rule['visibility'];
                }
            }
            $selected['scope'] = $this->index->elements[$class]['name'];
            $candidates[$selected['id']] = $selected;
        }
        if (count($candidates) > 1) {
            $this->methodSelectionFailed = true;

            return null;
        }
        if ($candidates !== []) {
            return array_values($candidates)[0];
        }
        foreach ($parents as $parent) {
            $selected = $this->sourceMethod($parent, $name, $seen, $knownHasFactory, $knownTraits, $knownTraitMethods);
            if ($selected !== null) {
                return $selected;
            }
        }

        return null;
    }

    /** @param array{creator: string, form: string, binding: ?string}|null $call
     * @return list<array<string, mixed>>
     *
     * @phpstan-impure
     */
    private function returnedValues(string $receiver, int $depth = 0, ?array $call = null, bool $checked = false, bool $complete = false): array
    {
        if (! str_starts_with($receiver, '@return:')) {
            return [];
        }
        if ($depth >= 16 || ++$this->returnVisits > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;

            return [];
        }
        $descriptor = $this->descriptor(substr($receiver, 8));
        if ($descriptor === null) {
            return [];
        }
        $values = [];
        if ($checked && $call === null) {
            return [];
        }
        $producers = $call === null ? $this->callables($descriptor[0], $descriptor[1], $descriptor[2], $depth + 1) : $this->factoryProducers($receiver, $call, $depth + 1);
        foreach ($producers as $producer) {
            if ($complete && ! $this->completeReturns($producer)) {
                return [];
            }
            foreach ($this->index->out[$producer] ?? [] as $position) {
                $edge = $this->index->relations[$position];
                if ($edge['kind'] !== 'returns-value') {
                    continue;
                }
                $source = ['path' => $edge['path'], 'line' => $edge['line'], 'end_line' => $edge['end_line'], 'producer' => $producer, 'offset' => $edge['metadata']['offset'] ?? null];
                $nested = $this->returnedValues($edge['metadata']['receiver'], $depth + 1, $checked ? ($edge['metadata']['factory_call'] ?? null) : null, $checked, $complete);
                if ($nested === []) {
                    $values[] = [...$edge['metadata'], 'sources' => [$source]];
                } else {
                    foreach ($nested as $value) {
                        $values[] = [...$value, 'sources' => [$source, ...$value['sources']]];
                    }
                }
                if (count($values) >= 1000) {
                    $this->limited = true;

                    return array_slice($values, 0, 1000);
                }
            }
        }

        return $values;
    }

    private function completeReturns(string $producer): bool
    {
        $summary = $this->index->elements[$producer]['metadata']['return_sites'] ?? null;
        if (! is_array($summary) || ! ($summary['complete'] ?? false) || ($summary['offsets'] ?? []) === []) {
            return false;
        }
        $offsets = [];
        foreach ($this->index->out[$producer] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if ($edge['kind'] === 'returns-value') {
                $offsets[] = $edge['metadata']['offset'];
            }
        }

        return array_diff($summary['offsets'], $offsets) === [];
    }

    /** @return list<string>
     * @phpstan-impure
     */
    private function callables(string $receiver, string $method, bool $exact, int $depth = 0): array
    {
        if ($depth >= 16 || $this->receiverInspection && (++$this->receiverVisits > 10000 || ImpactExtractor::sourceLimit(0) !== null)) {
            $this->limited = true;

            return [];
        }
        if (str_starts_with($receiver, '@function:')) {
            foreach ($this->strings(substr($receiver, 10)) as $name) {
                $ids = array_values(array_filter($this->index->names[strtolower($name)] ?? [], fn ($id) => $this->index->elements[$id]['kind'] === 'function'));
                if ($ids !== []) {
                    return $ids;
                }
            }

            return [];
        }
        if (str_starts_with($receiver, '@closure:')) {
            return array_values(array_filter($this->index->names[strtolower(substr($receiver, 9))] ?? [], fn ($id) => $this->index->elements[$id]['kind'] === 'closure'));
        }
        if (str_starts_with($receiver, '@method-callable:')) {
            $descriptor = $this->descriptor(substr($receiver, 17));

            return $descriptor === null ? [] : $this->callables($descriptor[0], $descriptor[1], $descriptor[2], $depth + 1);
        }
        if ($method === '__invoke' && str_starts_with($receiver, '@return:')) {
            $descriptor = $this->descriptor(substr($receiver, 8));
            if ($descriptor === null) {
                return [];
            }
            $targets = [];
            foreach ($this->returnedValues($receiver, $depth + 1) as $value) {
                array_push($targets, ...$this->callables($value['receiver'], '__invoke', $value['exact_receiver'], $depth + 1));
            }
            foreach ($this->callables($descriptor[0], $descriptor[1], $descriptor[2], $depth + 1) as $callable) {
                foreach ($this->index->elements[$callable]['metadata']['return_types'] ?? [] as $type) {
                    array_push($targets, ...$this->callables($type, '__invoke', false, $depth + 1));
                }
            }

            return array_values(array_unique($targets));
        }
        $targets = [];
        foreach ($this->types($receiver, $depth + 1) as $type) {
            $ids = $this->index->namedTypes($type);
            if (count($ids) !== 1) {
                continue;
            }
            $classes = [$ids[0], ...($exact ? [] : ($this->subtypes[$ids[0]] ?? []))];
            if (count($classes) > 1000) {
                $this->limited = true;
            }
            foreach (array_slice($classes, 0, 1001) as $class) {
                $found = $this->member($class, strtolower($method));
                if ($found === [] && $method === '__construct') {
                    $found = [$class];
                }
                array_push($targets, ...$found);
            }
        }

        return array_values(array_unique($targets));
    }

    /** Source signature proof for preserving a variable after argument passing. */
    public function argumentReceiver(string $receiver, int $depth = 0): ?string
    {
        if ($depth === 0) {
            $this->argumentVisits = 0;
        }
        if ($depth >= 16 || ++$this->argumentVisits > 10000 || $this->argumentVisits % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;

            return null;
        }
        if (! str_starts_with($receiver, '@after-argument:')) {
            return null;
        }
        $value = json_decode(substr($receiver, 16), true, 32);
        if (! is_array($value) || array_keys($value) !== [0, 1, 2, 3, 4, 5, 6]
            || ! is_string($value[0]) || ! is_string($value[1]) || ! is_string($value[2]) || ! is_bool($value[3])
            || ! is_array($value[4]) || ! is_array($value[5]) || ! array_is_list($value[5]) || count($value[5]) > 128
            || ! in_array(array_keys($value[4]), [['creator', 'form', 'binding'], ['creator', 'form', 'binding', 'factory_call']], true) || ! is_string($value[4]['creator'])
            || ! in_array($value[4]['form'], ['instance', 'static', 'function'], true)
            || $value[4]['binding'] !== null && ! in_array($value[4]['binding'], ['self', 'parent', 'static'], true)
            || ! is_array($value[6]) || ! array_is_list($value[6]) || $value[6] === [] || count($value[6]) > 128) {
            return null;
        }
        foreach ($value[5] as $argument) {
            if (! is_array($argument) || array_keys($argument) !== ['name', 'unpack', 'by_ref']
                || $argument['name'] !== null && ! is_string($argument['name']) || $argument['unpack'] !== false || $argument['by_ref'] !== false) {
                return null;
            }
        }
        foreach ($value[6] as $position) {
            if (! is_int($position) || $position < 0 || ! isset($value[5][$position])) {
                return null;
            }
        }
        if (str_starts_with($value[1], '@return:')) {
            return $this->returnedArgumentReceiver($value, $depth);
        }
        if ($this->aiSetterArgument($value, $depth)) {
            return $value[0];
        }
        if ($value[4]['form'] !== 'function') {
            foreach ($this->types($value[1], $depth + 1) as $type) {
                $implementation = $this->sourceMethodCandidate($type, $value[2]);
                if ($implementation['limited'] || $implementation['unresolved'] || $implementation['method'] === null) {
                    return null;
                }
            }
        }
        $selected = $this->sourceCallableCandidates($value[1], $value[2], $value[3], $value[4]);
        if ($selected['limited'] || $selected['targets'] === []) {
            return null;
        }
        foreach ($selected['targets'] as $target) {
            $signature = $this->index->elements[$target]['metadata']['call_parameters'] ?? null;
            if ($signature === null || ! CatalogAiGateways::sourceArguments(['metadata' => ['limited' => false, 'arguments' => $value[5]]], $signature)) {
                return null;
            }
            $parameters = $signature['parameters'];
            $names = array_column($parameters, 'name');
            $variadic = $parameters !== [] && $parameters[array_key_last($parameters)]['variadic'];
            foreach ($value[6] as $position) {
                $name = $value[5][$position]['name'];
                $parameter = $name !== null ? array_search($name, $names, true) : $position;
                if ($parameter === false || $parameter >= count($parameters)) {
                    $parameter = $variadic ? array_key_last($parameters) : null;
                }
                if ($parameter === null || $parameters[$parameter]['by_ref']) {
                    return null;
                }
            }
        }

        return $value[0];
    }

    /** @param array<int, mixed> $value */
    private function returnedArgumentReceiver(array $value, int $depth): ?string
    {
        $call = $value[4]['factory_call'] ?? null;
        if (! is_array($call) || ! is_string($call['creator'] ?? null)
            || ! in_array($call['form'] ?? null, ['function', 'instance', 'static'], true)
            || ! array_key_exists('binding', $call) || $call['binding'] !== null && ! in_array($call['binding'], ['self', 'parent', 'static'], true)) {
            return null;
        }
        $descriptor = $this->descriptor(substr($value[1], 8));
        if ($descriptor === null) {
            return null;
        }
        if ($call['form'] !== 'function' && ! (str_starts_with($descriptor[0], '@return:') && $descriptor[1] === '__invoke')) {
            $types = $this->types($descriptor[0], $depth + 1);
            if ($types === []) {
                return null;
            }
            foreach ($types as $type) {
                $implementation = $this->sourceMethodCandidate($type, $descriptor[1]);
                if ($implementation['limited'] || $implementation['unresolved'] || $implementation['method'] === null) {
                    return null;
                }
            }
        }
        $selected = $this->sourceCallableCandidates($descriptor[0], $descriptor[1], $descriptor[2], $call);
        if ($selected['limited'] || $selected['targets'] === []) {
            return null;
        }
        foreach ($selected['targets'] as $producer) {
            $summary = $this->index->elements[$producer]['metadata']['return_sites'] ?? null;
            if (! is_array($summary) || ! ($summary['complete'] ?? false) || ($summary['offsets'] ?? []) === []) {
                return null;
            }
            $returns = [];
            foreach ($this->index->out[$producer] ?? [] as $position) {
                $edge = $this->index->relations[$position];
                if ($edge['kind'] === 'returns-value') {
                    $returns[$edge['metadata']['offset']] = $edge['metadata'];
                }
            }
            foreach ($summary['offsets'] as $offset) {
                // Unknown returns have no value edge. Never silently discard an alternative.
                $returned = $returns[$offset] ?? null;
                if ($returned === null) {
                    return null;
                }
                $candidate = $this->returnedCallable($returned);
                if ($candidate === null) {
                    return null;
                }
                $next = '@after-argument:'.json_encode([$value[0], $candidate['receiver'], $candidate['method'], $candidate['exact'], $candidate['call'], $value[5], $value[6]], JSON_THROW_ON_ERROR);
                if ($this->argumentReceiver($next, $depth + 1) === null) {
                    return null;
                }
            }
        }

        return $value[0];
    }

    /** @param array<string, mixed> $returned
     * @return array{receiver: string, method: string, exact: bool, call: array<string, mixed>}|null
     */
    private function returnedCallable(array $returned): ?array
    {
        $receiver = $returned['receiver'];
        $method = '__invoke';
        $exact = $returned['exact_receiver'];
        $context = ['creator' => '', 'form' => 'instance', 'binding' => null];
        if (str_starts_with($receiver, '@return:')) {
            $context['factory_call'] = $returned['factory_call'] ?? null;
        } elseif (str_starts_with($receiver, '@function:') || str_starts_with($receiver, '@closure:')) {
            $context['form'] = 'function';
        } elseif (str_starts_with($receiver, '@method-callable:')) {
            $bound = $returned['bound_callable'] ?? null;
            $target = $this->descriptor(substr($receiver, 17));
            $creator = is_array($bound) ? ($bound['creator'] ?? null) : null;
            if ($target === null || ! ($bound['scope_bound'] ?? false) || ! is_string($creator) || ! isset($this->index->elements[$creator])
                || ! in_array($returned['callable_form'] ?? null, ['static', 'instance', 'bound-class'], true)) {
                return null;
            }
            [$receiver, $method, $exact] = $target;
            $scope = '';
            for ($level = 0; $level < 16 && isset($this->index->elements[$creator]); $level++) {
                $element = $this->index->elements[$creator];
                if (in_array($element['kind'], ['class', 'trait', 'enum'], true)) {
                    $scope = $element['name'];
                    break;
                }
                $creator = $element['parent'] ?? '';
            }
            $context = ['creator' => $scope, 'form' => $returned['callable_form'] === 'static' ? 'static' : 'instance',
                'binding' => ($bound['late'] ?? false) ? 'static' : ($bound['lexical'] ?? null)];
        }

        return ['receiver' => $receiver, 'method' => $method, 'exact' => $exact, 'call' => $context];
    }

    /** @param array<int, mixed> $value */
    private function aiSetterArgument(array $value, int $depth): bool
    {
        $method = strtolower($value[2]);
        if (! in_array($method, CatalogAiGateways::SETTERS, true) || $value[4]['form'] !== 'instance'
            || count($value[5]) !== 1 || ! in_array($value[5][0]['name'], [null, 'gateway'], true)) {
            return false;
        }
        $profile = CatalogPackageVersions::verified($this->index, 'laravel/ai');
        if ($profile === null) {
            return false;
        }
        $channel = ucfirst(substr($method, 3, -7));
        $trait = 'Laravel\\Ai\\Providers\\Concerns\\Has'.$channel.'Gateway';
        $contract = 'Laravel\\Ai\\Contracts\\Gateway\\'.($channel === 'Text' && version_compare($profile['version'], '0.9.0.0', '>=') ? 'StepText' : $channel).'Gateway';
        $provider = 'Laravel\\Ai\\Contracts\\Providers\\'.$channel.'Provider';
        if ($this->index->namedTypes($trait) !== [] || $this->index->namedTypes($contract) !== [] || $this->index->namedTypes($provider) !== []) {
            return false;
        }
        $types = $this->types($value[1], $depth + 1);
        $gatewayTypes = $this->types($value[0], $depth + 1);
        if ($types === [] || $gatewayTypes === [] || $this->limited) {
            return false;
        }
        foreach ($gatewayTypes as $type) {
            if (! CatalogAiGatewayTypes::matches($this->index, $type, $contract, $profile['version'])) {
                return false;
            }
        }
        $inventory = CatalogAiProviderTypes::gatewayTraits($profile['version']);
        foreach ($types as $type) {
            $ids = $this->index->namedTypes($type);
            $standard = CatalogAiProviderTypes::standardSetter($this->index, $type, $channel, $profile['version']);
            if (! $standard && ! (count($ids) === 1 && $this->index->hasContract($ids[0], $trait))) {
                return false;
            }
            $candidates = $ids;
            if (! $value[3]) {
                if (count($ids) === 1) {
                    array_push($candidates, ...($this->subtypes[$ids[0]] ?? []));
                } else {
                    if ($this->aiProviderSubtypes === null) {
                        $this->aiProviderSubtypes = [];
                        foreach ($this->index->elements as $element) {
                            if ($element['kind'] !== 'class') {
                                continue;
                            }
                            foreach (CatalogAiProviderTypes::ancestors($this->index, $element['name'], $profile['version']) as $sdk) {
                                $this->aiProviderSubtypes[strtolower($sdk)][] = $element['id'];
                            }
                            if (ImpactExtractor::sourceLimit(0) !== null) {
                                $this->limited = true;

                                return false;
                            }
                        }
                    }
                    array_push($candidates, ...($this->aiProviderSubtypes[strtolower($type)] ?? []));
                }
            }
            if (count($candidates) > 1000) {
                $this->limited = true;

                return false;
            }
            foreach (array_unique($candidates) as $id) {
                $implementation = $this->sourceMethodCandidate($this->index->elements[$id]['name'], $method, knownTraits: array_keys($inventory), knownTraitMethods: $inventory);
                if ($implementation['limited'] || $implementation['unresolved'] || $implementation['method'] !== null) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return list<string>
     * @phpstan-impure
     */
    private function types(string $receiver, int $depth = 0): array
    {
        if ($depth >= 16 || $this->receiverInspection && (++$this->receiverVisits > 10000 || ImpactExtractor::sourceLimit(0) !== null)) {
            $this->limited = true;

            return [];
        }
        if (str_starts_with($receiver, '@types:')) {
            $types = [];
            foreach ($this->strings(substr($receiver, 7)) as $type) {
                array_push($types, ...$this->types($type, $depth + 1));
            }

            return array_values(array_unique($types));
        }
        if (str_starts_with($receiver, '@after-argument:')) {
            $original = $this->argumentReceiver($receiver, $depth);

            return $original === null ? [] : $this->types($original, $depth + 1);
        }
        if (str_starts_with($receiver, '@property:')) {
            $pair = substr($receiver, 10);
            $separator = strrpos($pair, '#');
            if ($separator === false) {
                return [];
            }
            $types = [];
            foreach ($this->types(substr($pair, 0, $separator), $depth + 1) as $class) {
                $ids = $this->index->namedTypes($class);
                if (count($ids) !== 1) {
                    continue;
                }
                foreach ($this->member($ids[0], '$'.substr($pair, $separator + 1)) as $property) {
                    if ($this->receiverInspection) {
                        if (count($this->receiverSources) >= 128) {
                            $this->limited = true;

                            return array_values(array_unique($types));
                        }
                        $element = $this->index->elements[$property];
                        $source = ['path' => $element['path'], 'line' => $element['line'], 'end_line' => $element['end_line'], 'symbol' => $element['name']];
                        if (! in_array($source, $this->receiverSources, true)) {
                            $this->receiverSources[] = $source;
                        }
                    }
                    foreach ($this->index->elements[$property]['metadata']['types'] ?? [] as $type) {
                        array_push($types, ...$this->types($type, $depth + 1));
                    }
                }
            }

            return array_values(array_unique($types));
        }
        if (str_starts_with($receiver, '@return:')) {
            $descriptor = $this->descriptor(substr($receiver, 8));
            if ($descriptor === null) {
                return [];
            }
            $types = [];
            foreach ($this->callables($descriptor[0], $descriptor[1], $descriptor[2], $depth + 1) as $id) {
                foreach ($this->index->elements[$id]['metadata']['return_types'] ?? [] as $type) {
                    array_push($types, ...$this->types($type, $depth + 1));
                }
            }

            return array_values(array_unique($types));
        }
        if (str_starts_with($receiver, '@parent:')) {
            $types = [];
            foreach ($this->index->namedTypes(substr($receiver, 8)) as $class) {
                foreach ($this->index->out[$class] ?? [] as $position) {
                    $edge = $this->index->relations[$position];
                    if ($edge['kind'] === 'extends' && isset($this->index->elements[$edge['to']])) {
                        $types[] = $this->index->elements[$edge['to']]['name'];
                    }
                }
            }

            return array_values(array_unique($types));
        }

        return str_starts_with($receiver, '@') ? [] : [$receiver];
    }

    /** @param array<string, bool> $seen
     * @return list<string>
     */
    private function member(string $class, string $name, array $seen = []): array
    {
        $key = $class.'#'.$name;
        if (isset($this->memberCache[$key])) {
            return $this->memberCache[$key];
        }
        if (isset($seen[$class]) || count($seen) >= 32) {
            if (count($seen) >= 32) {
                $this->limited = true;
            }

            return [];
        }
        if (isset($this->members[$class][$name])) {
            return $this->members[$class][$name];
        }
        $seen[$class] = true;
        $traits = $parents = [];
        foreach ($this->index->out[$class] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if (! isset($this->index->elements[$edge['to']])) {
                continue;
            }
            if ($edge['kind'] === 'uses-trait') {
                $traits[] = $edge['to'];
            } elseif (in_array($edge['kind'], ['extends', 'implements'], true)) {
                array_push($parents, ...$this->member($edge['to'], $name, $seen));
            }
        }
        $selection = CatalogTraitSelection::candidates($this->index, $class, $name, $traits);
        if ($selection === null) {
            return [];
        }
        $traitMembers = [];
        foreach ($selection as [$trait, $traitMethod]) {
            array_push($traitMembers, ...$this->member($trait, $traitMethod, $seen));
        }
        if (count(array_unique($traitMembers)) > 1) {
            $element = $this->index->elements[$class];
            $this->index->diagnostics[] = ['code' => 'trait_method_ambiguous', 'message' => 'Trait aliases or declarations expose several source methods under the same name.', 'path' => $element['path'], 'line' => $element['line'], 'subject' => $class];
        }
        $result = array_values(array_unique($traitMembers !== [] ? $traitMembers : $parents));
        if (count($seen) === 1) {
            $this->memberCache[$key] = $result;
        }

        return $result;
    }

    /** @param array<string, bool> $seen
     * @return list<string>
     */
    private function ancestors(string $id, array $seen = []): array
    {
        if (isset($seen[$id]) || count($seen) >= 32) {
            return [];
        }
        $seen[$id] = true;
        $ids = [];
        foreach ($this->index->out[$id] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if (in_array($edge['kind'], ['extends', 'implements', 'uses-trait'], true) && isset($this->index->elements[$edge['to']])) {
                $ids[] = $edge['to'];
                array_push($ids, ...$this->ancestors($edge['to'], $seen));
            }
        }

        return array_values(array_unique($ids));
    }

    private function external(string $receiver): bool
    {
        if (str_starts_with($receiver, '@function:')) {
            return true;
        }
        if (str_starts_with($receiver, '@return:')) {
            $descriptor = $this->descriptor(substr($receiver, 8));

            return $descriptor !== null && ! str_starts_with($descriptor[0], '@') && $this->index->namedTypes($descriptor[0]) === [];
        }
        if (str_starts_with($receiver, '@')) {
            return false;
        }

        return $this->index->namedTypes($receiver) === [];
    }

    /** @param array<string, bool> $seen */
    private function unknownConstructor(string $class, array $seen = []): bool
    {
        if (isset($seen[$class]) || count($seen) >= 32) {
            return true;
        }
        if ($this->index->elements[$class]['metadata']['trait_adaptations'] ?? false) {
            return true;
        }
        $seen[$class] = true;
        foreach ($this->index->out[$class] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if (! in_array($edge['kind'], ['extends', 'uses-trait'], true)) {
                continue;
            }
            if (! isset($this->index->elements[$edge['to']]) || $this->unknownConstructor($edge['to'], $seen)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function strings(string $json): array
    {
        $value = json_decode($json, true, 32);

        return is_array($value) && array_is_list($value) && count($value) <= 128 && count(array_filter($value, 'is_string')) === count($value) ? $value : [];
    }

    /** @return array{string, string, bool}|null */
    private function descriptor(string $json): ?array
    {
        $value = json_decode($json, true, 32);

        return is_array($value) && array_keys($value) === [0, 1, 2] && is_string($value[0]) && is_string($value[1]) && is_bool($value[2]) ? $value : null;
    }

    /** @param array<string, mixed> $call */
    private function diagnostic(array $call, string $code, string $message): void
    {
        $this->index->diagnostics[] = ['code' => $code, 'message' => $message, 'path' => $call['path'], 'line' => $call['line'], 'subject' => $call['from']];
    }
}
