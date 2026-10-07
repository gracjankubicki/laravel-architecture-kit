<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Context;

/** Whole-record pagination with a hard bound on the complete serialized envelope. */
final class GraphPage
{
    public const MAX_BYTES = 65536;

    /** @param array<string, mixed> $arguments
     * @param  array<string, mixed>  $result
     * @param  list<array<string, mixed>>  $diagnostics
     * @param  list<string>  $scope
     * @return array<string, mixed>
     */
    public static function make(string $command, array $arguments, string $snapshot, array $scope, array $diagnostics, array $result, string $recordsKey): array
    {
        $offset = $arguments['offset'] ?? 0;
        $limit = $arguments['limit'] ?? 20;
        $summary = [];
        foreach ($diagnostics as $diagnostic) {
            $code = $diagnostic['code'];
            $summary[$code] = ($summary[$code] ?? 0) + 1;
        }
        ksort($summary);
        $envelope = ['v' => 1, 'cmd' => $command, 'ok' => true, 'status' => $result['status'], 'snapshot' => $snapshot,
            'scope' => $scope, 'analysis_complete' => $diagnostics === [], 'truncated' => false,
            'limits' => ['limit' => $limit, 'offset' => $offset, 'max_bytes' => self::MAX_BYTES],
            'diagnostics' => ['summary' => $summary, 'details' => [], 'details_truncated' => false], 'result' => $result, 'next' => null];
        $records = $result[$recordsKey];
        $envelope['result'][$recordsKey] = [];
        if (self::bytes($envelope) > self::MAX_BYTES) {
            return self::limitFailure($command, $snapshot, $recordsKey);
        }
        if (! is_int($limit) || $limit < 0 || $limit > 100 || ! is_int($offset) || $offset < 0
            || $offset > 0 && ! is_string($arguments['snapshot'] ?? null)) {
            $envelope['status'] = 'invalid_input';
            $envelope['ok'] = false;
            $envelope['analysis_complete'] = false;

            return $envelope;
        }
        if (isset($arguments['snapshot']) && $arguments['snapshot'] !== $snapshot) {
            $envelope['status'] = 'stale_snapshot';
            $envelope['ok'] = false;
            $envelope['analysis_complete'] = false;

            return $envelope;
        }
        $envelope['ok'] = ! in_array($result['status'], ['invalid_input', 'stale_snapshot', 'unavailable'], true);
        if (! $envelope['ok']) {
            $envelope['analysis_complete'] = false;
        }
        $nextOffset = $offset;
        foreach (array_slice($records, $offset, $limit) as $record) {
            $candidate = $envelope;
            $candidate['result'][$recordsKey][] = $record;
            $candidate['next'] = ['tool' => $command, 'arguments' => [...$arguments, 'offset' => $nextOffset + 1, 'snapshot' => $snapshot]];
            if (self::bytes($candidate) > self::MAX_BYTES) {
                if ($nextOffset === $offset) {
                    $envelope['diagnostics']['details'][] = ['code' => 'oversized_record', 'offset' => $offset,
                        'subject' => isset($record['id']) ? substr($record['id'], 0, 1024) : null, 'message' => 'One record exceeds the output budget. Narrow the query using its locator.'];
                }
                break;
            }
            $envelope['result'][$recordsKey][] = $record;
            $nextOffset++;
        }
        $envelope['truncated'] = $nextOffset < count($records);
        if ($limit > 0 && $nextOffset > $offset && $nextOffset < count($records)) {
            $envelope['next'] = ['tool' => $command, 'arguments' => [...$arguments, 'offset' => $nextOffset, 'snapshot' => $snapshot]];
        }
        $subjects = [];
        $paths = [];
        foreach ([$result['subject'] ?? [], $result['target'] ?? []] as $element) {
            if (isset($element['id'])) {
                $subjects[$element['id']] = true;
            }
            if (isset($element['path'])) {
                $paths[$element['path']] = true;
            }
        }
        foreach ($envelope['result'][$recordsKey] as $record) {
            foreach ([$record, $record['element'] ?? [], $record['candidate'] ?? []] as $element) {
                if (isset($element['id'])) {
                    $subjects[$element['id']] = true;
                }
                if (isset($element['path'])) {
                    $paths[$element['path']] = true;
                }
            }
            foreach ($record['relations'] ?? $record['explanation'] ?? (isset($record['relation']) ? [$record['relation']] : []) as $edge) {
                $subjects[$edge['from']] = true;
                $subjects[$edge['to']] = true;
                $paths[$edge['path']] = true;
            }
        }
        $relevant = array_values(array_filter($diagnostics, static fn ($diagnostic) => in_array($diagnostic['code'], ['catalog_incomplete', 'changed_inputs', 'traversal_limit'], true)
            || isset($subjects[$diagnostic['subject'] ?? '']) || isset($paths[$diagnostic['path'] ?? ''])));
        foreach (array_slice($relevant, 0, 20) as $diagnostic) {
            $candidate = $envelope;
            $candidate['diagnostics']['details'][] = $diagnostic;
            if (self::bytes($candidate) > self::MAX_BYTES) {
                break;
            }
            $envelope = $candidate;
        }
        $envelope['diagnostics']['details_truncated'] = count($envelope['diagnostics']['details']) < count($relevant);

        return self::bytes($envelope) <= self::MAX_BYTES ? $envelope : self::limitFailure($command, $snapshot, $recordsKey);
    }

    /** @return array<string, mixed> */
    private static function limitFailure(string $command, string $snapshot, string $recordsKey): array
    {
        return ['v' => 1, 'cmd' => $command, 'ok' => false, 'status' => 'unavailable', 'snapshot' => $snapshot,
            'scope' => [], 'analysis_complete' => false, 'truncated' => true,
            'limits' => ['max_bytes' => self::MAX_BYTES],
            'diagnostics' => ['summary' => ['envelope_limit' => 1], 'details' => [['code' => 'envelope_limit',
                'message' => 'Query metadata exceeds the output budget. Narrow the input or source scope.']]],
            'result' => [$recordsKey => []], 'next' => null];
    }

    /** @param array<string, mixed> $value */
    private static function bytes(array $value): int
    {
        return strlen(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }
}
