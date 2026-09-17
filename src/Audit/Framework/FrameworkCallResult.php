<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Framework;

/** A framework call description shared by the read and reachability walkers. */
final readonly class FrameworkCallResult
{
    /**
     * @param  list<array{class: string, method: string, arguments?: array<int|string, FrameworkValue|null>}>  $targets
     * @param  list<FrameworkValue>  $callbacks
     * @param  list<array{kind: string, detail: string}>  $effects
     */
    public function __construct(
        public bool $handled,
        public ?FrameworkValue $value = null,
        public array $targets = [],
        public array $callbacks = [],
        public array $effects = [],
        public ?string $incomplete = null,
    ) {}

    public static function unhandled(): self
    {
        return new self(false);
    }

    public static function value(?FrameworkValue $value): self
    {
        return new self(true, $value);
    }

    public static function effect(string $kind, string $detail, ?FrameworkValue $value = null): self
    {
        return new self(true, $value, effects: [['kind' => $kind, 'detail' => $detail]]);
    }

    /** @param list<FrameworkValue> $callbacks */
    public static function callbacks(array $callbacks, ?FrameworkValue $value = null): self
    {
        return new self(true, $value, callbacks: $callbacks);
    }
}
