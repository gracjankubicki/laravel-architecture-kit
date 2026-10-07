<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/** Attribute metadata retains attribute names and callback identities only. */
final class CatalogAttributes
{
    public const TYPE = 'Illuminate\\Database\\Eloquent\\Casts\\Attribute';

    /** @return array<int, array<string, mixed>> */
    public static function extract(FileContext $file): array
    {
        $rows = [];
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Stmt\ClassMethod::class) as $method) {
            $name = $method->name->toString();
            if (preg_match('/\A(get|set)([a-zA-Z_][a-zA-Z0-9_]{0,127})Attribute\z/iD', $name, $match) === 1) {
                $rows[max(0, $method->getStartFilePos())] = ['attribute' => Str::snake($match[2]), 'style' => 'legacy', 'roles' => [strtolower($match[1]) === 'get' ? 'accessor' : 'mutator'], 'get' => null, 'set' => null, 'resolved' => true];

                continue;
            }
            if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,127}\z/D', $name) !== 1) {
                continue;
            }
            $type = $method->returnType instanceof Node\NullableType ? $method->returnType->type : $method->returnType;
            if (! $type instanceof Node\Name || strcasecmp($file->resolvedName($type), self::TYPE) !== 0) {
                continue;
            }
            $body = $method->stmts ?? [];
            $root = count($body) === 1 && $body[0] instanceof Stmt\Return_ ? $body[0]->expr : null;
            $supported = true;
            while ($root instanceof Expr\MethodCall) {
                $supported = $supported && $root->name instanceof Node\Identifier && in_array(strtolower($root->name->toString()), ['shouldcache', 'withoutobjectcaching'], true) && ! $root->isFirstClassCallable();
                $root = $root->var;
            }
            $row = ['attribute' => Str::snake($name), 'style' => 'attribute', 'roles' => [], 'get' => null, 'set' => null, 'resolved' => $supported];
            $factory = $root instanceof Expr\New_ && $root->class instanceof Node\Name && strcasecmp($file->resolvedName($root->class), self::TYPE) === 0 ? 'make'
                : ($root instanceof Expr\StaticCall && $root->class instanceof Node\Name && strcasecmp($file->resolvedName($root->class), self::TYPE) === 0 && $root->name instanceof Node\Identifier && ! $root->isFirstClassCallable() ? strtolower($root->name->toString()) : null);
            if (! ($root instanceof Expr\StaticCall || $root instanceof Expr\New_) || ! in_array($factory, ['make', 'get', 'set'], true)) {
                $row['resolved'] = false;
            } else {
                foreach ($root->getArgs() as $argument) {
                    if ($argument->unpack || $argument->name !== null && ! in_array($argument->name->toString(), $factory === 'make' ? ['get', 'set'] : [$factory], true)) {
                        $row['resolved'] = false;
                    }
                }
                foreach (['get', 'set'] as $position => $direction) {
                    if ($factory !== 'make' && $direction !== $factory) {
                        continue;
                    }
                    $argument = self::argument($root, $factory === 'make' ? $position : 0, $direction);
                    if ($argument === null || $argument instanceof Expr\ConstFetch && strtolower($argument->name->toString()) === 'null') {
                        if ($factory !== 'make') {
                            $row['resolved'] = false;
                        }

                        continue;
                    }
                    $row['roles'][] = $direction === 'get' ? 'accessor' : 'mutator';
                    if ($argument instanceof Expr\Closure || $argument instanceof Expr\ArrowFunction) {
                        $offset = max(0, $argument->getStartFilePos());
                        $row[$direction] = CatalogElement::identity($file->path, 'closure', '(closure) '.$file->path.':'.$offset, $offset);
                    } else {
                        $row['resolved'] = false;
                    }
                }
            }
            $rows[max(0, $method->getStartFilePos())] = $row;
        }

        return $rows;
    }

    private static function argument(Expr\StaticCall|Expr\New_ $call, int $position, string $name): ?Node
    {
        foreach ($call->getArgs() as $index => $arg) {
            if (! $arg->unpack && ($arg->name?->toString() === $name || $arg->name === null && $index === $position)) {
                return $arg->value;
            }
        }

        return null;
    }

    public static function validate(mixed $value): void
    {
        if (! is_array($value) || array_keys($value) !== ['attribute', 'style', 'roles', 'get', 'set', 'resolved']
            || ! is_string($value['attribute']) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,255}\z/D', $value['attribute']) !== 1
            || ! in_array($value['style'], ['legacy', 'attribute'], true) || ! is_bool($value['resolved'])
            || ! is_array($value['roles']) || ! array_is_list($value['roles']) || count($value['roles']) > 2 || count(array_filter($value['roles'], 'is_string')) !== count($value['roles']) || array_diff($value['roles'], ['accessor', 'mutator']) !== []) {
            throw new InvalidArgumentException('Invalid Eloquent attribute descriptor.');
        }
        foreach (['get', 'set'] as $key) {
            if ($value[$key] !== null && (! is_string($value[$key]) || preg_match('/\Aelement:[a-f0-9]{32}\z/D', $value[$key]) !== 1)) {
                throw new InvalidArgumentException('Invalid Eloquent attribute callback identity.');
            }
        }
    }
}
