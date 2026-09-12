<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Context;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\DependencyEdge;

/**
 * How badly a change to one symbol is likely to break the thing that depends on it.
 *
 * Relationships used to be sorted alphabetically, so with the default limit of 20 the
 * result was cut by class name: a subclass that breaks at load time could fall out of the
 * answer while a passing type reference stayed in it. The ordering below is derived from
 * the edge kind the graph already records, not from a new heuristic.
 */
final readonly class ImpactRanking
{
    public const BREAKING = 'breaking';

    public const SIGNATURE = 'signature';

    public const USAGE = 'usage';

    public const CONTEXT = 'context';

    /**
     * Levels in the order the answer uses them, for schema and documentation.
     *
     * @var array<int, string>
     */
    public const LEVELS = [self::BREAKING, self::SIGNATURE, self::USAGE, self::CONTEXT];

    /**
     * Inheritance and contracts. The dependent cannot load at all once the shape it
     * builds on changes, so this is where a rename or a removed method hurts first.
     *
     * @var array<int, string>
     */
    private const BREAKING_KINDS = ['extends', 'implements', 'trait'];

    /**
     * The symbol appears in a signature, so a change is caught by the type system of the
     * dependent rather than at the call site alone.
     *
     * @var array<int, string>
     */
    private const SIGNATURE_KINDS = ['parameter', 'property', 'return'];

    /**
     * Executable use: construction, a static call, a catch clause, an attribute.
     *
     * @var array<int, string>
     */
    private const USAGE_KINDS = ['new', 'static', 'instanceof', 'catch', 'attribute', 'class-constant'];

    /**
     * Weak kinds the graph records for context only. Listed so the guard test can prove
     * every kind the builder emits has been classified deliberately, rather than falling
     * through to `context` because nobody looked at it.
     *
     * @var array<int, string>
     */
    private const CONTEXT_KINDS = ['class-reference', 'eloquent-relation'];

    /**
     * Every kind this ranking has an opinion about.
     *
     * @return array<int, string>
     */
    public static function classifiedKinds(): array
    {
        return [...self::BREAKING_KINDS, ...self::SIGNATURE_KINDS, ...self::USAGE_KINDS, ...self::CONTEXT_KINDS];
    }

    /**
     * Order used for sorting; lower means it belongs closer to the top of the answer.
     *
     * @var array<string, int>
     */
    private const ORDER = [
        self::BREAKING => 0,
        self::SIGNATURE => 1,
        self::USAGE => 2,
        self::CONTEXT => 3,
    ];

    public static function for(DependencyEdge $edge): string
    {
        // A weak edge is context only: `SomeClass::class` and Eloquent relation targets
        // are visible to the reader but do not break when the target changes.
        if (! $edge->strong) {
            return self::CONTEXT;
        }

        return match (true) {
            in_array($edge->kind, self::BREAKING_KINDS, true) => self::BREAKING,
            in_array($edge->kind, self::SIGNATURE_KINDS, true) => self::SIGNATURE,
            in_array($edge->kind, self::USAGE_KINDS, true) => self::USAGE,
            default => self::CONTEXT,
        };
    }

    public static function rank(string $impact): int
    {
        return self::ORDER[$impact] ?? self::ORDER[self::CONTEXT];
    }

    /**
     * @return array<int, string>
     */
    public static function levels(): array
    {
        return self::LEVELS;
    }
}
