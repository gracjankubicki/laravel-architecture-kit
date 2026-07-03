<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Install\Mcp;

use Illuminate\Filesystem\Filesystem;

final readonly class TomlConfigWriter
{
    public function __construct(
        private Filesystem $files,
        private string $path,
        private string $configKey,
    ) {}

    /**
     * @param  array<string, mixed>  $serverConfig
     */
    /**
     * @param  array<int, string>  $replaceKeys
     */
    public function render(string $serverKey, array $serverConfig, array $replaceKeys = []): ?string
    {
        $section = $this->buildSection($serverKey, $serverConfig);

        if (! $this->files->exists($this->path) || $this->files->size($this->path) < 3) {
            return $section."\n";
        }

        $content = $this->files->get($this->path);

        if (substr_count($content, '[') !== substr_count($content, ']')) {
            return null;
        }

        $targetKey = $this->firstExistingKey($content, [$serverKey, ...$replaceKeys]);

        if ($targetKey === null) {
            return rtrim($content)."\n\n".$section."\n";
        }

        $range = $this->sectionRange($content, $targetKey);

        if ($range === null || ! str_contains($range['contents'], 'architecture-kit')) {
            return null;
        }

        $content = substr_replace($content, $section."\n", $range['start'], $range['end'] - $range['start']);

        foreach ($replaceKeys as $replaceKey) {
            if ($replaceKey !== $targetKey) {
                $content = $this->removeSection($content, $replaceKey);
            }

            $content = $this->removeSection($content, $replaceKey.'.env');
        }

        return rtrim($content)."\n";
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function firstExistingKey(string $content, array $keys): ?string
    {
        foreach ($keys as $key) {
            if ($this->sectionRange($content, $key) !== null) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array{start: int, end: int, contents: string}|null
     */
    private function sectionRange(string $content, string $serverKey): ?array
    {
        $header = '['.$this->configKey.'.'.$serverKey.']';

        if (! preg_match('/^'.preg_quote($header, '/').'\s*$/m', $content, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $match[0][1];
        $afterHeader = $start + strlen($match[0][0]);
        $end = preg_match('/^\[.+\]\s*$/m', $content, $nextMatch, PREG_OFFSET_CAPTURE, $afterHeader)
            ? $nextMatch[0][1]
            : strlen($content);

        return [
            'start' => $start,
            'end' => $end,
            'contents' => substr($content, $start, $end - $start),
        ];
    }

    private function removeSection(string $content, string $serverKey): string
    {
        $range = $this->sectionRange($content, $serverKey);

        if ($range === null) {
            return $content;
        }

        return substr_replace($content, '', $range['start'], $range['end'] - $range['start']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function buildSection(string $serverKey, array $config): string
    {
        $lines = [
            '['.$this->configKey.'.'.$serverKey.']',
        ];

        foreach ($config as $key => $value) {
            if ($key === 'env' && is_array($value)) {
                continue;
            }

            $lines[] = $key.' = '.$this->formatValue($value);
        }

        if (isset($config['env']) && is_array($config['env']) && $config['env'] !== []) {
            $lines[] = '';
            $lines[] = '['.$this->configKey.'.'.$serverKey.'.env]';

            foreach ($config['env'] as $key => $value) {
                $lines[] = $key.' = '.$this->formatValue($value);
            }
        }

        return implode("\n", $lines);
    }

    private function formatValue(mixed $value): string
    {
        if (is_string($value)) {
            return '"'.strtr($value, [
                '\\' => '\\\\',
                '"' => '\\"',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
            ]).'"';
        }

        if (is_array($value)) {
            return '['.implode(', ', array_map($this->formatValue(...), $value)).']';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
