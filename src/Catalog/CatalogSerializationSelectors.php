<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/** Serialization selectors preserve hashed keys, not attribute values. */
final class CatalogSerializationSelectors
{
    /** @return array{properties: array<int, array<string, mixed>>, attributes: array<int, array<string, mixed>>, conventions: array<int, bool|null>, limited: bool} */
    public static function extract(FileContext $file): array
    {
        $properties = $attributes = $conventions = [];
        $visited = 0;
        $limited = false;
        $walk = function (Node $node) use (&$walk, &$properties, &$attributes, &$conventions, &$visited, &$limited, $file): void {
            if ($limited) {
                return;
            }
            if (++$visited > 25000 || $visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
                $limited = true;

                return;
            }
            if ($node instanceof Stmt\Property) {
                foreach ($node->props as $property) {
                    if ($property->name->toString() === 'snakeAttributes') {
                        $value = $property->default instanceof Expr\ConstFetch ? strtolower($property->default->name->toString()) : null;
                        $conventions[max(0, $property->getStartFilePos())] = $node->isStatic() && $node->isPublic() && in_array($value, ['true', 'false'], true) ? $value === 'true' : null;
                    }
                    if (! in_array($property->name->toString(), ['hidden', 'visible', 'appends'], true)) {
                        continue;
                    }
                    $keys = [];
                    $resolved = ! $node->isStatic() && $property->default instanceof Expr\Array_ && count($property->default->items) <= 128;
                    if ($property->default instanceof Expr\Array_) {
                        if (count($property->default->items) > 128) {
                            $limited = true;
                        } else {
                            foreach ($property->default->items as $item) {
                                if ($item === null || $item->unpack || ! $item->value instanceof Scalar\String_) {
                                    $resolved = false;
                                } else {
                                    $keys[] = hash('sha256', $item->value->value);
                                }
                            }
                        }
                    }
                    $properties[max(0, $property->getStartFilePos())] = ['resolved' => $resolved, 'keys' => array_values(array_unique($keys))];
                }
            }
            if ($node instanceof Stmt\ClassLike) {
                foreach ($node->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        if (in_array($file->resolvedName($attribute->name), ['Illuminate\\Database\\Eloquent\\Attributes\\Hidden', 'Illuminate\\Database\\Eloquent\\Attributes\\Visible', 'Illuminate\\Database\\Eloquent\\Attributes\\Appends'], true)) {
                            $offset = max(0, $node->getStartFilePos());
                            $attributes[$offset] ??= ['hidden' => null, 'visible' => null, 'appends' => null];
                            $kind = strtolower(substr($file->resolvedName($attribute->name), strrpos($file->resolvedName($attribute->name), '\\') + 1));
                            $keys = [];
                            $resolved = $attribute->args !== [] && count($attribute->args) <= 128;
                            if (count($attribute->args) > 128) {
                                $limited = true;
                            } else {
                                foreach ($attribute->args as $arg) {
                                    $resolved = $resolved && ! $arg->unpack && $arg->name === null && ($arg->value instanceof Scalar\String_ || $arg->value instanceof Expr\Array_);
                                }
                                $first = $attribute->args[0]->value ?? null;
                                if ($first instanceof Expr\Array_) {
                                    if (count($first->items) > 128) {
                                        $limited = true;
                                        $resolved = false;
                                    } else {
                                        foreach ($first->items as $item) {
                                            if ($item === null || $item->unpack || ! $item->value instanceof Scalar\String_) {
                                                $resolved = false;
                                            } else {
                                                $keys[] = hash('sha256', $item->value->value);
                                            }
                                        }
                                    }
                                } else {
                                    foreach ($attribute->args as $arg) {
                                        if ($arg->value instanceof Scalar\String_) {
                                            $keys[] = hash('sha256', $arg->value->value);
                                        } else {
                                            $resolved = false;
                                        }
                                    }
                                }
                            }
                            $resolved = $resolved && $attributes[$offset][$kind] === null;
                            $attributes[$offset][$kind] = ['resolved' => $resolved, 'keys' => array_values(array_unique($keys))];
                        }
                    }
                }
            }
            foreach ($node->getSubNodeNames() as $key) {
                foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                    if ($child instanceof Node) {
                        $walk($child);
                    }
                }
            }
        };
        foreach ($file->ast() ?? [] as $node) {
            $walk($node);
        }
        // Release the recursive closure's self-reference and captured FileContext/AST.
        $walk = null;

        return ['properties' => $properties, 'attributes' => $attributes, 'conventions' => $conventions, 'limited' => $limited];
    }

    public static function validate(mixed $value): void
    {
        if (! is_array($value) || array_keys($value) !== ['resolved', 'keys'] || ! is_bool($value['resolved'])
            || ! is_array($value['keys']) || ! array_is_list($value['keys']) || count($value['keys']) > 128) {
            throw new InvalidArgumentException('Invalid serialization selector.');
        }
        foreach ($value['keys'] as $key) {
            if (! is_string($key) || preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1) {
                throw new InvalidArgumentException('Invalid serialization attribute key.');
            }
        }
    }
}
