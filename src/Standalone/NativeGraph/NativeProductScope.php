<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;

/** Product exclusions apply before rules and assessments, independently of test witnesses. */
final readonly class NativeProductScope
{
    /** @param list<string> $exclude */
    public function __construct(private AuditScope $scope, private array $exclude, private bool $retainTests = false, private bool $catalogMetadata = false) {}

    public function allows(string $path): bool
    {
        if (! $this->scope->covers($path) && ! ($this->catalogMetadata && in_array($path, ComposerCatalogExtractor::FILES, true))) {
            return false;
        }
        if ($this->retainTests && $this->scope->includesTests() && $this->scope->isTestPath($path)) {
            return true;
        }
        foreach ($this->exclude as $pattern) {
            if (fnmatch($pattern, $path)) {
                return false;
            }
        }

        return true;
    }

    public function project(ProjectGraphSnapshot $graph): ProjectGraphSnapshot
    {
        $keep = fn ($item): bool => $this->allows($item->path);

        return new ProjectGraphSnapshot(array_values(array_filter($graph->symbols, $keep)), array_values(array_filter($graph->edges, $keep)),
            array_values(array_filter($graph->testInvocations, $keep)), array_values(array_filter($graph->impactFacts, $keep)));
    }
}
