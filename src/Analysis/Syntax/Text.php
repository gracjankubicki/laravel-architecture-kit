<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis\Syntax;

/** Display constant expressions and compare bodies without parser-specific formatting. */
final class Text
{
    public function prettyPrintExpr(Node $node): string
    {
        if (Node::is($node, 'name')) {
            return ($node->kind === 'name.fully-qualified' ? '\\' : ($node->kind === 'name.relative' ? 'namespace\\' : '')).$node->toString();
        }

        return match ($node->kind) {
            'identifier', 'var-like-identifier' => $node->toString(),
            'scalar.string' => str_starts_with(ltrim($node->text(), 'bB'), '"')
                ? json_encode($node->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : var_export($node->value, true),
            'scalar.int', 'scalar.float' => var_export($node->value, true),
            'expression.variable' => '$'.(is_string($node->name) ? $node->name : '{'.$this->prettyPrintExpr($node->name).'}'),
            'expression.const-fetch' => $this->prettyPrintExpr($node->name),
            'expression.class-const-fetch' => $this->prettyPrintExpr($node->class).'::'.$this->prettyPrintExpr($node->name),
            'expression.unary-minus' => '-'.$this->prettyPrintExpr($node->expr),
            'expression.unary-plus' => '+'.$this->prettyPrintExpr($node->expr),
            'expression.array' => '['.implode(', ', array_map(fn ($item) => $item === null ? '' : $this->prettyPrintExpr($item), $node->items)).']',
            'array-item' => ($node->key === null ? '' : $this->prettyPrintExpr($node->key).' => ').($node->byRef ? '&' : '').($node->unpack ? '...' : '').$this->prettyPrintExpr($node->value),
            'expression.new' => 'new '.$this->prettyPrintExpr($node->class).'('.$this->arguments($node).')',
            'arg' => ($node->name === null ? '' : $node->name->toString().': ').($node->unpack ? '...' : '').$this->prettyPrintExpr($node->value),
            default => $this->sourceText($node),
        };
    }

    /** Stable structural form for hashes, excluding positions, comments and derived attributes.
     * @param list<Node> $nodes */
    public function prettyPrint(array $nodes): string
    {
        return json_encode(array_map($this->value(...), $nodes), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function value(mixed $value): mixed
    {
        if ($value instanceof Node) {
            return ['kind' => $value->kind, 'fields' => array_map($this->value(...), $value->fields())];
        }
        if (is_array($value)) {
            return array_map($this->value(...), $value);
        }

        return $value;
    }

    private function arguments(Node $node): string
    {
        return $node->isFirstClassCallable() ? '...' : implode(', ', array_map($this->prettyPrintExpr(...), $node->getArgs()));
    }

    private function sourceText(Node $node): string
    {
        $tokens = token_get_all('<?php '.$node->text());
        $result = [];
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $result[] = $token[1];
            } else {
                $result[] = $token;
            }
        }

        return implode(' ', $result);
    }
}
