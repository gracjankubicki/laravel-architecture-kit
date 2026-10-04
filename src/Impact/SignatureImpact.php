<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;

/** Query-local comparison; only immediate call sites are evaluated, never BFS rows. */
final class SignatureImpact
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $groups = ['breaking' => [], 'check' => [], 'compatible' => []];

    private bool $limited = false;

    private int $visits = 0;

    /** @param array<string, mixed> $declaration
     * @return array<string, mixed> */
    public function inspect(ImpactIndex $index, array $declaration, ?string $proposal, int $limit): array
    {
        $before = $this->signature($index, $declaration);
        $after = $proposal === null ? null : MethodSignature::parse($proposal, $declaration['name'], $before, $declaration['class'], $index->classes[strtolower($declaration['class'])]['parents'][0] ?? null);
        $changes = $after === null ? [] : SignatureCompatibility::semanticChanges($before, $after);
        $targetClass = $index->classes[strtolower($declaration['class'])];
        foreach ($index->signatureCalls($declaration['symbol']) as $call) {
            if (! $this->budget()) {
                break;
            }
            $row = ['symbol' => $call['from'], 'path' => $call['path'], 'line' => $call['line'], 'kind' => 'call', 'certainty' => $call['certainty'], 'site' => $call['site']];
            if ($after === null || $call['kind'] === 'reference' || $call['certainty'] !== 'resolved' || $targetClass['kind'] === 'trait' || (($index->classes[strtolower($call['site']['class'])]['kind'] ?? '') === 'trait') || (($after['visibility'] !== $before['visibility'] || $after['static'] !== $before['static']) && $this->magicDispatch($index, $call)) || array_filter($call['site']['arguments'], static fn (array $arg): bool => $arg['unpack'])) {
                $this->add('check', $row, [$after === null ? 'Inspect this direct caller before changing the declaration.' : 'Possible or magic dispatch, callable reference, trait scope or unpacked arguments require inspection.']);

                continue;
            }
            $errors = $this->callErrors($index, $after, $call['site'], $declaration);
            $oldErrors = $this->callErrors($index, $before, $call['site'], $declaration);
            $newErrors = array_values(array_diff($errors, $oldErrors));
            $uncertain = $changes;
            if (SignatureCompatibility::extraPositionalArguments($after, $call['site'])) {
                $uncertain[] = 'Extra positional arguments may be consumed by the method body; inspect their semantics.';
            }
            if (array_filter($call['site']['arguments'], static fn (array $arg): bool => $arg['reference'] === 'unknown')) {
                $uncertain[] = 'An argument may return by reference; inspect its declaration.';
            }
            if ($oldErrors !== []) {
                $uncertain[] = 'The current call already has a static mismatch; inspect it separately.';
            }
            $this->add($newErrors !== [] ? 'breaking' : ($uncertain !== [] ? 'check' : 'compatible'), $row, $newErrors !== [] ? $newErrors : ($uncertain !== [] ? $uncertain : ['Arguments and access match within the supported static rules.']));
        }
        $relations = $this->contracts($index, $declaration);
        foreach ($relations as $relation) {
            if (! $this->budget()) {
                break;
            }
            $other = $relation['declaration'];
            $otherSignature = $this->signature($index, $other);
            $row = ['symbol' => $other['symbol'], 'path' => $other['path'], 'line' => $other['line'], 'kind' => $relation['direction'], 'certainty' => 'resolved'];
            if ($after === null) {
                $this->add('check', $row, ['Inspect this inherited or implemented declaration.']);

                continue;
            }
            $parentBefore = $relation['direction'] === 'ancestor_contract' ? $otherSignature : $before;
            $childBefore = $relation['direction'] === 'ancestor_contract' ? $before : $otherSignature;
            $parentAfter = $relation['direction'] === 'ancestor_contract' ? $otherSignature : $after;
            $childAfter = $relation['direction'] === 'ancestor_contract' ? $after : $otherSignature;
            if (strtolower($declaration['name']) === '__construct' && ! $parentAfter['abstract'] && ($relation['direction'] === 'ancestor_contract' ? $index->classes[strtolower($other['class'])]['kind'] : $targetClass['kind']) !== 'interface') {
                $this->add('check', $row, ['Concrete constructors are exempt from ordinary signature compatibility; inspect construction and visibility.']);

                continue;
            }
            $parentDeclaration = $relation['direction'] === 'ancestor_contract' ? $other : $declaration;
            $privateTraitRequirement = $parentBefore['abstract'] && $index->classes[strtolower($parentDeclaration['class'])]['kind'] === 'trait';
            $old = SignatureCompatibility::contract($parentBefore, $childBefore, $privateTraitRequirement);
            $new = SignatureCompatibility::contract($parentAfter, $childAfter, $privateTraitRequirement);
            $errors = array_values(array_diff($new['errors'], $old['errors']));
            $uncertain = $new['uncertain'];
            if ($old['errors'] !== []) {
                $uncertain[] = 'Existing declaration mismatch requires inspection.';
            }
            $this->add($errors !== [] ? 'breaking' : ($uncertain !== [] ? 'check' : 'compatible'), $row, $errors !== [] ? $errors : ($uncertain !== [] ? $uncertain : ['Declaration shape matches within the supported variance rules.']));
        }
        foreach ($changes as $reason) {
            $this->add('check', ['symbol' => $declaration['symbol'], 'path' => $declaration['path'], 'line' => $declaration['line'], 'kind' => 'declaration', 'certainty' => 'resolved'], [$reason]);
        }
        // Dispatch performed by Laravel is never discovered by executing the app.
        $limitations = ['Totals count evaluated rows; truncated totals are lower bounds. Signature analysis is bounded to 10000 visits and PHP memory headroom. Narrow the configured scope after an internal limit.', 'BREAKING means a proved static incompatibility if this call reaches the selected declaration, not proof of runtime execution.', 'No breaking rows is not proof that a change is safe or that tests pass.', 'Framework/container dispatch, magic calls, callbacks and runtime types require inspection. Use fully qualified class types in proposals; type aliases and variance can remain uncertain.'];
        if (strtolower($declaration['name']) === '__construct') {
            $this->add('check', ['symbol' => $declaration['symbol'], 'path' => $declaration['path'], 'line' => $declaration['line'], 'kind' => 'container', 'certainty' => 'possible'], ['Inspect Laravel autowiring and container registrations; new scalar requirements may prevent construction.']);
        }
        $totals = array_map('count', $this->groups);
        $truncated = $this->limited || max($totals) > $limit || $index->limitReached();
        $groups = array_map(static fn (array $rows): array => array_slice($rows, 0, $limit), $this->groups);

        return ['declaration' => $declaration['symbol'], 'current' => $before, 'proposed' => $after, 'mode' => $after === null ? 'inspect' : 'compare',
            ...$groups, 'total' => $totals, 'truncated' => $truncated, 'limitations' => $limitations,
            'status' => $truncated ? 'limit' : ($after === null ? 'inspect' : ($totals['breaking'] > 0 ? 'breaking' : ($totals['check'] > 0 ? 'check' : 'no_proven_breaking')))];
    }

    /** @param array<string, mixed> $signature
     * @param array<string, mixed> $site
     * @param array<string, mixed> $declaration
     * @return list<string> */
    private function callErrors(ImpactIndex $index, array $signature, array $site, array $declaration): array
    {
        $errors = SignatureCompatibility::argumentErrors($signature, $site);
        $caller = $site['class'];
        $related = strcasecmp($caller, $declaration['class']) === 0 || in_array(strtolower($declaration['class']), array_map('strtolower', $index->ancestors($caller)), true);
        if (! $signature['static'] && in_array($site['form'], ['class', 'self', 'parent', 'static'], true) && ($site['static_context'] || ! $related)) {
            $errors[] = 'non_static_method_called_statically';
        }
        if ($signature['visibility'] === 'private' && strcasecmp($caller, $declaration['class']) !== 0) {
            $errors[] = 'private_method_outside_declaring_class';
        }
        if ($signature['visibility'] === 'protected' && ! $related && ! in_array(strtolower($caller), array_map('strtolower', $index->ancestors($declaration['class'])), true)) {
            $errors[] = 'protected_method_outside_class_hierarchy';
        }

        return $errors;
    }

    /** @param array<string, mixed> $declaration
     * @return array<string, mixed> */
    private function signature(ImpactIndex $index, array $declaration): array
    {
        return $index->classes[strtolower($declaration['class'])]['methods'][strtolower($declaration['name'])]['signature'];
    }

    /** @param array<string, mixed> $call */
    private function magicDispatch(ImpactIndex $index, array $call): bool
    {
        $receiver = $call['receiver'];
        if ($receiver === null) {
            return false;
        }
        $handler = $call['site']['form'] === 'instance' ? '__call' : '__callStatic';

        return $index->method($receiver, $handler) !== null;
    }

    /** @param array<string, mixed> $declaration
     * @return list<array<string, mixed>> */
    private function contracts(ImpactIndex $index, array $declaration): array
    {
        $relations = [];
        foreach ($index->classes as $class) {
            if (! $this->budget()) {
                break;
            }
            $found = $index->method($class['name'], $declaration['name']);
            if ($found === null) {
                continue;
            }
            $ancestors = $index->ancestors($class['name']);
            if (strcasecmp($found['symbol'], $declaration['symbol']) === 0) {
                foreach ($ancestors as $ancestor) {
                    $parent = $index->classes[strtolower($ancestor)] ?? null;
                    if ($parent === null) {
                        $this->add('check', ['symbol' => $ancestor, 'path' => $class['path'], 'line' => $class['line'], 'kind' => 'ancestor_contract', 'certainty' => 'unresolved'], ['Ancestor declaration is outside the indexed scope; inspect its signature contract.']);

                        continue;
                    }
                    if ($parent['kind'] === 'trait' && ! isset($parent['methods'][strtolower($declaration['name'])])) {
                        continue;
                    }
                    $own = $index->method($parent['name'], $declaration['name']);
                    if ($own !== null && strcasecmp($own['symbol'], $declaration['symbol']) !== 0 && ($parent['kind'] !== 'trait' || $this->signature($index, $own)['abstract'])) {
                        $relations[$own['symbol'].'|ancestor_contract'] = ['declaration' => $own, 'direction' => 'ancestor_contract'];
                    }
                }
            } elseif (in_array(strtolower($declaration['class']), array_map('strtolower', $ancestors), true)) {
                if ($index->classes[strtolower($declaration['class'])]['kind'] === 'trait' && ! $this->signature($index, $declaration)['abstract']) {
                    // A class replaces a concrete trait method without an inheritance
                    // contract. A subclass overriding an imported parent method does
                    // have a contract with that parent declaration.
                    $inherited = false;
                    foreach ($ancestors as $ancestor) {
                        if (($index->classes[strtolower($ancestor)]['kind'] ?? null) !== 'class') {
                            continue;
                        }
                        $parentMethod = $index->method($ancestor, $declaration['name']);
                        if ($parentMethod !== null && strcasecmp($parentMethod['symbol'], $declaration['symbol']) === 0) {
                            $inherited = true;
                            break;
                        }
                    }
                    if (! $inherited) {
                        continue;
                    }
                }
                $relations[$found['symbol'].'|descendant_override'] = ['declaration' => $found, 'direction' => 'descendant_override'];
            }
        }

        return array_values($relations);
    }

    private function budget(): bool
    {
        $memory = MemoryLimit::bytes();
        if (++$this->visits > 10000 || ($memory !== null && memory_get_usage(true) + 65536 > $memory * 0.8)) {
            $this->limited = true;

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $row
     * @param list<string> $reasons */
    private function add(string $group, array $row, array $reasons): void
    {
        $this->groups[$group][] = [...$row, 'reasons' => array_values(array_unique($reasons))];
    }
}
