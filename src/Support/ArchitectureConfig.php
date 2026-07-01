<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Taqie\ArchitectureKit\Architecture;

final class ArchitectureConfig
{
    public function __construct(
        private readonly string $path,
        private readonly Filesystem $files = new Filesystem(),
    ) {
    }

    /**
     * @return array<int, Architecture>
     */
    public function readOrDefault(): array
    {
        if (! $this->files->exists($this->path)) {
            return Architecture::defaultSelection();
        }

        $config = require $this->path;

        if (! is_array($config)) {
            throw new InvalidArgumentException('config/architectures.php must return an array.');
        }

        return $this->normalizeConfig($config['enabled'] ?? []);
    }

    /**
     * @return array<int, Architecture>
     */
    public function read(): array
    {
        if (! $this->files->exists($this->path)) {
            throw new InvalidArgumentException('config/architectures.php does not exist.');
        }

        $config = require $this->path;

        if (! is_array($config)) {
            throw new InvalidArgumentException('config/architectures.php must return an array.');
        }

        return $this->normalizeConfig($config['enabled'] ?? []);
    }

    /**
     * @param  array<int, Architecture>  $enabled
     */
    public function render(array $enabled): string
    {
        $lines = [
            '<?php',
            '',
            'use Taqie\\ArchitectureKit\\Architecture;',
            '',
            'return [',
            "    'enabled' => [",
        ];

        foreach ($this->order($enabled) as $architecture) {
            $lines[] = '        Architecture::'.$architecture->name.',';
        }

        $lines[] = '    ],';
        $lines[] = '];';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, Architecture>  $enabled
     */
    public function write(array $enabled): void
    {
        $this->files->ensureDirectoryExists(dirname($this->path));
        $this->files->put($this->path, $this->render($enabled));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, Architecture>
     */
    public function normalize(array $values): array
    {
        return $this->normalizeValues($values, allowStrings: true);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, Architecture>
     */
    private function normalizeConfig(array $values): array
    {
        return $this->normalizeValues($values, allowStrings: false);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, Architecture>
     */
    private function normalizeValues(array $values, bool $allowStrings): array
    {
        $architectures = [];

        foreach ($values as $value) {
            $architecture = match (true) {
                $value instanceof Architecture => $value,
                $allowStrings && is_string($value) => Architecture::from($value),
                default => throw new InvalidArgumentException('Architecture entries must be Architecture enum cases.'),
            };

            $architectures[$architecture->value] = $architecture;
        }

        if ($architectures === []) {
            throw new InvalidArgumentException('At least one architecture must be enabled.');
        }

        return $this->order(array_values($architectures));
    }

    /**
     * @param  array<int, Architecture>  $enabled
     * @return array<int, Architecture>
     */
    private function order(array $enabled): array
    {
        return array_values(array_filter(
            Architecture::guidelineOrder(),
            fn (Architecture $architecture): bool => in_array($architecture, $enabled, true),
        ));
    }
}
