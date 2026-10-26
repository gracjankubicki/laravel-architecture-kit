<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use RuntimeException;

final class GraphPending extends RuntimeException
{
    /** @param array<string, mixed> $response */
    public function __construct(public readonly array $response)
    {
        parent::__construct('Map is '.$response['availability'].'. Continue source inspection, then collect architecture-map result for job_id.');
    }
}
