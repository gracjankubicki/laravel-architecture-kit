<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Finder as NodeFinder;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Attribute metadata retains attribute names and callback identities only. */
final class CatalogAttributes
{
    public const TYPE = 'Illuminate\\Database\\Eloquent\\Casts\\Attribute';

    /** @return array<int, array<string, mixed>> */
    public static function extract(FileContext $file): array
    {
        $rows = [];
        foreach ((new NodeFinder)->findOfKind($file->syntax() ?? [], 'statement.class-method') as $method) {
            $name = $method->name->toString();
            if (preg_match('/\A(get|set)([a-zA-Z_][a-zA-Z0-9_]{0,127})Attribute\z/iD', $name, $match) === 1) {
                $rows[max(0, $method->getStartFilePos())] = ['attribute' => Str::snake($match[2]), 'style' => 'legacy', 'roles' => [strtolower($match[1]) === 'get' ? 'accessor' : 'mutator'], 'get' => null, 'set' => null, 'resolved' => true];

                continue;
            }
            if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,127}\z/D', $name) !== 1) {
                continue;
            }
            $type = Node::is($method->returnType, 'nullable-type') ? $method->returnType->type : $method->returnType;
            if (! Node::is($type, 'name') || strcasecmp($file->resolvedName($type), self::TYPE) !== 0) {
                continue;
            }
            $body = $method->stmts ?? [];
            $root = count($body) === 1 && Node::is($body[0], 'statement.return') ? $body[0]->expr : null;
            $supported = true;
            while (Node::is($root, 'expression.method-call')) {
                $supported = $supported && Node::is($root->name, 'identifier') && in_array(strtolower($root->name->toString()), ['shouldcache', 'withoutobjectcaching'], true) && ! $root->isFirstClassCallable();
                $root = $root->var;
            }
            $row = ['attribute' => Str::snake($name), 'style' => 'attribute', 'roles' => [], 'get' => null, 'set' => null, 'resolved' => $supported];
            $factory = Node::is($root, 'expression.new') && Node::is($root->class, 'name') && strcasecmp($file->resolvedName($root->class), self::TYPE) === 0 ? 'make'
                : (Node::is($root, 'expression.static-call') && Node::is($root->class, 'name') && strcasecmp($file->resolvedName($root->class), self::TYPE) === 0 && Node::is($root->name, 'identifier') && ! $root->isFirstClassCallable() ? strtolower($root->name->toString()) : null);
            if (! (Node::is($root, 'expression.static-call') || Node::is($root, 'expression.new')) || ! in_array($factory, ['make', 'get', 'set'], true)) {
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
                    if ($argument === null || Node::is($argument, 'expression.const-fetch') && strtolower($argument->name->toString()) === 'null') {
                        if ($factory !== 'make') {
                            $row['resolved'] = false;
                        }

                        continue;
                    }
                    $row['roles'][] = $direction === 'get' ? 'accessor' : 'mutator';
                    if (Node::is($argument, 'expression.closure') || Node::is($argument, 'expression.arrow-function')) {
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

    private static function argument(Node $call, int $position, string $name): ?Node
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
