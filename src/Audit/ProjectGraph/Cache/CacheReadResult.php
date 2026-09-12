<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache;

/**
 * What came back from the cache, and why.
 *
 * The graph is null for every status except `Fresh`; the status is what separates an
 * ordinary first run from an entry that is being rejected on every run.
 */
final readonly class CacheReadResult
{
    public function __construct(
        public ?CachedGraph $graph,
        public CacheStatus $status,
    ) {}
}
