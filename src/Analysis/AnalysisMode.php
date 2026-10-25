<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

/** Source mapping and semantic interpretation are separate choices. */
enum AnalysisMode: string
{
    case Basic = 'basic';
    case Deep = 'deep';
}
