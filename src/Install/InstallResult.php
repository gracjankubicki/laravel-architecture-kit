<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Install;

final readonly class InstallResult
{
    /**
     * @param  array<int, string>  $creates
     * @param  array<int, string>  $updates
     * @param  array<int, string>  $blocked
     */
    public function __construct(
        public array $creates = [],
        public array $updates = [],
        public array $blocked = [],
    ) {}

    public function ok(): bool
    {
        return $this->blocked === [];
    }

    public function hasChanges(): bool
    {
        return $this->creates !== [] || $this->updates !== [];
    }
}
