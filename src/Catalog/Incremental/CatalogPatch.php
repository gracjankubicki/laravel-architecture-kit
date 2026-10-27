<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog\Incremental;

use Closure;
use RuntimeException;

/** Prepared immutable candidate and complete changed records for W03/W04 publication. */
final readonly class CatalogPatch
{
    /** @param array<string, string> $inputHashes
     * @param  array<string, array{removed: list<int|string>, upserted: array<mixed>}>  $changes
     * @param  list<string>  $deleted
     * @param  array<string, ?string>  $changedHashes
     */
    public function __construct(
        public string $baseGeneration,
        public string $identity,
        public string $session,
        public string $firstSequence,
        public string $lastSequence,
        public array $inputHashes,
        public CatalogGeneration $target,
        public array $changes,
        public array $deleted,
        public array $changedHashes,
        /** @var array<string, int> */
        public array $measurements,
    ) {}

    /** Does not mutate base. Reusing the same immutable base is idempotent.
     * @param  Closure(string): ?string  $contents
     */
    public function apply(CatalogGeneration $base, string $session, Closure $contents): CatalogGeneration
    {
        $this->assertBase($base, $session);
        CatalogUpdater::verifySources([...array_fill_keys($this->deleted, null), ...$this->target->hashes()], $contents);

        return $this->target;
    }

    /** Verify changed/deleted bytes while retaining unchanged files as last-confirmed.
     * W04 owns event-to-path coverage and full-scan timestamps. A barrier is not an OS flush.
     *
     * @param  Closure(string): ?string  $contents
     * @return array{generation: CatalogGeneration, basis: string, unchanged_basis: string, observed_sequence: string, verified_hashes: array<string, ?string>}
     */
    public function applyObserved(CatalogGeneration $base, CatalogObservedPrefix $prefix, Closure $contents): array
    {
        $this->assertBase($base, $this->session);
        $prefix->assertApplicable($this, $base);
        CatalogUpdater::verifySources($this->changedHashes, $contents);
        $prefix->assertApplicable($this, $base);

        return ['generation' => $this->target, 'basis' => 'observed_prefix', 'unchanged_basis' => 'last_confirmed',
            'observed_sequence' => $this->lastSequence, 'verified_hashes' => $this->changedHashes];
    }

    private function assertBase(CatalogGeneration $base, string $session): void
    {
        if ($base->id() !== $this->baseGeneration || $base->identity->digest() !== $this->identity || $base->hashes() !== $this->inputHashes) {
            throw new RuntimeException('patch_base_mismatch');
        }
        if ($session !== $this->session || $base->session !== $session || CatalogSequence::validate($this->firstSequence) !== CatalogSequence::next($base->sequence) || CatalogSequence::compare($this->lastSequence, $this->firstSequence) < 0) {
            throw new RuntimeException('patch_session_or_sequence_mismatch');
        }
    }
}
