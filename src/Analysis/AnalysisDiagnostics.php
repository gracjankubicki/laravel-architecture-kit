<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use RuntimeException;

final class AnalysisDiagnostics
{
    /** @return array<string, mixed> */
    public static function current(): array
    {
        $context = RepositoryContext::current();
        if ($context !== null) {
            $session = $context->analysis();
            $settings = $context->settings;
            $root = $context->rootPath;
            $origin = $context->configSource;
        } else {
            $session = AnalysisRuntime::session();
            $settings = app('config')->get('architectures', []);
            $root = base_path();
            $origin = 'config/architectures.php';
        }
        $session->fingerprint();
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($settings) || ! is_array($composer)) {
            throw new RuntimeException('Project settings and Composer must contain objects.');
        }
        $selected = EngineSettings::from($settings, $composer, $origin);
        $shared = null;
        if ($context?->profile !== null) {
            foreach (['.architecture-kit/settings.json', 'config/architectures.php'] as $path) {
                if (is_file($root.'/'.$path)) {
                    $source = (string) file_get_contents($root.'/'.$path);
                    $sharedSettings = str_ends_with($path, '.json') ? json_decode($source, true, 64, JSON_THROW_ON_ERROR) : ConfigurationReader::read($source);
                    if (! is_array($sharedSettings)) {
                        throw new RuntimeException('Shared engine settings must contain an object.');
                    }
                    $shared = EngineSettings::from($sharedSettings, $composer, $path)->engine;
                    break;
                }
            }
        }

        return ['engine' => $session->engine->engine, 'version' => $session->engine->version,
            'implementation' => $session->engine->implementation, 'schema' => $session->engine->schema,
            'capabilities' => $session->engine->capabilities, 'php_version' => $session->phpVersion,
            'php_origin' => $selected->php->origin, 'origin' => $selected->origin,
            'profile' => $context?->profile, 'shared_engine' => $shared,
            'overrides_shared_engine' => $shared !== null && $shared !== $session->engine->engine];
    }
}
