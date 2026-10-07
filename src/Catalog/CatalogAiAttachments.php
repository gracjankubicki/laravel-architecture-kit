<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node;
use PhpParser\Node\Expr;

/** Sanitized attachment facts shared by syntax extraction and existing local value tracking. */
final class CatalogAiAttachments
{
    /** @return array{resolved: bool, limited: bool, files: list<array{receiver: string, method: string, valid: bool, offset: int, line: int, end_line: int}>} */
    public static function extract(FileContext $file, ?Expr $expression): array
    {
        if ($expression === null) {
            return ['resolved' => true, 'limited' => false, 'files' => []];
        }
        $items = $expression instanceof Expr\Array_ ? self::attachmentItems($expression) : null;
        $limited = $expression instanceof Expr\Array_ && count($expression->items) > 128;
        $resolved = $items !== null && ! $limited;
        $files = [];
        foreach ($items ?? [] as $item) {
            $value = $item?->value;
            if ($item === null || $item->unpack || $item->byRef || ! $value instanceof Expr\StaticCall
                || ! $value->class instanceof Node\Name || ! $value->name instanceof Node\Identifier || $value->isFirstClassCallable()) {
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
     * @return list<Node\ArrayItem>|null
     */
    private static function attachmentItems(Expr\Array_ $array): ?array
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
            $negative = $key instanceof Expr\UnaryMinus;
            if ($key instanceof Expr\UnaryMinus || $key instanceof Expr\UnaryPlus) {
                $key = $key->expr;
                if (! $key instanceof Node\Scalar\Int_ && ! $key instanceof Node\Scalar\Float_) {
                    return null;
                }
            }
            if ($key instanceof Node\Scalar\Int_ || $key instanceof Node\Scalar\Float_) {
                $value = $negative ? -$key->value : $key->value;
                if (is_float($value) && (! is_finite($value) || $value >= PHP_INT_MAX || $value < PHP_INT_MIN)) {
                    return null;
                }
                $normalized = (int) $value;
            } elseif ($key instanceof Node\Scalar\String_) {
                $normalized = $key->value;
            } elseif ($key instanceof Expr\ConstFetch && in_array(strtolower($key->name->toString()), ['true', 'false', 'null'], true)) {
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
