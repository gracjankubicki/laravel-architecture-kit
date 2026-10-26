<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache;

use Closure;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/** Content checks do not parse PHP or resolve symbols. */
final class SourceManifest
{
    public static function identity(string $base, AnalysisMode $mode): string
    {
        $root = realpath($base);
        if ($root === false) {
            throw new RuntimeException('Repository root is unavailable.');
        }
        $inputs = [];
        $context = RepositoryContext::forRoot($base);
        $configuration = RepositoryContext::configurationPath($base);
        foreach (['composer.json', 'composer.lock', 'vendor/composer/installed.json', 'config/architectures.php'] as $path) {
            $absolute = $path === 'config/architectures.php' ? $configuration : $base.'/'.$path;
            try {
                self::assertUnlinked($absolute, str_starts_with($absolute, $base.'/') ? $base : ($context->home ?? dirname($absolute)));
                $inputs[$path] = is_file($absolute) ? hash_file('sha256', $absolute) : null;
            } catch (RuntimeException $error) {
                if ($path !== 'vendor/composer/installed.json') {
                    throw $error;
                }
                $inputs[$path] = 'unsafe';
            }
        }
        $inputs['Modules'] = is_dir($base.'/Modules') && ! is_link($base.'/Modules');

        return GraphSegmentStore::digest(['schema' => GraphSegmentStore::SCHEMA, 'root' => $root,
            'profile' => $context?->profile, 'inputs' => $inputs, 'backend' => AnalysisRuntime::session()->fingerprint(),
            'package' => PackageFingerprint::current(), 'mode' => $mode->value]);
    }

    /** @param list<string> $scope
     * @param list<string> $exclude
     * @return array<string, string> */
    public static function scan(Filesystem $files, string $base, array $scope, array $exclude, bool $retainTests = false): array
    {
        $found = [];
        $excluded = static function (string $path) use ($exclude, $retainTests): bool {
            if ($retainTests && RoleClassifier::isTestPath($path)) {
                return false;
            }
            foreach ($exclude as $pattern) {
                if (fnmatch($pattern, $path)) {
                    return true;
                }
            }

            return false;
        };
        foreach (ComposerCatalogExtractor::FILES as $path) {
            if (self::safe($base, $path, true) && is_file($base.'/'.$path) && ! $excluded($path)) {
                $found[$path] = self::signature($base.'/'.$path);
            }
        }
        foreach ($scope as $directory) {
            if (! is_string($directory) || ! DiscoverySettings::safe($base, $directory)) {
                throw new RuntimeException('Unsafe graph scope.');
            }
            if (is_file($base.'/'.$directory) && str_ends_with($directory, '.php')) {
                if (! $excluded($directory)) {
                    $found[$directory] = self::signature($base.'/'.$directory);
                }

                continue;
            }
            if (! is_dir($base.'/'.$directory)) {
                continue;
            }
            foreach ($files->allFiles($base.'/'.$directory) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $path = substr($file->getPathname(), strlen(rtrim($base, '/')) + 1);
                if (self::safe($base, $path) && ! $excluded($path)) {
                    $found[$path] = self::signature($file->getPathname());
                }
            }
        }
        ksort($found);

        return $found;
    }

    /** @param list<string> $scope
     * @return array<string, array{safe: bool, kind: string}> */
    public static function scopeState(string $base, array $scope): array
    {
        $state = [];
        foreach ($scope as $path) {
            $safe = DiscoverySettings::safe($base, $path);
            $state[$path] = ['safe' => $safe, 'kind' => ! $safe ? 'unsafe' : (is_dir($base.'/'.$path) ? 'directory' : (is_file($base.'/'.$path) ? 'file' : 'missing'))];
        }

        return $state;
    }

    /** @param array<string, string> $files
     * @param array<string, array{safe: bool, kind: string}> $scope */
    public static function inputDigest(array $files, array $scope): string
    {
        return GraphSegmentStore::digest(['files' => $files, 'scope' => $scope]);
    }

    public static function signature(string $absolute): string
    {
        clearstatcache(true, $absolute);
        $hash = hash_file('sha256', $absolute);
        if ($hash === false) {
            throw new RuntimeException('Cannot fingerprint graph source.');
        }

        return filemtime($absolute).':'.filesize($absolute).':'.$hash;
    }

    public static function safe(string $base, string $path, bool $metadata = false): bool
    {
        if (str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('~(^|/)(\.\.?|node_modules|\.git)(/|$)~', $path)
            || ! $metadata && (! str_ends_with($path, '.php') || preg_match('~(^|/)vendor(/|$)~', $path))) {
            return false;
        }
        try {
            self::assertUnlinked($base.'/'.$path, $base);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    public static function assertUnlinked(string $absolute, string $anchor): void
    {
        $cursor = rtrim($anchor, '/');
        if (is_link($cursor)) {
            throw new RuntimeException('Input root is a symlink.');
        }
        foreach (explode('/', substr($absolute, strlen($cursor) + 1)) as $part) {
            $cursor .= '/'.$part;
            if (is_link($cursor)) {
                throw new RuntimeException('Input crosses a symlink.');
            }
        }
    }

    /** @param array<string, string> $signatures
     * @return Closure(string): ?string */
    public static function sourceReader(Filesystem $files, string $base, array $signatures): Closure
    {
        $root = realpath($base);

        return static function (string $path) use ($files, $root, $signatures): ?string {
            if ($root === false || ! isset($signatures[$path]) || ! self::safe($root, $path, in_array($path, ComposerCatalogExtractor::FILES, true))) {
                return null;
            }
            $absolute = $root.'/'.$path;
            if (! is_file($absolute) || filesize($absolute) > 2 * 1024 * 1024) {
                return null;
            }
            try {
                $contents = $files->get($absolute);
                $hash = substr($signatures[$path], strrpos($signatures[$path], ':') + 1);

                return hash_equals($hash, hash('sha256', $contents)) ? $contents : null;
            } catch (\Throwable) {
                return null;
            }
        };
    }
}
