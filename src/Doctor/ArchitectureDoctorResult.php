<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Doctor;

use GracjanKubicki\ArchitectureKit\Architecture;

final readonly class ArchitectureDoctorResult
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     * @param  array<int, ArchitectureDoctorCheck>  $checks
     */
    public function __construct(
        public array $enabled,
        public array $checks,
        public bool $boostInstalled,
    ) {}

    public function ok(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->failed()) {
                return false;
            }
        }

        return true;
    }

    public function configOk(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->area === 'config' && $check->failed()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{
     *     ok: bool,
     *     enabled: array<int, string>,
     *     checks: array<int, array{area: string, status: string, path: string, message: string|null}>,
     *     boost: array{installed: bool, sync_command: string|null}
     * }
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok(),
            'enabled' => array_map(
                fn ($architecture): string => $architecture instanceof Architecture ? $architecture->value : $architecture,
                $this->enabled,
            ),
            'checks' => array_map(
                fn (ArchitectureDoctorCheck $check): array => $check->toArray(),
                $this->checks,
            ),
            'boost' => [
                'installed' => $this->boostInstalled,
                'sync_command' => $this->boostInstalled ? 'php artisan boost:update --discover' : null,
            ],
        ];
    }
}
