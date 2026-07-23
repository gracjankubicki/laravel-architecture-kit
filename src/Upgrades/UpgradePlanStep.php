<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Upgrades;

final readonly class UpgradePlanStep
{
    public function __construct(
        public UpgradeGuide $guide,
        public string $status,
        public string $path,
    ) {}

    /**
     * @return array{from: string, to: string, status: string, architecture: string, skill: string, path: string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->guide->from->value,
            'to' => $this->guide->to->value,
            'status' => $this->status,
            'architecture' => $this->guide->architecture,
            'skill' => $this->guide->name,
            'path' => $this->path,
        ];
    }
}
