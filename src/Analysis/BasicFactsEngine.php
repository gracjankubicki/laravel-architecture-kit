<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

interface BasicFactsEngine extends AnalysisEngine
{
    /** @param list<BasicSource> $sources
     * @return list<BasicFileFacts> */
    public function analyzeBasic(array $sources, BasicFactsOptions $options): array;
}
