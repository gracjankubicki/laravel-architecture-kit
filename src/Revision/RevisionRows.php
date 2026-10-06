<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Revision;

/** Multiset comparison keeps repeated occurrences while ignoring source coordinates in identity. */
final readonly class RevisionRows
{
    /** @param list<array<string, mixed>> $before
     * @param list<array<string, mixed>> $after
     * @param array<string, string> $symbols
     * @param array<string, string> $paths
     * @param array<string, array<string, string>> $symbolPaths
     * @return list<array<string, mixed>> */
    public static function compare(array $before, array $after, array $symbols = [], array $paths = [], array $symbolPaths = []): array
    {
        $old = self::buckets($before, $symbols, $paths, $symbolPaths);
        $new = self::buckets($after, [], [], []);
        $changes = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $left = $old[$key] ?? [];
            $right = $new[$key] ?? [];
            $same = min(count($left), count($right));
            foreach (array_slice($left, $same) as $row) {
                $changes[] = ['change' => 'removed', 'before' => $row, 'after' => null];
            }
            foreach (array_slice($right, $same) as $row) {
                $changes[] = ['change' => 'added', 'before' => null, 'after' => $row];
            }
        }

        return $changes;
    }

    /** @param list<array<string, mixed>> $rows
     * @param array<string, string> $symbols
     * @param array<string, string> $paths
     * @param array<string, array<string, string>> $symbolPaths
     * @return array<string, list<array<string, mixed>>> */
    private static function buckets(array $rows, array $symbols, array $paths, array $symbolPaths): array
    {
        $result = [];
        foreach ($rows as $row) {
            $key = hash('sha256', serialize(self::semantic($row, $symbols, $paths, $symbolPaths)));
            $result[$key][] = $row;
        }

        return $result;
    }

    /** @param array<string, string> $symbols
     * @param array<string, string> $paths
     * @param array<string, array<string, string>> $symbolPaths */
    private static function semantic(mixed $value, array $symbols, array $paths, array $symbolPaths, string $field = ''): mixed
    {
        if (is_array($value)) {
            foreach (['from', 'symbol', 'owner', 'class', 'name'] as $ownerField) {
                $owner = $value[$ownerField] ?? null;
                if (is_string($owner) && isset($symbolPaths[strtolower($owner)])) {
                    $paths = [...$paths, ...$symbolPaths[strtolower($owner)]];
                    break;
                }
            }
            $result = [];
            foreach ($value as $key => $item) {
                if (($key === 'id' && $field === '') || (in_array($key, ['line', 'offset'], true) && in_array($field, ['', 'source', 'site', 'registration', 'source_via', 'preparation_via', 'sites', 'calls'], true))) {
                    continue;
                }
                $result[$key] = self::semantic($item, $symbols, $paths, $symbolPaths, is_int($key) ? $field : (string) $key);
            }
            if (! array_is_list($result)) {
                ksort($result);
            }

            return $result;
        }
        if (! is_string($value)) {
            return $value;
        }
        if (in_array($field, ['path', 'preparation_paths'], true)) {
            return $paths[$value] ?? $value;
        }
        if (in_array($field, ['from', 'to', 'symbol', 'class', 'model', 'target', 'owner', 'receiver', 'job', 'callback'], true)) {
            $lower = strtolower($value);
            if (isset($symbols[$lower])) {
                return $symbols[$lower];
            }
            $owner = explode('::', $lower)[0];
            if (isset($symbols[$owner])) {
                return $symbols[$owner].substr($lower, strlen($owner));
            }
            if (str_starts_with($value, '(')) {
                $value = preg_replace_callback('/(?<=\) )[^:@]+(?=:\d)|(?<=@)[^:]+(?=:\d)/', static fn (array $match): string => $paths[$match[0]] ?? $match[0], $value) ?? $value;
                // Generated callback/event/group IDs carry offsets and source-derived hashes.
                $value = preg_replace('/:\d+(?=:|$)/', ':@site', $value) ?? $value;
                $value = preg_replace('/(?::http:|:)[a-f0-9]{32}(?=:|$)/', ':@context', $value) ?? $value;
            }

            return strtolower($value);
        }

        return $value;
    }
}
