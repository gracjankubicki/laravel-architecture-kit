<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache;

use Closure;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogDependencyState;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogGeneration;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogIdentity;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\ValidatedCatalogSource;
use GracjanKubicki\ArchitectureKit\Catalog\Search\SearchState;
use RuntimeException;
use Throwable;

/** One active manifest binds graph, dependency and search. No independent latest products. */
final readonly class CatalogProductSet
{
    public const SCHEMA = 1;

    public const DERIVED = ['dependencyMetadata', 'dependencySources', 'dependencyOperations', 'searchMetadata', 'searchValues', 'searchRecords', 'searchGrams'];

    public function __construct(public GraphSegmentStore $store, private ?Closure $observe = null) {}

    /** @param array<string, mixed> $metadata
     * @return array<string, mixed> */
    public function write(string $key, CatalogGeneration $generation, SearchState $search, array $metadata, ?Closure $current = null): array
    {
        if ($search->generation !== $generation->id() || $search->identity !== $generation->identity->digest()
            || ! $search->matches($generation->exportState()['elements'])) {
            return ['status' => 'error', 'reason' => 'product_set_identity'];
        }

        return $this->store->write($key, [...$generation->exportState(), ...CatalogDependencyState::parts($generation), ...$search->parts()],
            [...$metadata, 'product_set_schema' => self::SCHEMA, 'generation' => $generation->id(), 'identity' => $generation->identity->digest()], $current);
    }

    /** @return array<string, mixed> */
    public function read(string $key, CatalogIdentity $identity, ?Closure $accept = null, ?Closure $beforeDecode = null): array
    {
        $elements = [];
        $sourceGeneration = null;
        $searchRestore = new class
        {
            public ?Closure $collector = null;

            public function consume(string $kind, mixed $key, mixed $row): mixed
            {
                if ($this->collector === null) {
                    throw new RuntimeException('search_state_metadata_missing');
                }

                return ($this->collector)($kind, $key, $row);
            }
        };
        $started = hrtime(true);
        $usedBefore = memory_get_usage();
        $phases = [];
        $acceptMetadata = static function (array $metadata) use ($identity, $accept, &$sourceGeneration): bool {
            if (($metadata['product_set_schema'] ?? null) !== self::SCHEMA || ($metadata['identity'] ?? null) !== $identity->digest()
                || ! is_string($metadata['generation'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $metadata['generation'])) {
                throw new RuntimeException('product_set_schema_or_identity');
            }
            $sourceGeneration = $metadata['generation'];

            return $accept === null || $accept($metadata);
        };
        $collectors = [];
        foreach (['searchValues' => 'values', 'searchRecords' => 'records', 'searchGrams' => 'grams'] as $field => $kind) {
            $collectors[$field] = static function (mixed $key, mixed $row) use ($searchRestore, $kind): bool {
                return $searchRestore->consume($kind, $key, $row);
            };
        }
        $read = $this->store->read($key, [...CatalogState::FIELDS, ...self::DERIVED], acceptMetadata: $acceptMetadata,
            transform: self::transform(...), collectors: $collectors,
            beforeDecode: $beforeDecode, fieldReady: function (string $field, array $values) use (&$elements, $searchRestore, &$sourceGeneration, $identity, &$phases): void {
                if ($field === 'elements') {
                    $elements = $values;
                } elseif ($field === 'searchMetadata') {
                    $searchRestore->collector = SearchState::restorationCollector($elements, $sourceGeneration, $identity->digest());
                    $searchRestore->consume('metadata', null, $values);
                }
                $phases[$field] = ['retained_records' => count($values), 'php_used' => memory_get_usage(), 'php_allocated' => memory_get_usage(true)];
                if ($this->observe !== null) {
                    ($this->observe)($field, $values);
                }
            });
        unset($elements, $collectors);
        if ($read['status'] !== 'hit') {
            return $read;
        }
        try {
            $metadata = $read['metadata'];
            if (($metadata['product_set_schema'] ?? null) !== self::SCHEMA || ($metadata['identity'] ?? null) !== $identity->digest()) {
                throw new RuntimeException('product_set_schema_or_identity');
            }
            $parts = $read['state'];
            unset($read['state']);
            $state = array_intersect_key($parts, array_fill_keys(CatalogState::FIELDS, true));
            $generation = (new CatalogDependencyState)->hydrate([...$parts['dependencyMetadata'],
                'sources' => $parts['dependencySources'], 'operations' => $parts['dependencyOperations'], 'state' => $state], $identity);
            unset($parts['dependencyMetadata'], $parts['dependencySources'], $parts['dependencyOperations']);
            if ($generation->id() !== ($metadata['generation'] ?? null)) {
                throw new RuntimeException('product_set_generation');
            }
            $search = $searchRestore->consume('finish', null, null);
            unset($searchRestore);
            unset($parts, $state);

            return [...$read, 'catalog' => $generation, 'search' => $search, 'restore_measurements' => [
                'elapsed_ns' => hrtime(true) - $started, 'php_used_before' => $usedBefore, 'php_used_after' => memory_get_usage(),
                'php_allocated' => memory_get_usage(true), 'php_peak' => memory_get_peak_usage(true), 'phases' => $phases]];
        } catch (Throwable $error) {
            return ['status' => 'error', 'reason' => 'preparation_required:'.$error->getMessage(), 'decoded' => true, 'generation' => $read['generation']];
        }
    }

    /** Recomposition deliberately reads only compatible contributions, never an old index.
     * Segment checks plus CatalogFacts validation and source coverage remain mandatory.
     *
     * @return array<string, mixed> */
    public function readSources(string $key, CatalogIdentity $identity, ?Closure $accept = null, ?Closure $beforeDecode = null): array
    {
        $read = $this->store->read($key, ['dependencyMetadata', 'dependencySources'], manifestFields: [...CatalogState::FIELDS, ...self::DERIVED], acceptMetadata: $accept, transform: self::transform(...), beforeDecode: $beforeDecode);
        if ($read['status'] !== 'hit') {
            return $read;
        }
        try {
            if (($read['metadata']['product_set_schema'] ?? null) !== self::SCHEMA
                || ($read['metadata']['identity'] ?? null) !== $identity->digest()
                || ($read['state']['dependencyMetadata']['identity'] ?? null) !== $identity->values) {
                throw new RuntimeException('contribution_set_identity');
            }
            $sources = [];
            foreach ($read['state']['dependencySources'] as $path => $row) {
                if (! $row instanceof ValidatedCatalogSource || $row->contribution->facts->path !== $path
                    || ! str_ends_with($read['metadata']['input_files'][$path] ?? '', ':'.$row->contribution->sourceHash)) {
                    throw new RuntimeException('contribution_source_identity');
                }
                $sources[$path] = $row->contribution;
            }
            if (array_keys($sources) !== array_keys($read['metadata']['input_files'] ?? [])) {
                throw new RuntimeException('contribution_source_coverage');
            }
            unset($read['state']);

            return [...$read, 'sources' => $sources];
        } catch (Throwable $error) {
            return ['status' => 'error', 'reason' => $error->getMessage(), 'decoded' => true];
        }
    }

    private static function transform(string $field, mixed $key, mixed $row): mixed
    {
        return $field === 'dependencySources' ? ValidatedCatalogSource::fromArray($key, $row) : $row;
    }
}
