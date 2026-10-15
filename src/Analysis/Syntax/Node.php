<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis\Syntax;

use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use InvalidArgumentException;

/** A portable syntax tree. Fields contain scalars, arrays and these nodes only.
 * @property-read mixed $adaptations
 * @property-read mixed $alias
 * @property-read mixed $args
 * @property-read mixed $arms
 * @property-read mixed $attrGroups
 * @property-read mixed $attrs
 * @property-read mixed $body
 * @property-read mixed $byRef
 * @property-read mixed $cases
 * @property-read mixed $catches
 * @property-read mixed $class
 * @property-read mixed $cond
 * @property-read mixed $conds
 * @property-read mixed $consts
 * @property-read mixed $declares
 * @property-read mixed $default
 * @property-read mixed $dim
 * @property-read mixed $else
 * @property-read mixed $elseifs
 * @property-read mixed $expr
 * @property-read mixed $exprs
 * @property-read mixed $extends
 * @property-read mixed $finally
 * @property-read mixed $flags
 * @property-read mixed $hooks
 * @property-read mixed $if
 * @property-read mixed $implements
 * @property-read mixed $init
 * @property-read mixed $insteadof
 * @property-read mixed $items
 * @property-read mixed $key
 * @property-read mixed $keyVar
 * @property-read mixed $left
 * @property-read mixed $loop
 * @property-read mixed $method
 * @property-read mixed $name
 * @property-read mixed $namespacedName
 * @property-read mixed $newModifier
 * @property-read mixed $newName
 * @property-read mixed $num
 * @property-read mixed $params
 * @property-read mixed $parts
 * @property-read mixed $prefix
 * @property-read mixed $props
 * @property-read mixed $remaining
 * @property-read mixed $returnType
 * @property-read mixed $right
 * @property-read mixed $scalarType
 * @property-read mixed $static
 * @property-read mixed $stmts
 * @property-read mixed $trait
 * @property-read mixed $traits
 * @property-read mixed $type
 * @property-read mixed $types
 * @property-read mixed $unpack
 * @property-read mixed $uses
 * @property-read mixed $value
 * @property-read mixed $valueVar
 * @property-read mixed $var
 * @property-read mixed $variadic
 * @property-read mixed $vars
 */
