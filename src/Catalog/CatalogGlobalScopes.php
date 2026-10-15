<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Derived;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Finder as NodeFinder;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\DataCatalog;
use InvalidArgumentException;

/** Source global-scope registrations retain targets and provenance, not predicates. */
final class CatalogGlobalScopes
{
    /** @return array<string, list<array<string, mixed>>> */
    public static function extract(FileContext $file, CatalogFacts $php): array
    {
        $owners = [];
        foreach ($php->elements as $element) {
            $owners[$element->offset] = $element;
        }
        $result = [];
        foreach ((new NodeFinder)->findOfKind($file->syntax() ?? [], 'statement.class-like') as $class) {
            $name = DataCatalog::name($class, $file);
            $rows = [];
            foreach ($class->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if ($file->resolvedName($attribute->name) !== 'Illuminate\\Database\\Eloquent\\Attributes\\ScopedBy') {
                        continue;
                    }
                    $arg = $attribute->args[0] ?? null;
                    $valid = count($attribute->args) === 1 && $arg !== null && ! $arg->unpack && ($arg->name === null || $arg->name->toString() === 'classes');
                    $values = $valid ? (Node::is($arg->value, 'expression.array') ? $arg->value->items : [$arg]) : [null];
                    $limited = count($values) > 128;
                    if ($limited) {
                        $values = [null];
                    }
                    foreach ($values as $value) {
                        $node = Node::is($value, 'array-item') ? $value->value : (Node::is($value, 'arg') ? $value->value : null);
                        $type = $node === null ? null : self::type($node, $file, $name);
                        $rows[] = self::row($type, null, $type, 'attribute', null, $type !== null, $attribute, $limited);
                    }
                }
            }
            foreach ($class->getMethods() as $method) {
                // Retain local candidates; composition selects only the effective booted method,
                // which may be a trait alias of a differently named declaration.
                foreach ((new NodeFinder)->findOfKind($method->stmts ?? [], 'expression.static-call') as $call) {
                    if (! Node::is($call->class, 'name') || ! in_array(strtolower($call->class->toString()), ['static', 'self'], true)
                        || ! Node::is($call->name, 'identifier') || strtolower($call->name->toString()) !== 'addglobalscope' || $call->isFirstClassCallable()) {
                        continue;
                    }
                    $args = self::registrationArguments($call);
                    $target = null;
                    $key = null;
                    $type = null;
                    $valid = $method->isStatic() && ! $method->isPrivate() && $args !== null;
                    $args ??= [];
                    $value = $args[count($args) - 1]->value ?? null;
                    if (count($args) === 2) {
                        $literal = $args[0]->value;
                        $key = Node::is($literal, 'scalar.string') && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.-]{0,255}\z/D', $literal->value) === 1 ? $literal->value : null;
                        $valid = $valid && $key !== null;
                    }
                    if (Node::is($value, 'expression.closure') || Node::is($value, 'expression.arrow-function')) {
                        $target = $owners[$value->getStartFilePos()]->id ?? null;
                        $first = $value->params[0]->var ?? null;
                        $valid = $valid && $target !== null && Node::is($first, 'expression.variable') && is_string($first->name) && CatalogQueryScopes::preservesQuery($value, $first->name, expression: true);
                    } elseif (Node::is($value, 'node')) {
                        $type = Node::is($value, 'expression.new') && Node::is($value->class, 'name') && $value->getArgs() === []
                            ? self::type(Derived::classConstant($value->class, 'class'), $file, $name) : self::type($value, $file, $name);
                        $valid = $valid && $type !== null;
                        // A named implementation must be a Scope instance, not a class-string value.
                        $valid = $valid && (count($args) === 1 || Node::is($value, 'expression.new'));
                        $key ??= $type;
                    } else {
                        $valid = false;
                    }
                    $direct = false;
                    foreach ($method->stmts ?? [] as $stmt) {
                        $direct = $direct || Node::is($stmt, 'statement.expression') && $stmt->expr === $call;
                    }
                    $rows[] = self::row($type, $target, $key, 'booted', $owners[$method->getStartFilePos()]->id ?? null, $valid && $direct, $call);
                }
            }
            if (count($rows) > 256) {
                $rows = [self::row(null, null, null, 'attribute', null, false, $class, true)];
            }
            $result[strtolower($name)] = $rows;
        }

        return $result;
    }

    /** @return list<Node>|null */
    private static function registrationArguments(Node $call): ?array
    {
        $values = [];
        $named = false;
        foreach ($call->getArgs() as $position => $arg) {
            if ($arg->unpack || $arg->name === null && $named) {
                return null;
            }
            $named = $named || $arg->name !== null;
            $slot = $arg->name === null ? $position : array_search($arg->name->toString(), ['scope', 'implementation'], true);
            if ($slot === false || $slot > 1 || isset($values[$slot])) {
                return null;
            }
            $values[$slot] = $arg;
        }
        if (! isset($values[0])) {
            return null;
        }
        if (isset($values[1]) && Node::is($values[1]->value, 'expression.const-fetch') && strtolower($values[1]->value->name->toString()) === 'null') {
            unset($values[1]);
        }
        ksort($values);

        return array_values($values);
    }

    private static function type(Node $node, FileContext $file, string $class): ?string
    {
        $value = DataCatalog::literal($node, $file, $class);

        return is_string($value) && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $value) === 1 && strlen($value) <= 500 ? $value : null;
    }

    /** @return array<string, mixed> */
    private static function row(?string $type, ?string $callback, ?string $key, string $registration, ?string $method, bool $resolved, Node $site, bool $limited = false): array
    {
        return ['type' => $type, 'callback' => $callback, 'key' => $key, 'registration' => $registration, 'method' => $method, 'resolved' => $resolved, 'limited' => $limited, 'line' => max(1, $site->getStartLine()), 'end_line' => max(1, $site->getEndLine())];
    }

    public static function validate(mixed $rows): void
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 256) {
            throw new InvalidArgumentException('Invalid global scope source list.');
        }
        foreach ($rows as $row) {
            if (! is_array($row) || array_keys($row) !== ['type', 'callback', 'key', 'registration', 'method', 'resolved', 'limited', 'line', 'end_line']
                || ! in_array($row['registration'], ['attribute', 'booted'], true) || ! is_bool($row['resolved']) || ! is_bool($row['limited']) || ! is_int($row['line']) || $row['line'] < 1 || ! is_int($row['end_line']) || $row['end_line'] < $row['line']) {
                throw new InvalidArgumentException('Invalid global scope source descriptor.');
            }
            foreach (['callback', 'method'] as $field) {
                if ($row[$field] !== null && (! is_string($row[$field]) || preg_match('/\Aelement:[a-f0-9]{32}\z/D', $row[$field]) !== 1)) {
                    throw new InvalidArgumentException('Invalid global scope source identity.');
                }
            }
            foreach (['type', 'key'] as $field) {
                if ($row[$field] !== null && (! is_string($row[$field]) || strlen($row[$field]) > 500 || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.\\\\-]*\z/D', $row[$field]) !== 1)) {
                    throw new InvalidArgumentException('Invalid global scope source selector.');
                }
            }
        }
    }
}
