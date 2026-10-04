<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use GracjanKubicki\ArchitectureKit\Support\ProjectPath;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/** Finds project-local declarations independently of the audit graph's scope. */
final class HttpRouteDiscovery
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $templates = [];

    /**
     * @var array<string, array<int>|null>
     */
    private array $states = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $records = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $notices = [];

    /**
     * @var array<string, bool>
     */
    private array $loaded = [];

    /**
     * @var array<string, bool>
     */
    private array $visited = [];

    /**
     * @var list<string>
     */
    private array $providers = [];

    /** @var array<string, string> */
    private array $providerPaths = [];

    private bool $limited = false;

    private int $bytes = 0;

    private int $visits = 0;

    private int $entries = 0;

    /**
     * @var list<string>
     */
    private array $exclude;

    public function __construct(private readonly Filesystem $files, private readonly string $basePath) {}

    /**
     * @param  list<string>  $exclude
     * @return array<string, mixed>
     */
    public function discover(ProjectGraphSnapshot $graph, array $exclude): array
    {
        $this->exclude = $exclude;
        foreach ($graph->impactFacts as $facts) {
            foreach ($facts->classes as $name => $class) {
                $this->providerPaths[$name] = $facts->path;
                if (in_array('Illuminate\\Support\\ServiceProvider', $class['parents'], true)) {
                    $this->providers[] = $facts->path;
                }
            }
        }
        $roots = $this->roots();
        foreach ($roots as $path) {
            $this->states[$path] = $this->stat($path);
        }
        $context = HttpRouteExtractor::context();
        foreach ($roots as $path) {
            if (! str_starts_with($path, 'routes/')) {
                $this->load($path, $context, []);
            }
        }
        foreach ($roots as $path) {
            if (str_starts_with($path, 'routes/') && ! isset($this->loaded[$path])) {
                $fallback = $context;
                $fallback['possible'] = true;
                $fallback['reasons'][] = 'Discovered declaration; its loading registration is not established.';
                $this->load($path, $fallback, []);
            }
        }
        ksort($this->states);

        return ['routes' => array_values($this->records), 'notices' => $this->notices, 'limited' => $this->limited, 'signature' => hash('xxh128', serialize($this->states)), 'inputs' => $this->states, 'execution_facts' => array_map(fn ($template) => $template['execution'], $this->templates), 'has_sources' => $this->records !== [] || $this->notices !== [] || count(array_filter(array_keys($this->states), fn ($path) => str_starts_with($path, 'routes/') && $this->states[$path] !== null)) > 0];
    }

    /**
     * @return array{fresh: bool, limited: bool}
     */
    public function freshness(): array
    {
        $now = [];
        foreach ($this->roots() as $path) {
            $now[$path] = $this->stat($path);
        }
        foreach (array_keys($this->states) as $path) {
            $now[$path] = $this->stat($path);
        }
        ksort($now);

        return ['fresh' => ! $this->limited && $now === $this->states, 'limited' => $this->limited];
    }

    /**
     * @return list<string>
     */
    private function roots(): array
    {
        $roots = ['bootstrap/app.php', 'bootstrap/providers.php', ...$this->providers];
        $entries = 0;
        foreach (['routes', 'app/Providers'] as $directory) {
            $absolute = $this->basePath.'/'.$directory;
            if (is_link($absolute)) {
                $this->notice($directory, 1, 'HTTP source directory symlink is not traversed.');
            }
            if (! is_dir($absolute) || is_link($absolute)) {
                continue;
            }
            try {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if (++$entries > 10000) {
                        $this->limited = true;
                        $this->notice($directory, 1, 'HTTP directory entry limit reached.');
                        break 2;
                    }
                    if ($file->isLink() && $file->isDir()) {
                        $this->notice(ProjectPath::relative($this->basePath, $file->getPathname()), 1, 'HTTP source directory symlink is not traversed.');
                    }
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        $roots[] = ProjectPath::relative($this->basePath, $file->getPathname());
                    }
                }
            } catch (Throwable) {
                $this->notice($directory, 1, 'HTTP directory cannot be read.');
            }
        }
        $roots = array_values(array_unique($roots));
        sort($roots);

        return $roots;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $stack
     */
    private function load(string $path, array $context, array $stack): void
    {
        if (++$this->visits > 10000 || count($stack) > 12 || count($this->templates) >= 1000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;
            $this->notice($path, 1, 'HTTP source/context/depth/memory limit reached.');

            return;
        }
        $absoluteInput = str_starts_with($path, '/') ? $path : $this->basePath.'/'.$path;
        $normalized = '/'.ProjectPath::relative('/', $absoluteInput);
        $base = '/'.trim(ProjectPath::relative('/', $this->basePath), '/');
        if (! str_starts_with($normalized, $base.'/') || str_contains($path, "\0") || str_contains($path, ':')) {
            $this->notice($path, 1, 'HTTP source resolves outside the project or has an invalid path.');

            return;
        }
        $path = substr($normalized, strlen($base) + 1);
        if (str_starts_with($path, 'vendor/') || str_starts_with($path, 'bootstrap/cache/')) {
            $this->notice($path, 1, 'External or generated HTTP sources are not analyzed.');

            return;
        }
        foreach ($this->exclude as $pattern) {
            if (Str::is($pattern, $path)) {
                $this->notice($path, 1, 'HTTP source excluded by audit.exclude.');

                return;
            }
        }
        $this->states[$path] ??= $this->stat($path);
        $real = realpath($this->basePath.'/'.$path);
        $realBase = realpath($this->basePath);
        if ($real !== false) {
            if ($realBase === false || ! str_starts_with($real, $realBase.DIRECTORY_SEPARATOR)) {
                $this->notice($path, 1, 'HTTP source symlink resolves outside the project.');

                return;
            }
            $canonical = ProjectPath::relative($realBase, $real);
            if (str_starts_with($canonical, 'vendor/') || str_starts_with($canonical, 'bootstrap/cache/')) {
                $this->notice($path, 1, 'External or generated HTTP sources are not analyzed.');

                return;
            }
            foreach ($this->exclude as $pattern) {
                if (Str::is($pattern, $canonical)) {
                    $this->notice($path, 1, 'Canonical HTTP source excluded by audit.exclude.');

                    return;
                }
            }
            $path = $canonical;
        }
        if (in_array($path, $stack, true)) {
            $this->notice($path, 1, 'HTTP include cycle is unresolved.');

            return;
        }
        $key = $path.'|'.hash('xxh128', serialize($context));
        if (isset($this->visited[$key])) {
            return;
        }
        $this->visited[$key] = true;
        $this->loaded[$path] = true;
        $this->states[$path] ??= $this->stat($path);
        if (! isset($this->templates[$path])) {
            $absolute = $this->basePath.'/'.$path;
            if (! $this->files->isFile($absolute)) {
                if (! in_array($path, ['bootstrap/app.php', 'bootstrap/providers.php'], true)) {
                    $this->notice($path, 1, 'HTTP source is missing.');
                }

                return;
            }
            $real = realpath($absolute);
            $base = realpath($this->basePath);
            if ($real === false || $base === false || ! str_starts_with($real, $base.DIRECTORY_SEPARATOR)) {
                $this->notice($path, 1, 'HTTP source symlink resolves outside the project.');

                return;
            }
            try {
                $size = $this->files->size($absolute);
                $reason = ImpactExtractor::sourceLimit($size);
                if ($reason !== null || $this->bytes + $size > 20_000_000) {
                    $this->limited = true;
                    $this->notice($path, 1, $reason ?? 'HTTP total source byte limit reached.');

                    return;
                }
                $this->bytes += $size;
                $file = new FileContext($path, $this->files->get($absolute));
                try {
                    $this->templates[$path] = (new HttpRouteExtractor($this->basePath))->extract($file);
                    $this->templates[$path]['execution'] = (new ExecutionExtractor)->extract($file);
                } finally {
                    $file->releaseAst();
                }
            } catch (Throwable) {
                $this->notice($path, 1, 'HTTP source could not be read or normalized.');

                return;
            }
        }
        $template = $this->templates[$path];
        $this->limited = $this->limited || $template['limited'];
        array_push($this->notices, ...$template['notices']);
        foreach ($template['operations'] as $op) {
            $ceiling = MemoryLimit::bytes();
            if (++$this->entries > 10000 || ($ceiling !== null && memory_get_usage(true) + 262144 > $ceiling * 0.65)) {
                $this->limited = true;
                $this->notice($path, $op['site']['line'], 'HTTP registration/memory limit reached.');
                break;
            }
            $combined = $this->combine($context, $op['context']);
            if ($op['kind'] === 'provider') {
                $this->provider($op['class'], $combined, [...$stack, $path]);

                continue;
            }
            if ($op['kind'] === 'load') {
                $this->load($op['path'], $combined, [...$stack, $path]);

                continue;
            }
            $id = 'http:'.hash('xxh128', serialize([$op['site'], $combined, $op['resource']]));
            $handler = $op['handler'];
            if (isset($handler['string'])) {
                $value = $handler['string'];
                if (is_string($combined['controller']) && ! str_contains($value, '@') && ! isset($this->providerPaths[ltrim($value, '\\')])) {
                    $handler = ['class' => $combined['controller'], 'method' => $value];
                } elseif ($combined['controller'] === null && isset($combined['specified']['controller']) && ! str_contains($value, '@') && ! isset($this->providerPaths[ltrim($value, '\\')])) {
                    $handler = null;
                    $this->notice($path, $op['site']['line'], 'Route group controller is unresolved.');
                } else {
                    [$class, $method] = array_pad(explode('@', $value, 2), 2, '__invoke');
                    if (! str_starts_with($class, '\\') && is_string($combined['namespace']) && $combined['namespace'] !== '' && ! str_starts_with($class, $combined['namespace'])) {
                        $class = $combined['namespace'].'\\'.$class;
                    }
                    $handler = ['class' => $class, 'method' => $method];
                }
            }
            if (isset($handler['class'])) {
                $class = $handler['class'];
                if (! str_starts_with($class, '\\') && ! str_contains($class, '\\') && is_string($combined['namespace']) && $combined['namespace'] !== '') {
                    $class = $combined['namespace'].'\\'.$class;
                }
                $handler['class'] = ltrim($class, '\\');
            }
            $calls = $op['calls'];
            if (isset($handler['callback'])) {
                $old = $handler['callback'];
                $handler['callback'] .= ':'.$id;
                foreach ($calls as &$call) {
                    if ($call['from'] === $old) {
                        $call['from'] = $handler['callback'];
                    }
                }
                unset($call);
            }
            $prefix = $combined['prefix'];
            $uri = $prefix === null || $op['uri'] === null ? null : '/'.trim($prefix.'/'.$op['uri'], '/');
            $this->records[$id] = ['id' => $id, 'verbs' => $op['verbs'], 'uri' => $uri, 'name' => $combined['name'] === '' ? null : $combined['name'], 'domain' => $combined['domain'], 'middleware' => $combined['middleware'], 'excluded_middleware' => $combined['excluded_middleware'], 'constraints' => $combined['constraints'], 'handler' => $handler, 'source' => $op['site'], 'registration' => $combined['sites'], 'certainty' => $combined['possible'] ? 'possible' : 'declared', 'reasons' => array_values(array_unique($combined['reasons'])), 'calls' => $calls];
        }
    }

    /** @param array<string, mixed> $context
     * @param  list<string>  $stack
     */
    private function provider(string $class, array $context, array $stack): void
    {
        if (isset($this->providerPaths[$class])) {
            $context['possible'] = true;
            $context['reasons'][] = 'Provider activation is not established by source inspection.';
            $this->load($this->providerPaths[$class], $context, $stack);

            return;
        }
        $path = 'composer.json';
        $this->states[$path] = $this->stat($path);
        try {
            if (! $this->files->isFile($this->basePath.'/'.$path) || $this->files->size($this->basePath.'/'.$path) > 100000) {
                throw new \RuntimeException;
            }
            $config = json_decode($this->files->get($this->basePath.'/'.$path), true, 32, JSON_THROW_ON_ERROR);
            $maps = $config['autoload']['psr-4'] ?? [];
            foreach ($maps as $prefix => $directories) {
                if (! is_string($prefix) || ! str_starts_with($class, $prefix)) {
                    continue;
                }
                foreach (is_array($directories) ? $directories : [$directories] as $directory) {
                    if (is_string($directory)) {
                        $candidate = trim($directory, '/').'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                        $context['possible'] = true;
                        $context['reasons'][] = 'Provider activation is not established by source inspection.';
                        $this->load($candidate, $context, $stack);

                        return;
                    }
                }
            }
        } catch (Throwable) {
        }
        $this->notice('bootstrap/providers.php', 1, 'Provider source could not be resolved from project Composer PSR-4 mappings: '.$class);
    }

    /**
     * @param  array<string, mixed>  $parent
     * @param  array<string, mixed>  $local
     * @return array<string, mixed>
     */
    private function combine(array $parent, array $local): array
    {
        $result = $local;
        foreach (['prefix', 'namespace'] as $key) {
            $separator = $key === 'prefix' ? '/' : '\\';
            $result[$key] = $parent[$key] === null || $local[$key] === null ? null : trim($parent[$key].$separator.$local[$key], $separator);
        }
        $result['name'] = $parent['name'] === null || $local['name'] === null ? null : $parent['name'].$local['name'];
        foreach (['middleware', 'excluded_middleware'] as $key) {
            $result[$key] = $parent[$key] === null || $local[$key] === null ? null : array_values(array_unique([...$parent[$key], ...$local[$key]], SORT_REGULAR));
        }
        $result['constraints'] = $parent['constraints'] === null || $local['constraints'] === null ? null : [...$parent['constraints'], ...$local['constraints']];
        $result['domain'] = isset($local['specified']['domain']) ? $local['domain'] : $parent['domain'];
        $result['controller'] = isset($local['specified']['controller']) ? $local['controller'] : $parent['controller'];
        $result['specified'] = [...$parent['specified'], ...$local['specified']];
        $result['possible'] = $parent['possible'] || $local['possible'];
        $result['sites'] = [...$parent['sites'], ...$local['sites']];
        $result['reasons'] = [...$parent['reasons'], ...$local['reasons']];

        return $result;
    }

    /**
     * @return array<int>|null
     */
    private function stat(string $path): ?array
    {
        clearstatcache(true, $this->basePath.'/'.$path);
        $stat = @stat($this->basePath.'/'.$path);

        return $stat === false ? null : [(int) $stat['mtime'], (int) $stat['size']];
    }

    private function notice(string $path, int $line, string $reason): void
    {
        $this->notices[] = ['path' => $path, 'line' => $line, 'reason' => $reason];
    }
}
