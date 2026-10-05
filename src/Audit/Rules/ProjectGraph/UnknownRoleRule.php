<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectAuditRule;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;

final readonly class UnknownRoleRule implements ProjectAuditRule
{
    public function __construct(private string $level = 'off') {}

    /** @param array<int, mixed> $enabled
     * @param  list<string>|null  $focusPaths
     * @return list<AuditFinding>
     */
    public function check(ProjectGraphSnapshot $graph, array $enabled, ?array $focusPaths = null): array
    {
        if ($this->level === 'off') {
            return [];
        }
        $findings = [];
        foreach ($graph->symbols as $symbol) {
            if ($symbol->role !== 'unknown' || ($focusPaths !== null && ! in_array($symbol->path, $focusPaths, true))) {
                continue;
            }
            $findings[] = new AuditFinding($this->level, 'unknown-role', $symbol->path, $symbol->line, 'Unknown architectural layer for '.$symbol->name.'. Declare a role or check the existing directory convention.', code: $this->level === 'error' ? 'E_UNKNOWN_ROLE' : 'W_UNKNOWN_ROLE');
        }

        return $findings;
    }
}
