<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Install\Mcp;

use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Install\Agents\Agent;
use Taqie\ArchitectureKit\Install\Contracts\SupportsMcp;
use Taqie\ArchitectureKit\Install\InstallResult;
use Taqie\ArchitectureKit\Support\RuntimeResolver;

final readonly class McpWriter
{
    public const SERVER_KEY = 'architecture-kit';

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

            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, $contents);
        }
    }

    private function render(Agent&SupportsMcp $agent): ?string
    {
        $path = $this->absolute($agent->mcpConfigPath());
        $config = $agent->mcpServerConfig($this->command(), $this->args());

        if (str_ends_with($path, '.toml')) {
            return (new TomlConfigWriter($this->files, $path, $agent->mcpConfigKey()))->render(self::SERVER_KEY, $config);
        }

        return (new JsonConfigWriter($this->files, $path, $agent->mcpConfigKey()))->render(self::SERVER_KEY, $config);
    }

    private function absolute(string $path): string
    {
        return $this->basePath.'/'.$path;
    }

    private function command(): string
    {
        return $this->runtime->mcpCommand()['command'];
    }

    /**
     * @return array<int, string>
     */
    private function args(): array
    {
        return $this->runtime->mcpCommand()['args'];
    }
}
