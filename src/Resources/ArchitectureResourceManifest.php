<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Resources;

final readonly class ArchitectureResourceManifest
{
    public function __construct(private ArchitectureResources $resources) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<string, GeneratedFile>
     */
    public function expected(array $enabled): array
    {
        $expected = ['guideline' => $this->resources->guideline($enabled)];

        foreach ($this->resources->skills($enabled) as $key => $file) {
            $expected['skill:'.$key] = $file;
        }

        return $expected;
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<string, string>
     */
    public function stale(array $enabled): array
    {
        $expectedNames = array_values(array_map(
            fn (GeneratedFile $file): string => basename(dirname($file->path)),
            $this->resources->skills($enabled),
        ));

        return array_filter(
            $this->resources->existingGeneratedSkillPaths(),
            fn (string $path, string $name): bool => ! in_array($name, $expectedNames, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
