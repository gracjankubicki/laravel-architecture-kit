<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Revision\SnapshotInputs;
use GracjanKubicki\ArchitectureKit\Support\ProjectPath;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/** Independent stat freshness for every source, including absent roots and config. */
final class ExecutionSources
{
    /** @var array<string, array<int>|null> */
    private array $states = [];

    /** @var list<string> */
    private array $directories = ['app', 'routes', 'bootstrap'];

    /** @var list<array<string, mixed>> */
    private array $notices = [];

    private bool $limited = false;

    private int $entries = 0;

    private int $bytes = 0;

    /** @var list<string> */
    private array $exclude = [];

    /** @param list<string> $additionalDirectories */
    public function __construct(private readonly Filesystem $files, private readonly string $basePath, array $additionalDirectories = [], private readonly bool $retainDeclarations = false, private readonly ?SnapshotInputs $snapshot = null)
    {
        array_push($this->directories, ...$additionalDirectories);
    }

    /** @param list<string> $exclude
     * @param  array<string, mixed>  $httpInputs
     * @param  array<string, array<string, mixed>>  $shared
     * @return array<string, mixed>
     */
    public function discover(ProjectGraphSnapshot $graph, array $exclude, array $httpInputs = [], array $shared = []): array
    {
        $this->exclude = $exclude;
        $queue = ['routes/console.php', 'app/Console/Kernel.php', 'composer.json', 'bootstrap/app.php', 'bootstrap/providers.php', ...array_keys($httpInputs)];
        foreach ($graph->impactFacts as $facts) {
            $queue[] = $facts->path;
        }
        $this->states['composer.json'] = $this->stat('composer.json');
        if ($this->safe('composer.json') && ($this->snapshot !== null ? $this->snapshot->read('composer.json') !== null : $this->files->isFile($this->basePath.'/composer.json')) && ($this->states['composer.json'][1] ?? 0) <= 100000) {
            try {
                $composer = json_decode(($this->snapshot?->read('composer.json') ?? ($this->snapshot === null ? $this->files->get($this->basePath.'/composer.json') : '')), true, 32, JSON_THROW_ON_ERROR);
                foreach (['autoload', 'autoload-dev'] as $section) {
                    foreach ($composer[$section]['psr-4'] ?? [] as $paths) {
                        foreach ((array) $paths as $path) {
                            if (is_string($path) && $path !== '' && $this->safe($path)) {
                                $this->directories[] = rtrim($path, '/');
                            }
                        }
                    }
                    foreach ($composer[$section]['files'] ?? [] as $path) {
                        if (is_string($path)) {
                            $queue[] = $path;
                        }
                    }
                }
            } catch (Throwable) {
                $this->notice('composer.json', 'Execution Composer source configuration is unreadable.');
            }
        }
        $this->directories = array_values(array_unique($this->directories));
        array_push($queue, ...$this->listing());
        $result = ['classes' => [], 'calls' => [], 'operations' => [], 'notices' => [], 'limited' => false];
        $seen = [];
        $analyzed = [];
        $declarations = [];
        for ($i = 0; $i < count($queue); $i++) {
            $path = $this->normalize($queue[$i]);
            if ($path === null || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            if (! array_key_exists($path, $this->states)) {
                $this->states[$path] = isset($shared[$path]) ? ($httpInputs[$path] ?? null) : $this->stat($path);
            }
            if (! $this->safe($path) || Str::is($this->exclude, $path) || $path === 'composer.json') {
                continue;
            }
            if (! str_ends_with(strtolower($path), '.php')) {
                $this->notice($path, 'Only PHP source inputs are read; non-PHP input is unresolved.');

                continue;
            }
            $stat = $this->states[$path];
            if ($stat === null) {
                if (! in_array($path, ['routes/console.php', 'app/Console/Kernel.php', 'bootstrap/app.php', 'bootstrap/providers.php'], true)) {
                    $this->notice($path, 'Execution source input is missing.');
                }

                continue;
            }
            if (count($seen) > 10000 || $this->bytes + $stat[1] > 10000000 || ($reason = ImpactExtractor::sourceLimit($stat[1])) !== null) {
                $this->limited = true;
                $this->notice($path, $reason ?? 'Execution source count/bytes limit reached.');
                break;
            }
            try {
                $facts = $shared[$path] ?? null;
                if ($facts === null) {
                    $contents = ($this->snapshot?->read($path) ?? ($this->snapshot === null ? $this->files->get($this->basePath.'/'.$path) : ''));
                    $file = new FileContext($path, $contents);
                    $facts = (new ExecutionExtractor)->extract($file);
                    $file->releaseAst();
                }
                $this->bytes += $stat[1];
                $analyzed[] = $path;
                foreach ($facts['classes'] as $key => $class) {
                    if (isset($result['classes'][$key]) && $result['classes'][$key]['path'] !== $class['path']) {
                        $class['ambiguous'] = true;
                        $this->notice($path, 'Duplicate execution class declaration: '.$class['name']);
                    }
                    $result['classes'][$key] = $class;
                }
                if ($this->retainDeclarations) {
                    array_push($declarations, ...($facts['class_declarations'] ?? array_values($facts['classes'])));
                }
                array_push($result['calls'], ...$facts['calls']);
                array_push($result['operations'], ...$facts['operations']);
                array_push($this->notices, ...$facts['notices']);
                $this->limited = $this->limited || $facts['limited'];
                foreach ($facts['paths'] as $include) {
                    $queue[] = $include;
                }
                foreach ($facts['classes'] as $class) {
                    foreach ($class['methods']['discovereventswithin']['returns'] ?? [] as $paths) {
                        foreach (is_array($paths) ? $paths : [] as $directory) {
                            if (is_string($directory) && $this->safe($directory) && ! in_array($directory, $this->directories, true)) {
                                $this->directories[] = $directory;
                                array_push($queue, ...$this->listing([$directory]));
                            }
                        }
                    }
                }
                foreach ($facts['operations'] as $operation) {
                    $consolePaths = [];
                    if ($operation['kind'] === 'console_registration') {
                        $consolePaths = match ($operation['method']) {
                            'withcommands' => ($operation['args']['commands'] ?? $operation['args'][0] ?? []) ?: ['app/Console/Commands'],
                            'withrouting' => [$operation['args']['commands'] ?? $operation['args'][3] ?? null],
                            default => [],
                        };
                    } elseif ($operation['kind'] === 'console_candidate' && in_array($operation['method'], ['load', 'addcommandpaths', 'addcommandroutepaths'], true)) {
                        // Reading a candidate path is safe; semantic registration still checks its owner.
                        $consolePaths = $operation['args'][0] ?? [];
                    }
                    foreach (is_array($consolePaths) ? $consolePaths : [$consolePaths] as $consolePath) {
                        if (! is_string($consolePath) || str_contains($consolePath, '\\')) {
                            continue;
                        }
                        $consolePath = $this->normalize($consolePath);
                        if ($consolePath === null) {
                            continue;
                        }
                        if (str_ends_with($consolePath, '.php')) {
                            $queue[] = $consolePath;
                        } elseif (! in_array($consolePath, $this->directories, true)) {
                            $this->directories[] = $consolePath;
                            if (! ($this->snapshot !== null ? $this->snapshot->stat($consolePath) !== null : is_dir($this->basePath.'/'.$consolePath)) && $consolePath !== 'app/Console/Commands') {
                                $this->notice($consolePath, 'Console registration source directory is missing.');
                            }
                            array_push($queue, ...$this->listing([$consolePath]));
                        }
                    }
                    if ($operation['kind'] === 'discovery') {
                        $paths = $operation['args']['discover'] ?? $operation['args'][0] ?? null;
                        if (is_array($paths)) {
                            foreach ($paths as $directory) {
                                if (is_string($directory) && $this->safe($directory) && ! in_array($directory, $this->directories, true)) {
                                    $this->directories[] = $directory;
                                    array_push($queue, ...$this->listing([$directory]));
                                }
                            }
                        }
                    }
                }
            } catch (Throwable) {
                $this->notice($path, 'Execution source cannot be read.');
            }
        }
        ksort($this->states);

        return [...$result, 'notices' => $this->notices, 'limited' => $this->limited, 'inputs' => $this->states, 'analyzed_paths' => $analyzed, 'class_declarations' => $declarations, 'signature' => hash('xxh128', serialize($this->states))];
    }

    public function fresh(): bool
    {
        $before = $this->states;
        $this->entries = 0;
        $now = [];
        foreach (array_keys($before) as $path) {
            $now[$path] = $this->stat($path);
        }
        foreach ($this->listing() as $path) {
            $now[$path] = $this->stat($path);
        }
        // listing records missing directories as inputs as well.
        foreach (array_keys($this->states) as $path) {
            $now[$path] = $this->stat($path);
        }
        ksort($now);

        return ! $this->limited && $before === $now;
    }

    /** Read only a previously discovered, unchanged, safe PHP input. */
    public function read(string $path): ?FileContext
    {
        $state = $this->stat($path);
        if (! str_ends_with(strtolower($path), '.php') || $state === null || ! isset($this->states[$path]) || $state !== $this->states[$path] || ImpactExtractor::sourceLimit($state[1]) !== null) {
            $this->notice($path, 'Source changed or cannot be safely reread.');

            return null;
        }

        return new FileContext($path, ($this->snapshot?->read($path) ?? ($this->snapshot === null ? $this->files->get($this->basePath.'/'.$path) : '')));
    }

    /** @param list<string>|null $directories
     * @return list<string>
     */
    private function listing(?array $directories = null): array
    {
        $paths = [];
        if ($this->snapshot !== null) {
            foreach ($directories ?? $this->directories as $directory) {
                $this->states[$directory] = $this->snapshot->stat($directory);
            }

            return $this->snapshot->listing($directories ?? $this->directories);
        }
        foreach ($directories ?? $this->directories as $directory) {
            $directory = rtrim($directory, '/');
            $this->states[$directory] = $this->stat($directory);
            if (! $this->safe($directory) || ! is_dir($this->basePath.'/'.$directory)) {
                continue;
            }
            try {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->basePath.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if (++$this->entries > 20000 || ImpactExtractor::sourceLimit(0) !== null) {
                        $this->limited = true;
                        $this->notice($directory, 'Execution directory entry/memory limit reached.');
                        break 2;
                    }
                    if ($file->isLink()) {
                        $this->notice(ProjectPath::relative($this->basePath, $file->getPathname()), 'Execution source symlink is not followed.');
                    } elseif ($file->isFile() && $file->getExtension() === 'php') {
                        $paths[] = ProjectPath::relative($this->basePath, $file->getPathname());
                    }
                }
            } catch (Throwable) {
                $this->notice($directory, 'Execution source directory cannot be read.');
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    private function normalize(string $path): ?string
    {
        $base = rtrim(str_replace('\\', '/', $this->basePath), '/').'/';
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = preg_replace('~^(\./)+~', '', $path) ?? $path;
        if (! $this->safe($path)) {
            $this->notice($path, 'Execution source is outside the project or crosses a symlink.');

            return null;
        }

        return $path;
    }

    private function safe(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, 'bootstrap/cache/') || str_contains($path, "\0") || preg_match('~(^|/)(\.\.|vendor|node_modules|\.git)(/|$)~', $path)) {
            return false;
        }
        if ($this->snapshot !== null) {
            return SnapshotInputs::safe($path);
        }
        $cursor = $this->basePath;
        foreach (explode('/', $path) as $part) {
            $cursor .= '/'.$part;
            if (is_link($cursor)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int>|null */
    private function stat(string $path): ?array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot->stat($path);
        }
        clearstatcache(true, $this->basePath.'/'.$path);
        if (! $this->safe($path) || ! file_exists($this->basePath.'/'.$path)) {
            return null;
        }
        $stat = @stat($this->basePath.'/'.$path);

        return $stat === false ? null : [$stat['mtime'], $stat['size']];
    }

    private function notice(string $path, string $reason): void
    {
        $this->notices[] = ['path' => $path, 'line' => 1, 'reason' => $reason];
    }
}
