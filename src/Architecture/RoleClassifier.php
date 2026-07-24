<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Architecture;

final readonly class RoleClassifier
{
    public function classify(string $path, string $name, string $kind, bool $hasMethods = true): string
    {
        if ($kind === 'interface' && $this->isPort($path, $name, $hasMethods)) {
            return 'port';
        }

        return match (true) {
            str_starts_with($path, 'app/Providers/') => 'composition',
            str_starts_with($path, 'app/Http/Controllers/'),
            str_starts_with($path, 'app/Http/Requests/'),
            str_starts_with($path, 'app/Http/Resources/') => 'adapter',
            str_starts_with($path, 'app/Http/Integrations/'),
            str_starts_with($path, 'app/Infrastructure/'),
            str_starts_with($path, 'app/Adapters/'),
            str_contains($path, '/Infrastructure/'),
            str_contains($path, '/Adapters/') => 'infrastructure',
            str_starts_with($path, 'app/Actions/'),
            str_starts_with($path, 'app/Services/'),
            str_starts_with($path, 'app/Queries/'),
            str_starts_with($path, 'app/Jobs/'),
            str_starts_with($path, 'app/Listeners/') => 'application',
            str_starts_with($path, 'app/Models/'),
            str_starts_with($path, 'app/Domain/'),
            str_starts_with($path, 'app/Data/'),
            str_starts_with($path, 'app/ValueObjects/'),
            str_starts_with($path, 'app/Enums/') => 'domain',
            default => 'unknown',
        };
    }

    public function isPort(string $path, string $name, bool $hasMethods = true): bool
    {
        if ($this->isIgnoredInterface($path, $name, $hasMethods)) {
            return false;
        }

        return str_contains($path, '/Contracts/')
            || str_contains($path, '/Ports/')
            || str_contains($path, '/Gateways/')
            || str_ends_with($name, 'Interface')
            || preg_match('/(Detector|Issuer|Fetcher|Gateway|Client|Provider|Resolver|Archive|Directory|Scorer)$/', $name) === 1;
    }

    private function isIgnoredInterface(string $path, string $name, bool $hasMethods): bool
    {
        if (str_contains($path, '/Tests/') || str_starts_with($path, 'tests/')) {
            return true;
        }

        if (! $hasMethods && ! str_contains($path, '/Contracts/') && ! str_contains($path, '/Ports/')) {
            return true;
        }

        return in_array($name, [
            'ShouldQueue',
            'Arrayable',
            'Jsonable',
            'Responsable',
            'CastsAttributes',
            'CastsInboundAttributes',
        ], true);
    }
}
