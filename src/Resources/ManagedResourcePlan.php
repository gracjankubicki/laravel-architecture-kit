<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Resources;

final readonly class ManagedResourcePlan
{
    /**
     * @param  array<int, string>  $create
     * @param  array<int, string>  $update
     * @param  array<int, string>  $remove
     * @param  array<int, string>  $blocked
     */
    public function __construct(
        public array $create = [],
        public array $update = [],
        public array $remove = [],
        public array $blocked = [],
    ) {}

    public function hasChanges(): bool
    {
        return $this->create !== [] || $this->update !== [] || $this->remove !== [];
    }

    /** @return array<string, array<int, string>> */
    public function toArray(): array
    {
        return [
            'create' => $this->create,
            'update' => $this->update,
            'remove' => $this->remove,
            'blocked' => $this->blocked,
        ];
    }
}
