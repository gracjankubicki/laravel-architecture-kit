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

/** Cast declarations contain type names only; constructor arguments are omitted. */
final class CatalogCasts
{
    public const BUILTIN = ['array', 'bool', 'boolean', 'collection', 'custom_datetime', 'date', 'datetime', 'decimal', 'double',
        'encrypted', 'float', 'hashed', 'immutable_date', 'immutable_datetime', 'immutable_custom_datetime', 'int', 'integer',
        'json', 'object', 'real', 'string', 'timestamp'];

    /** @return array<string, array{property: array<string, mixed>|null, method: array<string, mixed>|null}> */
    public static function extract(FileContext $file): array
    {
        $rows = [];
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Stmt\ClassLike::class) as $node) {
            $class = DataCatalog::name($node, $file);
            $row = ['property' => null, 'method' => null];
            foreach ($node->getProperties() as $property) {
                foreach ($property->props as $item) {
                    if ($item->name->toString() === 'casts') {
                        $row['property'] = self::describe(self::literal($item->default, $file, $class));
                    }
                }
            }
            foreach ($node->getMethods() as $method) {
                if (strtolower($method->name->toString()) === 'casts') {
                    $body = $method->stmts ?? [];
                    $row['method'] = self::describe(count($body) === 1 && $body[0] instanceof Stmt\Return_ ? self::literal($body[0]->expr, $file, $class) : null);
                }
            }
            $rows[strtolower($class)] = $row;
        }

        return $rows;
    }

    private static function literal(?Node $node, FileContext $file, string $class, int $depth = 0): mixed
    {
        if ($depth > 16) {
            return null;
        }
        if ($node instanceof Expr\BinaryOp\Concat) {
            $left = self::literal($node->left, $file, $class, $depth + 1);
            $right = self::literal($node->right, $file, $class, $depth + 1);

            return is_string($left) && is_string($right) && strlen($left) + strlen($right) <= 100000 ? $left.$right : null;
        }
        if ($node instanceof Expr\Array_) {
            if (count($node->items) > 256) {
                return null;
            }
            $items = [];
            foreach ($node->items as $item) {
                if ($item === null || $item->unpack) {
                    return null;
                }
                $key = self::literal($item->key, $file, $class, $depth + 1);
                if (! is_string($key)) {
                    return null;
                }
                $items[$key] = self::literal($item->value, $file, $class, $depth + 1);
            }

            return $items;
        }

        return DataCatalog::literal($node, $file, $class);
    }

    /** @return array{resolved: bool, entries: list<array{attribute: string, cast: string, kind: string}>} */
    public static function describe(mixed $value): array
    {
        $entries = [];
        $resolved = is_array($value) && count($value) <= 256;
        if (! $resolved) {
            return ['resolved' => false, 'entries' => []];
        }
        foreach ($value as $attribute => $cast) {
            if (! is_string($attribute) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.-]{0,255}\z/D', $attribute) !== 1 || ! is_string($cast)) {
                $resolved = false;

                continue;
            }
            $type = explode(':', $cast, 2)[0];
            if (in_array(strtolower($type), self::BUILTIN, true)) {
                $entries[] = ['attribute' => $attribute, 'cast' => strtolower($type), 'kind' => 'builtin'];
            } elseif (strlen($type) <= 500 && preg_match('/\A\\\\?[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $type) === 1) {
                $entries[] = ['attribute' => $attribute, 'cast' => ltrim($type, '\\'), 'kind' => 'class'];
            } else {
                $resolved = false;
            }
        }

        return ['resolved' => $resolved, 'entries' => $entries];
    }

    public static function validate(mixed $value): void
    {
        if (! is_array($value) || array_keys($value) !== ['property', 'method']) {
            throw new InvalidArgumentException('Invalid cast declaration.');
        }
        foreach ($value as $descriptor) {
            if ($descriptor === null) {
                continue;
            }
            if (! is_array($descriptor) || array_keys($descriptor) !== ['resolved', 'entries'] || ! is_bool($descriptor['resolved'])
                || ! is_array($descriptor['entries']) || ! array_is_list($descriptor['entries']) || count($descriptor['entries']) > 256) {
                throw new InvalidArgumentException('Invalid cast selector descriptor.');
            }
            foreach ($descriptor['entries'] as $entry) {
                if (! is_array($entry) || array_keys($entry) !== ['attribute', 'cast', 'kind']
                    || ! is_string($entry['attribute']) || ! is_string($entry['cast']) || ! in_array($entry['kind'], ['builtin', 'class'], true)
                    || self::describe([$entry['attribute'] => $entry['cast']]) !== ['resolved' => true, 'entries' => [$entry]]) {
                    throw new InvalidArgumentException('Invalid normalized cast selector.');
                }
            }
        }
    }
}
