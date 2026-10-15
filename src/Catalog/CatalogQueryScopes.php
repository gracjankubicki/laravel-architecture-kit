<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Derived;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Finder as NodeFinder;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;

/** Local scope evidence retains query shape, never predicates or argument values. */
final class CatalogQueryScopes
{
    public const CONSTRAINTS = ['where', 'orwhere', 'wherein', 'wherenotin', 'wherecolumn', 'wherenull', 'wherenotnull', 'wherebetween', 'orderby', 'orderbydesc', 'groupby', 'having', 'select', 'addselect', 'distinct', 'limit', 'take', 'skip', 'offset', 'lockforupdate', 'sharedlock', 'reorder', 'latest', 'oldest', 'wherejsoncontains', 'wheredate', 'wheretime', 'wheremonth', 'whereyear', 'wherekey', 'wherekeynot'];

    /** @return array<int, array{style: string, preserves_query: bool}> */
    public static function extract(FileContext $file): array
    {
        $result = [];
        foreach ((new NodeFinder)->findOfKind($file->syntax() ?? [], 'statement.class-method') as $method) {
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
            $valid = Node::is($first, 'expression.variable') && is_string($first->name) && self::preservesQuery($method, $first->name, expression: true);
            $result[max(0, $method->getStartFilePos())] = ['style' => $style, 'preserves_query' => $valid];
        }

        return $result;
    }

    public static function preservesQuery(Node $method, string $variable, bool $expression = false): bool
    {
        $body = Node::is($method, 'expression.arrow-function') ? [Derived::returnValue($method->expr)] : ($method->stmts ?? []);
        $value = count($body) === 1 && (Node::is($body[0], 'statement.return') || $expression && Node::is($body[0], 'statement.expression')) ? $body[0]->expr : null;
        $steps = 0;
        while (Node::is($value, 'expression.method-call')) {
            if (++$steps > 64 || ! Node::is($value->name, 'identifier') || $value->isFirstClassCallable()
                || ! in_array(strtolower($value->name->toString()), self::CONSTRAINTS, true)) {
                return false;
            }
            foreach ($value->getArgs() as $argument) {
                if ($argument->unpack || Node::is($argument->value, 'expression.closure') || Node::is($argument->value, 'expression.arrow-function')) {
                    return false;
                }
            }
            $value = $value->var;
        }

        return Node::is($value, 'expression.variable') && $value->name === $variable;
    }

    /** @return array<int, bool> */
    public static function builderMethods(FileContext $file): array
    {
        $result = [];
        foreach ((new NodeFinder)->findOfKind($file->syntax() ?? [], 'statement.class-method') as $method) {
            $result[max(0, $method->getStartFilePos())] = self::preservesQuery($method, 'this');
        }

        return $result;
    }

    /** @return array<int, bool> */
    public static function scopeMethods(FileContext $file): array
    {
        $result = [];
        foreach ((new NodeFinder)->findOfKind($file->syntax() ?? [], 'statement.class-method') as $method) {
            $first = $method->params[0]->var ?? null;
            $result[max(0, $method->getStartFilePos())] = Node::is($first, 'expression.variable') && is_string($first->name) && self::preservesQuery($method, $first->name, expression: true);
        }

        return $result;
    }
}
