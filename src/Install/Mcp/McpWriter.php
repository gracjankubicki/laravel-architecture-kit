<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install\Mcp;

use GracjanKubicki\ArchitectureKit\Install\Agents\Agent;
use GracjanKubicki\ArchitectureKit\Install\Contracts\SupportsMcp;
use GracjanKubicki\ArchitectureKit\Install\InstallResult;
use GracjanKubicki\ArchitectureKit\Install\RuntimeResolver;
use Illuminate\Filesystem\Filesystem;

final readonly class McpWriter
{
    public const LEGACY_SERVER_KEY = 'architecture-kit';

    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private RuntimeResolver $runtime = new RuntimeResolver,
    ) {}

    /**
     * @param  array<int, Agent&SupportsMcp>  $agents
     */
    public function plan(array $agents): InstallResult
    {
        $creates = [];
        $updates = [];
        $blocked = [];

        foreach ($agents as $agent) {
            $path = $this->absolute($agent->mcpConfigPath());
            $contents = $this->render($agent);

            if ($contents === null) {
                $blocked[] = $agent->mcpConfigPath();

                continue;
            }

            if (! $this->files->exists($path)) {
                $creates[] = $agent->mcpConfigPath();

                continue;
            }

            if ($this->files->get($path) !== $contents) {
                $updates[] = $agent->mcpConfigPath();
            }
        }

        return new InstallResult($creates, $updates, $blocked);
    }

    /**
     * @param  array<int, Agent&SupportsMcp>  $agents
     */
    public function write(array $agents): void
    {
        foreach ($agents as $agent) {
            $path = $this->absolute($agent->mcpConfigPath());
            $contents = $this->render($agent);

            if ($contents === null) {
                continue;
            }

            if ($this->files->exists($path) && $this->files->get($path) === $contents) {
                continue;
            }

            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, $contents);
        }
    }

    private function render(Agent&SupportsMcp $agent): ?string
    {
        $path = $this->absolute($agent->mcpConfigPath());
        $command = $this->runtime->mcpCommand();
        $config = $agent->mcpServerConfig($command['command'], $command['args']);
        $serverKey = $this->serverKey();

        if (str_ends_with($path, '.toml')) {
            return (new TomlConfigWriter($this->files, $path, $agent->mcpConfigKey()))->render(
                $serverKey,
                $config,
                [self::LEGACY_SERVER_KEY],
            );
        }

        return (new JsonConfigWriter($this->files, $path, $agent->mcpConfigKey()))->render(
            $serverKey,
            $config,
            [self::LEGACY_SERVER_KEY],
        );
    }

    private function absolute(string $path): string
    {
        return $this->basePath.'/'.$path;
    }

    private function serverKey(): string
    {
        $project = strtolower(basename($this->basePath));
        $project = preg_replace('/[^a-z0-9]+/', '-', $project) ?: 'project';
        $project = trim($project, '-');

        return self::LEGACY_SERVER_KEY.'-'.($project === '' ? 'project' : $project);
    }
}
