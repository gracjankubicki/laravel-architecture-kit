<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install\Agents;

use GracjanKubicki\ArchitectureKit\Install\Contracts\SupportsHooks;
use GracjanKubicki\ArchitectureKit\Install\Contracts\SupportsMcp;

final class Codex extends Agent implements SupportsHooks, SupportsMcp
{
    public function name(): string
    {
        return 'codex';
    }

    public function displayName(): string
    {
        return 'Codex';
    }

    public function projectDetectionConfig(): array
    {
        return [
            'paths' => ['.codex'],
            'files' => ['.codex/config.toml'],
        ];
    }

    public function mcpConfigPath(): string
    {
        return '.codex/config.toml';
    }

    public function mcpConfigKey(): string
    {
        return 'mcp_servers';
    }

    public function mcpServerConfig(string $command, array $args = [], array $env = []): array
    {
        $normalized = $this->normalizeCommand($command, $args);

        return collect([
            'command' => $normalized['command'],
            'args' => $normalized['args'],
            'required' => true,
            'env' => $env,
        ])->filter(fn ($value): bool => ! in_array($value, [[], null, ''], true))
            ->toArray();
    }

    public function hookConfigPath(): string
    {
        return '.codex/hooks.json';
    }

    public function hookMode(): string
    {
        return 'codex';
    }
}
