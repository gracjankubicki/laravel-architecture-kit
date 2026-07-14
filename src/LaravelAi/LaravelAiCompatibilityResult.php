<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\LaravelAi;

final readonly class LaravelAiCompatibilityResult
{
    /** @param array<int, string> $missingCapabilities */
    public function __construct(
        public LaravelAiCompatibilityStatus $status,
        public ?string $section = null,
        public ?string $declaredConstraint = null,
        public ?string $installedVersion = null,
        public ?string $lockedVersion = null,
        public ?LaravelAiProfile $profile = null,
        public array $missingCapabilities = [],
        public string $message = '',
        public string $remediation = '',
    ) {}

    public function supported(): bool
    {
        return $this->status === LaravelAiCompatibilityStatus::Supported;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'section' => $this->section,
            'declared_constraint' => $this->declaredConstraint,
            'installed_version' => $this->installedVersion,
            'locked_version' => $this->lockedVersion,
            'profile' => $this->profile?->key(),
            'supported_constraint' => $this->profile?->constraint(),
            'supported_ranges' => array_map(
                fn (LaravelAiProfile $profile): string => $profile->constraint(),
                LaravelAiProfile::cases(),
            ),
            'missing_capabilities' => $this->missingCapabilities,
            'message' => $this->message,
            'remediation' => $this->remediation,
        ];
    }
}
