<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Framework;

/** Audit-local registrations consulted by framework dispatch. */
final readonly class FrameworkContext
{
    public const KNOWN = 'known';

    public const EMPTY = 'empty';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  list<string>  $providers
     * @param  array<string, string>  $gatePolicies
     * @param  array<string, array{class: string, method: string}|FrameworkValue>  $gateAbilities
     * @param  list<array{class: string, method: string}|FrameworkValue>  $gateBefore
     * @param  list<array{class: string, method: string}|FrameworkValue>  $gateAfter
     * @param  list<FrameworkValue>  $inertiaShares
     * @param  array<string, array{class: string, method: string}|FrameworkValue>  $fortifyActions
     * @param  array<string, FrameworkValue>  $fortifyViews
     * @param  array<string, FrameworkValue>  $fortifyCallbacks
     * @param  list<array{class: string, method: string}>  $fortifyPipeline
     * @param  array<string, string>  $bindings
     * @param  list<string>  $origins
     */
    public function __construct(
        public string $status = self::EMPTY,
        public array $providers = [],
        public array $gatePolicies = [],
        public array $gateAbilities = [],
        public array $gateBefore = [],
        public array $gateAfter = [],
        public array $inertiaShares = [],
        public array $fortifyActions = [],
        public array $fortifyViews = [],
        public array $fortifyCallbacks = [],
        public array $fortifyPipeline = [],
        public array $bindings = [],
        public array $origins = [],
        public ?string $unavailable = null,
    ) {}

    public static function unavailable(string $reason): self
    {
        return new self(status: self::UNAVAILABLE, unavailable: $reason);
    }
}
