<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit;

final readonly class EnabledArchitecture
{
    public function __construct(
        public Architecture|string $value,
        private string $projectPath,
    ) {}

    public function slug(): string
    {
        return $this->value instanceof Architecture ? $this->value->value : $this->value;
    }

    public function label(): string
    {
        if ($this->value instanceof Architecture) {
            return $this->value->label();
        }

        return str($this->value)->replace('-', ' ')->title()->toString();
    }

    public function skillName(): string
    {
        return $this->value instanceof Architecture
            ? $this->value->skillName()
            : 'architecture-kit-'.$this->value;
    }

    public function sourcePath(): string
    {
        return $this->value instanceof Architecture
            ? $this->value->sourcePath()
            : '.architecture-kit/architectures/'.$this->value;
    }

    public function defaultPlacement(): ?string
    {
        return $this->value instanceof Architecture
            ? $this->value->defaultPlacement()
            : null;
    }

    public function guidelineSource(string $packagePath): string
    {
        return $this->value instanceof Architecture
            ? $packagePath.'/resources/architectures/'.$this->value->value.'/guideline.md'
            : $this->projectPath.'/.architecture-kit/architectures/'.$this->value.'/guideline.md';
    }

    public function summarySource(string $packagePath): string
    {
        return $this->value instanceof Architecture
            ? $packagePath.'/resources/architectures/'.$this->value->value.'/summary.md'
            : $this->projectPath.'/.architecture-kit/architectures/'.$this->value.'/summary.md';
    }

    public function skillSource(string $packagePath): string
    {
        return $this->value instanceof Architecture
            ? $packagePath.'/resources/architectures/'.$this->value->value.'/SKILL.md'
            : $this->projectPath.'/.architecture-kit/architectures/'.$this->value.'/SKILL.md';
    }
}
