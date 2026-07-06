<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Install\Mcp;

use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Install\Agents\Agent;
use Taqie\ArchitectureKit\Install\Contracts\SupportsMcp;
use Taqie\ArchitectureKit\Install\InstallResult;
use Taqie\ArchitectureKit\Install\RuntimeResolver;

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

        if ($agents !== []) {
            $makefile = (new MakefileMcpTargetWriter($this->files, $this->basePath, $this->runtime))->plan();
            $creates = [...$creates, ...$makefile->creates];
            $updates = [...$updates, ...$makefile->updates];
            $blocked = [...$blocked, ...$makefile->blocked];
        }

        return new InstallResult($creates, $updates, $blocked);
    }

    /**
     * @param  array<int, Agent&SupportsMcp>  $agents
     */
    public function write(array $agents): void
    {
        if ($agents !== []) {
            (new MakefileMcpTargetWriter($this->files, $this->basePath, $this->runtime))->write();
        }

        foreach ($agents as $agent) {
            $path = $this->absolute($agent->mcpConfigPath());
            $contents = $this->render($agent);

            if ($contents === null) {
                continue;
            }

            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, $contents);
        }
    }

    private function render(Agent&SupportsMcp $agent): ?string
    {
        $path = $this->absolute($agent->mcpConfigPath());
        $config = $agent->mcpServerConfig('make', [MakefileMcpTargetWriter::TARGET]);
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
