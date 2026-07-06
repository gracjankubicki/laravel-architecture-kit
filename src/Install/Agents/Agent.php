<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Install\Agents;

use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Install\CommandNormalizer;

abstract class Agent
{
    public function __construct(
        protected readonly Filesystem $files,
        protected readonly string $basePath,
    ) {}

    abstract public function name(): string;

    abstract public function displayName(): string;

    /**
     * @return array{paths?: array<int, string>, files?: array<int, string>, commands?: array<int, string>}
     */
    abstract public function projectDetectionConfig(): array;

    public function detectInProject(): bool
    {
        $config = $this->projectDetectionConfig();

        foreach ($config['paths'] ?? [] as $path) {
            if ($this->files->isDirectory($this->basePath.'/'.$path)) {
                return true;
            }
        }

        foreach ($config['files'] ?? [] as $path) {
            if ($this->files->exists($this->basePath.'/'.$path)) {
                return true;
            }
        }

        return false;
    }

    public function getPhpPath(): string
    {
        return 'php';
    }

    public function getArtisanPath(): string
    {
        return 'artisan';
    }

    /**
     * @param  array<int, string>  $args
     * @return array{command: string, args: array<int, string>}
     */
    protected function normalizeCommand(string $command, array $args = []): array
    {
        return CommandNormalizer::normalize($command, $args);
    }
}
