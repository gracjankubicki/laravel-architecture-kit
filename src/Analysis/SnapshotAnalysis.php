<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use InvalidArgumentException;

/** Historic source uses its own Composer and language settings with the selected engine. */
final class SnapshotAnalysis
{
    public static function session(SourceSnapshot $source): AnalysisSession
    {
        $composer = json_decode($source->files['composer.json'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($composer)) {
            throw new InvalidArgumentException('Snapshot Composer declarations must contain an object.');
        }
        $path = 'config/architectures.php';
        if (! isset($source->files[$path]) && in_array($path, $source->paths, true)) {
            throw new InvalidArgumentException('Historical analysis settings are present but unavailable. Cannot establish the project PHP target.');
        }
        try {
            // Decode analysis settings independently of classification validation.
            // An unresolved configuration may contain a PHP override, so treating
            // it as [] would silently select a different language version.
            $settings = isset($source->files[$path]) ? ConfigurationReader::read($source->files[$path]) : [];
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException('Historical analysis settings are unresolved. Cannot establish the project PHP target: '.$error->getMessage(), previous: $error);
        }
        $version = ProjectPhpVersion::from($composer, $settings['analysis']['php_version'] ?? null);

        return AnalysisRuntime::session()->withPhpVersion($version->version);
    }

    /** @template T
     * @param callable(): T $read
     * @return T */
    public static function read(SourceSnapshot $source, callable $read): mixed
    {
        $previous = AnalysisRuntime::session();
        $session = self::session($source);
        AnalysisRuntime::activate($session);
        try {
            return $read();
        } finally {
            AnalysisRuntime::activate($previous);
        }
    }
}
