<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;

interface ProjectAuditRule
{
    /**
     * @param  array<int, mixed>  $enabled
     * @param  array<int, string>|null  $focusPaths
     * @return array<int, AuditFinding>
     */
    public function check(ProjectGraphSnapshot $graph, array $enabled, ?array $focusPaths = null): array;
}
