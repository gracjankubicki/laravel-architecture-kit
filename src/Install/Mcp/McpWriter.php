<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Install\Mcp;

use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Install\Agents\Agent;
use Taqie\ArchitectureKit\Install\Contracts\SupportsMcp;
use Taqie\ArchitectureKit\Install\InstallResult;

final readonly class McpWriter
{
    public const SERVER_KEY = 'architecture-kit';

    public function __construct(
        private Filesystem $files,
        private string $basePath,
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

        $cwd = config('architectures.agents.mcp.cwd');

        if (is_string($cwd) && $cwd !== '') {
            $config['cwd'] = $cwd;
        }

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
        $command = config('architectures.agents.mcp.command');

        return is_string($command) && $command !== '' ? $command : 'php';
    }

    /**
     * @return array<int, string>
     */
    private function args(): array
    {
        $args = config('architectures.agents.mcp.args');

        if (! is_array($args)) {
            return ['artisan', 'architecture-kit:mcp'];
        }

        $strings = array_values(array_filter($args, is_string(...)));

        return $strings === [] ? ['artisan', 'architecture-kit:mcp'] : $strings;
    }
}
