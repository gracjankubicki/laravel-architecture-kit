<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Upgrades;

final readonly class UpgradeGuide
{
    public function __construct(
        public string $name,
        public string $description,
        public string $architecture,
        public string $package,
        public VersionLine $from,
        public VersionLine $to,
        public string $contents,
    ) {}

    public function key(): string
    {
        return 'upgrade:'.$this->packageSlug().':'.$this->from->value.'-to-'.$this->to->value;
    }

    public function packageSlug(): string
    {
        return str_replace('/', '-', $this->package);
    }

    public function generatedPath(string $projectPath): string
    {
        return $projectPath.'/.ai/skills/'.$this->name.'/SKILL.md';
    }
}
