<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog\Incremental;

use Closure;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogCallResolver;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use InvalidArgumentException;
use RuntimeException;

/** Recompose global structural/classification work; execute only invalid call operations. */
final class CatalogUpdater
{
    public const VERSION = 1;

    /** W04 chooses the calibrated transition budget. Refusal never truncates analysis. */
    public function __construct(private readonly ?int $transitionBudgetBytes = null)
    {
        if ($transitionBudgetBytes !== null && $transitionBudgetBytes < 1) {
            throw new InvalidArgumentException('Invalid catalog transition budget.');
        }
    }

    private function checkBudget(int $before): void
    {
        if ($this->transitionBudgetBytes !== null && memory_get_usage() - $before > $this->transitionBudgetBytes) {
            throw new RuntimeException('preparation_required:catalog_transition_budget');
        }
    }

    /** @param list<CatalogContribution> $sources
     * @param  (Closure(string): ?string)|null  $contents  Exact final byte check supplied by the source owner.
     */
    public function prepare(array $sources, CatalogIdentity $identity, string $session, string $sequence = '0', ?Closure $contents = null): CatalogGeneration
    {
        self::session($session, $sequence);
        $generation = $this->compose($this->sources($sources), $identity, $session, $sequence);
        self::verifySources($generation->hashes(), $contents);

        return $generation;
    }

    /** @param list<CatalogContribution> $upserts
     * @param  list<string>  $deleted
     * @param  (Closure(string): ?string)|null  $contents
     */
    public function patch(CatalogGeneration $base, array $upserts, array $deleted, CatalogIdentity $identity, string $session, string $firstSequence, string $lastSequence, ?Closure $contents = null): CatalogPatch
    {
        if ($identity->digest() !== $base->identity->digest()) {
            throw new RuntimeException('preparation_required:identity_changed');
        }
        if ($session !== $base->session || CatalogSequence::validate($firstSequence) !== CatalogSequence::next($base->sequence) || CatalogSequence::compare($lastSequence, $firstSequence) < 0) {
            throw new RuntimeException('preparation_required:session_or_sequence');
        }
        $sources = $base->contributions;
        $changed = $this->sources($upserts);
        foreach ($deleted as $path) {
            if (! is_string($path) || ! isset($sources[$path]) || isset($changed[$path])) {
                throw new InvalidArgumentException('Invalid incremental deletion.');
            }
            unset($sources[$path]);
        }
        foreach ($changed as $path => $source) {
            $sources[$path] = $source;
        }
        // Keep the same canonical source order used by a full preparation.
        ksort($sources);
        $this->admit($base, $sources);
        $generation = $this->compose($sources, $identity, $session, $lastSequence, $base);
        $expected = [...array_fill_keys($deleted, null), ...$generation->hashes()];
        self::verifySources($expected, $contents);

        $tick = hrtime(true);
        $changes = self::changes($base->exportState(), $generation->exportState());
        $deltaTime = hrtime(true) - $tick;

        return new CatalogPatch($base->id(), $base->identity->digest(), $session, $firstSequence, $lastSequence,
            $base->hashes(), $generation, $changes, $deleted,
            [...array_fill_keys($deleted, null), ...array_map(fn (CatalogContribution $source) => $source->sourceHash, $changed)], ['changed_record_diff_ns' => $deltaTime]);
    }

    /** Observed reservation is conservative, not a proof of peak memory for arbitrary inputs.
     * It rejects known-insufficient transitions before building any new foundation.
     *
     * @param  array<string, CatalogContribution>  $sources
     */
    private function admit(CatalogGeneration $base, array $sources): void
    {
        $oldRecords = max(1, self::inputRecords($base->contributions));
        $growth = max(1, self::inputRecords($sources) / $oldRecords);
        $working = $base->measurements['transition_working_bytes'];
        $reservation = (int) ceil($working * $growth * 2);
        $memoryLimit = MemoryLimit::bytes();
        if ($this->transitionBudgetBytes !== null && $reservation > $this->transitionBudgetBytes
            || $memoryLimit !== null && $reservation > $memoryLimit * 0.65 - 262144 - memory_get_usage(true)) {
            throw new RuntimeException('preparation_required:catalog_transition_budget');
        }
    }

    /** @param array<string, CatalogContribution> $sources */
    private static function inputRecords(array $sources): int
    {
        $records = 0;
        foreach ($sources as $source) {
            $records += count($source->facts->elements) + count($source->facts->relations) + count($source->facts->diagnostics) + count($source->facts->descriptorReceivers) + 1;
        }

        return $records;
    }

