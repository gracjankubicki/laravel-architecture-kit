<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Architecture;

use GracjanKubicki\ArchitectureKit\Classification\ClassificationMappings;
use Illuminate\Filesystem\Filesystem;

final readonly class RoleClassifier
{
    public const TEST = 'test';

    public function __construct(public ClassificationMappings $mappings = new ClassificationMappings) {}

    public static function forProject(Filesystem $files, string $base): self
    {
        return new self(ClassificationMappings::load($files, $base));
    }

    public function classify(string $path, string $name, string $kind, bool $hasMethods = true): string
    {
        // Checked before ports: a test double living in tests/ is not a project port.
        if (self::isTestPath($path)) {
            return self::TEST;
        }
        if ($kind === 'file') {
            return 'unknown';
        }

        $mapping = $kind === 'file' ? [] : $this->mappings->roleMapping($path, $name);
        if (isset($mapping['role'])) {
            return $mapping['role'];
        }
        $short = substr($name, (int) strrpos('\\'.$name, '\\'));
        if ($kind === 'interface' && $this->isPort($path, $short, $hasMethods)) {
            return 'port';
        }

        return $this->roleFromPath($path);
    }

    /** @return array<string, mixed> */
    public function describe(string $path, string $name, string $phpKind, bool $hasMethods = true): array
    {
        $test = self::isTestPath($path);
        $mapping = $test || $phpKind === 'file' ? [] : $this->mappings->roleMapping($path, $name);
        $module = $this->mappings->module($path, $name);
        $kind = $test ? 'test' : ($mapping['kind'] ?? ($phpKind === 'file' ? null : $this->defaultKind($path)));

        return ['role' => $phpKind === 'file' ? ($test ? self::TEST : 'unknown') : $this->classify($path, $name, $phpKind, $hasMethods),
            'application_kind' => $kind, 'php_kind' => $phpKind, 'module' => $module['module'], 'module_parents' => $module['parents'],
            'provenance' => ['classification' => $mapping['source'] ?? ($test || $phpKind === 'file' ? 'invariant' : 'default_convention'), 'module' => $module['source'] ?? 'unassigned']];
    }

    private function defaultKind(string $path): ?string
    {
        $kinds = ['Actions' => 'action', 'Queries' => 'query', 'Controllers' => 'controller', 'Models' => 'model', 'Services' => 'service', 'Jobs' => 'job', 'Listeners' => 'listener', 'Events' => 'event', 'Policies' => 'policy', 'Requests' => 'request', 'Resources' => 'resource', 'Data' => 'data', 'ValueObjects' => 'value-object', 'Enums' => 'enum', 'Exceptions' => 'exception', 'Builders' => 'builder', 'Contracts' => 'port', 'Ports' => 'port', 'Providers' => 'provider'];
        foreach (array_reverse(explode('/', $path)) as $segment) {
            if (isset($kinds[$segment])) {
                return $kinds[$segment];
            }
        }

        return null;
    }

    /**
     * A file holding tests rather than application code. The missing-test rule tells a
     * test edge from a code edge by this, so it cannot rely on a class-name convention.
     */
    public static function isTestPath(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, 'tests/') || str_contains($path, '/Tests/');
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
