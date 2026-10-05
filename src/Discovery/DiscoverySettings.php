<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Discovery;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;

/** Read analysis settings without requiring project PHP or resolving project classes. */
final readonly class DiscoverySettings
{
    /** @param list<string> $exclude
     * @param  list<string>  $fingerprint
     * @param  list<int>  $configStat
     */
    public function __construct(public AuditScope $scope, public array $exclude, public ?ProjectGraphCache $cache, public array $fingerprint, public array $configStat) {}

    public static function safe(string $base, string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || preg_match('~(^|/)(\.\.|vendor|node_modules|\.git|\.env)(/|$)~', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            $base .= '/'.$part;
            if (is_link($base)) {
                return false;
            }
        }

        return true;
    }

    public static function load(Filesystem $files, string $base): self
    {
        $path = 'config/architectures.php';
        if (! self::safe($base, $path)) {
            throw new InvalidArgumentException('Analysis configuration crosses an unsafe path or symlink.');
        }
        $stat = self::stat($base.'/'.$path);
        $audit = [];
        if ($files->isFile($base.'/'.$path)) {
            if (($stat[1] ?? 0) > 100000) {
                throw new InvalidArgumentException('Analysis configuration exceeds 100 KB.');
            }
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($files->get($base.'/'.$path)) ?? [];
            $returned = false;
            foreach ($ast as $node) {
                if ($node instanceof Stmt\Declare_ || $node instanceof Stmt\Use_ || $node instanceof Stmt\Nop) {
                    continue;
                }
                if (! $node instanceof Stmt\Return_ || ! $node->expr instanceof Node\Expr\Array_ || $returned) {
                    throw new InvalidArgumentException('Use a static returned array for source-only analysis configuration.');
                }
                $returned = true;
                $evaluator = new ConstExprEvaluator;
                foreach ($node->expr->items as $item) {
                    if ($item === null || $item->unpack || $item->key === null) {
                        throw new InvalidArgumentException('Unpacked analysis configuration is unresolved.');
                    }
                    if ($evaluator->evaluateDirectly($item->key) === 'audit') {
                        $audit = $evaluator->evaluateDirectly($item->value);
                    }
                }
            }
            if (! $returned || ! is_array($audit)) {
                throw new InvalidArgumentException('Analysis configuration must contain a static audit array.');
            }
        }
        $paths = $audit['paths'] ?? [];
        $exclude = $audit['exclude'] ?? [];
        foreach ([$paths, $exclude] as $list) {
            if (! is_array($list) || count(array_filter($list, 'is_string')) !== count($list)) {
                throw new InvalidArgumentException('audit.paths and audit.exclude must be string arrays.');
            }
        }
        foreach ($paths as $directory) {
            if (! self::safe($base, $directory)) {
                throw new InvalidArgumentException('Analysis scope contains an unsafe directory.');
            }
        }
        if (in_array($audit['missing_test'] ?? 'off', ['warn', 'error'], true)) {
            $paths[] = 'tests';
        }
        $cache = $audit['cache'] ?? true;
        if (! is_bool($cache) && ! is_string($cache)) {
            throw new InvalidArgumentException('audit.cache must be a boolean or project-relative path.');
        }
        $cachePath = is_string($cache) ? $cache : ProjectGraphCache::DIRECTORY;
        if (! self::safe($base, $cachePath)) {
            throw new InvalidArgumentException('Analysis cache contains an unsafe path.');
        }

        return new self(new AuditScope(['app', ...$paths]), $exclude, $cache === false ? null : new ProjectGraphCache($files, $base, $cachePath), ['discovery-source-only-v1', hash('sha256', serialize($audit))], $stat);
    }

    /** @return list<int> */
    public static function stat(string $path): array
    {
        clearstatcache(true, $path);

        return is_file($path) ? [(int) filemtime($path), (int) filesize($path)] : [];
    }
}