    /** @param list<CatalogContribution> $sources
     * @return array<string, CatalogContribution>
     */
    private function sources(array $sources): array
    {
        $ordered = [];
        foreach ($sources as $source) {
            if (! $source instanceof CatalogContribution || isset($ordered[$source->facts->path])) {
                throw new InvalidArgumentException('Duplicate or invalid incremental source.');
            }
            $ordered[$source->facts->path] = $source;
        }
        ksort($ordered);

        return $ordered;
    }

    /** @param array<string, CatalogContribution> $sources */
    private function compose(array $sources, CatalogIdentity $identity, string $session, string $sequence, ?CatalogGeneration $base = null): CatalogGeneration
    {
        $started = hrtime(true);
        $used = memory_get_usage();
        $foundation = CatalogIndex::basicFoundation(array_values(array_map(fn (CatalogContribution $s): CatalogFacts => $s->facts, $sources)));
        $index = $foundation['index'];
        $foundationTime = hrtime(true) - $started;
        $tick = hrtime(true);
        $versions = new SemanticVersions($index, $foundation['calls']);
        $versionsTime = hrtime(true) - $tick;
        $tick = hrtime(true);
        $resolver = new CatalogCallResolver($index);
        $membersTime = hrtime(true) - $tick;
        $this->checkBudget($used);
        $operations = $trace = [];
        $ordinals = [];
        $executed = $reused = 0;
        $workingBytes = max(1, memory_get_usage() - $used);
        $ownership = ['foundation' => ['relations' => [0, count($index->relations)], 'diagnostics' => [0, count($index->diagnostics)]], 'calls' => []];
        $semanticTime = $exportTime = $validationTime = $reuseTime = 0;
        foreach ($foundation['calls'] as $call) {
            $path = $call['path'];
            $ordinal = $ordinals[$path] ?? 0;
            $ordinals[$path] = $ordinal + 1;
            $key = self::VERSION.':'.$path.':'.$ordinal;
            $input = SemanticVersions::digest($call);
            $previous = $base?->operations[$key] ?? null;
            $tick = hrtime(true);
            $reason = $this->invalidReason($previous, $input, $versions, $resolver);
            $validationTime += hrtime(true) - $tick;
            $tick = hrtime(true);
            $relationOffset = count($index->relations);
            $diagnosticOffset = count($index->diagnostics);
            if ($reason === null) {
                $fragment = $previous;
                $resolver->replayMembers($fragment['member_writes']);
                $reused++;
            } else {
                $reads = new SemanticReads($versions);
                $reads->read('descriptors:*');
                // Visibility and lexical binding inspect creators even if a target lookup is empty.
                $creator = $call['metadata']['bound_callable']['creator'] ?? $call['from'];
                for ($depth = 0; $depth < 16; $depth++) {
                    $reads->read('element:'.$creator);
                    if (! isset($index->elements[$creator]) || $index->elements[$creator]['parent'] === null) {
                        break;
                    }
                    $creator = $index->elements[$creator]['parent'];
                }
                $receiver = $call['metadata']['receiver'];
                $coarse = str_starts_with($receiver, '@') && ! str_starts_with($receiver, '@function:') && ! str_starts_with($receiver, '@closure:');
                if ($coarse) {
                    $reads->read('declarations:*');
                }
                $index->semanticReads = $reads;
                $relationStart = count($index->relations);
                $diagnosticStart = count($index->diagnostics);
                try {
                    // Basic has no returned-callable expansion. Preserve the full resolver for special descriptors.
                    $resolver->resolve([$call]);
                } finally {
                    $index->semanticReads = null;
                }
                $fragment = ['owner' => ['path' => $path, 'ordinal' => $ordinal, 'product' => self::VERSION],
                    'input' => $input, 'reads' => $reads->versions, 'lookups' => $reads->lookups, 'member_reads' => $reads->cacheReads, 'member_writes' => $reads->cacheWrites,
                    'relations' => array_slice($index->relations, $relationStart), 'diagnostics' => array_slice($index->diagnostics, $diagnosticStart),
                    'tracking' => $coarse ? 'declarations:*' : 'connected-declarations'];
                $executed++;
            }
            if ($reason === null) {
                $reuseTime += hrtime(true) - $tick;
            } else {
                $semanticTime += hrtime(true) - $tick;
            }
            $tick = hrtime(true);
            if ($reason === null) {
                foreach ($fragment['relations'] as $relation) {
                    $index->addRelation($relation);
                }
                array_push($index->diagnostics, ...$fragment['diagnostics']);
            }
            $exportTime += hrtime(true) - $tick;
            $this->checkBudget($used);
            $workingBytes = max($workingBytes, memory_get_usage() - $used);
            $ownership['calls'][$key] = ['relations' => [$relationOffset, count($fragment['relations'])], 'diagnostics' => [$diagnosticOffset, count($fragment['diagnostics'])]];
            $operations[$key] = $fragment;
            $trace[] = ['operation' => $key, 'path' => $path, 'action' => $reason === null ? 'reuse' : 'resolve',
                'reason' => $reason, 'tracking' => $fragment['tracking'], 'reads' => $fragment['reads']];
        }
        $tick = hrtime(true);
        $ownership['registrations'] = ['relations' => [count($index->relations), count($foundation['registrations'])]];
        foreach ($foundation['registrations'] as $registration) {
            $index->addRelation($registration);
        }
        $state = $index->exportState();
        $exportTime += hrtime(true) - $tick;

        return new CatalogGeneration($identity, $session, $sequence, $sources, $operations, $state,
            ['executed_calls' => $executed, 'reused_calls' => $reused, 'trace' => $trace,
                'transition_working_bytes' => max($workingBytes, memory_get_usage() - $used), 'ownership' => $ownership,
                'transition_budget_bytes' => $this->transitionBudgetBytes, 'outer_operation_entries' => count($operations), 'outer_source_entries' => count($sources),
                'global_work' => ['local-facts', 'structural/include/type', 'composerStates', 'classify', 'members/subtypes', 'connected-declaration-versions'],
                'foundation_ns' => $foundationTime, 'dependency_versions_ns' => $versionsTime, 'members_subtypes_ns' => $membersTime, 'dependency_validation_ns' => $validationTime, 'reuse_state_ns' => $reuseTime, 'call_semantics_ns' => $semanticTime, 'fragment_export_ns' => $exportTime,
                'total_ns' => hrtime(true) - $started, 'php_used_before' => $used, 'php_used_after' => memory_get_usage(),
                'php_allocated' => memory_get_usage(true), 'php_peak' => memory_get_peak_usage(),
                'overlay_depth' => 0, 'fragment_count' => count($operations), 'representation' => 'flat-shared-fragments; fresh-linear-materialization']);
    }

