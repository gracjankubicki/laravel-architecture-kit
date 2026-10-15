<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

/** Stop callbacks from decoding more output while failed processes are drained. */
final class WorkerOutputGate
{
    public bool $accepting = true;
}
