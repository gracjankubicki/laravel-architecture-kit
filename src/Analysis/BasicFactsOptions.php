<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;

/** File-local extraction settings. Project classification and resolution remain in PHP. */
final readonly class BasicFactsOptions
{
    public function __construct(
        public bool $impact = false,
        public bool $catalog = false,
        public bool $preserveOccurrences = false,
        public RoleClassifier $roles = new RoleClassifier,
    ) {}

    /** @return array{impact: bool, catalog: bool, preserve_occurrences: bool, classification_fingerprint: string} */
    public function toArray(): array
    {
        return ['impact' => $this->impact, 'catalog' => $this->catalog, 'preserve_occurrences' => $this->preserveOccurrences,
            'classification_fingerprint' => $this->roles->mappings->fingerprint()];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}
