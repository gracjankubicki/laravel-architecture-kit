<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Target;

use GracjanKubicki\ArchitectureKit\Revision\SnapshotInputs;
use InvalidArgumentException;

/** A human-accepted migration reference is not the audit suppression baseline. */
final readonly class TargetReference
{
    /** @param array<string, array<string, mixed>> $elements
     * @return array<string, mixed> */
    public static function candidate(TargetDefinition $target, array $elements, string $source, string $analysis, bool $complete): array
    {
        $rows = [];
        foreach ($elements as $id => $element) {
            $rows[$id] = ['path' => $element['path'], 'status' => $element['status'], 'issues' => array_column($element['issues'], 'id'), 'code' => $element['current']['shape']];
        }

        return ['format' => 'architecture-target-reference', 'v' => 1, 'accepted' => false, 'accepted_by' => null,
            'target' => self::identity($target), 'source' => $source, 'analysis' => $analysis, 'complete' => $complete, 'elements' => (object) $rows];
    }

    /** @return array<string, mixed> */
    public static function decode(string $source): array
    {
        if (strlen($source) > 1000000) {
            throw new InvalidArgumentException('Target reference exceeds 1 MB.');
        }
        $data = TargetJson::decode($source);
        if (! is_array($data) || ($data['complete'] ?? null) !== true || ! self::hash($data['analysis'] ?? null) || ! self::hash($data['source'] ?? null) || ($data['format'] ?? null) !== 'architecture-target-reference' || ($data['v'] ?? null) !== 1 || ($data['accepted'] ?? null) !== true || ! is_string($data['accepted_by'] ?? null) || trim($data['accepted_by']) === '' || ! is_array($data['target'] ?? null) || ! is_array($data['elements'] ?? null) || count($data['elements']) > 10000) {
            throw new InvalidArgumentException('Provide a human-accepted architecture target reference, not an audit baseline or unaccepted candidate.');
        }
        foreach ($data['elements'] as $id => $element) {
            if (! is_string($id) || ! is_array($element) || ! is_string($element['path'] ?? null) || ! SnapshotInputs::safe($element['path']) || ! self::hash($element['code'] ?? null) || ! in_array($element['status'] ?? null, ['migration', 'conformant', 'requires_check', 'outside_scope'], true) || ! is_array($element['issues'] ?? null) || ! array_is_list($element['issues']) || count(array_filter($element['issues'], 'is_string')) !== count($element['issues'])) {
                throw new InvalidArgumentException('Invalid target reference element.');
            }
        }
        foreach (['id', 'version', 'fingerprint', 'scope_fingerprint'] as $key) {
            if (! is_string($data['target'][$key] ?? null)) {
                throw new InvalidArgumentException('Target reference identity is incomplete.');
            }
        }

        return $data;
    }

    private static function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    /** @return array<string, string> */
    public static function identity(TargetDefinition $target): array
    {
        // Gate settings/reference location are not a change of the desired architecture.
        $contract = array_diff_key($target->values, array_flip(['mode', 'only', 'reference']));

        return ['id' => $target->values['id'], 'version' => $target->values['version'],
            'fingerprint' => hash('sha256', serialize($contract)), 'scope_fingerprint' => hash('sha256', serialize($target->values['paths']))];
    }

    /** @param array<string, mixed> $reference
     * @param array<string, array<string, mixed>> $elements
     * @return array<string, mixed> */
    public static function compare(TargetDefinition $target, array $reference, array $elements, string $analysis, bool $complete): array
    {
        $comparable = $reference['target'] === self::identity($target) && $reference['analysis'] === $analysis;
        $transitions = [];
        $resolved = $added = 0;
        foreach ($reference['elements'] as $id => $old) {
            $new = $elements[$id] ?? null;
            $state = $new === null ? 'source_not_observed' : ($new['status'] === 'outside_scope' ? 'outside_scope' : $new['status']);
            if ($state !== $old['status'] || ! $comparable) {
                $transitions[] = ['symbol' => $id, 'before' => $old['status'], 'after' => $state, 'code_improvement' => $comparable && $new !== null && $old['status'] === 'migration' && $state === 'conformant' && $complete && ! $new['requires_check'] && ($old['code'] !== $new['current']['shape'] || $old['path'] !== $new['path'])];
            }
            if ($comparable && $new !== null && $new['covered'] && $complete && ! $new['requires_check'] && $new['status'] !== 'requires_check') {
                $resolved += count(array_diff($old['issues'], array_column($new['issues'], 'id')));
            }
        }
        if ($comparable) {
            foreach ($elements as $id => $new) {
                if ($new['covered']) {
                    $added += count(array_diff(array_column($new['issues'], 'id'), $reference['elements'][$id]['issues'] ?? []));
                }
            }
        }

        return ['comparable' => $comparable, 'reason' => $comparable ? null : 'Target version, declarations, classification or analysis scope changed; no code improvement is claimed.',
            'resolved_issues' => $comparable && $complete ? $resolved : null, 'new_issues' => $comparable ? $added : null, 'transitions' => $transitions];
    }
}