final readonly class Node
{
    public string $kind;

    /** @var array<string, mixed> */
    private array $fields;

    /** @param array<string, mixed> $fields
     * @param array<string, mixed> $attributes
     * @param list<Comment> $comments */
    public function __construct(
        string $kind,
        array $fields,
        public readonly SourceInput $source,
        public readonly int $start,
        public readonly int $end,
        private array $attributes = [],
        private readonly array $comments = [],
    ) {
        if (! preg_match('/^[a-z][a-z0-9.-]*$/D', $kind) || $start < 0 || $end < $start || $end > strlen($source->contents)) {
            throw new InvalidArgumentException('Invalid portable syntax node or source range.');
        }
        $shape = Schema::FIELDS[$kind] ?? null;
        if ($shape === null || array_diff(array_keys($fields), $shape) !== [] || array_diff($shape, array_keys($fields)) !== []) {
            throw new InvalidArgumentException('Unknown portable syntax kind or incomplete structural fields: '.$kind.'.');
        }
        // JSON creates a new string for each repeated kind and field key. Reuse
        // the closed vocabulary and its traversal order on both backends.
        $this->kind = Schema::canonicalKind($kind);
        $ordered = [];
        foreach ($shape as $field) {
            $ordered[$field] = $fields[$field];
        }
        $this->fields = $ordered;
        $this->validate($fields);
        $this->validate($attributes);
        foreach ([...array_keys($fields), ...array_keys($attributes)] as $key) {
            if (! is_string($key) || preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/D', $key) !== 1) {
                throw new InvalidArgumentException('Invalid portable field or attribute name.');
            }
        }
        if (! array_is_list($comments)) {
            throw new InvalidArgumentException('Portable comments must be a list.');
        }
        foreach ($comments as $comment) {
            if (! $comment instanceof Comment) {
                throw new InvalidArgumentException('Portable comments must not contain parser objects.');
            }
        }
    }

    public function __get(string $field): mixed
    {
        return $this->fields[$field] ?? $this->attributes[$field] ?? null;
    }

    public function __isset(string $field): bool
    {
        return isset($this->fields[$field]) || isset($this->attributes[$field]);
    }

    /** @param array<string, mixed> $fields */
    public function withFields(array $fields): self
    {
        return new self($this->kind, array_replace($this->fields, $fields), $this->source, $this->start, $this->end, $this->attributes, $this->comments);
    }

    /** @return list<string> */
    public function getSubNodeNames(): array
    {
        return array_keys($this->fields);
    }

    /** @return array<string, mixed> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        return new self($this->kind, $this->fields, $this->source, $this->start, $this->end, [...$this->attributes, $name => $value], $this->comments);
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getStartFilePos(): int
    {
        return $this->start;
    }

    /** Convenience for source-slice consumers; the contract's end is exclusive. */
    public function getEndFilePos(): int
    {
        return $this->end - 1;
    }

    public function getStartLine(): int
    {
        return $this->source->lineAt($this->start);
    }

    public function getEndLine(): int
    {
        return $this->source->lineAt(max($this->start, $this->end - 1));
    }

    public function getLine(): int
    {
        return $this->getStartLine();
    }

    /** @return list<Comment> */
    public function getComments(): array
    {
        return $this->comments;
    }

    public function getDocComment(): ?Comment
    {
        foreach (array_reverse($this->comments) as $comment) {
            if ($comment->documentation) {
                return $comment;
            }
        }

        return null;
    }

    public function text(): string
    {
        return substr($this->source->contents, $this->start, $this->end - $this->start);
    }

    public function toString(): string
    {
        $name = $this->fields['name'] ?? null;

        return is_string($name) ? $name : $this->text();
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /** @phpstan-assert-if-true =self $node */
    public static function is(mixed $node, string $kind): bool
    {
        if (! $node instanceof self) {
            return false;
        }
        if ($node->kind === $kind || str_starts_with($node->kind, $kind.'.')) {
            return true;
        }

        return match ($kind) {
            'node' => true,
            'expression' => str_starts_with($node->kind, 'scalar.'),
            'identifier' => $node->kind === 'var-like-identifier',
            'function-like' => in_array($node->kind, ['statement.class-method', 'statement.function', 'expression.closure', 'expression.arrow-function', 'property-hook'], true),
            'statement.class-like' => in_array($node->kind, ['statement.class', 'statement.interface', 'statement.trait', 'statement.enum'], true),
            'expression.call-like' => in_array($node->kind, ['expression.func-call', 'expression.method-call', 'expression.nullsafe-method-call', 'expression.static-call', 'expression.new'], true),
            default => false,
        };
    }

    /** @return list<Node> */
    public function getMethods(): array
    {
        return array_values(array_filter($this->fields['stmts'] ?? [], static fn ($node): bool => self::is($node, 'statement.class-method')));
    }

    /** @return list<Node> */
    public function getTraitUses(): array
    {
        return array_values(array_filter($this->fields['stmts'] ?? [], static fn ($node): bool => self::is($node, 'statement.trait-use')));
    }

    /** @return list<Node> */
    public function getProperties(): array
    {
        return array_values(array_filter($this->fields['stmts'] ?? [], static fn ($node): bool => self::is($node, 'statement.property')));
    }

    public function getMethod(string $name): ?self
    {
        foreach ($this->getMethods() as $method) {
            if (strcasecmp($method->name->toString(), $name) === 0) {
                return $method;
            }
        }

        return null;
    }

    /** @return list<Node> */
    public function getParams(): array
    {
        return $this->fields['params'] ?? [];
    }

    public function getReturnType(): ?self
    {
        return $this->fields['returnType'] ?? null;
    }

    /** @return list<Node>|null */
    public function getStmts(): ?array
    {
        return $this->fields['stmts'] ?? null;
    }

    public function isFirstClassCallable(): bool
    {
        foreach ($this->fields['args'] ?? [] as $argument) {
            if (self::is($argument, 'variadic-placeholder')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Node> */
    public function getArgs(): array
    {
        if ($this->isFirstClassCallable()) {
            throw new \LogicException('A first-class callable has no invocation arguments.');
        }

        return $this->fields['args'] ?? [];
    }

    public function getAlias(): self
    {
        if (($alias = $this->fields['alias'] ?? null) instanceof self) {
            return $alias;
        }
        $name = $this->fields['name'];
        $parts = explode('\\', $name->toString());

        return new self('identifier', ['name' => end($parts)], $this->source, $name->start, $name->end);
    }

    /** @return list<string> */
    public function getParts(): array
    {
        return explode('\\', $this->toString());
    }

    public const MODIFIER_PUBLIC = 1;

    public const MODIFIER_PROTECTED = 2;

    public const MODIFIER_PRIVATE = 4;

    public const MODIFIER_STATIC = 8;

    public const MODIFIER_ABSTRACT = 16;

    public const MODIFIER_FINAL = 32;

    public const MODIFIER_READONLY = 64;

    public function isPublic(): bool
    {
        return (($this->fields['flags'] ?? 0) & (self::MODIFIER_PROTECTED | self::MODIFIER_PRIVATE)) === 0;
    }

    public function isProtected(): bool
    {
        return (($this->fields['flags'] ?? 0) & self::MODIFIER_PROTECTED) !== 0;
    }

    public function isPrivate(): bool
    {
        return (($this->fields['flags'] ?? 0) & self::MODIFIER_PRIVATE) !== 0;
    }

    public function isStatic(): bool
    {
        return ($this->fields['static'] ?? false) || (($this->fields['flags'] ?? 0) & self::MODIFIER_STATIC) !== 0;
    }

    public function isAbstract(): bool
    {
        return (($this->fields['flags'] ?? 0) & self::MODIFIER_ABSTRACT) !== 0;
    }

    public function isFinal(): bool
    {
        return (($this->fields['flags'] ?? 0) & self::MODIFIER_FINAL) !== 0;
    }

    public function isReadonly(): bool
    {
        return (($this->fields['flags'] ?? 0) & self::MODIFIER_READONLY) !== 0;
    }

    private function validate(mixed $value): void
    {
        if ($value instanceof self) {
            if ($value === $this || $value->source !== $this->source) {
                throw new InvalidArgumentException('A portable syntax node must reference its own source snapshot.');
            }

            return;
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                $this->validate($child);
            }
        } elseif (! is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('Portable syntax fields must not contain parser objects.');
        }
    }
}
