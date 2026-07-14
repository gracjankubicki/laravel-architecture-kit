<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Resources;

use Illuminate\Filesystem\Filesystem;

final readonly class ManagedResourceDeployment
{
    public function __construct(
        private Filesystem $files,
        private ArchitectureResources $resources,
    ) {}

    /**
     * @param  array<string, GeneratedFile>  $expected
     * @param  array<string, string>  $removals
     * @param  array<int, string>  $replaceableUnmanagedKeys
     */
    public function plan(array $expected, array $removals, array $replaceableUnmanagedKeys = []): ManagedResourcePlan
    {
        $create = [];
        $update = [];
        $remove = [];
        $blocked = [];

        foreach ($expected as $key => $file) {
            if (! $this->files->exists($file->path)) {
                $create[] = $file->path;

                continue;
            }

            if ($this->files->get($file->path) === $file->contents) {
                continue;
            }

            if (! in_array($key, $replaceableUnmanagedKeys, true) && ! $this->resources->isGenerated($file->path)) {
                $blocked[] = $file->path;

                continue;
            }

            $update[] = $file->path;
        }

        foreach ($removals as $path) {
            $directory = dirname($path);
            $extraFiles = array_filter(
                $this->files->allFiles($directory),
                fn ($file): bool => $file->getPathname() !== $path,
            );

            if ($extraFiles !== []) {
                $blocked[] = $directory;

                continue;
            }

            $remove[] = $directory;
        }

        return new ManagedResourcePlan($create, $update, $remove, array_values(array_unique($blocked)));
    }

    /**
     * @param  array<string, GeneratedFile>  $expected
     * @param  array<string, string>  $removals
     */
    public function apply(array $expected, array $removals): void
    {
        foreach ($expected as $file) {
            $this->files->ensureDirectoryExists(dirname($file->path));
            $this->files->put($file->path, $file->contents);
        }

        foreach ($removals as $path) {
            $this->files->deleteDirectory(dirname($path));
        }
    }
}
