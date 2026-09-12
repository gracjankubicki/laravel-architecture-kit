<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;

/**
 * What a run has to parse and what it can restore, decided before anything is read.
 *
 * The audit cannot simply ask for a finished graph: its rules need the syntax tree of the
 * files they check, so asking twice would parse the project twice. Handing it the plan
 * instead lets it parse each file once, run the rules on it, and keep the contribution,
 * while files it has no rules to run are restored rather than opened.
 */
final readonly class GraphBuildPlan
{
    /**
     * @param  array<string, string>  $files  Project-relative path to its absolute path.
     * @param  array<int, string>  $toParse  Paths whose contribution has to be built again.
     * @param  array<string, FileGraphEntry>  $reusable  Contributions restored from the previous run.
     * @param  CacheStatus  $cacheStatus  Why the stored graph was or was not used.
     * @param  bool  $stale  Whether the stored entry no longer describes the project.
     */
    public function __construct(
        public GraphCacheSignature $signature,
        public array $files,
        public array $toParse,
        public array $reusable,
        public CacheStatus $cacheStatus = CacheStatus::Disabled,
        public bool $stale = true,
    ) {}

    public function absolutePath(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }
}
