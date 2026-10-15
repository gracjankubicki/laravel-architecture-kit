<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Finder as NodeFinder;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use Illuminate\Support\Str;

/** Source metadata only. No model construction, reflection or database configuration. */
final class DataCatalog
{
    /** @var array<string, array<string, mixed>> */
    public array $classes = [];

    /** @var list<array<string, mixed>> */
    public array $notices = [];

    public function collect(FileContext $file): void
    {
        $ast = $file->syntax();
        if ($ast === null) {
            $this->notices[] = ['path' => $file->path, 'line' => 1, 'reason' => 'Unparseable DATA source.'];

            return;
        }
        foreach ((new NodeFinder)->findOfKind($ast, 'statement.class-like') as $node) {
            $name = self::name($node, $file);
            $meta = ['name' => $name, 'path' => $file->path, 'line' => $node->getStartLine(), 'parents' => [], 'properties' => [], 'methods' => []];
            foreach ($node->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if ($file->resolvedName($attribute->name) === 'Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder') {
                        $meta['builder'] = isset($attribute->args[0]) ? self::literal($attribute->args[0]->value, $file, $name) : null;
                    }
                }
            }
            if (Node::is($node, 'statement.class') && $node->extends !== null) {
                $meta['parents'][] = $file->resolvedName($node->extends);
            }
            foreach ($node->getTraitUses() as $use) {
                foreach ($use->traits as $trait) {
                    $meta['parents'][] = $file->resolvedName($trait);
                }
                if ($use->adaptations !== []) {
                    $this->notices[] = ['path' => $file->path, 'line' => $use->getStartLine(), 'reason' => 'DATA trait adaptations require inspection.'];
                }
            }
            foreach ($node->getProperties() as $property) {
                foreach ($property->props as $prop) {
                    $meta['properties'][$prop->name->toString()] = self::literal($prop->default, $file, $name);
                }
            }
            foreach ($node->getMethods() as $method) {
                $key = strtolower($method->name->toString());
                $meta['methods'][$key] = ['class' => $name, 'path' => $file->path, 'line' => $method->getStartLine(), 'name' => $method->name->toString(), 'symbol' => $name.'::'.$method->name->toString()];
                if (str_starts_with($key, 'scope') && strlen($key) > 5) {
                    $meta['methods'][$key]['legacy_scope'] = true;
                }
                if (Node::is($method->returnType, 'name')) {
                    $meta['methods'][$key]['return_class'] = $file->resolvedName($method->returnType);
                }
                $returnReceivers = [];
                foreach ($method->stmts ?? [] as $statement) {
                    if (! Node::is($statement, 'statement.return')) {
                        continue;
                    }
                    $expression = $statement->expr;
                    while (Node::is($expression, 'expression.method-call') || Node::is($expression, 'expression.nullsafe-method-call')) {
                        $expression = $expression->var;
                    }
                    if ((Node::is($expression, 'expression.static-call') || Node::is($expression, 'expression.new')) && Node::is($expression->class, 'name')) {
                        $returnReceivers[] = $file->resolvedName($expression->class);
                    }
                }
                $meta['methods'][$key]['return_receivers'] = $returnReceivers;
                if (count($method->stmts ?? []) === 1 && Node::is($method->stmts[0], 'statement.return')) {
                    $meta['methods'][$key]['literal_return'] = self::literal($method->stmts[0]->expr, $file, $name);
                }
                foreach ($method->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        if ($file->resolvedName($attribute->name) === 'Illuminate\Database\Eloquent\Attributes\Scope') {
                            $meta['methods'][$key]['scope'] = true;
                        }
                    }
                }
                if ($key === 'neweloquentbuilder') {
                    $news = (new NodeFinder)->findOfKind($method->stmts ?? [], 'expression.new');
                    $meta['builder'] = count($news) === 1 && Node::is($news[0]->class, 'name') ? $file->resolvedName($news[0]->class) : null;
                }
            }
            $key = strtolower($name);
            if (isset($this->classes[$key])) {
                $this->notices[] = ['path' => $file->path, 'line' => $node->getStartLine(), 'reason' => 'Duplicate DATA class declaration: '.$name];
                $this->classes[$key]['ambiguous'] = true;
            } else {
                $this->classes[$key] = $meta;
            }
        }
    }

    public static function name(Node $node, FileContext $file): string
    {
        return isset($node->namespacedName) ? $node->namespacedName->toString() : '(anonymous) '.$file->path.':'.$node->getStartFilePos();
    }

    public static function literal(?Node $node, FileContext $file, string $class): mixed
    {
        if (Node::is($node, 'expression.array')) {
            $items = [];
            foreach ($node->items as $item) {
                if ($item === null || $item->unpack) {
                    return ['dynamic' => true];
                }
                $value = self::literal($item->value, $file, $class);
                $key = $item->key === null ? count($items) : self::literal($item->key, $file, $class);
                if (! is_string($key) && ! is_int($key)) {
                    return ['dynamic' => true];
                }
                $items[$key] = $value;
            }

            return $items;
        }
        if (Node::is($node, 'scalar.string') || Node::is($node, 'scalar.int')) {
            return $node->value;
        }
        if (Node::is($node, 'expression.class-const-fetch') && Node::is($node->class, 'name') && Node::is($node->name, 'identifier') && strtolower($node->name->toString()) === 'class') {
            return in_array(strtolower($node->class->toString()), ['self', 'static'], true) ? $class : $file->resolvedName($node->class);
        }
        if (Node::is($node, 'expression.const-fetch') && strtolower($node->name->toString()) === 'null') {
            return null;
        }

        return ['dynamic' => true];
    }

    /** @param array<string, bool> $seen */
    public function inherits(string $class, string $target, array $seen = []): bool
    {
        $key = strtolower($class);
        if (strcasecmp($class, $target) === 0) {
            return true;
        }
        if (isset($seen[$key]) || count($seen) > 32 || ($this->classes[$key]['ambiguous'] ?? false)) {
            return false;
        }
        $seen[$key] = true;
        foreach ($this->classes[$key]['parents'] ?? [] as $parent) {
            if ($this->inherits($parent, $target, $seen)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, bool> $seen
     * @return array<string, mixed>|null
     */
    public function method(string $class, string $method, array $seen = []): ?array
    {
        $key = strtolower($class);
        if (isset($seen[$key]) || count($seen) > 32 || ($this->classes[$key]['ambiguous'] ?? false)) {
            return null;
        }
        $seen[$key] = true;
        $meta = $this->classes[$key] ?? ['methods' => [], 'parents' => []];
        if (isset($meta['methods'][strtolower($method)])) {
            return $meta['methods'][strtolower($method)];
        }
        foreach ($meta['parents'] as $parent) {
            if (isset($this->classes[strtolower($parent)]) && ($found = $this->method($parent, $method, $seen)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<string, bool> $seen */
    public function property(string $class, string $property, mixed $default = null, array $seen = []): mixed
    {
        $key = strtolower($class);
        if (isset($seen[$key]) || count($seen) > 32) {
            return ['dynamic' => true];
        }
        $seen[$key] = true;
        $meta = $this->classes[$key] ?? [];
        if (array_key_exists($property, $meta['properties'] ?? [])) {
            return $meta['properties'][$property];
        }
        foreach ($meta['parents'] ?? [] as $parent) {
            if (isset($this->classes[strtolower($parent)])) {
                $value = $this->property($parent, $property, $default, $seen);
                if ($value !== $default) {
                    return $value;
                }
            }
        }

        return $default;
    }

    /** @return array<string, mixed> */
    public function model(string $class): array
    {
        $table = $this->property($class, 'table') ?? Str::snake(Str::pluralStudly(class_basename($class)));
        $connection = $this->property($class, 'connection');
        if (($getter = $this->method($class, 'getTable')) !== null) {
            $table = $getter['literal_return'] ?? ['dynamic' => true];
        }
        if (($getter = $this->method($class, 'getConnectionName')) !== null) {
            $connection = $getter['literal_return'] ?? ['dynamic' => true];
        }

        $with = $this->property($class, 'with', []);
        $builder = $this->classes[strtolower($class)]['builder'] ?? $this->property($class, 'builder');

        return ['type' => 'query', 'model' => $class, 'builder' => is_string($builder) ? $builder : null, 'tables' => [['table' => is_string($table) ? $table : null, 'role' => 'primary']], 'connection' => self::connection($connection), 'eager' => is_array($with) ? array_values($with) : [], 'unknown' => ! is_string($table) || ! is_array($with) || ($builder !== null && ! is_string($builder)), 'object' => true];
    }

    /** @param array<string, mixed> $method */
    public function returnsData(array $method): bool
    {
        foreach ([$method['return_class'] ?? '', ...($method['return_receivers'] ?? [])] as $class) {
            if ($class === 'Illuminate\Support\Facades\DB' || $this->inherits($class, 'Illuminate\Database\Eloquent\Model') || $this->inherits($class, 'Illuminate\Database\Eloquent\Builder') || $this->inherits($class, 'Illuminate\Database\Query\Builder')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public static function connection(mixed $value): array
    {
        return is_string($value) ? ['kind' => 'named', 'name' => $value] : ['kind' => $value === null ? 'default' : 'dynamic', 'name' => null];
    }
}
