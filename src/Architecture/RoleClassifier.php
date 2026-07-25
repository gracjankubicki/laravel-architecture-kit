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
            $this->hasSegment($path, 'Providers') => 'composition',
            $this->hasSegment($path, 'Http/Controllers'),
            $this->hasSegment($path, 'Http/Requests'),
            $this->hasSegment($path, 'Http/Resources') => 'adapter',
            $this->hasSegment($path, 'Http/Integrations'),
            $this->hasSegment($path, 'Infrastructure'),
            $this->hasSegment($path, 'Adapters') => 'infrastructure',
            $this->hasSegment($path, 'Actions'),
            $this->hasSegment($path, 'Services'),
            $this->hasSegment($path, 'Queries'),
            $this->hasSegment($path, 'Jobs'),
            $this->hasSegment($path, 'Listeners') => 'application',
            $this->hasSegment($path, 'Models'),
            $this->hasSegment($path, 'Domain'),
            $this->hasSegment($path, 'Data'),
            $this->hasSegment($path, 'ValueObjects'),
            $this->hasSegment($path, 'Enums') => 'domain',
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

    private function hasSegment(string $path, string $segment): bool
    {
        return str_contains('/'.trim(str_replace('\\', '/', $path), '/').'/', '/'.trim($segment, '/').'/');
    }
}
