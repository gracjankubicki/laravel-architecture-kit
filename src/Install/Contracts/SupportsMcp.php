<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Install\Contracts;

interface SupportsMcp
{
    public function mcpConfigPath(): string;

    public function mcpConfigKey(): string;

    /**
     * @param  array<int, string>  $args
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    public function mcpServerConfig(string $command, array $args = [], array $env = []): array;
}
