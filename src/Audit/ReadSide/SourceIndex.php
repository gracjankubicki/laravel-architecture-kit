<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use Illuminate\Filesystem\Filesystem;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Lazy, audit-local source lookup. Never autoloads application classes. */
final class SourceIndex
{
    /** @var array<string, SourceClass|null> */
    private array $classes = [];

    private int $sourceBytes = 0;

    /** @var array<string, true> */
    private array $paths = [];

    /** @var array<string, true> */
    private array $auxiliary = [];

    /** @var array<string, list<string>>|null */
    private ?array $autoloadPaths = null;

    /** @return list<string> Sources consulted by this bounded lookup, including unavailable ones. */
    public function paths(): array
    {
        return array_keys($this->paths);
    }

    /** @var array<string, string> */
    private array $unavailable = [];

    /** @var array<string, string> */
    private array $unavailableCodes = [];

    public function unavailableCode(string $name): string
    {
        return $this->unavailableCodes[strtolower($name)] ?? 'source_unavailable';
    }

    /** Source lookups can change this result between successive calls.
     * @phpstan-impure
     */
    public function unavailableReason(string $name): ?string
    {
        return $this->unavailable[strtolower($name)] ?? null;
    }

    public function inScope(string $name): bool
    {
        return $this->graph->symbol($name) !== null;
    }

    public function __construct(private Filesystem $files, private string $basePath, private ProjectGraphSnapshot $graph) {}

    public function get(string $name): ?SourceClass
    {
        $key = strtolower($name);
        if (array_key_exists($key, $this->classes)) {
            return $this->classes[$key];
        }
        $this->classes[$key] = null;
        $symbol = $this->graph->symbol($name);
        if ($symbol !== null) {
            $this->paths[$symbol->path] = true;
        }
        if ($symbol === null || ! $this->files->isFile($this->basePath.'/'.$symbol->path)) {
            return null;
        }
        $this->read($name, $symbol->path);

        return $this->classes[$key];
    }

    /** Explicit, bounded source lookup for a selected test helper, never part of the audit graph. */
    public function loadAuxiliary(string $name): ?SourceClass
    {
        if (($source = $this->get($name)) !== null) {
            return $source;
        }
        $key = strtolower($name);
        if (isset($this->auxiliary[$key]) || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/D', $name)) {
            return null;
        }
        $this->auxiliary[$key] = true;
        if ($this->autoloadPaths === null) {
            $this->autoloadPaths = ['App\\' => ['app/'], 'Database\\Factories\\' => ['database/factories/']];
            $this->paths['composer.json'] = true;
            $composerPath = $this->basePath.'/composer.json';
            if ($this->files->isFile($composerPath)) {
                if (! $this->safe('composer.json') || $this->files->size($composerPath) > 100_000) {
                    $this->autoloadPaths = [];
                } else {
                    $composer = json_decode($this->files->get($composerPath), true);
                    if (! is_array($composer)) {
                        $this->autoloadPaths = [];
                    } else {
                        foreach (['autoload', 'autoload-dev'] as $section) {
                            $sectionData = $composer[$section] ?? null;
                            $mappings = is_array($sectionData) ? ($sectionData['psr-4'] ?? []) : [];
                            if (! is_array($mappings)) {
                                $this->autoloadPaths = [];
                                break;
                            }
                            foreach ($mappings as $prefix => $paths) {
                                if (is_string($prefix) && $prefix !== '' && (is_string($paths) || is_array($paths))) {
                                    $this->autoloadPaths[$prefix] = array_values(array_filter((array) $paths, 'is_string'));
                                }
                            }
                        }
                    }
                }
            }
        }
        $prefixes = array_keys($this->autoloadPaths);
        usort($prefixes, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $candidates = [];
        foreach ($prefixes as $prefix) {
            if (! str_starts_with($name, $prefix)) {
                continue;
            }
            foreach ($this->autoloadPaths[$prefix] as $directory) {
                $path = rtrim($directory, '/').'/'.str_replace('\\', '/', substr($name, strlen($prefix))).'.php';
                $this->paths[$path] = true;
                if ($this->safe($path) && $this->files->isFile($this->basePath.'/'.$path)) {
                    $candidates[$path] = true;
                }
            }
            break;
        }
        if (count($candidates) !== 1) {
            $this->unavailable[$key] = 'Auxiliary source is missing, unsafe or ambiguous for '.$name;

            return null;
        }
        $this->read($name, array_key_first($candidates));

        return $this->classes[$key];
    }

    private function safe(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
            || preg_match('#(^|/)(?:\.\.?|vendor|\.git|\.env)(?:/|$)#', $path)) {
            return false;
        }
        $base = realpath($this->basePath);
        $real = realpath($this->basePath.'/'.$path);

        return $base !== false && $real !== false && str_starts_with($real, $base.'/');
    }

