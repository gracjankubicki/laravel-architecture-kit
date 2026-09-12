<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

/**
 * The one place a finding was reported, as opposed to the rule it belongs to.
 *
 * An explanation without this can only restate the rule, which is the same sentence for
 * every violation in the project. The agent is then left to work out which symbol is at
 * fault and where the behaviour should go, and a wrong guess restarts the fix loop.
 */
final readonly class FindingOccurrence
{
    public function __construct(
        public string $path,
        public ?int $line = null,
        /** Symbol declared at that path, when the project graph can name one. */
        public ?string $symbol = null,
        /** Role of that symbol, used to say where the behaviour belongs. */
        public ?string $role = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'line' => $this->line,
            'symbol' => $this->symbol,
            'role' => $this->role,
        ];
    }
}
