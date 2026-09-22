<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Audit\AnalysisNotice;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\AuditSuggestion;

final readonly class ControllerAnalysisResult
{
    public const COMPLETE = 'complete';

    public const INCOMPLETE = 'incomplete';

    public const NOT_RUN = 'not_run';

    /**
     * @param  list<AuditFinding>  $findings
     * @param  list<AuditSuggestion>  $suggestions
     * @param  list<AnalysisNotice>  $notices
     */
    public function __construct(
        public array $findings = [],
        public array $suggestions = [],
        public array $notices = [],
        public string $status = self::NOT_RUN,
    ) {}
}
