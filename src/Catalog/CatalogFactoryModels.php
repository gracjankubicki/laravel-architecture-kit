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

/** Only source factory/model selectors survive; definition attributes and states are omitted. */
final class CatalogFactoryModels
{
    /** @return array<string, array<string, mixed>> */
    public static function extract(FileContext $file, bool $modelFactories = false): array
    {
        $rows = [];
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Stmt\ClassLike::class) as $class) {
            $name = DataCatalog::name($class, $file);
            $row = ['property' => null, 'attribute' => null, 'method' => null];
            foreach ($class->getProperties() as $property) {
                foreach ($property->props as $item) {
                    if ($item->name->toString() === ($modelFactories ? 'factory' : 'model')) {
                        $row['property'] = ['type' => self::type($item->default, $file, $name), 'resolved' => ! $property->isPrivate() && $property->isStatic() === $modelFactories, 'null_default' => $item->default === null || self::isNull($item->default)];
                    }
                }
            }
            foreach ($class->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if (strcasecmp($file->resolvedName($attribute->name), $modelFactories ? 'Illuminate\\Database\\Eloquent\\Attributes\\UseFactory' : 'Illuminate\\Database\\Eloquent\\Factories\\Attributes\\UseModel') === 0) {
                        $argument = null;
                        $valid = count($attribute->args) === 1 && $row['attribute'] === null;
                        foreach ($attribute->args as $arg) {
                            $valid = $valid && ! $arg->unpack && ($arg->name === null || $arg->name->toString() === ($modelFactories ? 'factoryClass' : 'class'));
                            $argument = $arg->value;
                        }
                        $row['attribute'] = ['type' => $valid ? self::type($argument, $file, $name) : null, 'resolved' => $valid, 'null_default' => false];
                    }
                }
            }
            foreach ($class->getMethods() as $method) {
                if (strtolower($method->name->toString()) === ($modelFactories ? 'newfactory' : 'modelname')) {
                    $body = $method->stmts ?? [];
                    $returned = count($body) === 1 && $body[0] instanceof Stmt\Return_ ? $body[0]->expr : null;
                    $selector = $returned;
                    if ($modelFactories) {
                        $selector = $returned instanceof Expr\StaticCall && $returned->class instanceof Node\Name && $returned->name instanceof Node\Identifier
                            && strtolower($returned->name->toString()) === 'new' && ! $returned->isFirstClassCallable() && $returned->getArgs() === []
                            ? new Expr\ClassConstFetch($returned->class, 'class') : null;
                    }
                    $row['method'] = ['type' => self::type($selector, $file, $name),
                        'resolved' => ($modelFactories ? ! $method->isPrivate() && $method->isStatic() : $method->isPublic() && ! $method->isStatic()) && ! $method->isAbstract(),
                        'null_default' => $modelFactories && $returned !== null && self::isNull($returned)];
                }
            }
            $rows[strtolower($name)] = $row;
        }

        return $rows;
    }

    private static function isNull(Node $node): bool
    {
        return $node instanceof Expr\ConstFetch && strtolower($node->name->toString()) === 'null';
    }

    private static function type(?Node $node, FileContext $file, string $class): ?string
    {
        if ($node instanceof Expr\ClassConstFetch && $node->class instanceof Node\Name && in_array(strtolower($node->class->toString()), ['static', 'parent'], true)) {
            return null;
        }
        $value = DataCatalog::literal($node, $file, $class);

        return is_string($value) && strlen($value) <= 500 && preg_match('/\A\\\\?[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $value) === 1 ? ltrim($value, '\\') : null;
    }

    public static function validate(mixed $value): void
    {
        if (! is_array($value) || array_keys($value) !== ['property', 'attribute', 'method']) {
            throw new InvalidArgumentException('Invalid factory model declaration.');
        }
        foreach ($value as $selector) {
            if ($selector === null) {
                continue;
            }
            if (! is_array($selector) || array_keys($selector) !== ['type', 'resolved', 'null_default'] || ! is_bool($selector['resolved']) || ! is_bool($selector['null_default'])
                || $selector['type'] !== null && (! is_string($selector['type']) || strlen($selector['type']) > 500 || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $selector['type']) !== 1)) {
                throw new InvalidArgumentException('Invalid factory model selector.');
            }
        }
    }
}
