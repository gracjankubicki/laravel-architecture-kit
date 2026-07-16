<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Planning;

final readonly class ArchitectureRecommendation
{
    /** @param array<int, string> $evidence */
    public function __construct(
        public string $slug,
        public string $label,
        public string $confidence,
        public array $evidence,
        public bool $configured = false,
    ) {}

    /** @return array{slug: string, label: string, confidence: string, evidence: array<int, string>, configured: bool} */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'label' => $this->label,
            'confidence' => $this->confidence,
            'evidence' => $this->evidence,
            'configured' => $this->configured,
        ];
    }
}
