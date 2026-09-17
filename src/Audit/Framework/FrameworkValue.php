<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Framework;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceClass;
use PhpParser\Node\Expr;

/** A bounded value known by the static framework interpreter. */
final readonly class FrameworkValue
{
    /**
     * @param  array<int|string, self|null>  $items
     * @param  array<string, self|null>  $captures
     */
    public function __construct(
        public ?string $type = null,
        public ?string $literal = null,
        public array $items = [],
        public Expr\Closure|Expr\ArrowFunction|null $callback = null,
        public ?SourceClass $callbackSource = null,
        public array $captures = [],
        public ?string $resourceClass = null,
        public bool $resourceCollection = false,
        public bool $unknown = false,
    ) {}

    public static function type(string $type): self
    {
        return new self(type: $type);
    }

    public static function scalar(?string $literal = null): self
    {
        return new self(type: '@scalar', literal: $literal);
    }

    /** @param array<int|string, self|null> $items */
    public static function array(array $items): self
    {
        return new self(type: '@array', items: $items);
    }

    /** @param array<string, self|null> $captures */
    public static function callback(SourceClass $source, Expr\Closure|Expr\ArrowFunction $callback, array $captures): self
    {
        return new self(type: '@callback', callback: $callback, callbackSource: $source, captures: $captures);
    }

    public static function resource(string $class, bool $collection = false): self
    {
        return new self(type: $class, resourceClass: $class, resourceCollection: $collection);
    }

    public static function unknown(): self
    {
        return new self(unknown: true);
    }

    /** @param list<self|null> $values */
    public static function merge(array $values): ?self
    {
        if ($values === []) {
            return null;
        }

        $first = $values[0];
        foreach ($values as $value) {
            if ($value != $first) {
                return self::unknown();
            }
        }

        return $first;
    }

    public function isUnknown(): bool
    {
        return $this->unknown || $this->type === null;
    }
}
