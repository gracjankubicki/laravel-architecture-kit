<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

interface AnalysisEngine
{
    public function descriptor(): EngineDescriptor;

    public function analyze(SourceInput $source): AnalysisDocument;
}
