<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

use Closure;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Finder as NodeFinder;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use WeakMap;

/** Bounded syntax reuse within one controller audit, never between queries. */
final class SourceFileCache
{
    /** @var array<string, array{FileContext, int}> */
    private array $entries = [];

    private int $bytes = 0;

    /** @var array<string, true> Parsed files with no named function declaration. */
    private array $withoutFunctions = [];

    private ?ProjectGraphSnapshot $graph = null;

    /** @var array<string, array<string, int>> */
    private array $namespaces = [];

    /** @var WeakMap<FileContext, list<Node>> */
    private WeakMap $declarations;

    public function __construct(private readonly ?AnalysisFactsCache $facts = null)
    {
        $this->declarations = new WeakMap;
    }

    /** @return list<Node> */
    public function classes(FileContext $file): array
    {
        return $this->declarations[$file] ??= array_values((new NodeFinder)->findOfKind($file->syntax() ?? [], 'statement.class-like'));
    }

    public function hasNoFunctions(string $path): bool
    {
        return isset($this->withoutFunctions[$path]) || ($this->facts?->hasNoFunctions($path) ?? false);
    }

    public function rememberNoFunctions(string $path): void
    {
        if (count($this->withoutFunctions) < 10_000) {
            $this->withoutFunctions[$path] = true;
            $this->facts?->rememberNoFunctions($path);
        }
    }

    /** @return array<string, array<string, int>> */
    public function namespacePaths(ProjectGraphSnapshot $graph): array
    {
        if ($this->graph !== $graph) {
            $this->graph = $graph;
            $this->namespaces = [];
            foreach ($graph->symbols as $ordinal => $symbol) {
                $separator = strrpos($symbol->name, '\\');
                $prefix = $separator === false ? '' : substr($symbol->name, 0, $separator + 1);
                $this->namespaces[$prefix][$symbol->path] ??= $ordinal;
            }
        }

        return $this->namespaces;
    }

    public function has(string $path): bool
    {
        return isset($this->entries[$path]);
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->bytes = 0;
    }

    public function get(string $path, int $size, Closure $load): FileContext
    {
        if (isset($this->entries[$path])) {
            $entry = $this->entries[$path];
            unset($this->entries[$path]);
            $this->entries[$path] = $entry;

            return $entry[0];
        }
        $limit = MemoryLimit::bytes();
        if ($limit !== null && memory_get_usage(true) + $size * 700 > $limit * 0.8) {
            $this->clear();
        }
        while ($this->entries !== [] && $this->bytes + $size > 1_000_000) {
            $key = array_key_first($this->entries);
            $this->bytes -= $this->entries[$key][1];
            unset($this->entries[$key]);
        }
        $file = $load();
        if ($size <= 1_000_000 && ($limit === null || memory_get_usage(true) <= $limit * 0.8)) {
            $this->entries[$path] = [$file, $size];
            $this->bytes += $size;
        }

        return $file;
    }
}
