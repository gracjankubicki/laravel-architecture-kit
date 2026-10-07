<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use Closure;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use Throwable;

/** Graph settings are source data, separate from the host's audit configuration. */
final readonly class CatalogSettings
{
    public const ROOTS = ['app', 'routes', 'bootstrap', 'config', 'database', 'resources/views', 'tests'];

    /** @param list<string> $exclude
     * @param  list<string>  $fingerprint
     * @param  array<string, list<int>>  $inputStats
     * @param  array<string, string>  $packages
     * @param  list<CatalogDiagnostic>  $diagnostics
     */
    public function __construct(public DiscoverySettings $discovery, public AuditScope $scope, public array $exclude, public array $fingerprint, public array $inputStats, public array $packages, public array $diagnostics) {}

    /** @param (Closure(string): void)|null $onParse */
    public static function load(Filesystem $files, string $base, ?Closure $onParse = null): self
    {
        $discovery = DiscoverySettings::load($files, $base, $onParse);
        $settings = [];
        if ($discovery->sourceFile !== null) {
            $nodes = $discovery->sourceFile->ast() ?? [];
            if (count($nodes) === 1 && $nodes[0] instanceof Stmt\Namespace_) {
                $nodes = $nodes[0]->stmts;
            }
            $evaluator = new ConstExprEvaluator;
            foreach ($nodes as $node) {
                if (! $node instanceof Stmt\Return_ || ! $node->expr instanceof Expr\Array_) {
                    continue;
                }
                foreach ($node->expr->items as $item) {
                    if ($item !== null && $item->key !== null && $evaluator->evaluateDirectly($item->key) === 'graph') {
                        $settings = $evaluator->evaluateDirectly($item->value);
                    }
                }
            }
        }
        if (! is_array($settings)) {
            throw new InvalidArgumentException('graph must be a static settings array.');
        }
        $paths = $settings['paths'] ?? [];
        $exclude = $settings['exclude'] ?? [];
        foreach ([$paths, $exclude] as $list) {
            if (! is_array($list) || ! array_is_list($list) || count(array_filter($list, 'is_string')) !== count($list)) {
                throw new InvalidArgumentException('graph.paths and graph.exclude must be string lists.');
            }
        }
        foreach ($paths as $directory) {
            if (! DiscoverySettings::safe($base, $directory)) {
                throw new InvalidArgumentException('Graph scope contains an unsafe directory.');
            }
        }
        $roots = array_values(array_unique([...self::ROOTS, ...$paths]));
        $diagnostics = [];
        foreach ($roots as $root) {
            if (! DiscoverySettings::safe($base, $root)) {
                $diagnostics[] = new CatalogDiagnostic('unsafe_root', 'Graph root is unsafe or contains a symlink: '.$root, 1);
            } elseif (! $files->isDirectory($base.'/'.$root)) {
                $diagnostics[] = new CatalogDiagnostic('missing_root', 'Graph root does not exist: '.$root, 1);
            }
        }
        $inputStats = ['config/architectures.php' => $discovery->configStat];
        $fingerprint = [...$discovery->fingerprint, 'catalog-source-v1', hash('sha256', serialize($settings))];
        $packages = [];
        foreach (['composer.lock', 'vendor/composer/installed.json'] as $relative) {
            $inputStats[$relative] = DiscoverySettings::stat($base.'/'.$relative);
            if ($inputStats[$relative] === []) {
                continue;
            }
            $absolute = self::metadataPath($base, $relative);
            if ($absolute === null || ($inputStats[$relative][1] ?? 0) > 4000000) {
                $diagnostics[] = new CatalogDiagnostic('package_metadata', 'Package metadata is unsafe or exceeds 4 MB: '.$relative, 1);

                continue;
            }
            try {
                $text = $files->get($absolute);
                $fingerprint[] = $relative.':'.hash('sha256', $text);
                $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw new InvalidArgumentException('Package metadata must be JSON data.');
                }
                $records = $relative === 'composer.lock' ? [...($decoded['packages'] ?? []), ...($decoded['packages-dev'] ?? [])] : ($decoded['packages'] ?? $decoded);
                if (! is_array($records)) {
                    throw new InvalidArgumentException('Package records must be an array.');
                }
                foreach ($records as $record) {
                    if (is_array($record) && is_string($record['name'] ?? null) && is_string($record['version'] ?? null)) {
                        $name = $record['name'];
                        $version = ltrim($record['version'], 'vV');
                        if (isset($packages[$name]) && $packages[$name] !== $version) {
                            $diagnostics[] = new CatalogDiagnostic('package_version_mismatch', 'Installed and locked versions disagree for '.$name.'.', 1);
                        }
                        $packages[$name] = $version;
                    }
                }
            } catch (Throwable) {
                $diagnostics[] = new CatalogDiagnostic('package_metadata', 'Package metadata could not be read: '.$relative, 1);
            }
        }
        ksort($packages);

        return new self($discovery, new AuditScope($roots), $exclude, $fingerprint, $inputStats, $packages, $diagnostics);
    }

    public function fresh(string $base): bool
    {
        foreach ($this->inputStats as $path => $stat) {
            if (DiscoverySettings::stat($base.'/'.$path) !== $stat) {
                return false;
            }
        }

        return true;
    }

    /** Only these Composer JSON files may cross the vendor exclusion. No PHP is required. */
    private static function metadataPath(string $base, string $relative): ?string
    {
        foreach (explode('/', $relative) as $part) {
            $base .= '/'.$part;
            if (is_link($base)) {
                return null;
            }
        }

        return $base;
    }
}
