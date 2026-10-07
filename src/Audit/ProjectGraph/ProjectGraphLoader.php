<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph;

use Closure;
use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CachedGraph;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CacheStatus;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphBuildPlan;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphCacheSignature;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\PackageFingerprint;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogDiagnostic;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use GracjanKubicki\ArchitectureKit\Impact\ImpactFacts;
use GracjanKubicki\ArchitectureKit\Support\ProjectPath;
use Illuminate\Filesystem\Filesystem;
use SplFileInfo;

final readonly class ProjectGraphLoader
{
    public RoleClassifier $classification;

    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private AuditScope $scope = new AuditScope,
        private ?ProjectGraphCache $cache = null,
        /**
         * Configuration that must not be shared between cached graphs.
         *
         * @var array<int, string>
         */
        private array $configuration = [],
        private bool $impact = false,
        private bool $sourceOnly = false,
        private bool $catalog = false,
        /** @var Closure(string): void|null */
        private ?Closure $onParse = null,
    ) {
        $this->classification = RoleClassifier::forProject($files, $basePath);
    }

    /**
     * @param  array<int, string>  $exclude
     * @return array<string, FileContext>
     */
    public function files(array $exclude = []): array
    {
        $contexts = [];

        foreach ($this->stream($exclude) as $context) {
            $contexts[$context->path] = $context;
        }

        ksort($contexts);

        return $contexts;
    }

    /**
     * @param  array<int, string>  $exclude
     * @return iterable<int, FileContext>
     */
    public function stream(array $exclude = []): iterable
    {
        foreach ($this->scan($exclude) as $path => $file) {
            yield new FileContext($path, $this->files->get($file[0]), $this->onParse);
        }
    }

    /**
     * Every file in scope with its stat, without reading any contents.
     *
     * Reading a file costs little next to parsing it, but it is the whole cost once
     * parsing is avoided: 1.55s of the 19.5s build on a large application. A cached run
     * only opens the files it has to parse again.
     *
     * @param  array<int, string>  $exclude
     * @return array<string, array{0: string, 1: string}>
     */
    private function scan(array $exclude): array
    {
        $found = [];
        if ($this->catalog) {
            foreach (ComposerCatalogExtractor::FILES as $relative) {
                $absolute = $this->basePath.'/'.$relative;
                if ($this->safeSource($relative) && $this->files->isFile($absolute) && ! $this->isExcluded($relative, $exclude)) {
                    $found[$relative] = [$absolute, GraphCacheSignature::stat((int) $this->files->lastModified($absolute), (int) $this->files->size($absolute))];
                }
            }
        }

        foreach ($this->scope->directories as $directory) {
            $absolute = $this->basePath.'/'.$directory;
            if ($this->sourceOnly && ! $this->safeSource($directory.'/__graph_source__.php')) {
                continue;
            }

            // A configured directory that does not exist is not an error: a project may
            // list one it has not created yet.
            if (! $this->files->isDirectory($absolute)) {
                continue;
            }

            foreach ($this->files->allFiles($absolute) as $file) {
                if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = ProjectPath::relative($this->basePath, $file->getPathname());
                if ($this->sourceOnly && ! $this->safeSource($path)) {
                    continue;
                }

                // A test file stays in the graph even when an exclusion pattern matches
                // it: the scope only contains tests/ because the missing-test rule needs
                // them, and hiding some would make covered classes look untested.
                if (! $this->isRequiredTestFile($path) && $this->isExcluded($path, $exclude)) {
                    continue;
                }

                $found[$path] = [
                    $file->getPathname(),
                    GraphCacheSignature::stat((int) $file->getMTime(), (int) $file->getSize()),
                ];
            }
        }

        return $found;
    }

    private function isRequiredTestFile(string $path): bool
    {
        return ! $this->catalog && $this->scope->includesTests() && $this->scope->isTestPath($path);
    }

    /** @param array<int, string> $exclude */
    public function load(array $exclude = []): ProjectGraphSnapshot
    {
        return $this->build($this->plan($exclude));
    }

    /**
     * Build the graph a plan describes, parsing only what the plan says to parse.
     *
     * Callers that need to know how the cache behaved take the plan first and pass it
     * here, rather than losing that answer inside `load()`.
     *
     * @param  array<string, FileContext>  $parsedSources  Transient source contexts parsed while reading settings.
     */
    public function build(GraphBuildPlan $plan, array $parsedSources = []): ProjectGraphSnapshot
    {
        $builder = new ProjectGraphBuilder(roles: $this->classification, impact: $this->impact, catalog: $this->catalog, basePath: $this->basePath);
        $entries = $plan->reusable;

        foreach ($plan->toParse as $path) {
            $absolute = $plan->files[$path];
            if ($this->sourceOnly && (! $this->safeSource($path) || ! $this->files->isFile($absolute) || GraphCacheSignature::stat((int) $this->files->lastModified($absolute), (int) $this->files->size($absolute)) !== ($plan->signature->files[$path] ?? null))) {
                $entries[$path] = new FileGraphEntry([], [], impact: new ImpactFacts($path, [], [], [['line' => 1, 'reason' => 'Source changed or became unsafe before graph reading.']]),
                    catalog: $this->catalog ? new CatalogFacts($path, diagnostics: [new CatalogDiagnostic('changed_inputs', 'Source changed or became unsafe before graph reading.', 1)]) : null);

                continue;
            }
            $metadata = $this->catalog && in_array($path, ComposerCatalogExtractor::FILES, true);
            if (($this->impact || $this->catalog) && ($reason = $metadata ? ComposerCatalogExtractor::sourceLimit($this->files->size($absolute)) : ImpactExtractor::sourceLimit($this->files->size($absolute))) !== null) {
                $entries[$path] = new FileGraphEntry([], [], impact: $this->impact ? new ImpactFacts($path, [], [], [['line' => 1, 'reason' => $reason]]) : null,
                    catalog: $this->catalog ? new CatalogFacts($path, diagnostics: [new CatalogDiagnostic('source_limit', $reason, 1)]) : null);

                continue;
            }
            $contents = $this->files->get($absolute);
            $context = $parsedSources[$path] ?? null;
            if ($context !== null && ($context->path !== $path || $context->contents !== $contents)) {
                $entries[$path] = new FileGraphEntry([], [], impact: $this->impact ? new ImpactFacts($path, [], [], [['line' => 1, 'reason' => 'Previously parsed source changed before graph reading.']]) : null,
                    catalog: $this->catalog ? new CatalogFacts($path, diagnostics: [new CatalogDiagnostic('changed_inputs', 'Previously parsed source changed before graph reading.', 1)]) : null);

                continue;
            }
            $entries[$path] = $builder->collect($context ?? new FileContext($path, $contents, $this->onParse));
        }

        return $this->compose($builder, $plan, $entries);
    }

    private function safeSource(string $path): bool
    {
        $metadata = $this->catalog && in_array($path, ComposerCatalogExtractor::FILES, true);
        if (! $metadata && (! str_ends_with($path, '.php') || preg_match('~(^|/)(\.\.|vendor|node_modules|\.git)(/|$)~', $path)) || str_contains($path, "\0")) {
            return false;
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

    /**
     * What this run has to parse, decided from stat alone.
     *
     * The audit needs this rather than a finished graph: its rules read the syntax tree
     * of the files they check, so it parses each file once and hands back what that file
     * contributed instead of asking for the graph a second time.
     *
     * @param  array<int, string>  $exclude
     */
    public function plan(array $exclude = []): GraphBuildPlan
    {
        $scanned = $this->scan($exclude);
        $signature = $this->signatureFor($scanned);
        $files = array_map(static fn (array $file): string => $file[0], $scanned);

        if ($this->cache === null) {
            return new GraphBuildPlan($signature, $files, array_keys($scanned), [], CacheStatus::Disabled);
        }

        $result = $this->cache->read($signature);
        $previous = $result->graph;

        if ($previous === null) {
            return new GraphBuildPlan($signature, $files, array_keys($scanned), [], $result->status);
        }

        $reusable = [];
        $toParse = [];

        // Driven by what the project has, not by what the entry claims. Walking the entry
        // instead would carry a deleted file's symbols forward, and would trust an entry
        // whose file list and contributions disagree: a signature naming two files beside
        // a contribution for one would look complete, and the missing class would vanish
        // from the graph without anything reporting it.
        foreach ($scanned as $path => $file) {
            $entry = $previous->entries[$path] ?? null;

            if ($entry !== null && (! $this->impact || ($entry->impact !== null && $entry->impact->cacheable())) && (! $this->catalog || ($entry->catalog !== null && $entry->catalog->cacheable())) && ($previous->signature->files[$path] ?? null) === $file[1]) {
                $reusable[$path] = $entry;

                continue;
            }

            $toParse[] = $path;
        }

        // Nothing to parse and nothing dropped means the stored entry still describes the
        // project exactly, so rewriting it would serialize 33.8 MB to produce the file
        // that is already there.
        return new GraphBuildPlan(
            $signature,
            $files,
            $toParse,
            $reusable,
            $result->status,
            stale: $toParse !== [] || count($reusable) !== count($previous->entries),
        );
    }

    /** Recheck file state without restoring a second cached graph.
     * @param  list<string>  $exclude
     */
    public function currentSignature(array $exclude = []): GraphCacheSignature
    {
        return $this->signatureFor($this->scan($exclude));
    }

    /** @param array<string, array{0: string, 1: string}> $scanned */
    private function signatureFor(array $scanned): GraphCacheSignature
    {
        return GraphCacheSignature::create(
            [PackageFingerprint::current(), $this->classification->mappings->fingerprint(), implode(',', $this->scope->directories), ...$this->configuration, ...($this->impact ? ['impact-v3'] : []), ...($this->catalog ? ['catalog-v1'] : [])],
            array_map(static fn (array $file): string => $file[1], $scanned),
        );
    }

    /**
     * Assemble the graph from restored and freshly parsed contributions, and store it.
     *
     * Entries are sorted by path first so the builder sees them in the same order a full
     * build would, and `finish()` then sorts and deduplicates exactly as before.
     *
     * @param  array<string, FileGraphEntry>  $entries
     */
    public function compose(ProjectGraphBuilder $builder, GraphBuildPlan $plan, array $entries): ProjectGraphSnapshot
    {
        ksort($entries);

        foreach ($entries as $entry) {
            $builder->addEntry($entry);
        }

        if ($plan->stale) {
            $this->cache?->write(new CachedGraph($plan->signature, $entries));
        }

        return $builder->finish();
    }

    /** @param array<int, string> $exclude */
    private function isExcluded(string $path, array $exclude): bool
    {
        foreach ($exclude as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
