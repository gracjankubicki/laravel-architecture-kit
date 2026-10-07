<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/** Local return descriptors contain channel selectors, never event payloads. */
final class CatalogBroadcastChannels
{
    public const TYPES = ['Illuminate\\Broadcasting\\Channel' => 'public', 'Illuminate\\Broadcasting\\PrivateChannel' => 'private', 'Illuminate\\Broadcasting\\PresenceChannel' => 'presence'];

    /** @return array<int, array{resolved: bool, channels: list<array{class: string, name: string, line: int}>}> */
    public static function extract(FileContext $file): array
    {
        $rows = [];
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Stmt\ClassMethod::class) as $method) {
            if (strtolower($method->name->toString()) !== 'broadcaston') {
                continue;
            }
            $body = $method->stmts ?? [];
            $expression = count($body) === 1 && $body[0] instanceof Stmt\Return_ ? $body[0]->expr : null;
            $row = ['resolved' => $expression !== null, 'channels' => []];
            $values = $expression instanceof Expr\Array_ ? $expression->items : [$expression];
            if (count($values) > 128) {
                $values = [];
                $row['resolved'] = false;
            }
            foreach ($values as $value) {
                if ($value instanceof Node\ArrayItem) {
                    if ($value->unpack) {
                        $row['resolved'] = false;

                        continue;
                    }
                    $value = $value->value;
                }
                $class = $value instanceof Expr\New_ && $value->class instanceof Node\Name ? $file->resolvedName($value->class) : null;
                if ($class !== null) {
                    $canonical = array_search(strtolower($class), array_map('strtolower', array_keys(self::TYPES)), true);
                    $class = $canonical === false ? null : array_keys(self::TYPES)[$canonical];
                }
                $argument = $value instanceof Expr\New_ ? ($value->args[0] ?? null) : null;
                $name = $argument instanceof Node\Arg && ! $argument->unpack && ($argument->name === null || $argument->name->toString() === 'name')
                    ? CatalogSelector::literal($argument->value) : null;
                if (! $value instanceof Expr\New_ || $class === null || ! isset(self::TYPES[$class]) || ! is_string($name) || preg_match('/\A[a-zA-Z0-9_.-]{1,256}\z/D', $name) !== 1 || count($value->args) !== 1) {
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
