<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Support\ProjectPath;
use Illuminate\Filesystem\Filesystem;
use SplFileInfo;

final readonly class ProjectGraphLoader
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private AuditScope $scope = new AuditScope,
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
        foreach ($this->scope->directories as $directory) {
            $absolute = $this->basePath.'/'.$directory;

            // A configured directory that does not exist is not an error: a project may
            // list one it has not created yet.
            if (! $this->files->isDirectory($absolute)) {
                continue;
            }

            foreach ($this->files->allFiles($absolute) as $file) {
                if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = ProjectPath::relative($this->basePath, $file->getPathname());

                // A test file stays in the graph even when an exclusion pattern matches
                // it: the scope only contains tests/ because the missing-test rule needs
                // them, and hiding some would make covered classes look untested.
                if (! $this->isRequiredTestFile($path) && $this->isExcluded($path, $exclude)) {
                    continue;
                }

                yield new FileContext($path, $this->files->get($file->getPathname()));
            }
        }
    }

    private function isRequiredTestFile(string $path): bool
    {
        return $this->scope->includesTests() && $this->scope->isTestPath($path);
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
