<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache;

use RuntimeException;

/** Retry in a fresh worker, passing rejectedGeneration to GraphSource::load. */
final class FreshGraphProcessRequired extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?string $rejectedGeneration, public readonly string $store, public readonly string $product)
    {
        parent::__construct('Graph restore requires a fresh build process: '.$product.':'.$reason);
    }
}
