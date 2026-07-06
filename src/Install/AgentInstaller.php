<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Install;

use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Install\Agents\Agent;
use Taqie\ArchitectureKit\Install\Contracts\SupportsHooks;
use Taqie\ArchitectureKit\Install\Contracts\SupportsMcp;
use Taqie\ArchitectureKit\Install\Hooks\HookWriter;
use Taqie\ArchitectureKit\Install\Mcp\McpWriter;

final readonly class AgentInstaller
{
    private RuntimeResolver $runtime;

    /**
     * @param  array<string, mixed>  $runtime
     */
    public function __construct(
        private Filesystem $files,
        private string $basePath,
        array $runtime = [],
    ) {
        $this->runtime = new RuntimeResolver($runtime);
    }

    /**
     * @param  array<int, Agent>  $agents
     * @return array{mcp: InstallResult, hooks: InstallResult, state: InstallResult}
     */
    public function plan(array $agents, bool $mcp, bool $hooks): array
    {
        $empty = new InstallResult;

        return [
            'mcp' => $mcp ? (new McpWriter($this->files, $this->basePath, $this->runtime))->plan($this->mcpAgents($agents)) : $empty,
            'hooks' => $hooks ? (new HookWriter($this->files, $this->basePath, $this->runtime))->plan($this->hookAgents($agents)) : $empty,
            'state' => $this->statePlan($agents, $mcp, $hooks),
        ];
    }

    /**
     * @param  array<int, Agent>  $agents
     */
    public function write(array $agents, bool $mcp, bool $hooks): void
    {
        if ($mcp) {
            (new McpWriter($this->files, $this->basePath, $this->runtime))->write($this->mcpAgents($agents));
        }

        if ($hooks) {
            (new HookWriter($this->files, $this->basePath, $this->runtime))->write($this->hookAgents($agents));
        }

        (new InstallState($this->files, $this->basePath))->write(
            array_map(fn (Agent $agent): string => $agent->name(), $agents),
            $mcp,
            $hooks,
        );
    }

    /**
     * @param  array<int, Agent>  $agents
     * @return array<int, Agent&SupportsMcp>
     */
    private function mcpAgents(array $agents): array
    {
        return array_values(array_filter($agents, fn (Agent $agent): bool => $agent instanceof SupportsMcp));
    }

    /**
     * @param  array<int, Agent>  $agents
     * @return array<int, Agent&SupportsHooks>
     */
    private function hookAgents(array $agents): array
    {
        return array_values(array_filter($agents, fn (Agent $agent): bool => $agent instanceof SupportsHooks));
    }

    /**
     * @param  array<int, Agent>  $agents
     */
    private function statePlan(array $agents, bool $mcp, bool $hooks): InstallResult
    {
        $state = new InstallState($this->files, $this->basePath);
        $path = $this->basePath.'/'.$state->relativePath();
        $contents = json_encode([
            'agents' => array_map(fn (Agent $agent): string => $agent->name(), $agents),
            'install' => [
                'mcp' => $mcp,
                'hooks' => $hooks,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        if (! $this->files->exists($path)) {
            return new InstallResult(creates: [$state->relativePath()]);
        }

        if ($this->files->get($path) !== $contents) {
            return new InstallResult(updates: [$state->relativePath()]);
        }

        return new InstallResult;
    }
}
