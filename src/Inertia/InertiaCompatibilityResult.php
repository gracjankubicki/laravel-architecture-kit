<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Inertia;

final readonly class InertiaCompatibilityResult
{
    public function __construct(
        public InertiaCompatibilityStatus $status,
        public ?string $section = null,
        public ?string $declaredConstraint = null,
        public ?string $installedVersion = null,
        public ?string $lockedVersion = null,
        public ?string $profile = null,
        public string $message = '',
        public string $remediation = '',
    ) {}

    public function supported(): bool
    {
        return $this->status === InertiaCompatibilityStatus::Supported;
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
            'profile' => $this->profile,
            'supported_constraint' => InertiaCompatibility::SUPPORTED_CONSTRAINT,
            'message' => $this->message,
            'remediation' => $this->remediation,
        ];
    }
}
