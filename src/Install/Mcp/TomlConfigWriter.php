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
    ) {
    }

    /**
     * @param  array<string, mixed>  $serverConfig
     */
    public function render(string $serverKey, array $serverConfig): ?string
    {
        $section = $this->buildSection($serverKey, $serverConfig);

        if (! $this->files->exists($this->path) || $this->files->size($this->path) < 3) {
            return $section."\n";
        }

        $content = $this->files->get($this->path);
        $header = '['.$this->configKey.'.'.$serverKey.']';

        if (substr_count($content, '[') !== substr_count($content, ']')) {
            return null;
        }

        if (! preg_match('/^'.preg_quote($header, '/').'\s*$/m', $content, $match, PREG_OFFSET_CAPTURE)) {
            return rtrim($content)."\n\n".$section."\n";
        }

        $start = $match[0][1];
        $afterHeader = $start + strlen($match[0][0]);
        $nextSectionOffset = preg_match('/^\[.+\]\s*$/m', $content, $nextMatch, PREG_OFFSET_CAPTURE, $afterHeader)
            ? $nextMatch[0][1]
            : strlen($content);

        $currentSection = substr($content, $start, $nextSectionOffset - $start);

        if (! str_contains(substr($currentSection, strlen($match[0][0])), 'architecture-kit')) {
            return null;
        }

        $before = rtrim(substr($content, 0, $start));
        $after = ltrim(substr($content, $nextSectionOffset));

        return ($before !== '' ? $before."\n\n" : '')
            .$section."\n"
            .($after !== '' ? "\n".$after : '');
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
