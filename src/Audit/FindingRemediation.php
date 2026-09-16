<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

/**
 * A concrete change for a violation whose resolution follows from the rule itself.
 *
 * Deliberately narrow. A proposal is offered only where the destination is stated by the
 * rule, not inferred from what the author might have meant, because a confident wrong
 * suggestion costs more than none: the agent applies it, the gate stays red, and the fix
 * loop restarts one step further from the truth.
 *
 * The proposal is text for an agent or a developer to weigh. Nothing here edits a file.
 */
final readonly class FindingRemediation
{
    /**
     * Codes whose remedy is a move with a named destination, mapped to that destination.
     *
     * @var array<string, array{move: string, to: string}>
     */
    private const PROPOSALS = [
        'E_THIN_CONTROLLER_INLINE_VALIDATION' => ['move' => 'the inline validation', 'to' => 'a Form Request typed on the controller method'],
        'E_THIN_CONTROLLER_MODEL_WRITE' => ['move' => 'the model write', 'to' => 'an Action invoked by the controller'],
        'E_THIN_CONTROLLER_TRANSACTION' => ['move' => 'the transaction and the work inside it', 'to' => 'an Action invoked by the controller'],
        'E_THIN_CONTROLLER_DISPATCH' => ['move' => 'the dispatch', 'to' => 'an Action invoked by the controller'],
        'E_ROUTE_INLINE_VALIDATION' => ['move' => 'the inline validation', 'to' => 'a Form Request on a controller the route points at'],
        'E_ROUTE_MODEL_WRITE' => ['move' => 'the model write', 'to' => 'an Action called from a controller'],
        'E_ROUTE_TRANSACTION' => ['move' => 'the transaction and the work inside it', 'to' => 'an Action called from a controller'],
        'E_ROUTE_DISPATCH' => ['move' => 'the dispatch', 'to' => 'an Action called from a controller'],
        'E_PORT_BYPASS' => ['move' => 'the dependency on the concrete adapter', 'to' => 'the port it implements, bound in a service provider'],
    ];

    /**
     * @return array{summary: string, move: string, to: string}|null
     */
    public static function for(string $code, ?FindingOccurrence $occurrence = null): ?array
    {
        $proposal = self::PROPOSALS[$code] ?? null;

        if ($proposal === null) {
            return null;
        }

        if ($code === 'E_PORT_BYPASS' && $occurrence?->role === 'adapter') {
            $proposal = [
                'move' => 'the external provider call',
                'to' => 'an Action or cohesive Service that depends on the available port',
            ];
        }

        $where = $occurrence === null
            ? ''
            : ' in '.($occurrence->symbol ?? $occurrence->path).($occurrence->line !== null ? ' at line '.$occurrence->line : '');

        return [
            'summary' => 'Move '.$proposal['move'].$where.' to '.$proposal['to'].'.',
            'move' => $proposal['move'],
            'to' => $proposal['to'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_keys(self::PROPOSALS);
    }
}
