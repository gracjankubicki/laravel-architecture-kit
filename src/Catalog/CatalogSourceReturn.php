<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\DataCatalog;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/** Deferred literal decoding retains source spans, never generic returned payloads. */
final class CatalogSourceReturn
{
    /** @return array<int, array<string, mixed>> */
    public static function extract(FileContext $file): array
    {
        $rows = [];
        $hash = hash('sha256', $file->contents);
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Stmt\ClassLike::class) as $class) {
            foreach ($class->getMethods() as $method) {
                $body = $method->stmts ?? [];
                if (count($body) !== 1 || ! $body[0] instanceof Stmt\Return_ || $body[0]->expr === null) {
                    continue;
                }
                $visits = 0;
                $value = self::expression($body[0]->expr, $file, DataCatalog::name($class, $file), $visits);
                if ($value === null && $visits > 1024) {
                    $value = ['kind' => 'limited'];
                }
                if ($value !== null) {
                    $rows[$method->getStartFilePos()] = ['hash' => $hash, 'value' => $value];
                }
            }
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private static function expression(Node $node, FileContext $file, string $class, int &$visits, int $depth = 0): ?array
    {
        if (++$visits > 1024 || $depth > 16) {
            $visits = 1025;

            return null;
        }
        if ($node instanceof Node\Scalar\String_) {
            if ($node->getStartFilePos() < 0 || $node->getEndFilePos() - $node->getStartFilePos() > 100000) {
                $visits = 1025;

                return null;
            }

            return ['kind' => 'string', 'start' => $node->getStartFilePos(), 'end' => $node->getEndFilePos()];
        }
        if ($node instanceof Expr\ConstFetch && strtolower($node->name->toString()) === 'null') {
            return ['kind' => 'null'];
        }
        if ($node instanceof Expr\ClassConstFetch && $node->name instanceof Node\Identifier && strtolower($node->name->toString()) === 'class' && $node->class instanceof Node\Name) {
            $name = DataCatalog::literal($node, $file, $class);
            if (in_array(strtolower($node->class->toString()), ['self', 'static', 'parent'], true) || ! is_string($name) || strlen($name) > 500
                || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $name) !== 1) {
                return null;
            }

            return ['kind' => 'class', 'name' => $name];
        }
        if ($node instanceof Expr\BinaryOp\Concat) {
            $left = self::expression($node->left, $file, $class, $visits, $depth + 1);
            $right = self::expression($node->right, $file, $class, $visits, $depth + 1);

            return $left === null || $right === null ? null : ['kind' => 'concat', 'left' => $left, 'right' => $right];
        }
        if ($node instanceof Expr\Array_) {
            if (count($node->items) > 256) {
                $visits = 1025;

                return null;
            }
            $items = [];
            foreach ($node->items as $item) {
                if ($item === null || $item->unpack || $item->byRef || $item->key === null) {
                    return null;
                }
                $key = self::expression($item->key, $file, $class, $visits, $depth + 1);
                $value = self::expression($item->value, $file, $class, $visits, $depth + 1);
                if ($key === null || $value === null) {
                    return null;
                }
                $items[] = ['key' => $key, 'value' => $value];
            }

            return ['kind' => 'array', 'items' => $items];
        }

        return null;
    }

    public static function validate(mixed $row, int $methodStart, int $methodEnd): void
    {
        if ($methodStart < 0 || $methodEnd < $methodStart || ! is_array($row) || array_keys($row) !== ['hash', 'value'] || ! is_string($row['hash']) || preg_match('/\A[0-9a-f]{64}\z/D', $row['hash']) !== 1) {
            throw new InvalidArgumentException('Invalid source return descriptor.');
        }
        $visits = 0;
        self::validateExpression($row['value'], $visits, $methodStart, $methodEnd);
    }

    private static function validateExpression(mixed $row, int &$visits, int $methodStart, int $methodEnd, int $depth = 0): void
    {
        if (++$visits > 1024 || $depth > 16 || ! is_array($row)) {
            throw new InvalidArgumentException('Invalid source return expression.');
        }
        $valid = match ($row['kind'] ?? null) {
            'null', 'limited' => array_keys($row) === ['kind'],
            'string' => array_keys($row) === ['kind', 'start', 'end'] && is_int($row['start']) && is_int($row['end']) && $row['start'] >= $methodStart && $row['end'] <= $methodEnd && $row['end'] >= $row['start'] && $row['end'] - $row['start'] <= 100000,
            'class' => array_keys($row) === ['kind', 'name'] && is_string($row['name']) && strlen($row['name']) <= 500 && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $row['name']) === 1,
            'concat' => array_keys($row) === ['kind', 'left', 'right'],
            'array' => array_keys($row) === ['kind', 'items'] && is_array($row['items']) && array_is_list($row['items']) && count($row['items']) <= 256,
            default => false,
        };
        if (! $valid) {
            throw new InvalidArgumentException('Invalid source return expression fields.');
        }
        if ($row['kind'] === 'concat') {
            self::validateExpression($row['left'], $visits, $methodStart, $methodEnd, $depth + 1);
            self::validateExpression($row['right'], $visits, $methodStart, $methodEnd, $depth + 1);
        } elseif ($row['kind'] === 'array') {
            foreach ($row['items'] as $item) {
                if (! is_array($item) || array_keys($item) !== ['key', 'value']) {
                    throw new InvalidArgumentException('Invalid source return array item.');
                }
                self::validateExpression($item['key'], $visits, $methodStart, $methodEnd, $depth + 1);
                self::validateExpression($item['value'], $visits, $methodStart, $methodEnd, $depth + 1);
            }
        }
    }

    /** @param array<string, mixed> $row */
    public static function decode(array $row, string $source): mixed
    {
        return match ($row['kind']) {
            'null' => null,
            'class' => $row['name'],
            'string' => self::string(substr($source, $row['start'], $row['end'] - $row['start'] + 1)),
            'concat' => self::concat(self::decode($row['left'], $source), self::decode($row['right'], $source)),
            'array' => self::items($row['items'], $source),
            default => ['dynamic' => true],
        };
    }

    private static function concat(mixed $left, mixed $right): mixed
    {
        return is_string($left) && is_string($right) && strlen($left) + strlen($right) <= 100000 ? $left.$right : ['dynamic' => true];
    }

    /** @param list<array<string, mixed>> $items */
    private static function items(array $items, string $source): mixed
    {
        $result = [];
        foreach ($items as $item) {
            $key = self::decode($item['key'], $source);
            if (! is_string($key)) {
                return ['dynamic' => true];
            }
            $result[$key] = self::decode($item['value'], $source);
        }

        return $result;
    }

    private static function string(string $token): mixed
    {
        if (strlen($token) < 2 || ! in_array($token[0], ["'", '"'], true) || substr($token, -1) !== $token[0]) {
            return ['dynamic' => true];
        }
        $text = substr($token, 1, -1);
        if ($token[0] === "'") {
            return preg_replace_callback('/\\\\([\\\\\'])/', fn ($match) => $match[1], $text);
        }
        // The original AST proved a constant string. Decode its token without eval or reparsing.
        $decoded = '';
        for ($position = 0, $length = strlen($text); $position < $length; $position++) {
            $char = $text[$position];
            if ($char !== '\\' || $position + 1 >= $length) {
                $decoded .= $char;

                continue;
            }
            $next = $text[$position + 1];
            $tail = substr($text, $position + 1, 12);
            if (preg_match('/\A[0-7]{1,3}/', $tail, $match) === 1) {
                $decoded .= chr(octdec($match[0]) % 256);
                $position += strlen($match[0]);
            } elseif (preg_match('/\Ax([0-9a-fA-F]{1,2})/', $tail, $match) === 1) {
                $decoded .= chr((int) hexdec($match[1]));
                $position += strlen($match[0]);
            } elseif (preg_match('/\Au\{([0-9a-fA-F]{1,6})\}/', $tail, $match) === 1) {
                $point = (int) hexdec($match[1]);
                if ($point > 0x10FFFF || $point >= 0xD800 && $point <= 0xDFFF) {
                    return ['dynamic' => true];
                }
                $decoded .= self::utf8($point);
                $position += strlen($match[0]);
            } elseif (str_contains('nrtvef\\$"', $next)) {
                $decoded .= match ($next) {
                    'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\x1b", 'f' => "\f", default => $next,
                };
                $position++;
            } else {
                $decoded .= '\\';
            }
        }

        return $decoded;
    }

    private static function utf8(int $point): string
    {
        return match (true) {
            $point < 0x80 => chr($point),
            $point < 0x800 => chr(0xC0 | $point >> 6).chr(0x80 | $point & 0x3F),
            $point < 0x10000 => chr(0xE0 | $point >> 12).chr(0x80 | $point >> 6 & 0x3F).chr(0x80 | $point & 0x3F),
            default => chr(0xF0 | $point >> 18).chr(0x80 | $point >> 12 & 0x3F).chr(0x80 | $point >> 6 & 0x3F).chr(0x80 | $point & 0x3F),
        };
    }
}
