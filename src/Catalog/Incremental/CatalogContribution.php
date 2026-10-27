<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog\Incremental;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use InvalidArgumentException;

/** Exact source-byte identity travels beside the original, unclassified file facts. */
final readonly class CatalogContribution
{
    public function __construct(public CatalogFacts $facts, public string $sourceHash)
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $sourceHash) || $facts->path === '' || str_contains($facts->path, "\0")) {
            throw new InvalidArgumentException('Invalid incremental contribution identity.');
        }
    }

    public static function collect(FileContext $file, ProjectGraphBuilder $builder): self
    {
        if ($builder->analysisMode() !== AnalysisMode::Basic) {
            throw new InvalidArgumentException('Incremental catalog requires Basic source contributions.');
        }
        $hash = hash('sha256', $file->contents);
        $facts = $builder->collectBasicMany([$file])[0]->catalog;
        if ($facts === null) {
            throw new InvalidArgumentException('Basic catalog contribution is required.');
        }

        return new self($facts, $hash);
    }

    /** @return array{hash: string, facts: array<string, mixed>} */
    public function toArray(): array
    {
        return ['hash' => $this->sourceHash, 'facts' => $this->facts->toArray()];
    }
}
