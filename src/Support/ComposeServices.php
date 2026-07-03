<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class ComposeServices
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
    ) {}

    public function detectedRuntimeDriver(): string
    {
        if ($this->files->exists($this->basePath.'/vendor/bin/sail')) {
            return 'sail';
        }

        if ($this->composePath() !== null) {
            return 'docker';
        }

        return 'local';
    }

    public function composePath(): ?string
    {
        foreach (['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'] as $filename) {
            $path = $this->basePath.'/'.$filename;

            if ($this->files->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>|null
     */
    public function services(): ?array
    {
        $path = $this->composePath();

        if ($path === null) {
            return null;
        }

        try {
            $compose = Yaml::parse($this->files->get($path));
        } catch (ParseException) {
            return null;
        }

        if (! is_array($compose) || ! isset($compose['services']) || ! is_array($compose['services'])) {
            return null;
        }

        $services = array_values(array_filter(array_keys($compose['services']), is_string(...)));
        sort($services);

        return $services === [] ? null : $services;
    }

    public function sailService(): string
    {
        $envPath = $this->basePath.'/.env';

        if (! $this->files->exists($envPath)) {
            return 'laravel.test';
        }

        foreach (preg_split('/\R/', $this->files->get($envPath)) ?: [] as $line) {
            if (! str_starts_with($line, 'APP_SERVICE=')) {
                continue;
            }

            $service = trim(substr($line, strlen('APP_SERVICE=')), " \t\n\r\0\x0B\"'");

            return $service === '' ? 'laravel.test' : $service;
        }

        return 'laravel.test';
    }
}
