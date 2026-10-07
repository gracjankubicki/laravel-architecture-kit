<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ExecutionLinks;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Connects config driver names to declared factories; registration is not request execution. */
final class CatalogAuthRegistrationResolver
{
    private int $callbackVisits = 0;

    private bool $callbackUnresolved = false;

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    /** @param array<string, mixed> $facts */
    public function resolve(array $facts, ExecutionLinks $links): void
    {
        $this->callbackVisits = 0;
        foreach ($facts['classes'] as $class) {
            foreach (['Illuminate\Contracts\Auth\Guard' => 'guard', 'Illuminate\Contracts\Auth\UserProvider' => 'user-provider'] as $contract => $role) {
                if ($links->inherits($class['name'], $contract)) {
                    foreach ($this->index->namedTypes($class['name']) as $id) {
                        $this->index->elements[$id]['roles'] = array_values(array_unique([...$this->index->elements[$id]['roles'], $role]));
                        $this->index->elements[$id]['role_evidence'][] = ['role' => $role, 'basis' => 'source-contract', 'path' => $class['path'], 'line' => $class['line']];
                    }
                }
            }
        }
        $counts = [];
        $visits = 0;
        $configs = [];
        foreach ($this->index->elements as $resource) {
            if (in_array($resource['kind'], ['auth-user-provider', 'auth-guard'], true) && is_string($resource['metadata']['driver'] ?? null)) {
                $configs[$resource['kind']][$resource['metadata']['driver']][] = $resource;
            }
        }
        foreach ($facts['operations'] as $op) {
            if ($op['kind'] === 'auth_registration' && is_string($op['args']['driver'])) {
                $kind = $op['method'] === 'provider' ? 'user-provider-driver' : 'auth-driver';
                $counts[$kind.':'.$op['args']['driver']] = ($counts[$kind.':'.$op['args']['driver']] ?? 0) + 1;
            }
        }
        foreach ($facts['operations'] as $op) {
            if ($op['kind'] !== 'auth_registration') {
                continue;
            }
            $name = $op['args']['driver'];
            $callback = $op['args']['callback'];
            $targets = $this->callbackTargets($callback, $op, $links);
            if (! is_string($name) || count($targets) !== 1) {
                $this->notice($op, 'Authentication driver name or registration callable is unresolved.');

                continue;
            }
            $kind = $op['method'] === 'provider' ? 'user-provider-driver' : 'auth-driver';
            $id = CatalogElement::resourceIdentity($kind, $name);
            if (! isset($this->index->elements[$id])) {
                $element = new CatalogElement($id, $name, $kind, $op['source']['line'], $op['source']['line'], $op['source']['offset'], metadata: ['execution_proven' => false]);
                $this->index->elements[$id] = [...$element->toArray(), 'path' => $op['source']['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => []];
                $this->index->names[strtolower($name)][] = $id;
            }
            $this->edge($id, $targets[0], 'auth-factory-registration', $op, ['operation' => $op['method']]);
            if ($counts[$kind.':'.$name] > 1) {
                $this->notice($op, 'Several authentication driver registrations are candidates; runtime order is unresolved.');
            }
            foreach ($configs[$op['method'] === 'provider' ? 'auth-user-provider' : 'auth-guard'][$name] ?? [] as $resource) {
                if ($this->budgetExceeded($visits)) {
                    $this->notice($op, 'Authentication factory composition reached its operation or memory budget.');

                    return;
                }
                if ($resource['kind'] === ($op['method'] === 'provider' ? 'auth-user-provider' : 'auth-guard') && ($resource['metadata']['driver'] ?? null) === $name) {
                    $this->edge($resource['id'], $targets[0], $op['method'] === 'viarequest' ? 'auth-request-user' : 'auth-driver-factory', $op,
                        ['config_source' => ['path' => $resource['path'], 'line' => $resource['line']], 'operation' => $op['method']]);
                }
            }
            if ($op['method'] !== 'viarequest') {
                $found = false;
                foreach ($this->index->out[$targets[0]] ?? [] as $position) {
                    $row = $this->index->relations[$position];
                    if ($row['kind'] !== 'returns-value') {
                        continue;
                    }
                    $receiver = $row['metadata']['receiver'];
                    $candidates = [['receiver' => $receiver, 'sources' => []]];
                    if (str_starts_with($receiver, '@return:')) {
                        $call = $row['metadata']['factory_call'] ?? null;
                        $returned = $call === null ? ['values' => [], 'limited' => false] : $this->calls->factoryReturnCandidates($receiver, $call);
                        if ($call === null || $returned['values'] === []) {
                            $this->notice($op, 'Authentication factory return producer is absent, inaccessible or unresolved in its source call form.');
                        }
                        $candidates = $returned['values'];
                        if ($returned['limited']) {
                            $this->notice($op, 'Authentication factory return lookup reached its source traversal budget.');
                        }
                    }
                    if ($this->budgetExceeded($visits)) {
                        $this->notice($op, 'Authentication factory results reached their operation or memory budget.');

                        return;
                    }
                    foreach ($candidates as $candidate) {
                        if ($this->budgetExceeded($visits)) {
                            $this->notice($op, 'Authentication factory candidates reached their operation or memory budget.');

                            return;
                        }
                        $receiver = $candidate['receiver'];
                        $resultTargets = str_starts_with($receiver, '@') ? [] : $this->index->namedTypes($receiver);
                        if ($resultTargets === []) {
                            $this->notice($op, 'Authentication factory result type is dynamic, external or unresolved.');
                        }
                        foreach ($resultTargets as $target) {
                            if ($this->budgetExceeded($visits)) {
                                $this->notice($op, 'Authentication factory candidates reached their operation or memory budget.');

                                return;
                            }
                            $found = true;
                            $contract = $op['method'] === 'provider' ? 'Illuminate\Contracts\Auth\UserProvider' : 'Illuminate\Contracts\Auth\Guard';
                            $matches = $links->inherits($this->index->elements[$target]['name'], $contract);
                            $this->edge($targets[0], $target, 'auth-factory-result', $op, ['result_candidate' => true,
                                'expected_contract' => $contract, 'contract_matches' => $matches,
                                'contract_requires_check' => ! $matches,
                                'return_chain_sources' => $candidate['sources'],
                                'return_chain_requires_check' => $candidate['sources'] !== [],
                                'return_source' => ['path' => $row['path'], 'line' => $row['line'], 'end_line' => $row['end_line']]]);
                            if (! $matches) {
                                $this->notice($op, 'Authentication factory result does not have a recognized source Guard or UserProvider contract; check its runtime compatibility.');
                            }
                        }
                    }
                }
                if (! $found) {
                    $this->notice($op, 'Authentication factory result type is dynamic or unresolved.');
                }
            }
        }
    }

    /** Charges every traversed configuration, return and result candidate.
     * @phpstan-impure
     */
    private function budgetExceeded(int &$visits): bool
    {
        return ++$visits > 10000 || ImpactExtractor::sourceLimit(0) !== null;
    }

    /**
     * @param  array<string, mixed>  $op
     * @return list<string>
     */
    private function callbackTargets(mixed $callback, array $op, ExecutionLinks $links): array
    {
        $this->callbackUnresolved = false;
        if (! is_array($callback)) {
            return [];
        }
        if ($op['method'] !== 'viarequest' && ($callback['type'] ?? null) !== 'callback' && ! ($callback['first_class'] ?? false)) {
            $this->notice($op, 'Auth extend/provider require Closure callbacks; this callable form is not a Closure.');

            return [];
        }
        $symbol = $callback['symbol'] ?? null;
        if (is_string($symbol) && str_starts_with($symbol, '(callback) ')) {
            $symbol = '(closure) '.substr($symbol, 11);
        }
        $targets = is_string($symbol) ? ($this->index->names[strtolower($symbol)] ?? []) : [];
        foreach ($callback['function_names'] ?? [] as $name) {
            $functions = array_values(array_filter($this->index->names[strtolower($name)] ?? [], fn ($id) => $this->index->elements[$id]['kind'] === 'function'));
            if ($functions !== []) {
                $targets = $functions;
                break;
            }
        }
        if (is_string($symbol) && str_contains($symbol, '::')) {
            [$owner, $method] = explode('::', $symbol, 2);
            $methods = $this->sourceMethods($owner, strtolower($method), $op);
            if (count($methods) !== 1 || $this->callbackUnresolved) {
                $this->notice($op, 'Authentication callback method source is absent or ambiguous.');

                return [];
            }
            $targets = array_keys($methods);
            $metadata = array_values($methods)[0];
            $creator = $callback['creator_class'] ?? '';
            $scope = $metadata['scope'] ?? $owner;
            $visibility = $metadata['visibility'] ?? null;
            $accessible = $visibility === 'public' || ($callback['first_class'] ?? false) &&
                ($visibility === 'private' && strcasecmp($creator, $scope) === 0
                    || $visibility === 'protected' && $links->inherits($creator, $scope));
            if (($metadata['abstract'] ?? false) || ! $accessible
                || (in_array($callback['callable_form'] ?? null, ['static-string', 'static-array', 'static-first-class'], true) && ! ($metadata['static'] ?? false))) {
                $this->notice($op, 'Authentication callback method is not callable in its declared form.');

                return [];
            }
        }
        $targets = array_values(array_filter($targets, fn ($id) => in_array($this->index->elements[$id]['kind'], ['method', 'function', 'closure'], true)));

        return $targets;
    }

    /** @param array<string, mixed> $op
     * @param  list<string>  $seen
     * @return array<string, array<string, mixed>>
     *
     * @phpstan-impure
     */
    private function sourceMethods(string $owner, string $name, array $op, array $seen = []): array
    {
        if (in_array(strtolower($owner), $seen, true) || count($seen) >= 32 || ++$this->callbackVisits > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->callbackUnresolved = true;
            $this->notice($op, 'Authentication callback lookup reached a cycle, depth, operation or memory limit.');

            return [];
        }
        $ids = $this->index->namedTypes($owner);
        if (count($ids) !== 1) {
            return [];
        }
        $id = $ids[0];
        $own = array_values(array_filter($this->index->names[strtolower($owner.'::'.$name)] ?? [], fn ($member) => $this->index->elements[$member]['kind'] === 'method'));
        if ($own !== []) {
            $result = [];
            foreach ($own as $member) {
                $result[$member] = [...$this->index->elements[$member]['metadata'], 'scope' => $owner];
            }

            return $result;
        }
        $seen[] = strtolower($owner);
        $traits = $parents = [];
        foreach ($this->index->out[$id] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if ($edge['kind'] === 'uses-trait' && ! isset($this->index->elements[$edge['to']])) {
                $this->callbackUnresolved = true;
                $this->notice($op, 'Authentication callback trait source is unavailable.');

                return [];
            }
            if ($edge['kind'] === 'uses-trait' && isset($this->index->elements[$edge['to']])) {
                $traits[] = $edge['to'];
            } elseif ($edge['kind'] === 'extends' && isset($this->index->elements[$edge['to']])) {
                $parents[] = $edge['to'];
            }
        }
        $selection = CatalogTraitSelection::candidates($this->index, $id, $name, $traits);
        if ($selection === null) {
            $this->callbackUnresolved = true;
            $this->notice($op, 'Authentication callback trait adaptations are unresolved.');

            return [];
        }
        $result = [];
        foreach ($selection as [$trait, $method]) {
            foreach ($this->sourceMethods($this->index->elements[$trait]['name'], $method, $op, $seen) as $member => $metadata) {
                foreach ($this->index->elements[$id]['metadata']['trait_rules'] ?? [] as $rule) {
                    if ($rule['method'] === $method && ($rule['alias'] ?? $method) === $name
                        && ($rule['trait'] === null || strcasecmp($rule['trait'], $this->index->elements[$trait]['name']) === 0)
                        && $rule['visibility'] !== null) {
                        $metadata['visibility'] = $rule['visibility'];
                    }
                }
                $result[$member] = [...$metadata, 'scope' => $owner];
            }
        }
        if ($this->callbackUnresolved) {
            return [];
        }
        if ($result !== []) {
            return $result;
        }
        foreach ($parents as $parent) {
            $result += $this->sourceMethods($this->index->elements[$parent]['name'], $name, $op, $seen);
        }

        return $result;
    }

    /** @param array<string, mixed> $op
     * @param  array<string, mixed>  $metadata
     */
    private function edge(string $from, string $to, string $kind, array $op, array $metadata): void
    {
        $this->index->addRelation(['from' => $from, 'to' => $to, 'kind' => $kind, 'path' => $op['source']['path'], 'line' => $op['source']['line'], 'end_line' => $op['source']['line'],
            'resolution' => 'conditional', 'knowledge' => 'static', 'metadata' => [...$metadata, 'execution_proven' => false, 'conditions' => [...$op['conditions'], 'Authentication registration and selected config must be active; callbacks are not executed.']]]);
    }

    /** @param array<string, mixed> $op */
    private function notice(array $op, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'auth_registration_analysis', 'message' => $message, 'path' => $op['source']['path'], 'line' => $op['source']['line'], 'subject' => null];
    }
}
