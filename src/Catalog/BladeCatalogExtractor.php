<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;

/** Reads Blade as source. It never invokes the Blade compiler or renders a template. */
final class BladeCatalogExtractor
{
    /** Preserve newlines when hiding comments or exposing PHP expressions. */
    public static function phpSource(FileContext $file): FileContext
    {
        return (new BladePhpSource)->prepare($file);
    }

    public function extract(FileContext $file): CatalogFacts
    {
        if (! str_ends_with($file->path, '.blade.php')) {
            return new CatalogFacts($file->path);
        }
        $fileId = CatalogElement::identity($file->path, 'file', $file->path);
        $name = str_starts_with($file->path, 'resources/views/') ? str_replace('/', '.', substr($file->path, strlen('resources/views/'), -strlen('.blade.php'))) : $file->path;
        $viewId = CatalogElement::resourceIdentity('view', $name);
        $metadata = ['logical_resource' => true, 'view_source' => true];
        // Livewire's single-file compiler selects only the first native PHP portion.
        if (preg_match('/<\?php\s*.*?\s*\?>/s', $file->contents, $portion, PREG_OFFSET_CAPTURE)) {
            $metadata['livewire_class_portion'] = ['start' => $portion[0][1], 'end' => $portion[0][1] + strlen($portion[0][0])];
        }
        $elements = [$viewId => new CatalogElement($viewId, $name, 'view', 1, substr_count($file->contents, "\n") + 1, 0, metadata: $metadata)];
        $relations = [new CatalogRelation($viewId, $fileId, 'view-source', 1, substr_count($file->contents, "\n") + 1)];
        $diagnostics = [];
        $source = '';
        foreach (token_get_all($file->contents) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $source .= is_array($token) && $token[0] === T_INLINE_HTML ? $text : (preg_replace('/[^\n]/', ' ', $text) ?? '');
        }
        $source = preg_replace_callback('/\{\{--.*?--\}\}|(?<!@)(?:\{!!.*?!!\}|\{\{.*?\}\})/s', fn ($match) => preg_replace('/[^\n]/', ' ', $match[0]), $source) ?? $source;
        preg_match_all('/(?<!@)@(extends|includeIf|includeWhen|includeUnless|includeFirst|include|component|each)\s*\(/i', $source, $directives, PREG_OFFSET_CAPTURE);
        foreach ($directives[0] as $position => [$matched, $offset]) {
            if (count($relations) >= 10000) {
                $diagnostics[] = new CatalogDiagnostic('blade_limit', 'Blade relationship budget reached.', 1, $viewId);
                break;
            }
            $directive = strtolower($directives[1][$position][0]);
            $arguments = $this->arguments($source, $offset + strlen($matched));
            $argument = $arguments[in_array($directive, ['includewhen', 'includeunless'], true) ? 1 : 0] ?? '';
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            if (! preg_match('/^\s*([\x22\x27])([a-zA-Z0-9_.:\/-]+)\1\s*$/', $argument, $literal)) {
                $diagnostics[] = new CatalogDiagnostic('dynamic_view', 'Blade view target is dynamic or uses an unsupported expression.', $line, $viewId);

                continue;
            }
            $target = $literal[2];
            $targetId = CatalogElement::resourceIdentity('view', $target);
            $elements[$targetId] ??= new CatalogElement($targetId, $target, 'view', $line, $line, $offset, metadata: ['logical_resource' => true]);
            $relations[] = new CatalogRelation($viewId, $targetId, $directive === 'extends' ? 'extends-view' : 'includes-view', $line, $line,
                in_array($directive, ['includeif', 'includewhen', 'includeunless'], true) ? 'conditional' : 'resolved', ['directive' => $directive]);
        }
        preg_match_all('/<x-([a-zA-Z0-9_.:-]+)\b/', $source, $tags, PREG_OFFSET_CAPTURE);
        foreach ($tags[1] as [$tag, $offset]) {
            if (count($relations) >= 10000) {
                $diagnostics[] = new CatalogDiagnostic('blade_limit', 'Blade relationship budget reached.', 1, $viewId);
                break;
            }
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            if ($tag === 'dynamic-component') {
                $diagnostics[] = new CatalogDiagnostic('dynamic_component', 'Dynamic Blade component needs source inspection.', $line, $viewId);

                continue;
            }
            $id = CatalogElement::resourceIdentity('blade-component-tag', $tag);
            $elements[$id] ??= new CatalogElement($id, $tag, 'blade-component-tag', $line, $line, $offset, metadata: ['logical_resource' => true]);
            $relations[] = new CatalogRelation($viewId, $id, 'renders-component', $line, $line);
        }

        $livewire = (new LivewireViewCatalogExtractor)->extract($file);

        return new CatalogFacts($file->path, [...array_values($elements), ...$livewire->elements], $relations, [...$diagnostics, ...$livewire->diagnostics]);
    }

    /** @return list<string> */
    private function arguments(string $source, int $start): array
    {
        $stack = [];
        $quote = null;
        $parts = [];
        $current = '';
        for ($i = $start; $i < strlen($source) && $i - $start < 10000; $i++) {
            $character = $source[$i];
            if ($quote !== null) {
                $current .= $character;
                if ($character === '\\' && $i + 1 < strlen($source)) {
                    $current .= $source[++$i];
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif (in_array($character, ['(', '[', '{'], true)) {
                $stack[] = $character;
                if (count($stack) > 32) {
                    return [];
                }
            } elseif (in_array($character, [')', ']', '}'], true)) {
                if ($stack === [] && $character === ')') {
                    $parts[] = $current;

                    return $parts;
                }
                $opening = array_pop($stack);
                if ($opening !== [')' => '(', ']' => '[', '}' => '{'][$character]) {
                    return [];
                }
            } elseif ($character === ',' && $stack === []) {
                $parts[] = $current;
                $current = '';

                continue;
            }
            $current .= $character;
        }

        return [];
    }
}
