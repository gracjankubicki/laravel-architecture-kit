<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog\Incremental;

use Closure;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use RuntimeException;

/** No mutable CatalogIndex instance is shared with a reader or a later generation. */
final readonly class CatalogGeneration
{
    private string $generationId;

    /** @var array<string, mixed> */
    public array $measurements;

    /** @param array<string, CatalogContribution> $contributions
     * @param  array<string, array<string, mixed>>  $operations
     * @param  array<string, array<mixed>>  $state
     * @param  array<string, mixed>  $measurements
     */
    public function __construct(
        public CatalogIdentity $identity,
        public string $session,
        public string $sequence,
        public array $contributions,
        public array $operations,
        private array $state,
        array $measurements,
    ) {
        $started = hrtime(true);
        $this->generationId = SemanticVersions::digest([$this->identity->digest(), $this->session, $this->sequence, $this->hashes(), SemanticVersions::digest($this->state)]);
        $this->measurements = [...$measurements, 'generation_digest_ns' => hrtime(true) - $started];
    }

    public function id(): string
    {
        return $this->generationId;
    }

    /** @return array<string, string> */
    public function hashes(): array
    {
        return array_map(fn (CatalogContribution $source) => $source->sourceHash, $this->contributions);
    }

    /** Copy-on-write array view, not a writable alias to this generation.
     * @return array<string, array<mixed>>
     */
    public function exportState(): array
    {
        return $this->state;
    }

    /** Legacy query materialization validates and shares PHP array pages until a write. */
    public function index(?Closure $contents = null): CatalogIndex
    {
        $hashes = $this->hashes();
        $reader = $contents === null ? null : static function (string $path) use ($contents, $hashes): ?string {
            $hash = $hashes[$path] ?? null;
            if ($hash === null) {
                return null;
            }
            $bytes = $contents($path);
            if ($bytes === null || hash('sha256', $bytes) !== $hash) {
                throw new RuntimeException('changed_inputs:'.$path);
            }

            return $bytes;
        };

        return CatalogIndex::fromState($this->state, $reader);
    }
}
