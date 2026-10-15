<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Classification;

use GracjanKubicki\ArchitectureKit\Analysis\ConfigurationReader;
use InvalidArgumentException;

/** Declarations opt into data-only configuration, including its other entries. */
final readonly class DeclaredConfiguration
{
    /** @return array<string, mixed>|null Null preserves legacy configuration without declarations. */
    public static function read(string $source): ?array
    {
        if (strlen($source) > 100000) {
            if (! self::hasDeclarationKey($source)) {
                return null;
            }
            throw new InvalidArgumentException('Architecture configuration exceeds 100 KB.');
        }
        if (! self::hasDeclarationKey($source)) {
            return null;
        }
        $config = ConfigurationReader::read($source);
        new ClassificationMappings($config['audit']['classification']);

        return $config;
    }

    /** Decode historical configuration without opting it into runtime configuration semantics.
     * @return array<string, mixed> */
    public static function readSource(string $source): array
    {
        return ConfigurationReader::read($source);
    }

    /** Token scan avoids building an AST for oversized legacy configuration. */
    public static function hasDeclarationKey(string $source): bool
    {
        if (! str_contains($source, 'classification') && ! str_contains($source, '\\')) {
            return false;
        }
        $tokens = self::literalTokens($source);
        $stack = [];
        $pending = null;
        $arrayOpening = false;
        foreach ($tokens as $i => $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_RETURN) {
                $pending = 'root';

                continue;
            }
            if (is_array($token) && $token[0] === T_ARRAY) {
                $arrayOpening = true;

                continue;
            }
            if ($text === '[' || $text === '(' || $text === '{') {
                if ($text === '(' && ! $arrayOpening) {
                    $stack[] = 'group';

                    continue;
                }
                $stack[] = $pending ?? 'other';
                $pending = null;
                $arrayOpening = false;

                continue;
            }
            if ($text === ']' || $text === ')' || $text === '}') {
                if (array_pop($stack) !== 'group') {
                    $pending = null;
                }

                continue;
            }
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $key = ConfigurationReader::literal($text);
                $nextIndex = $i + 1;
                $frame = null;
                foreach (array_reverse($stack) as $level) {
                    if ($level !== 'group') {
                        $frame = $level;
                        break;
                    }
                    if (($tokens[$nextIndex] ?? null) === ')') {
                        $nextIndex++;
                    }
                }
                $next = $tokens[$nextIndex] ?? null;
                if (is_array($next) && $next[0] === T_DOUBLE_ARROW) {
                    if ($frame === 'audit' && $key === 'classification') {
                        return true;
                    }
                    if ($frame === 'root' && $key === 'audit') {
                        $pending = 'audit';

                        continue;
                    }
                }
            }
            if (! is_array($token) || $token[0] !== T_DOUBLE_ARROW) {
                $pending = null;
            }
        }

        return false;
    }

    /** Normalize literal heredoc/nowdoc with the parser's own string semantics.
     * @return list<string|array{int, string, int}>
     */
    private static function literalTokens(string $source): array
    {
        $raw = array_values(array_filter(token_get_all($source), static fn ($token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $tokens = [];
        for ($i = 0; $i < count($raw); $i++) {
            $token = $raw[$i];
            if (is_array($token) && $token[0] === T_START_HEREDOC) {
                $end = $i + 1;
                $literal = $token[1];
                if (isset($raw[$end]) && is_array($raw[$end]) && $raw[$end][0] === T_ENCAPSED_AND_WHITESPACE) {
                    $literal .= $raw[$end][1];
                    $end++;
                }
                if (isset($raw[$end]) && is_array($raw[$end]) && $raw[$end][0] === T_END_HEREDOC) {
                    $literal .= $raw[$end][1];
                    try {
                        $value = ConfigurationReader::read('<?php return ['.$literal.'];')[0] ?? null;
                        if (is_string($value)) {
                            $tokens[] = [T_CONSTANT_ENCAPSED_STRING, var_export($value, true), $token[2]];
                            $i = $end;

                            continue;
                        }
                    } catch (InvalidArgumentException) {
                        // A dynamic string is not a literal declaration key.
                    }
                }
            }
            $tokens[] = $token;
        }

        return $tokens;
    }
}