    private function read(string $name, string $path): void
    {
        $key = strtolower($name);
        $size = $this->files->size($this->basePath.'/'.$path);
        $limit = MemoryLimit::bytes();
        // A bounded query must not retain a second full-project syntax tree.
        if ($size > 100_000 || $this->sourceBytes + $size > 1_000_000 || ($limit !== null && memory_get_usage(true) + $size * 700 > $limit * 0.8)) {
            $this->unavailableCodes[$key] = 'source_budget';
            $this->unavailable[$key] = 'Source or memory budget exceeded for '.$name;

            return;
        }
        $this->sourceBytes += $size;
        $file = new FileContext($path, $this->files->get($this->basePath.'/'.$path));
        $this->indexFunctions($file);
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Node\Stmt\ClassLike::class) as $node) {
            if (isset($node->namespacedName)) {
                $className = $node->namespacedName->toString();
                $this->classes[strtolower($className)] = new SourceClass($className, $file, $node);
                if (isset($this->auxiliary[$key])) {
                    $this->auxiliary[strtolower($className)] = true;
                }
            }
        }

        if ($file->parseError() !== null) {
            $this->unavailable[$key] = 'Auxiliary or scoped source cannot be parsed for '.$name;
        }
    }

    /** @var array<string, array{FileContext, Node\Stmt\Function_}|null> */
    private array $functions = [];

    /** @var array<string, true> */
    private array $functionFiles = [];

    /** @var list<string>|null */
    private ?array $helperFiles = null;

    private ?string $functionLookupFailure = null;

    /** PHP resolves namespace/import candidates before falling back to a global function.
     * @return array{name: string, source: SourceClass|null, node: Node\Stmt\Function_|null, reason: string|null}
     */
    public function functionCall(Node\Name $name, SourceClass $caller): array
    {
        $namespace = $name->getAttribute('namespacedName');
        $candidates = array_values(array_unique($namespace instanceof Node\Name
            ? [$namespace->toString(), $caller->file->resolvedName($name)] : [$caller->file->resolvedName($name)]));
        $this->indexFunctions($caller->file);
        if ($this->helperFiles === null) {
            $this->helperFiles = [];
            $path = 'composer.json';
            $this->paths[$path] = true;
            if ($this->files->isFile($this->basePath.'/'.$path)) {
                if (! $this->safe($path) || $this->files->size($this->basePath.'/'.$path) > 100_000) {
                    $this->functionLookupFailure = 'source_budget';
                } else {
                    $composer = json_decode($this->files->get($this->basePath.'/'.$path), true);
                    if (! is_array($composer)) {
                        $this->functionLookupFailure = 'source_unavailable';
                    } else {
                        foreach (['autoload', 'autoload-dev'] as $section) {
                            $files = $composer[$section]['files'] ?? [];
                            if (! is_array($files) || ! array_is_list($files)) {
                                $this->functionLookupFailure = 'source_unavailable';

                                continue;
                            }
                            foreach ($files as $file) {
                                if (! is_string($file)) {
                                    $this->functionLookupFailure = 'source_unavailable';

                                    continue;
                                }
                                $this->helperFiles[] = $file;
                            }
                        }
                    }
                }
            }
        }
        $paths = $this->helperFiles;
        foreach ($this->graph->symbols as $symbol) {
            $separator = strrpos($symbol->name, '\\');
            $prefix = $separator === false ? '' : substr($symbol->name, 0, $separator + 1);
            foreach ($candidates as $candidate) {
                $position = strrpos($candidate, '\\');
                if (($position === false ? '' : substr($candidate, 0, $position + 1)) === $prefix) {
                    $paths[] = $symbol->path;
                }
            }
        }
        foreach (array_unique($paths) as $path) {
            if (isset($this->functionFiles[$path])) {
                continue;
            }
            $this->paths[$path] = true;
            if (! $this->safe($path) || ! $this->files->isFile($this->basePath.'/'.$path)) {
                $this->functionLookupFailure = 'source_unavailable';

                continue;
            }
            $size = $this->files->size($this->basePath.'/'.$path);
            $limit = MemoryLimit::bytes();
            if ($size > 100_000 || $this->sourceBytes + $size > 1_000_000 || ($limit !== null && memory_get_usage(true) + $size * 700 > $limit * 0.8)) {
                $this->functionLookupFailure = 'source_budget';

                continue;
            }
            $this->sourceBytes += $size;
            $this->indexFunctions(new FileContext($path, $this->files->get($this->basePath.'/'.$path)));
        }
        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (array_key_exists($key, $this->functions)) {
                $found = $this->functions[$key];

                // An empty lexical class context provides locations and name resolution,
                // without borrowing the caller's $this/self/parent binding.
                return ['name' => $candidate, 'source' => $found === null ? null : new SourceClass('(function) '.$candidate, $found[0], new Node\Stmt\Class_(null)),
                    'node' => $found[1] ?? null, 'reason' => $found === null ? 'unresolved_call' : $this->functionLookupFailure];
            }
        }

        return ['name' => $candidates[array_key_last($candidates)], 'source' => null, 'node' => null, 'reason' => $this->functionLookupFailure];
    }

    private function indexFunctions(FileContext $file): void
    {
        if (isset($this->functionFiles[$file->path])) {
            return;
        }
        $this->functionFiles[$file->path] = true;
        $nodes = $file->ast();
        if ($nodes === null) {
            $this->functionLookupFailure = 'source_unavailable';

            return;
        }
        $top = [];
        foreach ($nodes as $node) {
            array_push($top, ...($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node]));
        }
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Function_::class) as $node) {
            $key = strtolower($node->namespacedName->toString());
            $this->functions[$key] = array_key_exists($key, $this->functions) || ! in_array($node, $top, true) ? null : [$file, $node];
        }
    }

    public function isA(string $name, string $parent, int $depth = 0): bool
    {
        if (strcasecmp($name, $parent) === 0) {
            return true;
        }
        $frameworkParents = [
            'Carbon\\Carbon' => 'Carbon\\CarbonInterface',
            'Carbon\\CarbonImmutable' => 'Carbon\\CarbonInterface',
            'Illuminate\\Support\\Carbon' => 'Carbon\\Carbon',
            'Illuminate\\Foundation\\Auth\\User' => 'Illuminate\\Database\\Eloquent\\Model',
            'Orchestra\\Testbench\\TestCase' => 'Illuminate\\Foundation\\Testing\\TestCase',
            'Illuminate\\Foundation\\Http\\FormRequest' => 'Illuminate\\Http\\Request',
            'Illuminate\\Http\\Resources\\Json\\ResourceCollection' => 'Illuminate\\Http\\Resources\\Json\\JsonResource',
            'Illuminate\\Database\\Eloquent\\Collection' => 'Illuminate\\Support\\Collection',
        ];
        foreach (['BelongsTo', 'BelongsToMany', 'HasOne', 'HasMany', 'HasOneThrough', 'HasManyThrough', 'MorphTo', 'MorphOne', 'MorphMany', 'MorphToMany', 'MorphedByMany'] as $relation) {
            $frameworkParents['Illuminate\\Database\\Eloquent\\Relations\\'.$relation] = 'Illuminate\\Database\\Eloquent\\Relations\\Relation';
        }
        if (isset($frameworkParents[$name])) {
            return $this->isA($frameworkParents[$name], $parent, $depth + 1);
        }
        if ($depth >= 12) {
            $this->unavailableCodes[strtolower($name)] = 'analysis_limit';
            $this->unavailable[strtolower($name)] = 'Inheritance depth budget exceeded for '.$name;

            return false;
        }
        $source = $this->get($name);
        $extends = $source?->node instanceof Node\Stmt\Class_ ? $source->node->extends : null;

        if ($extends === null) {
            return false;
        }
        $base = $source->file->resolvedName($extends);
        if (isset($this->auxiliary[strtolower($name)])) {
            $this->loadAuxiliary($base);
        }
        $matches = $this->isA($base, $parent, $depth + 1);
        if (! $matches && ($reason = $this->unavailableReason($base)) !== null) {
            $this->unavailable[strtolower($name)] = $reason;
            $this->unavailableCodes[strtolower($name)] = $this->unavailableCode($base);
        }

        return $matches;
    }

    /** @return array{SourceClass, Node\Stmt\ClassMethod}|null */
    public function method(string $class, string $method, int $depth = 0): ?array
    {
        if ($depth >= 12) {
            $this->unavailable[strtolower($class)] = 'Method inheritance depth budget exceeded for '.$class;
            $this->unavailableCodes[strtolower($class)] = 'analysis_limit';

            return null;
        }
        if (($source = $this->get($class)) === null) {
            return null;
        }
        if (($node = $source->node->getMethod($method)) !== null) {
            return [$source, $node];
        }
        foreach ($source->node->getTraitUses() as $use) {
            // Trait aliases/conflicts need PHP's dispatch rules; do not guess.
            if ($use->adaptations !== []) {
                return null;
            }
            foreach ($use->traits as $trait) {
                if (isset($this->auxiliary[strtolower($class)])) {
                    $this->loadAuxiliary($source->file->resolvedName($trait));
                }
                $found = $this->method($source->file->resolvedName($trait), $method, $depth + 1);
                if ($found !== null) {
                    return $found;
                }
                if (($reason = $this->unavailableReason($source->file->resolvedName($trait))) !== null) {
                    $this->unavailable[strtolower($class)] = $reason;
                    $this->unavailableCodes[strtolower($class)] = $this->unavailableCode($source->file->resolvedName($trait));
                }
            }
        }
        if ($source->node instanceof Node\Stmt\Class_ && $source->node->extends !== null) {
            $parent = $source->file->resolvedName($source->node->extends);
            if (isset($this->auxiliary[strtolower($class)])) {
                $this->loadAuxiliary($parent);
            }
            $found = $this->method($parent, $method, $depth + 1);
            if ($found === null && ($reason = $this->unavailableReason($parent)) !== null) {
                $this->unavailable[strtolower($class)] = $reason;
                $this->unavailableCodes[strtolower($class)] = $this->unavailableCode($parent);
            }

            return $found;
        }

        return null;
    }
}
