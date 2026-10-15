<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;
use InvalidArgumentException;

/** Bootstrap tool settings as literal data before an analysis backend is selected. */
final class ConfigurationReader
{
    /** @var list<string|array{int, string, int}> */
    private array $tokens;

    private int $offset = 0;

    /** @var array<string, string> */
    private array $imports = [];

    private function __construct(string $source)
    {
        if (strlen($source) > 100000) {
            throw new InvalidArgumentException('Architecture configuration exceeds 100 KB.');
        }
        $this->tokens = array_values(array_filter(token_get_all($source), static fn ($token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)));
    }

    /** @return array<string, mixed> */
    public static function read(string $source): array
    {
        return (new self($source))->configuration();
    }

    /** Decode a quoted literal without evaluating PHP or loading project classes. */
    public static function literal(string $text): string
    {
        if (strtolower($text[0] ?? '') === 'b') {
            $text = substr($text, 1);
        }
        if (str_starts_with($text, "'")) {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], substr($text, 1, -1));
        }
        if (! str_starts_with($text, '"')) {
            throw new InvalidArgumentException('Expected a quoted configuration literal.');
        }

        return self::doubleQuoted(substr($text, 1, -1));
    }

    private static function doubleQuoted(string $value): string
    {
        return preg_replace_callback('/\\\\(?:[nrtvef\\\\$\"]|[0-7]{1,3}|x[0-9a-fA-F]{1,2}|u\{[0-9a-fA-F]+\})/', static function (array $match): string {
            $escape = substr($match[0], 1);
            if (str_starts_with($escape, 'u{')) {
                $code = hexdec(substr($escape, 2, -1));
                if ($code > 0x10FFFF || $code >= 0xD800 && $code <= 0xDFFF) {
                    throw new InvalidArgumentException('Invalid Unicode escape in configuration.');
                }

                return html_entity_decode('&#'.$code.';', ENT_NOQUOTES, 'UTF-8');
            }

            return stripcslashes($match[0]);
        }, $value) ?? $value;
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        $returned = false;
        $settings = null;
        while (($token = $this->peek()) !== null) {
            if (is_array($token) && $token[0] === T_USE && ! $returned) {
                $this->offset++;
                $this->import();
            } elseif (is_array($token) && $token[0] === T_DECLARE && ! $returned) {
                $this->offset++;
                $this->expect('(');
                if ($this->text($this->take()) !== 'strict_types') {
                    throw $this->invalid();
                }
                $this->expect('=');
                if (! in_array($this->text($this->take()), ['0', '1'], true)) {
                    throw $this->invalid();
                }
                $this->expect(')');
                $this->expect(';');
            } elseif (is_array($token) && $token[0] === T_RETURN && ! $returned) {
                $returned = true;
                $this->offset++;
                $settings = $this->expression();
                $this->expect(';');
            } elseif ($token === ';' || is_array($token) && $token[0] === T_CLOSE_TAG && $this->offset === count($this->tokens) - 1) {
                $this->offset++;
            } else {
                throw $this->invalid();
            }
        }
        if (! $returned || ! is_array($settings)) {
            throw new InvalidArgumentException('Historical configuration requires one top-level literal array return.');
        }

        return $settings;
    }

    private function import(): void
    {
        do {
            $name = $this->text($this->take());
            if (! preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\?$/D', $name)) {
                throw $this->invalid();
            }
            $separator = $this->peek();
            if ($separator === '\\' || is_array($separator) && $separator[0] === T_NS_SEPARATOR) {
                $this->offset++;
                $name .= '\\';
            }
            if ($this->peek() === '{') {
                $this->offset++;
                do {
                    $this->importName(rtrim($name, '\\').'\\'.$this->text($this->take()));
                    if ($this->peek() !== ',') {
                        break;
                    }
                    $this->offset++;
                } while ($this->peek() !== '}');
                $this->expect('}');
            } else {
                $this->importName($name);
            }
            if ($this->peek() !== ',') {
                break;
            }
            $this->offset++;
        } while (true);
        $this->expect(';');
    }

    private function importName(string $name): void
    {
        $parts = explode('\\', $name);
        $alias = end($parts);
        $next = $this->peek();
        if (is_array($next) && $next[0] === T_AS) {
            $this->offset++;
            $alias = $this->text($this->take());
        }
        $key = strtolower($alias);
        if (isset($this->imports[$key])) {
            throw new InvalidArgumentException('Duplicate configuration import.');
        }
        $this->imports[$key] = ltrim($name, '\\');
    }

    private function expression(int $depth = 0): mixed
    {
        if ($depth > 64) {
            throw new InvalidArgumentException('Configuration exceeds its nesting budget.');
        }
        $value = $this->primary($depth);
        // Literal concatenation is useful for namespace/path settings. No function calls.
        while ($this->peek() === '.') {
            $this->offset++;
            $right = $this->primary($depth);
            if (! is_scalar($value) || ! is_scalar($right)) {
                throw $this->invalid();
            }
            $value = (string) $value.(string) $right;
        }

        return $value;
    }

    private function primary(int $depth): mixed
    {
        $token = $this->take();
        if ($token === '[' || is_array($token) && $token[0] === T_ARRAY) {
            $end = ']';
            if ($token !== '[') {
                $this->expect('(');
                $end = ')';
            }
            $values = [];
            while ($this->peek() !== $end) {
                $first = $this->expression($depth + 1);
                $next = $this->peek();
                if (is_array($next) && $next[0] === T_DOUBLE_ARROW) {
                    $this->offset++;
                    if (! is_string($first) && ! is_int($first)) {
                        throw $this->invalid();
                    }
                    $values[$first] = $this->expression($depth + 1);
                } else {
                    $values[] = $first;
                }
                if ($this->peek() !== ',') {
                    break;
                }
                $this->offset++;
            }
            $this->expect($end);

            return $values;
        }
        if ($token === '(') {
            $value = $this->expression($depth + 1);
            $this->expect(')');

            return $value;
        }
        if ($token === '-' || $token === '+') {
            $value = $this->primary($depth + 1);
            if (! is_int($value) && ! is_float($value)) {
                throw $this->invalid();
            }

            return $token === '-' ? -$value : $value;
        }
        if (is_array($token)) {
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                return self::literal($token[1]);
            }
            if (in_array($token[0], [T_LNUMBER, T_DNUMBER], true)) {
                $number = str_replace('_', '', $token[1]);
                if ($token[0] === T_DNUMBER) {
                    return (float) $number;
                }

                return preg_match('/^0[0-7]+$/D', $number) ? intval($number, 8) : intval($number, 0);
            }
            if ($token[0] === T_START_HEREDOC) {
                return $this->heredoc($token[1]);
            }
            $name = $token[1];
            if (in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                return match (strtolower($name)) {
                    'true' => true, 'false' => false, default => null
                };
            }
            $next = $this->take();
            if (! is_array($next) || $next[0] !== T_DOUBLE_COLON) {
                throw $this->invalid();
            }
            $constant = $this->text($this->take());
            $parts = explode('\\', ltrim($name, '\\'));
            $class = str_starts_with($name, '\\') ? ltrim($name, '\\') : (($this->imports[strtolower($parts[0])] ?? $parts[0]).(count($parts) > 1 ? '\\'.implode('\\', array_slice($parts, 1)) : ''));
            if (strtolower($constant) === 'class') {
                return $class;
            }
            $cases = match ($class) {
                Architecture::class => Architecture::cases(), MissingTestLevel::class => MissingTestLevel::cases(), default => []
            };
            foreach ($cases as $case) {
                if ($case->name === $constant) {
                    return $case;
                }
            }
        }
        throw $this->invalid();
    }

    private function heredoc(string $opening): string
    {
        $body = '';
        $token = $this->peek();
        if (is_array($token) && $token[0] === T_ENCAPSED_AND_WHITESPACE) {
            $body = $token[1];
            $this->offset++;
        }
        $closing = $this->take();
        if (! is_array($closing) || $closing[0] !== T_END_HEREDOC) {
            throw $this->invalid();
        }
        preg_match('/^[ \t]*/', $closing[1], $match);
        $indent = $match[0];
        if ($indent !== '') {
            $body = preg_replace('/^'.preg_quote($indent, '/').'/m', '', $body) ?? $body;
        }
        $body = preg_replace('/\r?\n$/D', '', $body) ?? $body;

        return str_contains($opening, "'") ? $body : self::doubleQuoted($body);
    }

    /** @return string|array{int, string, int}|null */
    private function peek(): string|array|null
    {
        return $this->tokens[$this->offset] ?? null;
    }

    /** @return string|array{int, string, int} */
    private function take(): string|array
    {
        return $this->tokens[$this->offset++] ?? throw $this->invalid();
    }

    /** @param string|array{int, string, int} $token */
    private function text(string|array $token): string
    {
        return is_array($token) ? $token[1] : $token;
    }

    private function expect(string $value): void
    {
        if ($this->take() !== $value) {
            throw $this->invalid();
        }
    }

    private function invalid(): InvalidArgumentException
    {
        return new InvalidArgumentException('Declared configuration must use literal data or Architecture/MissingTestLevel enum cases; executable or conditional PHP is not allowed.');
    }
}
