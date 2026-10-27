<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use RuntimeException;

/** Preserve the exact reservation counters before the worker unwinds its live graph inputs. */
final class GraphAdmissionRefused extends RuntimeException
{
    /** @param array<string, mixed> $measurements */
    public function __construct(string $reason, public readonly array $measurements)
    {
        parent::__construct($reason);
    }
}
