<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Derived;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Finder as NodeFinder;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\DataCatalog;

/** Source builder selectors reuse the sanitized class-selector cache shape. */
final class CatalogQueryBuilders
{
    /** @return array<string, array<string, mixed>> */
    public static function extract(FileContext $file): array
    {
        $result = [];
        foreach ((new NodeFinder)->findOfKind($file->syntax() ?? [], 'statement.class-like') as $class) {
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
                $returned = count($body) === 1 && Node::is($body[0], 'statement.return') ? $body[0]->expr : null;
                $valid = $method->isPublic() && ! $method->isStatic() && ! $method->isAbstract() && Node::is($returned, 'expression.new') && Node::is($returned->class, 'name');
                $arg = $valid ? ($returned->args[0] ?? null) : null;
                $param = $method->params[0]->var ?? null;
                $valid = $valid && count($returned->args) === 1 && $arg !== null && $arg->name === null && ! $arg->unpack
                    && Node::is($arg->value, 'expression.variable') && Node::is($param, 'expression.variable') && $arg->value->name === $param->name && count($method->params) === 1;
                $type = Node::is($returned, 'expression.new') && Node::is($returned->class, 'name')
                    ? self::type(Derived::classConstant($returned->class, 'class'), $file, $name) : null;
                $row['method'] = ['type' => $valid ? $type : null, 'resolved' => $valid, 'null_default' => false];
            }
            $result[strtolower($name)] = $row;
        }

        return $result;
    }

    private static function type(?Node $node, FileContext $file, string $class): ?string
    {
        if (Node::is($node, 'expression.class-const-fetch') && Node::is($node->class, 'name') && in_array(strtolower($node->class->toString()), ['static', 'parent'], true)) {
            return null;
        }
        $value = DataCatalog::literal($node, $file, $class);

        return is_string($value) && strlen($value) <= 500 && preg_match('/\A\\\\?[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $value) === 1 ? ltrim($value, '\\') : null;
    }
}
