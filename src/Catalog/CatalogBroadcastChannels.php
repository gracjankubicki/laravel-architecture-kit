<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Finder as NodeFinder;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use InvalidArgumentException;

/** Local return descriptors contain channel selectors, never event payloads. */
final class CatalogBroadcastChannels
{
    public const TYPES = ['Illuminate\\Broadcasting\\Channel' => 'public', 'Illuminate\\Broadcasting\\PrivateChannel' => 'private', 'Illuminate\\Broadcasting\\PresenceChannel' => 'presence'];

    /** @return array<int, array{resolved: bool, channels: list<array{class: string, name: string, line: int}>}> */
    public static function extract(FileContext $file): array
    {
        $rows = [];
        foreach ((new NodeFinder)->findOfKind($file->syntax() ?? [], 'statement.class-method') as $method) {
            if (strtolower($method->name->toString()) !== 'broadcaston') {
                continue;
            }
            $body = $method->stmts ?? [];
            $expression = count($body) === 1 && Node::is($body[0], 'statement.return') ? $body[0]->expr : null;
            $row = ['resolved' => $expression !== null, 'channels' => []];
            $values = Node::is($expression, 'expression.array') ? $expression->items : [$expression];
            if (count($values) > 128) {
                $values = [];
                $row['resolved'] = false;
            }
            foreach ($values as $value) {
                if (Node::is($value, 'array-item')) {
                    if ($value->unpack) {
                        $row['resolved'] = false;

                        continue;
                    }
                    $value = $value->value;
                }
                $class = Node::is($value, 'expression.new') && Node::is($value->class, 'name') ? $file->resolvedName($value->class) : null;
                if ($class !== null) {
                    $canonical = array_search(strtolower($class), array_map('strtolower', array_keys(self::TYPES)), true);
                    $class = $canonical === false ? null : array_keys(self::TYPES)[$canonical];
                }
                $argument = Node::is($value, 'expression.new') ? ($value->args[0] ?? null) : null;
                $name = Node::is($argument, 'arg') && ! $argument->unpack && ($argument->name === null || $argument->name->toString() === 'name')
                    ? CatalogSelector::literal($argument->value) : null;
                if (! Node::is($value, 'expression.new') || $class === null || ! isset(self::TYPES[$class]) || ! is_string($name) || preg_match('/\A[a-zA-Z0-9_.-]{1,256}\z/D', $name) !== 1 || count($value->args) !== 1) {
                    $row['resolved'] = false;

                    continue;
                }
                $row['channels'][] = ['class' => $class, 'name' => $name, 'line' => max(1, $value->getStartLine())];
            }
            $rows[max(0, $method->getStartFilePos())] = $row;
        }

        return $rows;
    }

    public static function validate(mixed $value): void
    {
        if (! is_array($value) || array_keys($value) !== ['resolved', 'channels'] || ! is_bool($value['resolved']) || ! is_array($value['channels']) || ! array_is_list($value['channels']) || count($value['channels']) > 128) {
            throw new InvalidArgumentException('Invalid broadcast channel descriptor.');
        }
        foreach ($value['channels'] as $channel) {
            if (! is_array($channel) || array_keys($channel) !== ['class', 'name', 'line'] || ! is_string($channel['class']) || ! isset(self::TYPES[$channel['class']]) || ! is_string($channel['name']) || preg_match('/\A[a-zA-Z0-9_.-]{1,256}\z/D', $channel['name']) !== 1 || ! is_int($channel['line']) || $channel['line'] < 1) {
                throw new InvalidArgumentException('Invalid broadcast channel selector.');
            }
        }
    }
}
