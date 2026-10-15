<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis\Syntax;

use Closure;
use InvalidArgumentException;

/** Evaluate syntax from a closed set of literal operations, never project PHP. */
final readonly class ConstantValue
{
    public function __construct(private ?Closure $fallback = null) {}

    public function evaluateDirectly(Node $node): mixed
    {
        return $this->read($node, 0);
    }

    private function read(Node $node, int $depth): mixed
    {
        if ($depth > 64) {
            throw new InvalidArgumentException('Constant expression exceeds its nesting budget.');
        }
        if (in_array($node->kind, ['scalar.string', 'scalar.int', 'scalar.float'], true)) {
            return $node->value;
        }
        if ($node->kind === 'expression.const-fetch') {
            return match (strtolower($node->name->toString())) {
                'true' => true, 'false' => false, 'null' => null,
                default => $this->unknown($node),
            };
        }
        if ($node->kind === 'expression.array') {
            $result = [];
            foreach ($node->items as $item) {
                if ($item === null || $item->byRef) {
                    throw new InvalidArgumentException('Invalid constant array item.');
                }
                $value = $this->read($item->value, $depth + 1);
                if ($item->unpack) {
                    if (! is_array($value)) {
                        throw new InvalidArgumentException('Constant array unpacking requires an array.');
                    }
                    $result = [...$result, ...$value];
                } elseif ($item->key !== null) {
                    $result[$this->read($item->key, $depth + 1)] = $value;
                } else {
                    $result[] = $value;
                }
            }

            return $result;
        }
        if ($node->kind === 'expression.unary-minus' || $node->kind === 'expression.unary-plus') {
            $value = $this->read($node->expr, $depth + 1);

            return $node->kind === 'expression.unary-minus' ? -$value : +$value;
        }
        if ($node->kind === 'expression.binary-op.concat') {
            return $this->read($node->left, $depth + 1).$this->read($node->right, $depth + 1);
        }

        return $this->unknown($node);
    }

    private function unknown(Node $node): mixed
    {
        if ($this->fallback !== null) {
            return ($this->fallback)($node);
        }
        throw new InvalidArgumentException('Expected literal data, found '.$node->kind.'.');
    }
}
