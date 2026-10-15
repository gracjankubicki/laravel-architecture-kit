<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Derived;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Finder as NodeFinder;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\DataCatalog;
use InvalidArgumentException;

/** Only source factory/model selectors survive; definition attributes and states are omitted. */
final class CatalogFactoryModels
{
    /** @return array<string, array<string, mixed>> */
    public static function extract(FileContext $file, bool $modelFactories = false): array
    {
        $rows = [];
        foreach ((new NodeFinder)->findOfKind($file->syntax() ?? [], 'statement.class-like') as $class) {
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
                    $returned = count($body) === 1 && Node::is($body[0], 'statement.return') ? $body[0]->expr : null;
                    $selector = $returned;
                    if ($modelFactories) {
                        $selector = Node::is($returned, 'expression.static-call') && Node::is($returned->class, 'name') && Node::is($returned->name, 'identifier')
                            && strtolower($returned->name->toString()) === 'new' && ! $returned->isFirstClassCallable() && $returned->getArgs() === []
                            ? Derived::classConstant($returned->class, 'class') : null;
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
        return Node::is($node, 'expression.const-fetch') && strtolower($node->name->toString()) === 'null';
    }

    private static function type(?Node $node, FileContext $file, string $class): ?string
    {
        if (Node::is($node, 'expression.class-const-fetch') && Node::is($node->class, 'name') && in_array(strtolower($node->class->toString()), ['static', 'parent'], true)) {
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
