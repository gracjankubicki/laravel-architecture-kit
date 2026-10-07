<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/** Local scope evidence retains query shape, never predicates or argument values. */
final class CatalogQueryScopes
{
    public const CONSTRAINTS = ['where', 'orwhere', 'wherein', 'wherenotin', 'wherecolumn', 'wherenull', 'wherenotnull', 'wherebetween', 'orderby', 'orderbydesc', 'groupby', 'having', 'select', 'addselect', 'distinct', 'limit', 'take', 'skip', 'offset', 'lockforupdate', 'sharedlock', 'reorder', 'latest', 'oldest', 'wherejsoncontains', 'wheredate', 'wheretime', 'wheremonth', 'whereyear', 'wherekey', 'wherekeynot'];

    /** @return array<int, array{style: string, preserves_query: bool}> */
    public static function extract(FileContext $file): array
    {
        $result = [];
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Stmt\ClassMethod::class) as $method) {
            $name = strtolower($method->name->toString());
            $style = str_starts_with($name, 'scope') && strlen($name) > 5 ? 'legacy' : null;
            foreach ($method->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if ($file->resolvedName($attribute->name) === 'Illuminate\\Database\\Eloquent\\Attributes\\Scope') {
                        $style = 'attribute';
                    }
                }
            }
            if ($style === null) {
                continue;
            }
            $first = $method->params[0]->var ?? null;
            $valid = $first instanceof Expr\Variable && is_string($first->name) && self::preservesQuery($method, $first->name, expression: true);
            $result[max(0, $method->getStartFilePos())] = ['style' => $style, 'preserves_query' => $valid];
        }

        return $result;
    }

    public static function preservesQuery(Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction $method, string $variable, bool $expression = false): bool
    {
        $body = $method instanceof Expr\ArrowFunction ? [new Stmt\Return_($method->expr)] : ($method->stmts ?? []);
        $value = count($body) === 1 && ($body[0] instanceof Stmt\Return_ || $expression && $body[0] instanceof Stmt\Expression) ? $body[0]->expr : null;
        $steps = 0;
        while ($value instanceof Expr\MethodCall) {
            if (++$steps > 64 || ! $value->name instanceof Node\Identifier || $value->isFirstClassCallable()
                || ! in_array(strtolower($value->name->toString()), self::CONSTRAINTS, true)) {
                return false;
            }
            foreach ($value->getArgs() as $argument) {
                if ($argument->unpack || $argument->value instanceof Expr\Closure || $argument->value instanceof Expr\ArrowFunction) {
                    return false;
                }
            }
            $value = $value->var;
        }

        return $value instanceof Expr\Variable && $value->name === $variable;
    }

    /** @return array<int, bool> */
    public static function builderMethods(FileContext $file): array
    {
        $result = [];
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Stmt\ClassMethod::class) as $method) {
            $result[max(0, $method->getStartFilePos())] = self::preservesQuery($method, 'this');
        }

        return $result;
    }

    /** @return array<int, bool> */
    public static function scopeMethods(FileContext $file): array
    {
        $result = [];
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Stmt\ClassMethod::class) as $method) {
            $first = $method->params[0]->var ?? null;
            $result[max(0, $method->getStartFilePos())] = $first instanceof Expr\Variable && is_string($first->name) && self::preservesQuery($method, $first->name, expression: true);
        }

        return $result;
    }
}
