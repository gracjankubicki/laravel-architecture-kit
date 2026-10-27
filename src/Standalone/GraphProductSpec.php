<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Composer\ProjectLayout;

final class GraphProductSpec
{
    /** @param list<string> $exclude
     * @param list<string> $configuration
     * @return array<string, mixed> */
    public static function full(string $base, string $kind, AuditScope $scope, array $exclude, ?ProjectGraphCache $cache, array $configuration): array
    {
        return ['kind' => $kind, 'audit_scope' => $scope->directories,
            'scope' => array_values(array_unique([...$scope->directories, ...ProjectLayout::read($base)->graphSources()])),
            'freshness_scope' => array_values(array_unique([...$scope->directories, ...ProjectLayout::read($base)->graphSources(), ...['tests', 'app']])),
            'exclude' => $exclude, 'cache' => $cache?->configuredDirectory(), 'configuration' => $configuration];
    }
}
