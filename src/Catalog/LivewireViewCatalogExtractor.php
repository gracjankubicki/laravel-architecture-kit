<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Reads action selectors from HTML attributes without evaluating browser expressions. */
final class LivewireViewCatalogExtractor
{
    public function extract(FileContext $file): CatalogFacts
    {
        if (! str_ends_with($file->path, '.blade.php') || stripos($file->contents, 'wire:') === false) {
            return new CatalogFacts($file->path);
        }
        if (($reason = ImpactExtractor::sourceLimit(strlen($file->contents))) !== null) {
            return new CatalogFacts($file->path, diagnostics: [new CatalogDiagnostic('source_limit', $reason, 1)]);
        }
        $name = str_starts_with($file->path, 'resources/views/') ? str_replace('/', '.', substr($file->path, strlen('resources/views/'), -strlen('.blade.php'))) : $file->path;
        $owner = CatalogElement::resourceIdentity('view', $name);
        $mask = fn ($match) => preg_replace('/[^\n]/', ' ', $match[0]);
        $input = preg_replace_callback('/\{\{--.*?--\}\}|<!--.*?-->|(?<!@)@verbatim\b.*?@endverbatim\b|(?<!@)@php\b(?!\s*\().*?@endphp\b/s', $mask, $file->contents) ?? $file->contents;
        $html = '';
        foreach (token_get_all($input) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $html .= is_array($token) && $token[0] === T_INLINE_HTML ? $text : (preg_replace('/[^\n]/', ' ', $text) ?? '');
        }
        $html = preg_replace_callback('/<(script|style)\b[^>]*>.*?<\/\1\s*>/si', $mask, $html) ?? $html;
        preg_match_all('/<[a-zA-Z][a-zA-Z0-9:._-]*\b(?:[^>\x22\x27]|\x22[^\x22]*\x22|\x27[^\x27]*\x27)*>/s', $html, $tags, PREG_OFFSET_CAPTURE);
        $elements = $diagnostics = [];
        $visited = 0;
        foreach ($tags[0] as [$tag, $base]) {
            preg_match('/^<[a-zA-Z][a-zA-Z0-9:._-]*/', $tag, $opening);
            $cursor = strlen($opening[0]);
            while (preg_match('/\G\s+([^\s=<>\x22\x27]+)(?:\s*=\s*(?:\x22([^\x22]*)\x22|\x27([^\x27]*)\x27|([^\s>]+)))?/s', $tag, $attribute, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $cursor)) {
                if (++$visited > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                    $diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Livewire view attributes reached their source budget.', 1, $owner);
                    break 2;
                }
                $cursor = $attribute[0][1] + strlen($attribute[0][0]);
                $key = strtolower($attribute[1][0]);
                if (! str_starts_with($key, 'wire:')) {
                    continue;
                }
                $directive = explode('.', substr($key, 5))[0];
                if (in_array($directive, ['snapshot', 'effects', 'model', 'loading', 'ignore', 'id', 'data', 'key', 'target', 'dirty', 'sort', 'confirm', 'transition', 'cloak', 'offline'], true)) {
                    continue;
                }
                if (preg_match('/\A[a-z][a-z0-9_-]{0,127}\z/D', $directive) !== 1) {
                    $diagnostics[] = new CatalogDiagnostic('livewire_view_analysis', 'Livewire directive name is dynamic or unsupported.', 1, $owner);

                    continue;
                }
                $value = $attribute[2][0] ?? $attribute[3][0] ?? $attribute[4][0] ?? '';
                $method = $this->method($value);
                $offset = $base + $attribute[1][1];
                $line = substr_count(substr($file->contents, 0, $offset), "\n") + 1;
                $elements[] = new CatalogElement(CatalogElement::identity($file->path, 'livewire-view-action', $key, $offset), 'Livewire '.$directive.' '.($method ?? 'dynamic'),
                    'livewire-view-action', $line, $line, $offset, $owner, metadata: ['directive' => $directive, 'method' => $method, 'resolved' => $method !== null]);
                if ($method === null) {
                    $diagnostics[] = new CatalogDiagnostic('livewire_view_analysis', 'Livewire action contains a dynamic or unsupported browser expression.', $line, $owner);
                }
            }
        }

        return new CatalogFacts($file->path, $elements, [], $diagnostics);
    }

    private function method(string $expression): ?string
    {
        $expression = trim($expression);
        if (strlen($expression) > 10000 || ! preg_match('/\A([a-zA-Z_][a-zA-Z0-9_]{0,255})(.*)\z/sD', $expression, $match)) {
            return null;
        }
        $tail = trim($match[2]);
        if ($tail === '') {
            return $match[1];
        }
        if (! str_starts_with($tail, '(')) {
            return null;
        }
        $stack = [];
        $quote = null;
        for ($position = 0; $position < strlen($tail); $position++) {
            $character = $tail[$position];
            if ($quote !== null) {
                if ($character === '\\') {
                    $position++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '`' || substr($tail, $position, 2) === '/*' || substr($tail, $position, 2) === '//') {
                return null;
            } elseif (str_contains('([{', $character)) {
                $stack[] = $character;
                if (count($stack) > 32) {
                    return null;
                }
            } elseif (str_contains(')]}', $character)) {
                if (array_pop($stack) !== [')' => '(', ']' => '[', '}' => '{'][$character]) {
                    return null;
                }
                if ($stack === []) {
                    return $position === strlen($tail) - 1 ? $match[1] : null;
                }
            }
        }

        return null;
    }
}
