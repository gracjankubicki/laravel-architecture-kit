<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

/** One source of settings, separate from the checkout whose code is read. */
final readonly class ConfigurationSelection
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public string $codeRoot,
        public string $ownerRoot,
        public string $source,
        public string $kind,
        public ?string $profile,
        public string $sourceHash,
        public array $settings,
    ) {}

    public function inherited(): bool
    {
        return $this->ownerRoot !== $this->codeRoot;
    }

    public function identity(): string
    {
        return hash('sha256', serialize([$this->codeRoot, $this->ownerRoot, $this->source, $this->kind, $this->profile, $this->sourceHash]));
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return ['code_root' => $this->codeRoot, 'configuration_owner_root' => $this->ownerRoot,
            'configuration_source' => $this->source, 'configuration_kind' => $this->kind,
            'requested_profile' => $this->profile, 'inherited' => $this->inherited(),
            'source_hash' => $this->sourceHash, 'selection_identity' => $this->identity()];
    }
}
