<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\PublicApi;

use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;

/** Bound retained facts and expansion work before allocation. */
final class DeclarationBudget
{
    private int $used = 0;

    public bool $limited = false;

    /** @phpstan-impure */
    public function claim(): bool
    {
        $ceiling = MemoryLimit::bytes();
        if ($this->limited || $this->used >= 10000 || ($ceiling !== null && memory_get_usage(true) + 65536 > $ceiling * 0.75)) {
            $this->limited = true;

            return false;
        }
        $this->used++;

        return true;
    }
}
