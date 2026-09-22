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
     * @param  array<int|string, self|null>  $parameterTypes
     * @param  list<string>  $possibleTypes
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
        public ?self $element = null,
        public bool $nullable = false,
        public ?self $callbackResult = null,
        public array $parameterTypes = [],
        public array $possibleTypes = [],
        public bool $ambiguous = false,
    ) {}

    public static function type(string $type): self
    {
        return new self(type: $type, possibleTypes: [$type]);
    }

    public static function scalar(?string $literal = null): self
    {
        return new self(type: '@scalar', literal: $literal, possibleTypes: ['@scalar']);
    }

    /** @param array<int|string, self|null> $items */
    public static function array(array $items): self
    {
        return new self(type: '@array', items: $items, possibleTypes: ['@array']);
    }

    /**
     * @param  array<string, self|null>  $captures
     * @param  array<int|string, self|null>  $parameterTypes
     */
    public static function callback(
        SourceClass $source,
        Expr\Closure|Expr\ArrowFunction $callback,
        array $captures,
        array $parameterTypes = [],
    ): self {
        return new self(
            type: '@callback',
            callback: $callback,
            callbackSource: $source,
            captures: $captures,
            parameterTypes: $parameterTypes,
            possibleTypes: ['@callback'],
        );
    }

    public static function resource(string $class, bool $collection = false): self
    {
        return new self(type: $class, resourceClass: $class, resourceCollection: $collection, possibleTypes: [$class]);
    }

    public static function collection(?self $element = null): self
    {
        return new self(type: '@collection', element: $element, possibleTypes: ['@collection']);
    }

    public static function callbackResult(self $callback): self
    {
        return new self(
            type: '@callback-result',
            callback: $callback->callback,
            callbackSource: $callback->callbackSource,
            captures: $callback->captures,
            parameterTypes: $callback->parameterTypes,
            callbackResult: $callback,
            possibleTypes: ['@callback-result'],
        );
    }

    public function nullable(): self
    {
        return new self(
            type: $this->type,
            literal: $this->literal,
            items: $this->items,
            callback: $this->callback,
            callbackSource: $this->callbackSource,
            captures: $this->captures,
            resourceClass: $this->resourceClass,
            resourceCollection: $this->resourceCollection,
            unknown: $this->unknown,
            element: $this->element,
            nullable: true,
            callbackResult: $this->callbackResult,
            parameterTypes: $this->parameterTypes,
            possibleTypes: $this->possibleTypes,
            ambiguous: $this->ambiguous,
        );
    }

    public function markAmbiguous(): self
    {
        return new self(
            type: $this->type,
            literal: $this->literal,
            items: $this->items,
            callback: $this->callback,
            callbackSource: $this->callbackSource,
            captures: $this->captures,
            resourceClass: $this->resourceClass,
            resourceCollection: $this->resourceCollection,
            unknown: $this->unknown,
            element: $this->element,
            nullable: true,
            callbackResult: $this->callbackResult,
            parameterTypes: $this->parameterTypes,
            possibleTypes: $this->possibleTypes,
            ambiguous: true,
        );
    }

    public function withCallbackResult(?self $result): self
    {
        return new self(
            type: $this->type,
            literal: $this->literal,
            items: $this->items,
            callback: $this->callback,
            callbackSource: $this->callbackSource,
            captures: $this->captures,
            resourceClass: $this->resourceClass,
            resourceCollection: $this->resourceCollection,
            unknown: $this->unknown,
            element: $this->element,
            nullable: $this->nullable,
            callbackResult: $result,
            parameterTypes: $this->parameterTypes,
            possibleTypes: $this->possibleTypes,
            ambiguous: $this->ambiguous,
        );
    }

    /** @param array<int|string, self|null> $parameterTypes */
    public function withParameters(array $parameterTypes): self
    {
        return new self(
            type: $this->type,
            literal: $this->literal,
            items: $this->items,
            callback: $this->callback,
            callbackSource: $this->callbackSource,
            captures: $this->captures,
            resourceClass: $this->resourceClass,
            resourceCollection: $this->resourceCollection,
            unknown: $this->unknown,
            element: $this->element,
            nullable: $this->nullable,
            callbackResult: $this->callbackResult,
            parameterTypes: $parameterTypes,
            possibleTypes: $this->possibleTypes,
            ambiguous: $this->ambiguous,
        );
    }

    public static function unknown(): self
    {
        return new self(unknown: true);
    }

    /** @param list<self|null> $values */
    public static function merge(array $values): ?self
    {
        return self::union($values);
    }

    /** @param list<self|null> $values */
    public static function union(array $values): ?self
    {
        $known = [];
        $nullable = false;
        foreach ($values as $value) {
            if ($value === null) {
                $nullable = true;

                continue;
            }
            if ($value->isUnknown()) {
                return self::unknown();
            }
            $known[$value->signature()] = $value;
        }
        if ($known === []) {
            return $nullable ? self::scalar()->nullable() : null;
        }
        if (count($known) === 1) {
            $value = array_values($known)[0];

            return $nullable && ! $value->nullable ? $value->markAmbiguous() : $value;
        }
        if (count($known) > 4) {
            return self::unknown();
        }
        $values = array_values($known);
        $possibleTypes = [];
        foreach ($values as $value) {
            foreach ($value->typeNames() as $type) {
                $possibleTypes[$type] = true;
            }
        }

        return new self(
            type: $values[0]->type,
            nullable: $nullable || (bool) array_filter($values, fn (self $value): bool => $value->nullable),
            possibleTypes: array_keys($possibleTypes),
            ambiguous: true,
        );
    }

    public function isUnknown(): bool
    {
        return $this->unknown
            || ($this->type === null && $this->possibleTypes === [] && $this->resourceClass === null && $this->callback === null);
    }

    public function isAmbiguous(): bool
    {
        return $this->ambiguous || count($this->typeNames()) > 1;
    }

    /** @return list<string> */
    public function typeNames(): array
    {
        return $this->possibleTypes !== [] ? $this->possibleTypes : array_values(array_filter([$this->type]));
    }

    public function acceptsType(string $type): bool
    {
        foreach ($this->typeNames() as $candidate) {
            if (strcasecmp($candidate, $type) === 0) {
                return true;
            }
        }

        return false;
    }

    public function narrowType(string $type): ?self
    {
        if (! $this->acceptsType($type)) {
            return null;
        }

        return new self(
            type: $this->type,
            literal: $this->literal,
            resourceClass: $this->resourceClass,
            resourceCollection: $this->resourceCollection,
            element: $this->element,
            callbackResult: $this->callbackResult,
            parameterTypes: $this->parameterTypes,
            possibleTypes: [$type],
            ambiguous: false,
        );
    }

    public function excludeType(string $type): ?self
    {
        $remaining = array_values(array_filter(
            $this->typeNames(),
            fn (string $candidate): bool => strcasecmp($candidate, $type) !== 0,
        ));
        if ($remaining === []) {
            return null;
        }
        if (count($remaining) === 1) {
            return self::type($remaining[0]);
        }

        return self::union(array_map(self::type(...), $remaining));
    }

    private function signature(): string
    {
        return json_encode([
            $this->type,
            $this->literal,
            $this->resourceClass,
            $this->resourceCollection,
            $this->unknown,
            $this->element?->signature(),
            $this->nullable,
            $this->callbackResult?->signature(),
            $this->parameterTypes,
            $this->possibleTypes,
            $this->ambiguous,
        ], JSON_THROW_ON_ERROR);
    }
}
