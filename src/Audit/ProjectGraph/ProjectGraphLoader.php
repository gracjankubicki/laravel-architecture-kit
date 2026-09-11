<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Support\ProjectPath;
use Illuminate\Filesystem\Filesystem;
use SplFileInfo;

final readonly class ProjectGraphLoader
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
    ) {}

    /**
     * @param  array<int, string>  $exclude
     * @return array<string, FileContext>
     */
    public function files(array $exclude = []): array
    {
        $contexts = [];

        foreach ($this->stream($exclude) as $context) {
            $contexts[$context->path] = $context;
        }

        ksort($contexts);

        return $contexts;
    }

    /**
     * @param  array<int, string>  $exclude
     * @return iterable<int, FileContext>
     */
    public function stream(array $exclude = []): iterable
    {
        if (! $this->files->isDirectory($this->basePath.'/app')) {
            return;
        }

        foreach ($this->files->allFiles($this->basePath.'/app') as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = ProjectPath::relative($this->basePath, $file->getPathname());

            if ($this->isExcluded($path, $exclude)) {
                continue;
            }

            yield new FileContext($path, $this->files->get($file->getPathname()));
        }
    }

    /** @param array<int, string> $exclude */
    public function load(array $exclude = []): ProjectGraphSnapshot
    {
        return (new ProjectGraphBuilder)->build(array_values($this->files($exclude)));
    }

    /** @param array<int, string> $exclude */
    private function isExcluded(string $path, array $exclude): bool
    {
        foreach ($exclude as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
