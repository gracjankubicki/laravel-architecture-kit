<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\PublicApi;

use GracjanKubicki\ArchitectureKit\Impact\SignatureCompatibility;

/** Declaration compatibility and behaviour inspection remain different verdicts. */
final class ContractChanges
{
    /** @param array<string, array<string, mixed>> $before
     * @param array<string, array<string, mixed>> $after
     * @return list<array<string, mixed>> */
    public function compare(array $before, array $after, bool $beforeComplete = true, bool $afterComplete = true): array
    {
        $changes = [];
        $ids = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($ids);
        foreach ($ids as $id) {
            $old = $before[$id] ?? null;
            $new = $after[$id] ?? null;
            if ($old !== null && $new !== null && $this->contract($old) === $this->contract($new)) {
                continue;
            }
            $uncertain = ($old['conditional'] ?? false) || ($new['conditional'] ?? false) || ($old['ambiguous'] ?? false) || ($new['ambiguous'] ?? false);
            if ($new === null) {
                $behavioural = in_array($old['kind'], ['event', 'published_migration', 'published_config', 'published_file'], true);
                $verdict = $afterComplete && ! $uncertain && ! $behavioural ? 'breaking' : 'check';
                $reasons = [$verdict === 'breaking' ? 'Public contract removed.' : ($behavioural ? 'Published resource or event removed; inspect installations and consumer behaviour.' : 'Contract is absent from a partial or conditional inventory; removal is not proved.')];
            } elseif ($old === null) {
                $owner = 'class:'.strtolower($new['owner'] ?? '');
                $required = ($new['kind'] ?? '') === 'method' && ($new['signature']['abstract'] ?? false) && isset($before[$owner]);
                $verdict = $beforeComplete && ! $uncertain ? ($required ? 'breaking' : 'compatible') : 'check';
                $reasons = [$required ? 'New abstract method adds a requirement for implementations.' : 'Public contract added.'];
            } elseif ($uncertain) {
                $verdict = 'check';
                $reasons = ['Conditional or ambiguous declaration changed; runtime contract is unresolved.'];
            } else {
                [$verdict, $reasons] = $this->changed($old, $new);
            }
            $changes[] = ['element' => $id, 'verdict' => $verdict, 'reasons' => $reasons, 'before' => $old, 'after' => $new];
        }

        return $changes;
    }

    /** @param array<string, mixed> $entry
     * @return array<string, mixed> */
    private function contract(array $entry): array
    {
        return array_diff_key($entry, array_flip(['source', 'via', 'declared_in', 'exposure', 'owner']));
    }

    /** @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array{string, list<string>} */
    private function changed(array $before, array $after): array
    {
        if (in_array($before['kind'], ['command', 'mcp', 'event', 'published_migration', 'published_config', 'published_file'], true) && $before['kind'] === $after['kind']) {
            return FrameworkChanges::changed($before, $after);
        }
        $breaking = [];
        $checks = [];
        if ($before['kind'] !== $after['kind']) {
            $breaking[] = 'Declaration kind changed.';
        }
        foreach (['visibility', 'final', 'abstract', 'readonly', 'static'] as $flag) {
            if (($before[$flag] ?? null) === ($after[$flag] ?? null)) {
                continue;
            }
            if ($flag === 'visibility') {
                if (SignatureCompatibility::visibility($after[$flag]) < SignatureCompatibility::visibility($before[$flag])) {
                    $breaking[] = 'Visibility narrowed.';
                }
            } elseif (in_array($flag, ['final', 'abstract', 'readonly'], true) && ($after[$flag] ?? false)) {
                $breaking[] = 'Declaration became '.$flag.'.';
            } elseif ($flag === 'static') {
                $breaking[] = 'Static declaration changed.';
            } else {
                $checks[] = 'Modifier changed: '.$flag.'.';
            }
        }
        if (isset($before['signature'], $after['signature'])) {
            $old = $before['signature'];
            $new = $after['signature'];
            if ($old['static'] !== $new['static']) {
                $breaking[] = 'Method static contract changed.';
            }
            if (SignatureCompatibility::visibility($new['visibility']) < SignatureCompatibility::visibility($old['visibility'])) {
                $breaking[] = 'Method visibility narrowed.';
            }
            if ((! $old['final'] && $new['final']) || (! $old['abstract'] && $new['abstract'])) {
                $breaking[] = 'Method now restricts subclass implementations.';
            }
            foreach ($new['parameters'] as $i => $parameter) {
                $previous = $old['parameters'][$i] ?? null;
                if (! $parameter['optional'] && ($previous === null || $previous['optional'])) {
                    $breaking[] = 'Required argument added: '.$parameter['name'].'.';
                }
                if ($previous !== null && $previous['name'] !== $parameter['name']) {
                    $breaking[] = 'Parameter renamed; named callers change: '.$previous['name'].' to '.$parameter['name'].'.';
                }
                if ($previous !== null && $previous['by_ref'] !== $parameter['by_ref']) {
                    $breaking[] = 'Parameter reference contract changed: '.$parameter['name'].'.';
                }
            }
            if (count($new['parameters']) < count($old['parameters'])) {
                $breaking[] = 'Parameter removed; named callers and extension contracts change.';
            }
            if ($old['return_by_ref'] !== $new['return_by_ref']) {
                $breaking[] = 'Return by reference contract changed.';
            }
            foreach (SignatureCompatibility::semanticChanges($old, $new) as $reason) {
                // New optional arguments alone are a compatible extension.
                if (str_starts_with($reason, 'New parameter type or promoted')) {
                    continue;
                }
                $checks[] = $reason;
            }
        }
        foreach (['type', 'value', 'default', 'backing_type', 'flags', 'hooks', 'parent', 'interfaces', 'traits', 'adaptations'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $checks[] = 'Changed '.$field.' requires compatibility inspection.';
            }
        }
        if (($before['body'] ?? null) !== ($after['body'] ?? null)) {
            $checks[] = 'Implementation changed; declarations do not prove behavioural compatibility.';
        }
        if ($breaking !== []) {
            return ['breaking', array_values(array_unique([...$breaking, ...$checks]))];
        }
        if ($checks !== []) {
            return ['check', array_values(array_unique($checks))];
        }

        return ['compatible', ['Declaration extends the recognized contract without a proved incompatibility.']];
    }
}
