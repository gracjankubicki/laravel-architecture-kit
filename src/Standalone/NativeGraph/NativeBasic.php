<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use RuntimeException;

/** Explicit experimental adapter. State export is only for bounded differential tooling. */
final readonly class NativeBasic
{
    public const FIELDS = ['elements', 'relations', 'diagnostics', 'descriptorReceivers', 'names', 'out', 'in', 'hierarchyLimits'];

    public function __construct(private NativeGraphClient $client)
    {
        if (! in_array('basic-graph-v1', $client->descriptor['capabilities'] ?? [], true)) {
            throw new RuntimeException('native_basic_capability');
        }
    }

    /** @param array<string, string> $identity */
    public function compose(string $generation, array $identity): string
    {
        return $this->leased($generation, $identity, fn ($lease) => $this->client->request(['op' => 'compose', 'generation' => $generation, 'identity' => $identity, 'lease' => $lease])['generation']);
    }

    /**
     * Private tooling only; product callers use query pages.
     *
     * @param  array<string, string>  $identity
     * @return array<string, array<array-key, mixed>>
     */
    public function state(string $generation, array $identity): array
    {
        return $this->leased($generation, $identity, function ($lease) use ($generation, $identity): array {
            $state = [];
            foreach (self::FIELDS as $field) {
                $state[$field] = [];
                $start = 0;
                do {
                    $page = $this->client->request(['op' => 'read_product', 'generation' => $generation, 'identity' => $identity, 'lease' => $lease, 'field' => $field, 'start' => $start, 'limit' => 1]);
                    foreach (TypedValue::decode($page['records']) as $key => $row) {
                        if (array_key_exists($key, $state[$field])) {
                            throw new RuntimeException('native_basic_duplicate_page_key');
                        }
                        $state[$field][$key] = $row;
                    }
                    if ($page['next'] !== null && $page['next'] <= $start) {
                        throw new RuntimeException('native_basic_page_progress');
                    }
                    $start = $page['next'];
                } while ($start !== null);
                if (count($state[$field]) !== $page['total']) {
                    throw new RuntimeException('native_basic_page_coverage');
                }
            }

            return $state;
        });
    }

    /**
     * Complete public query payload assembled from bounded native pages.
     *
     * @param  array<string, string>  $identity
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function query(string $generation, array $identity, array $query): array
    {
        return $this->leased($generation, $identity, function ($lease) use ($generation, $identity, $query): array {
            $start = 0;
            $records = [];
            $result = null;
            do {
                $page = $this->client->request(['op' => 'query_page', 'generation' => $generation, 'identity' => $identity, 'lease' => $lease, 'query' => $query, 'start' => $start, 'limit' => 16]);
                $metadata = TypedValue::decode($page['metadata']);
                if ($result !== null && $metadata !== $result) {
                    throw new RuntimeException('native_basic_query_metadata_changed');
                }
                $result = $metadata;
                array_push($records, ...TypedValue::decode($page['records']));
                if ($page['next'] !== null && $page['next'] <= $start) {
                    throw new RuntimeException('native_basic_query_page_progress');
                }
                $start = $page['next'];
            } while ($start !== null);
            if (count($records) !== $page['total']) {
                throw new RuntimeException('native_basic_query_coverage');
            }
            $result[$page['record_field']] = $records;

            return $result;
        });
    }

    /**
     * Patch carries changed records and explicit complete final coverage.
     *
     * @param  array<string, string>  $identity
     * @param  array<string, string>  $targetIdentity
     * @param  list<array<string, string>>  $scope
     * @param  iterable<array<array-key, mixed>>  $records
     */
    public function patch(string $generation, array $identity, array $targetIdentity, array $scope, iterable $records): string
    {
        return $this->leased($generation, $identity, function ($lease) use ($generation, $identity, $targetIdentity, $scope, $records): string {
            $ingest = $this->client->request(['op' => 'patch_begin', 'generation' => $generation, 'identity' => $identity, 'lease' => $lease, 'target_identity' => $targetIdentity, 'scope' => $scope])['ingest'];
            try {
                foreach ($records as $record) {
                    $this->client->request(['op' => 'patch_append', 'ingest' => $ingest, 'record' => $record]);
                    $this->enrich($ingest, $record);
                }

                return $this->client->request(['op' => 'patch_commit', 'ingest' => $ingest])['generation'];
            } catch (\Throwable $error) {
                try {
                    $this->client->request(['op' => 'patch_abort', 'ingest' => $ingest]);
                } catch (\Throwable) {
                    // Preserve the first error.
                }
                throw $error;
            }
        });
    }

    /**
     * @param  array<string, string>  $identity
     * @return list<array<string, mixed>>
     */
    public function trace(string $generation, array $identity): array
    {
        return $this->leased($generation, $identity, function ($lease) use ($generation, $identity): array {
            $records = [];
            $start = 0;
            do {
                $page = $this->client->request(['op' => 'dependency_trace', 'generation' => $generation, 'identity' => $identity, 'lease' => $lease, 'start' => $start, 'limit' => 16]);
                array_push($records, ...TypedValue::decode($page['records']));
                if ($page['next'] !== null && $page['next'] <= $start) {
                    throw new RuntimeException('native_basic_trace_page_progress');
                }
                $start = $page['next'];
            } while ($start !== null);

            return $records;
        });
    }

    /**
     * Prepare, validate derived canonical IDs, then atomically publish. R04 uses this adapter.
     *
     * @param  array<string, string>  $identity
     * @param  list<array<string, string>>|null  $expectedScope
     * @return array<string, mixed>
     */
    public function restore(string $directory, array $identity, ?array $expectedScope = null): array
    {
        $candidate = $this->client->request(['op' => 'restore', 'identity' => $identity, 'directory' => $directory, 'validate_canonical' => true]);
        if (! isset($candidate['validation_lease'])) {
            throw new RuntimeException('native_basic_restore_product_missing');
        }
        $accepted = false;
        try {
            $this->validateCanonicalFiles($candidate['generation'], $identity, $candidate['validation_lease']);
            if ($expectedScope !== null) {
                $this->validateScope($candidate['generation'], $identity, $candidate['validation_lease'], $expectedScope);
            }
            $result = $this->client->request(['op' => 'accept_restore', 'generation' => $candidate['generation'], 'identity' => $identity, 'lease' => $candidate['validation_lease']]);
            $accepted = true;

            return $result;
        } finally {
            if (! $accepted) {
                try {
                    $this->client->request(['op' => 'reject_restore', 'generation' => $candidate['generation'], 'identity' => $identity, 'lease' => $candidate['validation_lease']]);
                } catch (\Throwable) {
                    // Preserve the first validation/transport failure. Process teardown drops staged data.
                }
            }
        }
    }

    /** Tie the independently stored locator/source manifest to the actual staged native product.
     * @param array<string, string> $identity
     * @param list<array<string, string>> $scope */
    private function validateScope(string $generation, array $identity, string $lease, array $scope): void
    {
        $start = 0;
        do {
            $page = $this->client->request(['op' => 'scope_page', 'generation' => $generation, 'identity' => $identity, 'lease' => $lease, 'start' => $start, 'limit' => 32]);
            if (($page['generation'] ?? null) !== $generation || ($page['start'] ?? null) !== $start || ($page['total'] ?? null) !== count($scope)
                || ! is_array($page['owners'] ?? null) || count($page['owners']) > 32) {
                throw new RuntimeException('native_basic_source_coverage');
            }
            foreach ($page['owners'] as $offset => $owner) {
                if (! is_array($owner) || count($owner) !== 4) {
                    throw new RuntimeException('native_basic_source_coverage');
                }
                foreach (['owner', 'sha256', 'kind', 'php_version'] as $field) {
                    if (! is_string($owner[$field] ?? null) || $owner[$field] !== ($scope[$start + $offset][$field] ?? null)) {
                        throw new RuntimeException('native_basic_source_coverage');
                    }
                }
            }
            $end = $start + count($page['owners']);
            if ($page['next'] !== ($end < count($scope) ? $end : null) || $page['next'] !== null && $end <= $start) {
                throw new RuntimeException('native_basic_source_coverage');
            }
            $start = $page['next'];
        } while ($start !== null);
    }

    /**
     * Bounded pure-helper validation without source paths or a reconstructed PHP catalog.
     *
     * @param  array<string, string>  $identity
     */
    public function validateCanonicalFiles(string $generation, array $identity, string $lease): void
    {
        $start = 0;
        $count = 0;
        $total = null;
        $previous = null;
        do {
            $page = $this->client->request(['op' => 'canonical_page', 'generation' => $generation, 'identity' => $identity, 'lease' => $lease, 'start' => $start, 'limit' => 32]);
            if (($page['generation'] ?? null) !== $generation || ($page['start'] ?? null) !== $start || ! is_int($page['total'] ?? null)
                || $total !== null && $page['total'] !== $total || ! is_array($page['paths'] ?? null) || count($page['paths']) > 32) {
                throw new RuntimeException('native_basic_canonical_page_shape');
            }
            $total = $page['total'];
            foreach ($page['paths'] as $pair) {
                if (! is_array($pair) || array_keys($pair) !== [0, 1] || ! is_string($pair[0]) || ! is_string($pair[1])
                    || $previous !== null && strcmp($previous, $pair[0]) >= 0
                    || CatalogElement::identity($pair[0], 'file', $pair[0]) !== $pair[1]) {
                    throw new RuntimeException('native_basic_canonical_id_mismatch');
                }
                $previous = $pair[0];
                $count++;
            }
            if ($page['next'] !== null && ($page['next'] !== $count || $page['next'] <= $start || $page['next'] >= $total)) {
                throw new RuntimeException('native_basic_canonical_page_progress');
            }
            $start = $page['next'];
        } while ($start !== null);
        if ($count !== $total) {
            throw new RuntimeException('native_basic_canonical_coverage');
        }
    }

    /**
     * One local record at a time; never reads paths mentioned by analysis facts.
     *
     * @param  array<array-key, mixed>  $record
     */
    public function enrich(string $ingest, array $record): void
    {
        $entry = TypedValue::decode($record['entry']);
        $paths = [];
        foreach ($entry['catalog']['relations'] ?? [] as $relation) {
            if (str_starts_with($relation['to'], 'include:')) {
                foreach ($relation['metadata']['candidate_paths'] ?? [] as $path) {
                    $paths[$path] = true;
                }
            }
            if (str_starts_with($relation['to'], 'file:')) {
                $paths[substr($relation['to'], 5)] = true;
            }
        }
        $pairs = [];
        foreach ($paths as $path => $_) {
            $path = (string) $path;
            $pairs[] = [$path, CatalogElement::identity($path, 'file', $path)];
            if (count($pairs) === 32) {
                $this->client->request(['op' => 'enrich_files', 'ingest' => $ingest, 'paths' => $pairs]);
                $pairs = [];
            }
        }
        if ($pairs !== []) {
            $this->client->request(['op' => 'enrich_files', 'ingest' => $ingest, 'paths' => $pairs]);
        }
    }

    /**
     * @template T
     *
     * @param  array<string, string>  $identity
     * @param  \Closure(string): T  $operation
     * @return T
     */
    private function leased(string $generation, array $identity, \Closure $operation): mixed
    {
        $lease = $this->client->request(['op' => 'pin', 'generation' => $generation, 'identity' => $identity])['lease'];
        try {
            $result = $operation($lease);
        } catch (\Throwable $error) {
            try {
                $this->client->request(['op' => 'release', 'lease' => $lease]);
            } catch (\Throwable) {
                // Preserve the operation failure if the process has already exited.
            }
            throw $error;
        }
        $this->client->request(['op' => 'release', 'lease' => $lease]);

        return $result;
    }
}
