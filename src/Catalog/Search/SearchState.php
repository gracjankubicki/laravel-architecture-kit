<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog\Search;

use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogGeneration;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogPatch;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\SemanticVersions;
use InvalidArgumentException;

/** Immutable value/owner index. No records, AST, resolver, ancestor chain or tombstones. */
final class SearchState
{
    public const SCHEMA = 1;

    public const DEFAULT_POSTING_BUDGET = 32768;

    /** @var array<string, array<int|string, true>> Shared normalized values -> record IDs. */
    private array $values = [];

    /** @var array<string, string> Intern table; every owner's key reuses the canonical string. */
    private array $intern = [];

    /** @var array<int|string, list<string>> Record-owned values, allowing exact removal. */
    private array $records = [];

    /** @var list<string> Sorted dictionary, never one entry per prefix. */
    private array $dictionary = [];

    /** @var array<string, array<string, true>> Byte trigram -> shared value keys. */
    private array $grams = [];

    private int $postings = 0;

    private bool $limited = false;

    private function __construct(
        public readonly string $generation,
        public readonly string $identity,
        public readonly string $sourceDigest,
        public readonly int $postingBudget,
    ) {
        if ($postingBudget < 0) {
            throw new InvalidArgumentException('search_posting_budget');
        }
    }

    /** @param array<int|string, array<string, mixed>> $elements */
    public static function build(array $elements, string $generation = 'legacy', string $identity = 'legacy', int $postingBudget = self::DEFAULT_POSTING_BUDGET): self
    {
        $state = new self($generation, $identity, self::digestElements($elements), $postingBudget);
        foreach ($elements as $id => $element) {
            $state->add((string) $id, $element);
        }
        $state->sortDictionary();

        return $state;
    }

    public static function fromGeneration(CatalogGeneration $generation, int $postingBudget = self::DEFAULT_POSTING_BUDGET): self
    {
        return self::build($generation->exportState()['elements'], $generation->id(), $generation->identity->digest(), $postingBudget);
    }

    /** A fully checked restart changes observation identity without rebuilding postings. */
    public function rebind(CatalogGeneration $generation): self
    {
        if ($this->identity !== $generation->identity->digest() || ! $this->matches($generation->exportState()['elements'])) {
            throw new InvalidArgumentException('search_rebind_identity');
        }
        $next = new self($generation->id(), $this->identity, $this->sourceDigest, $this->postingBudget);
        foreach (['values', 'intern', 'records', 'dictionary', 'grams', 'postings', 'limited'] as $field) {
            $next->{$field} = $this->{$field};
        }

        return $next;
    }

    /** Consumes W02's actual element delta. Never composes a second CatalogIndex or diffs it.
     * W04 verifies sources/publication separately; this returns an unpublished candidate.
     */
    public function apply(CatalogPatch $patch): self
    {
        if ($patch->baseGeneration !== $this->generation || $patch->identity !== $this->identity
            || $patch->target->identity->digest() !== $this->identity || $patch->target->session !== $patch->session
            || $patch->target->sequence !== $patch->lastSequence) {
            throw new InvalidArgumentException('search_patch_base_or_target');
        }
        $elements = $patch->target->exportState()['elements'];
        $next = new self($patch->target->id(), $this->identity, self::digestElements($elements), $this->postingBudget);
        $next->values = $this->values;
        $next->intern = $this->intern;
        $next->records = $this->records;
        $next->grams = $this->grams;
        $next->postings = $this->postings;
        $next->limited = $this->limited;
        $delta = $patch->changes['elements'] ?? null;
        if (! is_array($delta) || ! is_array($delta['removed'] ?? null) || ! is_array($delta['upserted'] ?? null)) {
            throw new InvalidArgumentException('search_patch_elements');
        }
        foreach ($delta['removed'] as $id) {
            if ((! is_string($id) && ! is_int($id)) || ! isset($next->records[$id]) || isset($elements[$id])) {
                throw new InvalidArgumentException('search_patch_removed');
            }
            $next->remove((string) $id);
        }
        foreach ($delta['upserted'] as $id => $element) {
            if (($elements[$id] ?? null) !== $element) {
                throw new InvalidArgumentException('search_patch_upsert');
            }
            $next->remove((string) $id);
            $next->add((string) $id, $element);
        }
        // Flat compaction on every patch: no old values or generation references survive.
        // A budget fallback may recover after deletions, using only the normalized dictionary.
        if ($this->limited) {
            $next->rebuildGrams();
        }
        $next->sortDictionary();
        $next->assertElements($elements);

        return $next;
    }

