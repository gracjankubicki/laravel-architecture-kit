<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Config;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\ArchitectureCatalog;
use GracjanKubicki\ArchitectureKit\Audit\CustomRuleSet;
use GracjanKubicki\ArchitectureKit\Install\RuntimeResolver;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

final class ArchitectureConfig
{
    private readonly ArchitectureCatalog $catalog;

    /** @var array<string, mixed>|null */
    private ?array $loadedConfig = null;

    private bool $configLoaded = false;

    public function __construct(
        private readonly string $path,
        private readonly Filesystem $files = new Filesystem,
        ?ArchitectureCatalog $catalog = null,
    ) {
        $this->catalog = $catalog ?? new ArchitectureCatalog($this->files, $this->projectPath());
    }

    /**
     * @return array<int, Architecture|string>
     */
    public function readOrDefault(): array
    {
        if (! $this->files->exists($this->path)) {
            return Architecture::defaultSelection();
        }

        $config = $this->config();

        return $this->normalizeConfig($config['enabled'] ?? []);
    }

    /**
     * @return array<int, Architecture|string>
     */
    public function read(): array
    {
        if (! $this->files->exists($this->path)) {
            throw new InvalidArgumentException('config/architectures.php does not exist.');
        }

        $config = $this->config();

        return $this->normalizeConfig($config['enabled'] ?? []);
    }

    /**
     * @return array<int, string>
     */
    public function auditExcludes(): array
    {
        $config = $this->config();
        $exclude = $config['audit']['exclude'] ?? [];

        if (! is_array($exclude)) {
            throw new InvalidArgumentException('config/architectures.php audit.exclude must be an array.');
        }

        return array_values(array_filter($exclude, 'is_string'));
    }

    public function customRuleSet(): CustomRuleSet
    {
        $config = $this->config();

        if ($config === []) {
            return new CustomRuleSet;
        }

        $rules = $config['rules'] ?? [];

        if (! is_array($rules)) {
            throw new InvalidArgumentException('config/architectures.php rules must be an array.');
        }

        $ruleSet = CustomRuleSet::fromConfig($rules);
        $enabled = $this->normalizeConfig($config['enabled'] ?? []);
        $unknown = $ruleSet->unknownScopedSlugs($this->catalog->slugs($enabled));

        if ($unknown !== []) {
            $slug = $unknown[0];

            throw new InvalidArgumentException("config/architectures.php rules.{$slug} references an unknown architecture. Enable it or add .architecture-kit/architectures/{$slug}/guideline.md.");
        }

        return $ruleSet;
    }

