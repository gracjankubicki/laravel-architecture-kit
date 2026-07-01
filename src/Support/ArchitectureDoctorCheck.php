<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Support;

final readonly class ArchitectureDoctorCheck
{
    public function __construct(
        public string $area,
        public string $status,
        public string $path,
        public ?string $message = null,
    ) {
    }

    public function failed(): bool
    {
        return in_array($this->status, ['missing', 'outdated', 'stale', 'blocked'], true);
    }

    /**
     * @return array{area: string, status: string, path: string, message: string|null}
     */
    public function toArray(): array
    {
        return [
            'area' => $this->area,
            'status' => $this->status,
            'path' => $this->path,
            'message' => $this->message,
        ];
    }
}
