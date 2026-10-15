<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit;

use GracjanKubicki\ArchitectureKit\Composer\ProjectLayout;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfigPath;
use Illuminate\Filesystem\Filesystem;

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
        $default = $this->value instanceof Architecture ? $this->value->defaultPlacement() : null;
        $layout = ProjectLayout::read($this->projectPath);
        if ($default === null || ! $layout->isPackage()) {
            return $default;
        }
        $files = new Filesystem;
        $settings = (new ArchitectureConfig(ArchitectureConfigPath::resolve($files, $this->projectPath), $files))->projectSettings();

        return implode(', ', array_map(fn (string $folder): string => $layout->placement($this->slug(), $folder, 'App', $settings)['folder'], explode(', ', $default)));
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
