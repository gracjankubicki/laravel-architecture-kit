<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\DataCatalog;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/** Source builder selectors reuse the sanitized class-selector cache shape. */
final class CatalogQueryBuilders
{
    /** @return array<string, array<string, mixed>> */
    public static function extract(FileContext $file): array
    {
        $result = [];
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Stmt\ClassLike::class) as $class) {
            $name = DataCatalog::name($class, $file);
            $row = ['property' => null, 'attribute' => null, 'method' => null];
            foreach ($class->getProperties() as $property) {
                foreach ($property->props as $item) {
                    if ($item->name->toString() === 'builder') {
                        $row['property'] = ['type' => self::type($item->default, $file, $name), 'resolved' => ! $property->isPrivate() && $property->isStatic(), 'null_default' => false];
                    }
                }
            }
            foreach ($class->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if ($file->resolvedName($attribute->name) !== 'Illuminate\\Database\\Eloquent\\Attributes\\UseEloquentBuilder') {
                        continue;
                    }
                    $arg = $attribute->args[0] ?? null;
                    $valid = count($attribute->args) === 1 && $arg !== null && ! $arg->unpack && ($arg->name === null || $arg->name->toString() === 'builderClass') && $row['attribute'] === null;
                    $row['attribute'] = ['type' => $valid ? self::type($arg->value, $file, $name) : null, 'resolved' => $valid, 'null_default' => false];
                }
            }
            foreach ($class->getMethods() as $method) {
                if (strtolower($method->name->toString()) !== 'neweloquentbuilder') {
                    continue;
                }
                $body = $method->stmts ?? [];
                $returned = count($body) === 1 && $body[0] instanceof Stmt\Return_ ? $body[0]->expr : null;
                $valid = $method->isPublic() && ! $method->isStatic() && ! $method->isAbstract() && $returned instanceof Expr\New_ && $returned->class instanceof Node\Name;
                $arg = $valid ? ($returned->args[0] ?? null) : null;
                $param = $method->params[0]->var ?? null;
                $valid = $valid && count($returned->args) === 1 && $arg !== null && $arg->name === null && ! $arg->unpack
                    && $arg->value instanceof Expr\Variable && $param instanceof Expr\Variable && $arg->value->name === $param->name && count($method->params) === 1;
                $type = $returned instanceof Expr\New_ && $returned->class instanceof Node\Name
                    ? self::type(new Expr\ClassConstFetch($returned->class, 'class'), $file, $name) : null;
                $row['method'] = ['type' => $valid ? $type : null, 'resolved' => $valid, 'null_default' => false];
            }
            $result[strtolower($name)] = $row;
        }

        return $result;
    }

    private static function type(?Node $node, FileContext $file, string $class): ?string
    {
        if ($node instanceof Expr\ClassConstFetch && $node->class instanceof Node\Name && in_array(strtolower($node->class->toString()), ['static', 'parent'], true)) {
            return null;
        }
        $value = DataCatalog::literal($node, $file, $class);

        return is_string($value) && strlen($value) <= 500 && preg_match('/\A\\\\?[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $value) === 1 ? ltrim($value, '\\') : null;
    }
}
