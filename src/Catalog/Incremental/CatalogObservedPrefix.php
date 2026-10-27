<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog\Incremental;

use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchSession;
use RuntimeException;

/** Live continuity proof, never serialized as a claim of current filesystem bytes. */
final readonly class CatalogObservedPrefix
{
    public function __construct(private WatchSession|\Closure $watch, private string $throughSequence)
    {
        CatalogSequence::validate($throughSequence);
    }

    public function assertApplicable(CatalogPatch $patch, CatalogGeneration $base): void
    {
        if ($this->watch instanceof \Closure) {
            // An internal authenticated probe drains the coordinator's real WatchClient.
            // The probe binds product/scope/epoch/base snapshot, not public request data.
            if ($base->sequence === '0' || ($this->watch)($patch, $base) !== true) {
                throw new RuntimeException('preparation_required:observation_not_continuous');
            }

            return;
        }
        if ($base->sequence === '0' || $this->watch->closed || ! $this->watch->ready || $this->watch->rescanRequired
            || $this->watch->health !== 'observing' || $this->watch->id !== $patch->session
            || $this->watch->sequence !== $this->throughSequence || $this->throughSequence !== $patch->lastSequence) {
            throw new RuntimeException('preparation_required:observation_not_continuous');
        }
    }
}