    /** @param array<string, mixed>|null $previous */
    private function invalidReason(?array $previous, string $input, SemanticVersions $versions, CatalogCallResolver $resolver): ?string
    {
        if ($previous === null || $previous['input'] !== $input) {
            return 'operation_input';
        }
        foreach ($previous['diagnostics'] as $diagnostic) {
            if (str_contains($diagnostic['code'], 'limit')) {
                return 'incomplete_operation';
            }
        }
        foreach ($previous['reads'] as $key => $version) {
            if ($versions->version($key) !== $version) {
                return 'dependency:'.$key;
            }
        }
        foreach ($previous['member_reads'] as $key => $value) {
            if ($resolver->cachedMember($key) !== $value) {
                return 'member_cache:'.$key;
            }
        }

        return null;
    }

    /** Positional list deltas deliberately expose shifted adjacency and diagnostic positions.
     * @param  array<string, array<mixed>>  $before
     * @param  array<string, array<mixed>>  $after
     * @return array<string, array{removed: list<int|string>, upserted: array<mixed>}>
     */
    private static function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $values) {
            $removed = array_keys(array_diff_key($before[$field], $values));
            $upserted = [];
            foreach ($values as $key => $value) {
                if (! array_key_exists($key, $before[$field]) || $before[$field][$key] !== $value) {
                    $upserted[$key] = $value;
                }
            }
            $changes[$field] = ['removed' => $removed, 'upserted' => $upserted];
        }

        return $changes;
    }

    /** @param array<string, ?string> $hashes
     * @param  (Closure(string): ?string)|null  $contents
     */
    public static function verifySources(array $hashes, ?Closure $contents): void
    {
        if ($contents === null) {
            return; // Caller must bind byte verification to publication; no freshness claim is made here.
        }
        foreach ($hashes as $path => $expected) {
            $bytes = $contents($path);
            $actual = $bytes === null ? null : hash('sha256', $bytes);
            if ($actual !== $expected) {
                throw new RuntimeException('changed_inputs:'.$path);
            }
        }
    }

    private static function session(string $session, string $sequence): void
    {
        if ($session === '' || strlen($session) > 256 || CatalogSequence::validate($sequence) !== $sequence) {
            throw new InvalidArgumentException('Invalid incremental session.');
        }
    }
}
