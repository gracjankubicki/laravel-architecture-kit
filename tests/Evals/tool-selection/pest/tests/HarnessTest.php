<?php

declare(strict_types=1);

use Pest\Evals\Scorers\AgentTrajectory;
use Pest\Evals\Scorers\ToolCallMatch;

it('rejects wrong tool names and arguments with the real plugin scorer', function (): void {
    $scorer = new ToolCallMatch(['scaffold' => ['architecture' => 'actions', 'name' => 'SendInvoice']]);
    $trace = static fn (string $name, string $argument): string => json_encode(['tool_calls' => [
        ['name' => $name, 'arguments' => ['architecture' => 'actions', 'name' => $argument]],
    ]], JSON_THROW_ON_ERROR);
    expect($scorer->score('', $trace('scaffold', 'SendInvoice'))->score)->toBe(1.0)
        ->and($scorer->score('', $trace('doctor', 'SendInvoice'))->score)->toBe(0.0)
        ->and($scorer->score('', $trace('scaffold', 'WrongAction'))->score)->toBe(0.0);
});

it('rejects context after impact with the real plugin trajectory scorer', function (): void {
    $scorer = new AgentTrajectory(['enabled-architectures', 'architecture-context', 'impact']);
    $trace = static fn (array $names): string => json_encode(['tool_calls' => array_map(
        static fn (string $name): array => ['name' => $name, 'arguments' => []], $names,
    )], JSON_THROW_ON_ERROR);
    expect($scorer->score('', $trace(['enabled-architectures', 'architecture-context', 'impact']))->score)->toBe(1.0)
        ->and($scorer->score('', $trace(['enabled-architectures', 'impact', 'architecture-context']))->score)->toBeLessThan(1.0);
});
