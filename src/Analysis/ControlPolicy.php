<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

/** Reaction to a result never changes the truth of that result. */
enum ControlPolicy: string
{
    case Advisory = 'advisory';
    case Enforce = 'enforce';

    /** @return array{policy: string, blocking: bool} */
    public function result(bool $ok): array
    {
        return ['policy' => $this->value, 'blocking' => $this === self::Enforce && ! $ok];
    }
}
