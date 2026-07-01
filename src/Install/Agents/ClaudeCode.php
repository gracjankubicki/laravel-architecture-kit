<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Install\Agents;

use Taqie\ArchitectureKit\Install\Contracts\SupportsHooks;
use Taqie\ArchitectureKit\Install\Contracts\SupportsMcp;

final class ClaudeCode extends Agent implements SupportsHooks, SupportsMcp
{
    public function name(): string
    {
        return 'claude_code';
    }

    public function displayName(): string
    {
        return 'Claude Code';
    }

    public function projectDetectionConfig(): array
    {
        return [
            'paths' => ['.claude'],
            'files' => ['CLAUDE.md'],
        ];
    }

    public function mcpConfigPath(): string
    {
        return '.mcp.json';
    }

    public function mcpConfigKey(): string
    {
        return 'mcpServers';
    }

    public function mcpServerConfig(string $command, array $args = [], array $env = []): array
    {
        $normalized = $this->normalizeCommand($command, $args);

        return collect([
            'command' => $normalized['command'],
            'args' => $normalized['args'],
            'env' => $env,
        ])->filter(fn ($value): bool => ! in_array($value, [[], null, ''], true))
            ->toArray();
    }

    public function hookConfigPath(): string
    {
        return '.claude/settings.json';
    }

    public function hookMode(): string
    {
        return 'claude';
    }
}
