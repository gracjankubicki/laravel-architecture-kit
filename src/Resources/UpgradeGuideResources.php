<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Resources;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Upgrades\UpgradeGuideCatalog;
use Illuminate\Filesystem\Filesystem;

final readonly class UpgradeGuideResources
{
    public function __construct(
        private string $packagePath,
        private string $projectPath,
        private Filesystem $files,
    ) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<string, GeneratedFile>
     */
    public function skills(array $enabled): array
    {
        $skills = [];

        foreach ((new UpgradeGuideCatalog($this->packagePath, $this->files))->forArchitectures($enabled) as $guide) {
            $skills[$guide->key()] = new GeneratedFile(
                path: $guide->generatedPath($this->projectPath),
                contents: GeneratedResourceMarker::skill($guide->contents),
            );
        }

        ksort($skills);

        return $skills;
    }
}
