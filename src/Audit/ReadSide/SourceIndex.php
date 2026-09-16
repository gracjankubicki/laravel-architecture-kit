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

    /** @return list<string> Sources consulted by this bounded lookup, including unavailable ones. */
    public function paths(): array
    {
        return array_keys($this->paths);
    }

    /** @var array<string, string> */
    private array $unavailable = [];

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
        $size = $this->files->size($this->basePath.'/'.$symbol->path);
        $limit = MemoryLimit::bytes();
        // A bounded query must not retain a second full-project syntax tree.
        if ($size > 100_000 || $this->sourceBytes + $size > 1_000_000 || ($limit !== null && memory_get_usage(true) + $size * 700 > $limit * 0.8)) {
            $this->unavailable[$key] = 'Source or memory budget exceeded for '.$name;

            return null;
        }
        $this->sourceBytes += $size;
        $file = new FileContext($symbol->path, $this->files->get($this->basePath.'/'.$symbol->path));
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Node\Stmt\ClassLike::class) as $node) {
            if (isset($node->namespacedName)) {
                $className = $node->namespacedName->toString();
                $this->classes[strtolower($className)] = new SourceClass($className, $file, $node);
            }
        }

        return $this->classes[$key];
    }

    public function isA(string $name, string $parent, int $depth = 0): bool
    {
        if (strcasecmp($name, $parent) === 0) {
            return true;
        }
        $frameworkParents = [
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
            $this->unavailable[strtolower($name)] = 'Inheritance depth budget exceeded for '.$name;

            return false;
        }
        $source = $this->get($name);
        $extends = $source?->node instanceof Node\Stmt\Class_ ? $source->node->extends : null;

        if ($extends === null) {
            return false;
        }
        $base = $source->file->resolvedName($extends);
        $matches = $this->isA($base, $parent, $depth + 1);
        if (! $matches && ($reason = $this->unavailableReason($base)) !== null) {
            $this->unavailable[strtolower($name)] = $reason;
        }

        return $matches;
    }

    /** @return array{SourceClass, Node\Stmt\ClassMethod}|null */
    public function method(string $class, string $method, int $depth = 0): ?array
    {
        if ($depth >= 12 || ($source = $this->get($class)) === null) {
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
                $found = $this->method($source->file->resolvedName($trait), $method, $depth + 1);
                if ($found !== null) {
                    return $found;
                }
                if (($reason = $this->unavailableReason($source->file->resolvedName($trait))) !== null) {
                    $this->unavailable[strtolower($class)] = $reason;
                }
            }
        }
        if ($source->node instanceof Node\Stmt\Class_ && $source->node->extends !== null) {
            $parent = $source->file->resolvedName($source->node->extends);
            $found = $this->method($parent, $method, $depth + 1);
            if ($found === null && ($reason = $this->unavailableReason($parent)) !== null) {
                $this->unavailable[strtolower($class)] = $reason;
            }

            return $found;
        }

        return null;
    }
}
