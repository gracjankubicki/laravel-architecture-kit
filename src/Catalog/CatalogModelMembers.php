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

/** Local relationship declarations retain selectors, not keys, constraints or values. */
final class CatalogModelMembers
{
    public const RELATIONS = ['hasone', 'hasmany', 'belongsto', 'belongstomany', 'morphone', 'morphmany', 'morphto', 'morphtomany', 'morphedbymany', 'hasonethrough', 'hasmanythrough'];

    /** @return array<int, array<string, mixed>> */
    public static function extract(FileContext $file): array
    {
        $rows = [];
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Stmt\ClassLike::class) as $class) {
            $name = DataCatalog::name($class, $file);
            foreach ($class->getMethods() as $method) {
                $body = $method->stmts ?? [];
                $expression = count($body) === 1 && $body[0] instanceof Stmt\Return_ ? $body[0]->expr : null;
                $pivotClass = null;
                $pivotClassSelected = false;
                $supported = true;
                $root = $expression;
                while ($root instanceof Expr\MethodCall && ! $root->var instanceof Expr\Variable) {
                    if (! $root->name instanceof Node\Identifier || $root->isFirstClassCallable() || ! in_array(strtolower($root->name->toString()), ['where', 'wherein', 'orderby', 'withpivot', 'withtimestamps', 'as', 'using', 'withdefault'], true)) {
                        $supported = false;
                    } elseif (strtolower($root->name->toString()) === 'using' && ! $pivotClassSelected) {
                        $pivotClassSelected = true;
                        $pivotClass = self::type(self::argument($root, 0, 'class'), $file, $name);
                        $supported = $supported && $pivotClass !== null;
                    }
                    $root = $root->var;
                }
                if (! $root instanceof Expr\MethodCall || ! $root->var instanceof Expr\Variable || $root->var->name !== 'this' || ! $root->name instanceof Node\Identifier
                    || ! in_array(strtolower($root->name->toString()), self::RELATIONS, true) || $root->isFirstClassCallable()) {
                    continue;
                }
                $type = strtolower($root->name->toString());
                $related = $type === 'morphto' ? null : self::type(self::argument($root, 0, 'related'), $file, $name);
                $through = in_array($type, ['hasonethrough', 'hasmanythrough'], true) ? self::type(self::argument($root, 1, 'through'), $file, $name) : null;
                $pivotNode = in_array($type, ['belongstomany', 'morphtomany', 'morphedbymany'], true) ? self::argument($root, $type === 'belongstomany' ? 1 : 2, 'table') : null;
                $default = $pivotNode === null || $pivotNode instanceof Expr\ConstFetch && strtolower($pivotNode->name->toString()) === 'null';
                $pivot = $default ? null : CatalogSelector::literal($pivotNode);
                $pivot = $pivot !== null && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.-]{0,255}\z/D', $pivot) === 1 ? $pivot : null;
                $morph = in_array($type, ['morphtomany', 'morphedbymany'], true) ? CatalogSelector::literal(self::argument($root, 1, 'name')) : null;
                $morph = $morph !== null && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.-]{0,255}\z/D', $morph) === 1 ? $morph : null;
                $rows[max(0, $method->getStartFilePos())] = ['eloquent_relation' => ['type' => $type, 'related' => $related, 'through' => $through,
                    'pivot' => ['mode' => $default ? 'default' : ($pivot === null ? 'dynamic' : 'literal'), 'name' => $pivot], 'morph' => $morph, 'pivot_class' => $pivotClass,
                    'resolved' => $supported && $related !== null && (! in_array($type, ['morphtomany', 'morphedbymany'], true) || $morph !== null) && (! in_array($type, ['hasonethrough', 'hasmanythrough'], true) || $through !== null), 'line' => max(1, $root->getStartLine())]];
            }
        }

        return $rows;
    }

    private static function type(?Node $node, FileContext $file, string $class): ?string
    {
        if ($node instanceof Expr\ClassConstFetch && $node->class instanceof Node\Name && strtolower($node->class->toString()) === 'static') {
            return null;
        }
        $value = $node instanceof Expr\ClassConstFetch ? DataCatalog::literal($node, $file, $class) : CatalogSelector::literal($node);

        return is_string($value) && strlen($value) <= 500 && preg_match('/\A\\\\?[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $value) === 1 ? ltrim($value, '\\') : null;
    }

    private static function argument(Expr\MethodCall $call, int $position, string $name): ?Node
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
        if (! is_array($value) || array_keys($value) !== ['type', 'related', 'through', 'pivot', 'morph', 'pivot_class', 'resolved', 'line']
            || ! in_array($value['type'], self::RELATIONS, true) || ! is_bool($value['resolved']) || ! is_int($value['line']) || $value['line'] < 1
            || ! is_array($value['pivot']) || array_keys($value['pivot']) !== ['mode', 'name'] || ! in_array($value['pivot']['mode'], ['default', 'literal', 'dynamic'], true)) {
            throw new InvalidArgumentException('Invalid Eloquent relation descriptor.');
        }
        foreach (['related', 'through', 'pivot_class'] as $key) {
            if ($value[$key] !== null && (! is_string($value[$key]) || strlen($value[$key]) > 500 || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $value[$key]) !== 1)) {
                throw new InvalidArgumentException('Invalid Eloquent relation type.');
            }
        }
        foreach ([$value['pivot']['name'], $value['morph']] as $name) {
            if ($name !== null && (! is_string($name) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.-]{0,255}\z/D', $name) !== 1)) {
                throw new InvalidArgumentException('Invalid Eloquent relation table selector.');
            }
        }
    }
}
