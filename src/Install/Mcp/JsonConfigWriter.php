<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Install\Mcp;

use Illuminate\Filesystem\Filesystem;

final readonly class JsonConfigWriter
{
    public function __construct(
        private Filesystem $files,
        private string $path,
        private string $configKey,
    ) {
    }

    /**
     * @param  array<string, mixed>  $serverConfig
     */
    public function render(string $serverKey, array $serverConfig): ?string
    {
        if (! $this->files->exists($this->path) || $this->files->size($this->path) < 3) {
            return $this->encode([
                $this->configKey => [
                    $serverKey => $serverConfig,
                ],
            ]);
        }

        $decoded = json_decode($this->files->get($this->path), true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $servers = $decoded[$this->configKey] ?? [];

        if (! is_array($servers)) {
            return null;
        }

        if (
            array_key_exists($serverKey, $servers)
            && (! is_array($servers[$serverKey]) || ! $this->isUpdateable($servers[$serverKey]))
        ) {
            return null;
        }

        $servers[$serverKey] = $serverConfig;
        $decoded[$this->configKey] = $servers;

        return $this->encode($decoded);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}')."\n";
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private function isUpdateable(array $server): bool
    {
        $command = $server['command'] ?? null;
        $args = $server['args'] ?? [];

        if ($command === null && $args === []) {
            return true;
        }

        return is_string($command)
            && is_array($args)
            && str_contains($command.' '.implode(' ', array_filter($args, is_string(...))), 'architecture-kit');
    }
}
