<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

/** Private persistence policy; public diagnostics retain their existing wire shape. */
enum AnalysisLimitReason: string
{
    case Structure = 'structure';
    case Memory = 'memory';
    case Unknown = 'unknown';
}
