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

        return $this->roleFromPath($path);
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

    private function hasSegment(string $path, string $segment): bool
    {
        return str_contains('/'.trim(str_replace('\\', '/', $path), '/').'/', '/'.trim($segment, '/').'/');
    }

    private function roleFromPath(string $path): string
    {
        $segments = explode('/', trim(str_replace('\\', '/', $path), '/'));

        foreach ($segments as $index => $segment) {
            if ($segment === 'Http') {
                $httpRole = match ($segments[$index + 1] ?? null) {
                    'Controllers', 'Requests', 'Resources' => 'adapter',
                    'Integrations' => 'infrastructure',
                    default => null,
                };

                if ($httpRole !== null) {
                    return $httpRole;
                }
            }

            $role = match ($segment) {
                'Providers' => 'composition',
                'Infrastructure', 'Adapters' => 'infrastructure',
                'Actions', 'Services', 'Queries', 'Jobs', 'Listeners' => 'application',
                'Models', 'Domain', 'Data', 'ValueObjects', 'Enums' => 'domain',
                default => null,
            };

            if ($role !== null) {
                return $role;
            }
        }

        return 'unknown';
    }
}
