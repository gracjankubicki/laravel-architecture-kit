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
    private static ?ProjectGraphSnapshot $assessment = null;

    private static ?ProjectGraphSnapshot $assessmentTests = null;

    public static function assessmentGraph(): ?ProjectGraphSnapshot
    {
        return self::$assessmentTests ?? self::$assessment;
    }

    /** @template T
     * @param \Closure(): T $run
     * @return T */
    public static function withNativeAssessment(ProjectGraphSnapshot $graph, \Closure $run, ?ProjectGraphSnapshot $testGraph = null): mixed
    {
        if (self::$assessment !== null || self::$snapshot !== null) {
            throw new RuntimeException('Nested or resident PHP assessment is unavailable.');
        }
        self::$assessment = $graph;
        self::$assessmentTests = $testGraph;
        try {
            return $run();
        } finally {
            self::$assessment = null;
            self::$assessmentTests = null;
        }
    }

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
        if (self::$assessment !== null) {
            return $loader->plan($exclude);
        }
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
        if (self::$assessment !== null) {
            return self::$assessment;
        }
        if (! self::$sealed) {
            return null;
        }
        if (self::$key !== $key) {
            throw new RuntimeException('Prepared graph product differs from the requested scope, inputs or classification.');
        }

        return self::$snapshot;
    }
}
