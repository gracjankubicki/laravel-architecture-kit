<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\BasicSource;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\SourceMap;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Piecewise byte mapping keeps copied expressions at their original source positions. */
final class BladePhpSource
{
    public const MAPPING_VERSION = 'blade-php-v1';

    private string $source = '';

    /** @var list<array{start: int, original: int, length: int, copied: bool}> */
    private array $segments = [];

    public function prepare(FileContext $file): FileContext
    {
        if (ImpactExtractor::sourceLimit(strlen($file->contents)) !== null) {
            return $file;
        }
        $input = $this->input($file);
        if ($input->mappingFingerprint === '') {
            return $file;
        }
        $parsed = $file->withContents($input->prepared->contents);
        $nodes = $parsed->syntax();
        if ($nodes === null) {
            return $parsed;
        }
        $map = new SourceMap($input->original, $this->original(...));
        $nodes = array_map($map->node(...), $nodes);

        return $file->withSyntax($nodes);
    }

    /** Prepare bytes and mapping without invoking either parser. */
    public function input(FileContext $file): BasicSource
    {
        $source = new SourceInput($file->path, $file->contents, $file->analysisSession()->phpVersion);
        $this->source = '';
        $this->segments = [];
        // Hide source comments before tokenization so their PHP tags cannot introduce declarations.
        $input = preg_replace_callback('/\{\{--.*?--\}\}|(?<!@)@verbatim\b.*?@endverbatim\b/s',
            fn ($match) => preg_replace('/[^\n]/', ' ', $match[0]), $file->contents) ?? $file->contents;
        $offset = 0;
        foreach (token_get_all($input) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_INLINE_HTML) {
                $this->html($text, $offset);
            } else {
                $this->append($text, $offset);
            }
            $offset += strlen($text);
        }
        if ($this->source === $file->contents) {
            return BasicSource::plain($source);
        }

        return new BasicSource($source, new SourceInput($file->path, $this->source, $source->phpVersion), $this->segments, self::MAPPING_VERSION);
    }

    private function html(string $text, int $base): void
    {
        preg_match_all('/(?<!@)(?:@php\b(?!\s*\()(.*?)(?<!@)@endphp\b|@php\b(?!\s*\()|@endphp\b|\{!!(.*?)!!\}|\{\{(.*?)\}\})/si', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $cursor = 0;
        foreach ($matches as $match) {
            [$whole, $offset] = $match[0];
            $this->append(substr($text, $cursor, $offset - $cursor), $base + $cursor);
            if (($match[1][0] ?? null) !== null) {
                $this->append('<?php ', $base + $offset, false);
                $this->append($match[1][0], $base + $match[1][1]);
                $this->append(' ?>', $base + $offset + strlen($whole) - strlen('@endphp'), false);
            } elseif (strtolower($whole) === '@php') {
                $this->append('<?php ', $base + $offset, false);
            } elseif (strtolower($whole) === '@endphp') {
                $this->append(' ?>', $base + $offset, false);
            } else {
                $expression = $match[2][0] !== null ? $match[2] : $match[3];
                $this->append('<?php ', $base + $offset, false);
                $this->append($expression[0], $base + $expression[1]);
                $this->append('; ?>', $base + $offset + strlen($whole) - ($match[2][0] !== null ? 3 : 2), false);
            }
            $cursor = $offset + strlen($whole);
        }
        $this->append(substr($text, $cursor), $base + $cursor);
    }

    private function append(string $text, int $original, bool $copied = true): void
    {
        if ($text === '') {
            return;
        }
        $this->segments[] = ['start' => strlen($this->source), 'original' => $original, 'length' => strlen($text), 'copied' => $copied];
        $this->source .= $text;
    }

    private function original(int $position): int
    {
        $low = 0;
        $high = count($this->segments) - 1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $segment = $this->segments[$middle];
            if ($position < $segment['start']) {
                $high = $middle - 1;
            } elseif ($position >= $segment['start'] + $segment['length']) {
                $low = $middle + 1;
            } else {
                return $segment['original'] + ($segment['copied'] ? $position - $segment['start'] : 0);
            }
        }

        return $position;
    }
}
