<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Install;

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
        foreach ($this->primaryComposeFilenames() as $filename) {
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
        $paths = $this->composePaths();

        if ($paths === []) {
            return null;
        }

        $services = [];

        foreach ($paths as $path) {
            try {
                $compose = Yaml::parse($this->files->get($path));
            } catch (ParseException) {
                return null;
            }

            if (! is_array($compose) || ! isset($compose['services']) || ! is_array($compose['services'])) {
                continue;
            }

            foreach (array_keys($compose['services']) as $service) {
                if (is_string($service)) {
                    $services[] = $service;
                }
            }
        }

        $services = array_values(array_unique($services));
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

    /**
     * @return array<int, string>
     */
    private function composePaths(): array
    {
        $paths = [];

        foreach ([...$this->primaryComposeFilenames(), ...$this->overrideComposeFilenames()] as $filename) {
            $path = $this->basePath.'/'.$filename;

            if ($this->files->exists($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @return array<int, string>
     */
    private function primaryComposeFilenames(): array
    {
        return ['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'];
    }

    /**
     * @return array<int, string>
     */
    private function overrideComposeFilenames(): array
    {
        return ['compose.override.yaml', 'compose.override.yml', 'docker-compose.override.yml', 'docker-compose.override.yaml'];
    }
}
