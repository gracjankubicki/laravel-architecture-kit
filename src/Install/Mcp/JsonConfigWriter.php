<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install\Mcp;

use Illuminate\Filesystem\Filesystem;

final readonly class JsonConfigWriter
{
    public function __construct(
        private Filesystem $files,
        private string $path,
        private string $configKey,
    ) {}

    /**
     * @param  array<string, mixed>  $serverConfig
     * @param  array<int, string>  $existingKeys
     */
    public function render(string $serverKey, array $serverConfig, array $existingKeys = []): ?string
    {
        if (! $this->files->exists($this->path)) {
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

        $managedKeys = array_values(array_unique([$serverKey, ...$existingKeys]));
        $hasExistingIntegration = false;

        foreach ($managedKeys as $managedKey) {
            if (! array_key_exists($managedKey, $servers)) {
                continue;
            }

            if (! is_array($servers[$managedKey])) {
                return null;
            }

            $hasExistingIntegration = true;
        }

        if ($hasExistingIntegration) {
            return $this->files->get($this->path);
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
}
