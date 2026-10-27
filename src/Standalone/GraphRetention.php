<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

final class GraphRetention
{
    public function __construct(public float $lastActivity) {}

    public function record(string $kind, float $at): void
    {
        if (in_array($kind, ['query', 'code'], true)) {
            $this->lastActivity = max($this->lastActivity, $at);
        }
    }

    public function expired(float $now, bool $building, int $leases): bool
    {
        return ! $building && $leases === 0 && $now - $this->lastActivity >= GraphProtocol::IDLE_SECONDS;
    }
}