    /** Candidate ranks are exact over normalized values, before fetching large raw records.
     * @return array<int|string, int>
     */
    public function ranks(string $needle): array
    {
        $matched = [];
        // Binary lower bound and contiguous range; no materialized per-record prefixes.
        $key = 'v'.$needle;
        $lo = 0;
        $hi = count($this->dictionary);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if (strcmp($this->dictionary[$mid], $key) < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        for ($i = $lo, $count = count($this->dictionary); $i < $count && str_starts_with($this->dictionary[$i], $key); $i++) {
            $value = $this->dictionary[$i];
            $matched[$value] = $value === $key ? 0 : 1;
        }
        $candidates = null;
        if (! $this->limited && strlen($needle) >= 3) {
            foreach (SearchValues::grams($needle) as $gram) {
                $posting = $this->grams[$gram] ?? [];
                if ($candidates === null || count($posting) < count($candidates)) {
                    $candidates = $posting;
                }
                if ($candidates === []) {
                    break;
                }
            }
        }
        // Smallest posting is a superset of all substring matches. No candidate cap.
        foreach ($candidates ?? $this->values as $value => $_) {
            if (! isset($matched[$value]) && str_contains(substr($value, 1), $needle)) {
                $matched[$value] = 2;
            }
        }
        $ranks = [];
        foreach ($matched as $value => $rank) {
            foreach ($this->values[$value] as $id => $_) {
                $ranks[$id] = min($ranks[$id] ?? 3, $rank);
            }
        }

        return $ranks;
    }

    /** Separate from the legacy raw result, for W04's public execution metadata.
     * @return array<string, mixed>
     */
    public function execution(string $query): array
    {
        return ['mode' => $this->limited ? 'exact_dictionary_scan' : (strlen(strtolower(trim($query))) < 3 ? 'short_dictionary_scan' : 'indexed'),
            'reason' => $this->limited ? 'posting_budget' : null, 'generation' => $this->generation,
            'posting_budget' => $this->postingBudget, 'postings' => $this->postings];
    }

    /** @return array<string, int> */
    public function measurements(): array
    {
        return ['records' => count($this->records), 'values' => count($this->values), 'dictionary' => count($this->dictionary),
            'grams' => count($this->grams), 'postings' => $this->postings, 'overlay_depth' => 0, 'tombstones' => 0];
    }

    /** @param array<int|string, array<string, mixed>> $elements */
    public function matches(array $elements): bool
    {
        return hash_equals($this->sourceDigest, self::digestElements($elements));
    }

    /** Canonical digest ignores PHP map insertion order, unlike the legacy ordered export.
     * @param  array<int|string, array<string, mixed>>  $elements
     */
    public static function digestElements(array $elements): string
    {
        ksort($elements, SORT_STRING);

        return SemanticVersions::digest($elements);
    }

    /** Data only. Base64 value keys preserve even trigrams spanning UTF-8 byte boundaries.
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $values = [];
        foreach ($this->dictionary as $value) {
            $ids = array_map('strval', array_keys($this->values[$value]));
            sort($ids, SORT_STRING);
            $values[base64_encode($value)] = $ids;
        }
        $records = $this->records;
        ksort($records, SORT_STRING);
        foreach ($records as &$keys) {
            sort($keys, SORT_STRING);
            $keys = array_map('base64_encode', $keys);
        }
        unset($keys);
        $grams = $this->grams;
        ksort($grams, SORT_STRING);
        foreach ($grams as &$owners) {
            $keys = array_keys($owners);
            sort($keys, SORT_STRING);
            $owners = array_map('base64_encode', $keys);
        }
        unset($owners);

        return ['schema' => self::SCHEMA, 'generation' => $this->generation, 'identity' => $this->identity,
            'source_digest' => $this->sourceDigest, 'posting_budget' => $this->postingBudget,
            'limited' => $this->limited, 'postings' => $this->postings, 'values' => $values, 'records' => $records, 'grams' => $grams];
    }

    /** Independent record streams for the atomic graph/dependency/search set.
     * No complete search JSON or second encoded dictionary is retained.
     *
     * @return array<string, iterable<int|string, mixed>>
     */
    public function parts(): array
    {
        return ['searchMetadata' => ['schema' => self::SCHEMA, 'generation' => $this->generation, 'identity' => $this->identity,
            'source_digest' => $this->sourceDigest, 'posting_budget' => $this->postingBudget, 'limited' => $this->limited, 'postings' => $this->postings],
            'searchValues' => (function (): \Generator {
                foreach ($this->dictionary as $value) {
                    $ids = array_map('strval', array_keys($this->values[$value]));
                    sort($ids, SORT_STRING);
                    yield base64_encode($value) => $ids;
                }
            })(),
            'searchRecords' => (function (): \Generator {
                foreach ($this->records as $id => $values) {
                    sort($values, SORT_STRING);
                    yield $id => array_map('base64_encode', $values);
                }
            })(),
            'searchGrams' => (function (): \Generator {
                foreach ($this->grams as $gram => $owners) {
                    $keys = array_keys($owners);
                    sort($keys, SORT_STRING);
                    yield $gram => array_map('base64_encode', $keys);
                }
            })()];
    }

    /** Strict restore does not run a parser/resolver or rebuild the index. Invalid data throws.
     * @param  array<string, mixed>  $data
     * @param  array<int|string, array<string, mixed>>  $elements
     */
    public static function restore(array $data, array $elements, string $generation, string $identity): self
    {
        $keys = ['schema', 'generation', 'identity', 'source_digest', 'posting_budget', 'limited', 'postings', 'values', 'records', 'grams'];
        if (array_keys($data) !== $keys || ! is_array($data['values']) || ! is_array($data['records']) || ! is_array($data['grams'])) {
            throw new InvalidArgumentException('search_state_schema_or_identity');
        }
        $collect = self::restorationCollector($elements, $generation, $identity);
        $collect('metadata', null, array_diff_key($data, ['values' => true, 'records' => true, 'grams' => true]));
        foreach (['values', 'records', 'grams'] as $field) {
            foreach ($data[$field] as $key => $row) {
                $collect($field, $key, $row);
            }
        }

        return $collect('finish', null, null);
    }

    /** The legacy and segmented readers share every reference/coverage validator.
     * The returned collector retains native state and the shared immutable elements only.
     *
     * @param  array<int|string, array<string, mixed>>  $elements
     */
    public static function restorationCollector(array $elements, string $generation, string $identity): \Closure
    {
        $state = null;
        $metadata = [];
        $actualPostings = 0;
        $finished = false;

        return static function (string $field, mixed $key, mixed $row) use (&$state, &$metadata, &$actualPostings, &$finished, $elements, $generation, $identity): self|bool {
            if ($finished) {
                throw new InvalidArgumentException('search_state_collector_finished');
            }
            if ($field === 'metadata') {
                if ($state !== null || ! is_array($row) || array_keys($row) !== ['schema', 'generation', 'identity', 'source_digest', 'posting_budget', 'limited', 'postings']
                    || $row['schema'] !== self::SCHEMA || $row['generation'] !== $generation || $row['identity'] !== $identity
                    || ! is_string($row['source_digest']) || ! is_int($row['posting_budget']) || $row['posting_budget'] < 0
                    || ! is_bool($row['limited']) || ! is_int($row['postings'])) {
                    throw new InvalidArgumentException('search_state_schema_or_identity');
                }
                $metadata = $row;
                $state = new self($generation, $identity, $row['source_digest'], $row['posting_budget']);
                $state->limited = $row['limited'];
                if (! $state->matches($elements)) {
                    throw new InvalidArgumentException('search_state_source_digest');
                }

                return true;
            }
            if ($state === null) {
                throw new InvalidArgumentException('search_state_metadata_missing');
            }
            if ($field === 'values') {
                $value = self::decodeKey($key);
                if (isset($state->values[$value]) || ! is_array($row) || ! array_is_list($row) || $row === []) {
                    throw new InvalidArgumentException('search_state_owners');
                }
                $state->intern[$value] = $value;
                foreach ($row as $id) {
                    if (! is_string($id) || ! isset($elements[$id]) || isset($state->values[$value][$id])) {
                        throw new InvalidArgumentException('search_state_owner_reference');
                    }
                    $state->values[$value][$id] = true;
                }

                return true;
            }
            if ($field === 'records') {
                if (isset($state->records[$key]) || ! is_array($row) || ! array_is_list($row)) {
                    throw new InvalidArgumentException('search_state_record');
                }
                $state->records[$key] = array_map(static fn (mixed $encoded): string => $state->intern[self::decodeKey($encoded)]
                    ?? throw new InvalidArgumentException('search_state_value_reference'), $row);

                return true;
            }
            if ($field === 'grams') {
                $gram = (string) $key;
                if (isset($state->grams[$gram]) || preg_match('/^[a-f0-9]{6}$/D', $gram) !== 1
                    || ! is_array($row) || ! array_is_list($row) || $row === []) {
                    throw new InvalidArgumentException('search_state_gram');
                }
                foreach ($row as $encoded) {
                    $value = $state->intern[self::decodeKey($encoded)] ?? throw new InvalidArgumentException('search_state_value_reference');
                    if (! isset($state->values[$value]) || isset($state->grams[$gram][$value]) || ! str_contains(substr($value, 1), hex2bin($gram))) {
                        throw new InvalidArgumentException('search_state_gram_reference');
                    }
                    $state->grams[$gram][$value] = true;
                    $actualPostings++;
                }

                return true;
            }
            if ($field !== 'finish') {
                throw new InvalidArgumentException('search_state_collector_field');
            }
            $state->assertElements($elements);
            if ($actualPostings !== $metadata['postings'] || $actualPostings > $state->postingBudget || $state->limited && $actualPostings !== 0) {
                throw new InvalidArgumentException('search_state_posting_count');
            }
            if (! $state->limited) {
                foreach ($state->values as $value => $_) {
                    foreach (SearchValues::grams(substr($value, 1)) as $gram) {
                        if (! isset($state->grams[$gram][$value])) {
                            throw new InvalidArgumentException('search_state_missing_posting');
                        }
                    }
                }
            }
            $state->postings = $actualPostings;
            $state->sortDictionary();
            $finished = true;

            return $state;
        };
    }

    private static function decodeKey(mixed $key): string
    {
        $value = is_string($key) ? base64_decode($key, true) : false;
        if ($value === false || ! str_starts_with($value, 'v') || base64_encode($value) !== $key || strtolower($value) !== $value) {
            throw new InvalidArgumentException('search_state_value');
        }

        return $value;
    }

    /** @param array<int|string, array<string, mixed>> $elements */
    private function assertElements(array $elements): void
    {
        if (count($this->records) !== count($elements)) {
            throw new InvalidArgumentException('search_state_record_count');
        }
        foreach ($elements as $id => $element) {
            $expected = SearchValues::keys($element);
            $actual = $this->records[$id] ?? [];
            sort($expected, SORT_STRING);
            sort($actual, SORT_STRING);
            if ($expected !== $actual) {
                throw new InvalidArgumentException('search_state_record_values');
            }
            foreach ($actual as $value) {
                if (! isset($this->values[$value][$id])) {
                    throw new InvalidArgumentException('search_state_missing_owner');
                }
            }
        }
        foreach ($this->values as $value => $owners) {
            foreach ($owners as $id => $_) {
                if (! in_array($value, $this->records[$id] ?? [], true)) {
                    throw new InvalidArgumentException('search_state_extra_owner');
                }
            }
        }
    }

    /** @param array<string, mixed> $element */
    private function add(string $id, array $element): void
    {
        $keys = SearchValues::keys($element);
        $this->records[$id] = [];
        foreach ($keys as $value) {
            $value = $this->intern[$value] ??= $value;
            $this->records[$id][] = $value;
            $new = ! isset($this->values[$value]);
            $this->values[$value][$id] = true;
            if ($new && ! $this->limited) {
                $this->addGrams($value);
            }
        }
    }

    private function remove(string $id): void
    {
        foreach ($this->records[$id] ?? [] as $value) {
            unset($this->values[$value][$id]);
            if ($this->values[$value] !== []) {
                continue;
            }
            unset($this->values[$value], $this->intern[$value]);
            if (! $this->limited) {
                foreach (SearchValues::grams(substr($value, 1)) as $gram) {
                    if (isset($this->grams[$gram][$value])) {
                        unset($this->grams[$gram][$value]);
                        $this->postings--;
                        if ($this->grams[$gram] === []) {
                            unset($this->grams[$gram]);
                        }
                    }
                }
            }
        }
        unset($this->records[$id]);
    }

    private function addGrams(string $value): void
    {
        foreach (SearchValues::grams(substr($value, 1)) as $gram) {
            if (isset($this->grams[$gram][$value])) {
                continue;
            }
            if ($this->postings >= $this->postingBudget) {
                // Drop the whole optional accelerator. An incomplete index is never queried.
                $this->grams = [];
                $this->postings = 0;
                $this->limited = true;

                return;
            }
            $this->grams[$gram][$value] = true;
            $this->postings++;
        }
    }

    private function rebuildGrams(): void
    {
        $this->grams = [];
        $this->postings = 0;
        $this->limited = false;
        foreach ($this->values as $value => $_) {
            $this->addGrams($value);
            if ($this->limited) {
                break;
            }
        }
    }

    private function sortDictionary(): void
    {
        $this->dictionary = array_keys($this->values);
        sort($this->dictionary, SORT_STRING);
    }
}
