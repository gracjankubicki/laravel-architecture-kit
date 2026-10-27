<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Capture;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogSettings;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/** Pre/post full scan detects add/delete/config drift, including unchanged file lengths. */
final class CaptureSourcePlan
{
    /**
     * @return array<array-key, mixed>
     */
    public static function create(string $root): array
    {
        $files = new Filesystem;
        $settings = CatalogSettings::load($files, $root);
        $session = AnalysisRuntime::session();

        return ['root' => $root, 'scope' => $settings->scope->directories, 'exclude' => $settings->exclude,
            'files' => SourceManifest::scan($files, $root, $settings->scope->directories, $settings->exclude),
            'scope_state' => SourceManifest::scopeState($root, $settings->scope->directories),
            'identity' => SourceManifest::identity($root, AnalysisMode::Basic), 'session' => $session->fingerprint(),
            'php_version' => $session->phpVersion, 'parser' => $session->engine->engine,
            'classification' => RoleClassifier::forProject($files, $root)->mappings->fingerprint()];
    }

    /**
     * @param  array<array-key, mixed>  $plan
     */
    public static function assertCurrent(array $plan): void
    {
        try {
            $current = self::create($plan['root']);
        } catch (\Throwable $error) {
            if (str_starts_with($error->getMessage(), 'capture_') || $error->getMessage() === 'measurement_write_failed') {
                throw $error;
            }
            throw new RuntimeException('capture_source_drift', previous: $error);
        }
        if ($current !== $plan) {
            throw new RuntimeException('capture_source_drift');
        }
    }
}
