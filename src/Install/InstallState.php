<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install;

use Illuminate\Filesystem\Filesystem;

final readonly class InstallState
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
    ) {}

    /**
     * @param  array<int, string>  $agents
     */
    public function write(array $agents, bool $mcp, bool $hooks): void
    {
        $path = $this->path();

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, json_encode([
            'agents' => array_values($agents),
            'install' => [
                'mcp' => $mcp,
                'hooks' => $hooks,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * @return array{agents: array<int, string>, install: array{mcp: bool, hooks: bool}}|null
     */
    public function read(): ?array
    {
        if (! $this->files->exists($this->path())) {
            return null;
        }

        $decoded = json_decode($this->files->get($this->path()), true);

        if (! is_array($decoded)) {
            return null;
        }

        $agents = $decoded['agents'] ?? [];
        $install = $decoded['install'] ?? [];

        if (! is_array($agents) || ! is_array($install)) {
            return null;
        }

        return [
            'agents' => array_values(array_filter($agents, is_string(...))),
            'install' => [
                'mcp' => ($install['mcp'] ?? false) === true,
                'hooks' => ($install['hooks'] ?? false) === true,
            ],
        ];
    }

    public function relativePath(): string
    {
        return '.architecture-kit/install.json';
    }

    private function path(): string
    {
        return $this->basePath.'/'.$this->relativePath();
    }
}
