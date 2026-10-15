<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;

/** Sanitized attachment facts shared by syntax extraction and existing local value tracking. */
final class CatalogAiAttachments
{
    /** @return array{resolved: bool, limited: bool, files: list<array{receiver: string, method: string, valid: bool, offset: int, line: int, end_line: int}>} */
    public static function extract(FileContext $file, ?Node $expression): array
    {
        if ($expression === null) {
            return ['resolved' => true, 'limited' => false, 'files' => []];
        }
        $items = Node::is($expression, 'expression.array') ? self::attachmentItems($expression) : null;
        $limited = Node::is($expression, 'expression.array') && count($expression->items) > 128;
        $resolved = $items !== null && ! $limited;
        $files = [];
        foreach ($items ?? [] as $item) {
            $value = $item?->value;
            if ($item === null || $item->unpack || $item->byRef || ! Node::is($value, 'expression.static-call')
                || ! Node::is($value->class, 'name') || ! Node::is($value->name, 'identifier') || $value->isFirstClassCallable()) {
                $resolved = false;

                continue;
            }
            $receiver = $file->resolvedName($value->class);
            $method = strtolower($value->name->toString());
            $parameters = CatalogAiOperations::fileParameters($receiver, $method);
            if ($parameters === null) {
                $resolved = false;

                continue;
            }
            $seen = [];
            $valid = count($value->args) >= 1 && count($value->args) <= count($parameters);
            $named = false;
            foreach ($value->getArgs() as $position => $arg) {
                $parameter = $arg->name?->toString() ?? ($parameters[$position] ?? 'unknown');
                $valid = $valid && ! $arg->unpack && ! $arg->byRef && in_array($parameter, $parameters, true)
                    && ! isset($seen[$parameter]) && (! $named || $arg->name !== null);
                $seen[$parameter] = true;
                $named = $named || $arg->name !== null;
            }
            $valid = $valid && isset($seen[$parameters[0]]);
            $files[] = ['receiver' => $receiver, 'method' => $method, 'valid' => $valid, 'offset' => max(0, $value->getStartFilePos()),
                'line' => max(1, $value->getStartLine()), 'end_line' => max(1, $value->getEndLine())];
        }

        return ['resolved' => $resolved, 'limited' => $limited, 'files' => $files];
    }

    /** PHP array construction over literal keys only; values remain unevaluated AST nodes.
     * @return list<Node>|null
     */
    private static function attachmentItems(Node $array): ?array
    {
        $keyed = array_filter($array->items, fn ($item) => $item?->key !== null) !== [];
        if (count($array->items) > 128 && $keyed) {
            // A later keyed item outside the budget could replace any retained candidate.
            return null;
        }
        $items = [];
        foreach (array_slice($array->items, 0, 128) as $item) {
            if ($item === null || $item->unpack || $item->byRef) {
                return null;
            }
            if ($item->key === null) {
                if (array_key_exists(PHP_INT_MAX, $items)) {
                    return null;
                }
                $items[] = $item;

                continue;
            }
            $key = $item->key;
            $negative = Node::is($key, 'expression.unary-minus');
            if (Node::is($key, 'expression.unary-minus') || Node::is($key, 'expression.unary-plus')) {
                $key = $key->expr;
                if (! Node::is($key, 'scalar.int') && ! Node::is($key, 'scalar.float')) {
                    return null;
                }
            }
            if (Node::is($key, 'scalar.int') || Node::is($key, 'scalar.float')) {
                $value = $negative ? -$key->value : $key->value;
                if (is_float($value) && (! is_finite($value) || $value >= PHP_INT_MAX || $value < PHP_INT_MIN)) {
                    return null;
                }
                $normalized = (int) $value;
            } elseif (Node::is($key, 'scalar.string')) {
                $normalized = $key->value;
            } elseif (Node::is($key, 'expression.const-fetch') && in_array(strtolower($key->name->toString()), ['true', 'false', 'null'], true)) {
                $normalized = match (strtolower($key->name->toString())) {
                    'true' => 1,
                    'false' => 0,
                    default => '',
                };
            } else {
                return null;
            }
            $items[$normalized] = $item;
        }

        return array_values($items);
    }
}
