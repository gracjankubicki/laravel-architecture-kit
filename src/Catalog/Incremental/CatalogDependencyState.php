<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog\Incremental;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CatalogState;
use InvalidArgumentException;

/** Versioned JSON product. Corrupt, missing, oversized or foreign input requires preparation. */
final class CatalogDependencyState
{
    public const SCHEMA = 1;

    public function __construct(private readonly int $maxBytes = 67108864, private readonly int $maxRecords = 200000) {}

    public function encode(CatalogGeneration $generation): string
    {
        $payload = ['schema' => self::SCHEMA, 'resolver' => CatalogUpdater::VERSION,
            'identity' => $generation->identity->values, 'ownership' => $generation->measurements['ownership'], 'admission' => ['transition_working_bytes' => $generation->measurements['transition_working_bytes']], 'session' => $generation->session, 'sequence' => $generation->sequence,
            'sources' => array_map(fn (CatalogContribution $source) => $source->toArray(), $generation->contributions),
            'operations' => $generation->operations, 'state' => $generation->exportState()];
        $bytes = json_encode(['payload' => $payload, 'digest' => SemanticVersions::digest($payload)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $this->bounds(strlen($bytes), count($payload['sources']) + count($payload['operations']) + array_sum(array_map('count', $payload['state'])));

        return $bytes;
    }

    public function decode(?string $bytes, CatalogIdentity $identity): CatalogGeneration
    {
        if ($bytes === null || strlen($bytes) > $this->maxBytes) {
            throw new InvalidArgumentException('preparation_required:dependency_state_missing_or_size');
        }
        try {
            $envelope = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($envelope) || array_keys($envelope) !== ['payload', 'digest'] || ! is_array($envelope['payload']) || ! is_string($envelope['digest'])
                || ! hash_equals(SemanticVersions::digest($envelope['payload']), $envelope['digest'])) {
                throw new InvalidArgumentException('dependency_state_digest');
            }
            $data = $envelope['payload'];
            $this->bounds(strlen($bytes), count($data['sources'] ?? []) + count($data['operations'] ?? []) + array_sum(array_map('count', $data['state'] ?? [])));

            return $this->hydrate($data, $identity);
        } catch (\Throwable $error) {
            throw new InvalidArgumentException('preparation_required:dependency_state_invalid:'.$error->getMessage(), previous: $error);
        }
    }

    /** Data-only segmented adapter. The graph's eight fields are supplied once by the set reader.
     * Validation is shared with the legacy JSON transport; its transport size cap is not an analysis cap.
     *
     * @param  array<string, mixed>  $data
     */
    public function hydrate(array $data, CatalogIdentity $identity): CatalogGeneration
    {
        if (array_keys($data) !== ['schema', 'resolver', 'identity', 'ownership', 'admission', 'session', 'sequence', 'sources', 'operations', 'state']
            || $data['schema'] !== self::SCHEMA || $data['resolver'] !== CatalogUpdater::VERSION
            || ! is_array($data['ownership']) || array_keys($data['ownership']) !== ['foundation', 'calls', 'registrations']
            || ! is_array($data['admission']) || array_keys($data['admission']) !== ['transition_working_bytes'] || ! is_int($data['admission']['transition_working_bytes']) || $data['admission']['transition_working_bytes'] < 1
            || $data['identity'] !== $identity->values || ! is_string($data['session']) || $data['session'] === '' || strlen($data['session']) > 256
            || ! is_string($data['sequence']) || CatalogSequence::validate($data['sequence']) !== $data['sequence'] || ! is_array($data['sources']) || ! is_array($data['operations']) || ! is_array($data['state'])) {
            throw new InvalidArgumentException('dependency_state_shape_or_identity');
        }
        CatalogState::validate($data['state']);
        $sources = [];
        foreach ($data['sources'] as $path => $source) {
            $validated = $source instanceof ValidatedCatalogSource ? $source : ValidatedCatalogSource::fromArray($path, $source);
            if ($validated->contribution->facts->path !== $path) {
                throw new InvalidArgumentException('dependency_source');
            }
            $sources[$path] = $validated->contribution;
        }
        $expectedKeys = [];
        foreach ($sources as $path => $source) {
            $ordinal = 0;
            foreach ($source->facts->relations as $relation) {
                if (! str_starts_with($relation->to, 'dispatch:')) {
                    continue;
                }
                $key = CatalogUpdater::VERSION.':'.$path.':'.$ordinal++;
                $expectedKeys[] = $key;
                $row = [...$relation->toArray(), 'path' => $path, 'knowledge' => 'static'];
                if (($data['operations'][$key]['input'] ?? null) !== SemanticVersions::digest($row)) {
                    throw new InvalidArgumentException('dependency_operation_input');
                }
            }
        }
        if (array_keys($data['operations']) !== $expectedKeys) {
            throw new InvalidArgumentException('dependency_operation_coverage');
        }
        self::ownership($data['ownership'], $data['operations'], $data['state']);
        foreach ($data['operations'] as $key => $operation) {
            self::operation($key, $operation, $sources, $data['state']);
        }

        return new CatalogGeneration($identity, $data['session'], $data['sequence'], $sources, $data['operations'], $data['state'],
            ['ownership' => $data['ownership'], 'transition_working_bytes' => $data['admission']['transition_working_bytes'], 'restored' => true, 'executed_calls' => 0, 'reused_calls' => 0, 'overlay_depth' => 0]);
    }

    /** @return array<string, iterable<int|string, mixed>> */
    public static function parts(CatalogGeneration $generation): array
    {
        return ['dependencyMetadata' => ['schema' => self::SCHEMA, 'resolver' => CatalogUpdater::VERSION,
            'identity' => $generation->identity->values, 'ownership' => $generation->measurements['ownership'],
            'admission' => ['transition_working_bytes' => $generation->measurements['transition_working_bytes']],
            'session' => $generation->session, 'sequence' => $generation->sequence],
            'dependencySources' => (static function () use ($generation): \Generator {
                foreach ($generation->contributions as $path => $source) {
                    yield $path => $source->toArray();
                }
            })(), 'dependencyOperations' => $generation->operations];
    }

    /** @param array<string, CatalogContribution> $sources
     * @param  array<string, array<mixed>>  $state
     */
    private static function operation(mixed $key, mixed $operation, array $sources, array $state): void
    {
        if (! is_string($key) || ! is_array($operation)
            || array_keys($operation) !== ['owner', 'input', 'reads', 'lookups', 'member_reads', 'member_writes', 'relations', 'diagnostics', 'tracking']
            || ! is_array($operation['owner']) || array_keys($operation['owner']) !== ['path', 'ordinal', 'product']
            || ! is_string($operation['owner']['path']) || ! isset($sources[$operation['owner']['path']])
            || ! is_int($operation['owner']['ordinal']) || $operation['owner']['ordinal'] < 0 || $operation['owner']['product'] !== CatalogUpdater::VERSION
            || $key !== CatalogUpdater::VERSION.':'.$operation['owner']['path'].':'.$operation['owner']['ordinal']
            || ! is_string($operation['input']) || ! preg_match('/^[a-f0-9]{64}$/D', $operation['input'])
            || ! in_array($operation['tracking'], ['declarations:*', 'connected-declarations'], true)) {
            throw new InvalidArgumentException('dependency_operation');
        }
        foreach (['reads', 'lookups', 'member_reads', 'member_writes', 'relations', 'diagnostics'] as $field) {
            if (! is_array($operation[$field])) {
                throw new InvalidArgumentException('dependency_operation_field');
            }
        }
        foreach ($operation['reads'] as $resource => $version) {
            if (! is_string($resource) || ! preg_match('/^(name:|element:|descriptors:\*|declarations:\*)/', $resource)
                || ! is_string($version) || ! preg_match('/^[a-f0-9]{64}$/D', $version)) {
                throw new InvalidArgumentException('dependency_read');
            }
        }
        foreach ($operation['lookups'] as $name => $ids) {
            if (! is_string($name) || ! str_starts_with($name, 'name:') || ! is_array($ids) || $ids !== ($state['names'][substr($name, 5)] ?? [])
                || ! isset($operation['reads'][$name])) {
                throw new InvalidArgumentException('dependency_lookup');
            }
        }
        foreach (['member_reads', 'member_writes'] as $field) {
            foreach ($operation[$field] as $resource => $ids) {
                if (! is_string($resource) || $ids === null && $field !== 'member_reads' || $ids !== null && (! is_array($ids) || ! array_is_list($ids))) {
                    throw new InvalidArgumentException('dependency_member_cache');
                }
                foreach ($ids ?? [] as $id) {
                    if (! is_string($id) || ! isset($state['elements'][$id])) {
                        throw new InvalidArgumentException('dependency_member_reference');
                    }
                }
            }
        }
        CatalogState::validateFragment($operation['relations'], $operation['diagnostics'], $state['elements'], $state['descriptorReceivers']);
    }

    /** Range ownership preserves multiplicity without duplicating every record's owner string.
     * @param  array<string, mixed>  $owners
     * @param  array<string, array<string, mixed>>  $operations
     * @param  array<string, array<mixed>>  $state
     */
    private static function ownership(array $owners, array $operations, array $state): void
    {
        $offsets = [];
        foreach (['relations', 'diagnostics'] as $field) {
            $range = $owners['foundation'][$field] ?? null;
            if (! is_array($range) || array_keys($range) !== [0, 1] || $range[0] !== 0 || ! is_int($range[1]) || $range[1] < 0) {
                throw new InvalidArgumentException('dependency_foundation_owner');
            }
            $offsets[$field] = $range[1];
        }
        if (! is_array($owners['calls']) || array_keys($owners['calls']) !== array_keys($operations)) {
            throw new InvalidArgumentException('dependency_operation_owner');
        }
        foreach ($operations as $key => $operation) {
            foreach (['relations', 'diagnostics'] as $field) {
                $rows = $operation[$field] ?? null;
                if (! is_array($rows) || ($owners['calls'][$key][$field] ?? null) !== [$offsets[$field], count($rows)]) {
                    throw new InvalidArgumentException('dependency_operation_range');
                }
                foreach ($rows as $row) {
                    if (($state[$field][$offsets[$field]++] ?? null) !== $row) {
                        throw new InvalidArgumentException('dependency_operation_record');
                    }
                }
            }
        }
        $registration = $owners['registrations']['relations'] ?? null;
        if (! is_array($registration) || array_keys($registration) !== [0, 1] || $registration[0] !== $offsets['relations']
            || ! is_int($registration[1]) || $registration[1] < 0 || count($state['relations']) !== $offsets['relations'] + $registration[1]
            || $offsets['diagnostics'] !== count($state['diagnostics'])) {
            throw new InvalidArgumentException('dependency_registration_owner');
        }
    }

    private function bounds(int $bytes, int $records): void
    {
        if ($bytes > $this->maxBytes || $records > $this->maxRecords) {
            throw new InvalidArgumentException('preparation_required:dependency_state_budget');
        }
    }
}
