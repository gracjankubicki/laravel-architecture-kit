<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

use InvalidArgumentException;

final readonly class RuntimeResolver
{
    /**
     * @var array{driver: string, service: string|null, php: string, command: array<int, string>|null}
     */
    private array $runtime;

    /**
     * @param  array<string, mixed>  $runtime
     */
    public function __construct(array $runtime = [])
    {
        $this->runtime = $this->normalize($runtime);
    }

    /**
     * @return array{driver: string, service: string|null, php: string, command: array<int, string>|null}
     */
    public function runtime(): array
    {
        return $this->runtime;
    }

    /**
     * @return array<int, string>
     */
    public function commandPrefix(): array
    {
        return match ($this->runtime['driver']) {
            'local' => [$this->runtime['php']],
            'sail', 'docker' => [
                'docker',
                'compose',
                'exec',
                '-T',
                (string) $this->runtime['service'],
                $this->runtime['php'],
            ],
            'custom' => $this->runtime['command'] ?? [],
            default => throw new InvalidArgumentException("Unsupported Architecture Kit runtime driver [{$this->runtime['driver']}]."),
        };
    }

    /**
     * @param  array<int, string>  $arguments
     * @return array<int, string>
     */
    public function artisanCommand(string $command, array $arguments = []): array
    {
        return [
            ...$this->commandPrefix(),
            'artisan',
            $command,
            ...$arguments,
        ];
    }

    /**
     * @return array{command: string, args: array<int, string>}
     */
    public function mcpCommand(): array
    {
        $command = $this->artisanCommand('architecture-kit:mcp');

        return [
            'command' => array_shift($command) ?? 'php',
            'args' => $command,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function guardCommand(): array
    {
        return $this->artisanCommand('architecture-kit:guard', ['--changed', '--strict', '--json']);
    }

    /**
     * @return array<int, string>
     */
    public function gitCommand(): array
    {
        return match ($this->runtime['driver']) {
            'local' => ['git', '--version'],
            'sail', 'docker' => [
                'docker',
                'compose',
                'exec',
                '-T',
                (string) $this->runtime['service'],
                'git',
                '--version',
            ],
            'custom' => ['git', '--version'],
            default => ['git', '--version'],
        };
    }

    public function shellArray(string $variableName): string
    {
        return $variableName.'=('.implode(' ', array_map(escapeshellarg(...), $this->commandPrefix())).')';
    }

    public function shellCommand(array $command): string
    {
        return implode(' ', array_map(escapeshellarg(...), $command));
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @return array{driver: string, service: string|null, php: string, command: array<int, string>|null}
     */
    private function normalize(array $runtime): array
    {
        $driver = $runtime['driver'] ?? 'local';

        if (! is_string($driver) || ! in_array($driver, ['local', 'sail', 'docker', 'custom'], true)) {
            throw new InvalidArgumentException('config/architectures.php runtime.driver must be one of: local, sail, docker, custom.');
        }

        $php = $runtime['php'] ?? 'php';

        if (! is_string($php) || trim($php) === '') {
            throw new InvalidArgumentException('config/architectures.php runtime.php must be a non-empty string.');
        }

        $service = $runtime['service'] ?? null;

        if ($driver === 'docker') {
            if (! is_string($service) || trim($service) === '') {
                throw new InvalidArgumentException('config/architectures.php runtime.service must be set when runtime.driver is docker.');
            }

            $service = trim($service);
        }

        if ($driver === 'sail') {
            $service = is_string($service) && trim($service) !== '' ? trim($service) : 'laravel.test';
        }

        if ($driver !== 'docker' && $driver !== 'sail') {
            $service = is_string($service) && trim($service) !== '' ? trim($service) : null;
        }

        $command = $this->normalizeCommand($runtime['command'] ?? null);

        if ($driver === 'custom' && $command === null) {
            throw new InvalidArgumentException('config/architectures.php runtime.command must be set when runtime.driver is custom.');
        }

        return [
            'driver' => $driver,
            'service' => $service,
            'php' => trim($php),
            'command' => $driver === 'custom' ? $command : null,
        ];
    }

    /**
     * @return array<int, string>|null
     */
    private function normalizeCommand(mixed $command): ?array
    {
        if ($command === null) {
            return null;
        }

        if (is_string($command)) {
            $command = trim($command);

            if ($command === '') {
                return null;
            }

            $parts = preg_split('/\s+/', $command);

            return $parts === false ? null : $parts;
        }

        if (! is_array($command)) {
            throw new InvalidArgumentException('config/architectures.php runtime.command must be an array of strings.');
        }

        $parts = array_values(array_filter($command, fn (mixed $part): bool => is_string($part) && trim($part) !== ''));

        return $parts === [] ? null : $parts;
    }
}