    /**
     * @return array{driver: string, service: string|null, php: string, command: array<int, string>|null}
     */
    public function runtime(): array
    {
        $config = $this->config();

        if (! isset($config['runtime'])) {
            return (new RuntimeResolver)->runtime();
        }

        if (! is_array($config['runtime'])) {
            throw new InvalidArgumentException('config/architectures.php runtime must be an array.');
        }

        return (new RuntimeResolver($config['runtime']))->runtime();
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        if ($this->configLoaded) {
            return $this->loadedConfig ?? [];
        }

        if (! $this->files->exists($this->path)) {
            $this->configLoaded = true;

            return $this->loadedConfig = [];
        }

        $config = require $this->path;

        if (! is_array($config)) {
            throw new InvalidArgumentException('config/architectures.php must return an array.');
        }

        $this->configLoaded = true;

        return $this->loadedConfig = $config;
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function render(array $enabled, ?array $runtime = null): string
    {
        if ($this->files->exists($this->path)) {
            $contents = $this->files->get($this->path);
            $updated = $this->replaceEnabledBlock($contents, $enabled);

            if ($updated === null) {
                throw new InvalidArgumentException('Architecture Kit cannot safely update config/architectures.php because its top-level enabled entry is not a short array. Update the file manually, then run the installer again.');
            }

            if ($runtime === null) {
                return $updated;
            }

            $updated = $this->replaceOrInsertRuntimeBlock($this->removeTopLevelArrayBlock($updated, 'agents'), $runtime);

            if ($updated === null) {
                throw new InvalidArgumentException('Architecture Kit cannot safely update config/architectures.php because its top-level runtime entry is not a short array. Update the file manually, then run the installer again.');
            }

            return $updated;
        }

        return $this->renderFresh($enabled, $runtime);
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @param  array<string, mixed>|null  $runtime
     */
    private function renderFresh(array $enabled, ?array $runtime = null): string
    {
        $lines = [
            '<?php',
            '',
            'use GracjanKubicki\\ArchitectureKit\\Architecture;',
            '',
            'return [',
            "    'enabled' => [",
        ];

        foreach ($this->order($enabled) as $architecture) {
            $lines[] = '        '.$this->renderArchitectureEntry($architecture).',';
        }

        $lines[] = '    ],';

        if ($runtime !== null) {
            $lines[] = $this->renderRuntimeBlock($runtime, '    ');
        }

        $lines[] = '];';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    private function replaceEnabledBlock(string $contents, array $enabled): ?string
    {
        return $this->replaceEnabledBlockUsingTokens($contents, $enabled);
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function replaceOrInsertRuntimeBlock(string $contents, array $runtime): ?string
    {
        $normalized = (new RuntimeResolver($runtime))->runtime();
        $range = $this->topLevelArrayBlockRange($contents, 'runtime');

        if ($range !== null) {
            return substr_replace(
                $contents,
                $this->renderRuntimeBlock($normalized, $this->indentForOffset($contents, $range['start'])),
                $range['start'],
                $range['end'] - $range['start'],
            );
        }

        if ($this->topLevelKeyExists($contents, 'runtime')) {
            return null;
        }

        $returnArrayEnd = $this->returnArrayEndOffset($contents);

        if ($returnArrayEnd === null) {
            return null;
        }

        return rtrim(substr($contents, 0, $returnArrayEnd))
            ."\n".$this->renderRuntimeBlock($normalized, '    ')."\n"
            .substr($contents, $returnArrayEnd);
    }

    private function removeTopLevelArrayBlock(string $contents, string $key): string
    {
        $range = $this->topLevelArrayBlockRange($contents, $key);

        if ($range === null) {
            return $contents;
        }

        return substr_replace($contents, '', $range['start'], $range['end'] - $range['start']);
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    private function replaceEnabledBlockUsingTokens(string $contents, array $enabled): ?string
    {
        $range = $this->enabledBlockRange($contents);

        if ($range === null) {
            return null;
        }

        $replacement = $this->renderEnabledBlock($enabled, $range['indent']);

        if ($range['prefix_newline']) {
            $replacement = "\n".$replacement;
        }

        if ($range['suffix_newline']) {
            $replacement .= "\n".$range['suffix_indent'];
        }

        return substr_replace(
            $contents,
            $replacement,
            $range['start'],
            $range['end'] - $range['start'],
        );
    }

    /**
     * @return array{start: int, end: int, indent: string, prefix_newline: bool, suffix_newline: bool, suffix_indent: string}|null
     */
    private function enabledBlockRange(string $contents): ?array
    {
        $tokens = $this->tokensWithOffsets($contents);
        $arrayDepth = 0;
        $waitingForReturnArray = false;
        $returnArrayDepth = null;

        foreach ($tokens as $index => $token) {
            $text = $token['text'];

            if ($token['id'] === T_RETURN) {
                $waitingForReturnArray = true;

                continue;
            }

            if ($waitingForReturnArray && ! $this->isIgnorableToken($token)) {
                $waitingForReturnArray = $text === '[';
            }

            if (
                $returnArrayDepth !== null
                && $arrayDepth === $returnArrayDepth
                && $this->isStringKeyToken($token, 'enabled')
                && $this->nextSignificantTokenText($tokens, $index) === '=>'
            ) {
                $valueIndex = $this->nextSignificantTokenIndex(
                    $tokens,
                    $this->nextSignificantTokenIndex($tokens, $index) ?? $index,
                );

                if ($valueIndex === null || $tokens[$valueIndex]['text'] !== '[') {
                    continue;
                }

                $valueEnd = $this->matchingShortArrayEnd($tokens, $valueIndex);

                if ($valueEnd === null) {
                    return null;
                }

                return $this->rangeFromOffsets($contents, $token['offset'], $valueEnd);
            }

            if ($text === '[') {
                $arrayDepth++;

                if ($waitingForReturnArray) {
                    $returnArrayDepth = $arrayDepth;
                    $waitingForReturnArray = false;
                }

                continue;
            }

            if ($text === ']') {
                $arrayDepth--;

                if ($returnArrayDepth !== null && $arrayDepth < $returnArrayDepth) {
                    $returnArrayDepth = null;
                }
            }
        }

        return null;
    }

    /**
     * @return array{start: int, end: int}|null
     */
    private function topLevelArrayBlockRange(string $contents, string $key): ?array
    {
        $tokens = $this->tokensWithOffsets($contents);
        $arrayDepth = 0;
        $waitingForReturnArray = false;
        $returnArrayDepth = null;

        foreach ($tokens as $index => $token) {
            $text = $token['text'];

            if ($token['id'] === T_RETURN) {
                $waitingForReturnArray = true;

                continue;
            }

            if ($waitingForReturnArray && ! $this->isIgnorableToken($token)) {
                $waitingForReturnArray = $text === '[';
            }

            if (
                $returnArrayDepth !== null
                && $arrayDepth === $returnArrayDepth
                && $this->isStringKeyToken($token, $key)
                && $this->nextSignificantTokenText($tokens, $index) === '=>'
            ) {
                $valueIndex = $this->nextSignificantTokenIndex(
                    $tokens,
                    $this->nextSignificantTokenIndex($tokens, $index) ?? $index,
                );

                if ($valueIndex === null || $tokens[$valueIndex]['text'] !== '[') {
                    continue;
                }

                $valueEnd = $this->matchingShortArrayEnd($tokens, $valueIndex);

                if ($valueEnd === null) {
                    return null;
                }

                $start = $token['offset'];
                $end = $this->consumeTrailingComma($contents, $valueEnd);

                return [
                    'start' => $start,
                    'end' => $end,
                ];
            }

            if ($text === '[') {
                $arrayDepth++;

                if ($waitingForReturnArray) {
                    $returnArrayDepth = $arrayDepth;
                    $waitingForReturnArray = false;
                }

                continue;
            }

            if ($text === ']') {
                $arrayDepth--;

                if ($returnArrayDepth !== null && $arrayDepth < $returnArrayDepth) {
                    $returnArrayDepth = null;
                }
            }
        }

        return null;
    }

    private function topLevelKeyExists(string $contents, string $key): bool
    {
        $tokens = $this->tokensWithOffsets($contents);
        $arrayDepth = 0;
        $waitingForReturnArray = false;
        $returnArrayDepth = null;

        foreach ($tokens as $index => $token) {
            $text = $token['text'];

            if ($token['id'] === T_RETURN) {
                $waitingForReturnArray = true;

                continue;
            }

            if ($waitingForReturnArray && ! $this->isIgnorableToken($token)) {
                $waitingForReturnArray = $text === '[';
            }

            if (
                $returnArrayDepth !== null
                && $arrayDepth === $returnArrayDepth
                && $this->isStringKeyToken($token, $key)
                && $this->nextSignificantTokenText($tokens, $index) === '=>'
            ) {
                return true;
            }

            if ($text === '[') {
                $arrayDepth++;

                if ($waitingForReturnArray) {
                    $returnArrayDepth = $arrayDepth;
                    $waitingForReturnArray = false;
                }

                continue;
            }

            if ($text === ']') {
                $arrayDepth--;

                if ($returnArrayDepth !== null && $arrayDepth < $returnArrayDepth) {
                    $returnArrayDepth = null;
                }
            }
        }

        return false;
    }

    private function returnArrayEndOffset(string $contents): ?int
    {
        $tokens = $this->tokensWithOffsets($contents);

        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_RETURN) {
                continue;
            }

            $arrayIndex = $this->nextSignificantTokenIndex($tokens, $index);

            if ($arrayIndex === null || $tokens[$arrayIndex]['text'] !== '[') {
                continue;
            }

            $end = $this->matchingShortArrayEnd($tokens, $arrayIndex);

            return $end === null ? null : $end - 1;
        }

        return null;
    }

    /**
     * @return array<int, array{id: int|null, text: string, offset: int}>
     */
    private function tokensWithOffsets(string $contents): array
    {
        $tokens = [];
        $offset = 0;

        foreach (token_get_all($contents) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $tokens[] = [
                'id' => is_array($token) ? $token[0] : null,
                'text' => $text,
                'offset' => $offset,
            ];
            $offset += strlen($text);
        }

        return $tokens;
    }

    /**
     * @param  array{id: int|null, text: string, offset: int}  $token
     */
    private function isIgnorableToken(array $token): bool
    {
        return in_array($token['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @param  array{id: int|null, text: string, offset: int}  $token
     */
    private function isStringKeyToken(array $token, string $key): bool
    {
        if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING) {
            return false;
        }

        $text = $token['text'];
        $quote = $text[0] ?? '';

        if ($quote !== "'" && $quote !== '"') {
            return false;
        }

        return stripcslashes(substr($text, 1, -1)) === $key;
    }

    /**
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     */
    private function nextSignificantTokenText(array $tokens, int $index): ?string
    {
        $next = $this->nextSignificantTokenIndex($tokens, $index);

        return $next === null ? null : $tokens[$next]['text'];
    }

    /**
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     */
    private function nextSignificantTokenIndex(array $tokens, int $index): ?int
    {
        for ($next = $index + 1; $next < count($tokens); $next++) {
            if (! $this->isIgnorableToken($tokens[$next])) {
                return $next;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{id: int|null, text: string, offset: int}>  $tokens
     */
    private function matchingShortArrayEnd(array $tokens, int $startIndex): ?int
    {
        $depth = 0;

        for ($index = $startIndex; $index < count($tokens); $index++) {
            if ($tokens[$index]['text'] === '[') {
                $depth++;

                continue;
            }

            if ($tokens[$index]['text'] !== ']') {
                continue;
            }

            $depth--;

            if ($depth === 0) {
                return $tokens[$index]['offset'] + strlen($tokens[$index]['text']);
            }
        }

        return null;
    }

    /**
     * @return array{start: int, end: int, indent: string, prefix_newline: bool, suffix_newline: bool, suffix_indent: string}
     */
    private function rangeFromOffsets(string $contents, int $start, int $valueEnd): array
    {
        $indent = $this->indentForOffset($contents, $start);
        $prefixNewline = $this->needsPrefixNewline($contents, $start);
        $end = $this->consumeTrailingComma($contents, $valueEnd);
        $next = $contents[$end] ?? '';
        $suffixNewline = $next !== '' && $next !== "\n" && $next !== "\r";

        return [
            'start' => $start,
            'end' => $end,
            'indent' => $indent,
            'prefix_newline' => $prefixNewline,
            'suffix_newline' => $suffixNewline,
            'suffix_indent' => $next === ']' ? '' : $indent,
        ];
    }

    private function indentForOffset(string $contents, int $offset): string
    {
        $lineStart = strrpos(substr($contents, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $before = substr($contents, $lineStart, $offset - $lineStart);

        return trim($before) === '' ? $before : '    ';
    }

    private function needsPrefixNewline(string $contents, int $offset): bool
    {
        $lineStart = strrpos(substr($contents, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;

        return trim(substr($contents, $lineStart, $offset - $lineStart)) !== '';
    }

    private function consumeTrailingComma(string $contents, int $offset): int
    {
        $cursor = $this->consumeInlineWhitespace($contents, $offset);

        if (($contents[$cursor] ?? '') !== ',') {
            return $offset;
        }

        return $this->consumeInlineWhitespace($contents, $cursor + 1);
    }

    private function consumeInlineWhitespace(string $contents, int $offset): int
    {
        $cursor = $offset;

        while (($contents[$cursor] ?? '') === ' ' || ($contents[$cursor] ?? '') === "\t") {
            $cursor++;
        }

        return $cursor;
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    private function renderEnabledBlock(array $enabled, string $indent): string
    {
        $lines = [
            $indent."'enabled' => [",
        ];

        foreach ($this->order($enabled) as $architecture) {
            $lines[] = $indent.'    '.$this->renderArchitectureEntry($architecture).',';
        }

        $lines[] = $indent.'],';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $runtime
     */
    private function renderRuntimeBlock(array $runtime, string $indent): string
    {
        $runtime = (new RuntimeResolver($runtime))->runtime();
        $lines = [
            $indent."'runtime' => [",
            $indent."    'driver' => ".var_export($runtime['driver'], true).',',
        ];

        if ($runtime['service'] !== null) {
            $lines[] = $indent."    'service' => ".var_export($runtime['service'], true).',';
        }

        $lines[] = $indent."    'php' => ".var_export($runtime['php'], true).',';

        if ($runtime['driver'] === 'custom') {
            $lines[] = $indent."    'command' => ".$this->renderStringArray($runtime['command'] ?? [], $indent.'    ').',';
        } else {
            $lines[] = $indent."    'command' => null,";
        }

        $lines[] = $indent.'],';

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $values
     */
    private function renderStringArray(array $values, string $indent): string
    {
        if ($values === []) {
            return '[]';
        }

        $lines = ['['];

        foreach ($values as $value) {
            $lines[] = $indent.'    '.var_export($value, true).',';
        }

        $lines[] = $indent.']';

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function write(array $enabled, ?array $runtime = null): void
    {
        $this->files->ensureDirectoryExists(dirname($this->path));
        $this->files->put($this->path, $this->render($enabled, $runtime));
        $this->loadedConfig = null;
        $this->configLoaded = false;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, Architecture|string>
     */
    public function normalize(array $values): array
    {
        return $this->normalizeValues($values, allowStrings: true);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, Architecture|string>
     */
    private function normalizeConfig(array $values): array
    {
        return $this->normalizeValues($values, allowStrings: true);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, Architecture|string>
     */
    private function normalizeValues(array $values, bool $allowStrings): array
    {
        $architectures = [];

        foreach ($values as $value) {
            $architecture = match (true) {
                $value instanceof Architecture => $value,
                is_string($value) => Architecture::tryFrom($value) ?? $this->customSlug($value, $allowStrings),
                default => throw new InvalidArgumentException('Architecture entries must be Architecture enum cases.'),
            };

            $key = $architecture instanceof Architecture ? $architecture->value : $architecture;
            $architectures[$key] = $architecture;
        }

        if ($architectures === []) {
            throw new InvalidArgumentException('At least one architecture must be enabled.');
        }

        return $this->order(array_values($architectures));
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, Architecture|string>
     */
    private function order(array $enabled): array
    {
        return array_map(
            fn ($architecture): Architecture|string => $architecture->value,
            $this->catalog->ordered($enabled),
        );
    }

    private function renderArchitectureEntry(Architecture|string $architecture): string
    {
        return $architecture instanceof Architecture
            ? 'Architecture::'.$architecture->name
            : var_export($architecture, true);
    }

    private function customSlug(string $value, bool $allowStrings): string
    {
        if (! $allowStrings) {
            throw new InvalidArgumentException('Architecture entries must be Architecture enum cases or custom architecture slugs.');
        }

        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
            throw new InvalidArgumentException("Custom architecture slug [{$value}] must be kebab-case.");
        }

        return $value;
    }

    private function projectPath(): string
    {
        $directory = dirname($this->path);

        return basename($directory) === 'config'
            ? dirname($directory)
            : $directory;
    }
}
