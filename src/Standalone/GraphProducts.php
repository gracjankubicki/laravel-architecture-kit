<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphBuildPlan;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use RuntimeException;

/** Exactly one prepared full product per fresh worker, separate from CatalogIndex. */
final class GraphProducts
{
    private static ?string $key = null;

    private static ?ProjectGraphSnapshot $snapshot = null;

    private static bool $sealed = false;

    private static ?GraphBuildPlan $plan = null;

    public static function prepare(ProjectGraphLoader $loader, GraphBuildPlan $plan): ProjectGraphSnapshot
    {
        if (self::$snapshot !== null || self::$sealed || GraphRuntime::resident() !== null) {
            throw new RuntimeException('A heavy product requires a fresh graph worker.');
        }
        self::$key = $loader->productKey($plan);
        self::$plan = $plan;
        self::$snapshot = $loader->build($plan);
        self::$sealed = true;

        return self::$snapshot;
    }

    /** Query callers explicitly pin the prepared plan instead of rescanning the live repository.
     * @param list<string> $exclude */
    public static function plan(ProjectGraphLoader $loader, array $exclude): ?GraphBuildPlan
    {
        if (! self::$sealed || self::$plan === null) {
            return null;
        }
        if (self::$plan->exclude !== $exclude || $loader->productKey(self::$plan) !== self::$key) {
            throw new RuntimeException('Prepared graph product differs from the requested scope, inputs or classification.');
        }

        return self::$plan;
    }

    public static function get(string $key): ?ProjectGraphSnapshot
    {
        if (! self::$sealed) {
            return null;
        }
        if (self::$key !== $key) {
            throw new RuntimeException('Prepared graph product differs from the requested scope, inputs or classification.');
        }

        return self::$snapshot;
    }
}
