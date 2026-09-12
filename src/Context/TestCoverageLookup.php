<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Context;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Support\ProjectPath;
use Illuminate\Filesystem\Filesystem;
use SplFileInfo;

/**
 * Which tests exercise one symbol.
 *
 * Answering this from a full project graph costs about twenty seconds on a large
 * application, and almost all of it is the PHP parser: measured 17.78s of parsing out of
 * 20.4s total for 33MB of sources. So the graph is not built. Files are filtered by the
 * class's short name first and only the matches are parsed, which on the same application
 * cut the work from 11566 files to 22 and the time to under a second, with an identical
 * result.
 *
 * The filter cannot miss an edge: the graph only ever records one from a `Name` node, and
 * a name has to appear in the source for the parser to see it. That holds for an import,
 * a fully qualified call, and `use X as Y` alike, so the text match is a superset of what
 * parsing would find.
 */
final readonly class TestCoverageLookup
{
    public const DIRECT = 'direct';

    public const INDIRECT = 'indirect';

    public function __construct(
        private Filesystem $files,
        private string $basePath,
    ) {}

    /**
     * Test files that depend on the symbol, directly or through one application class.
     *
     * @return array<int, array{path: string, coverage: string, via: string|null}>
     */
    public function for(string $symbol): array
    {
        $testSources = $this->sources(AuditScope::TESTS);

        if ($testSources === []) {
            return [];
        }

        $covered = [];

        foreach ($this->pathsDependingOn($testSources, [$symbol]) as $path => $_) {
            $covered[$path] = ['path' => $path, 'coverage' => self::DIRECT, 'via' => null];
        }

        // One hop out: a test driving a service that uses the symbol still exercises it,
        // so stopping at direct edges would report a covered element as untested.
        // All intermediate classes are resolved in one pass. A widely used symbol such as
        // a model has hundreds of them, and re-reading the test suite per class turned a
        // two second answer into a minute.
        $users = $this->applicationUsers($symbol);

        foreach ($this->pathsDependingOn($testSources, $users) as $path => $via) {
            if (isset($covered[$path])) {
                continue;
            }

            $covered[$path] = ['path' => $path, 'coverage' => self::INDIRECT, 'via' => $via];
        }

        ksort($covered);

        return array_values($covered);
    }

    /**
     * Application classes that depend on the symbol, used as the middle of the hop.
     *
     * @return array<int, string>
     */
    private function applicationUsers(string $symbol): array
    {
        $sources = $this->sources(AuditScope::APPLICATION);

        if ($sources === []) {
            return [];
        }

        $graph = $this->parse($this->matching($sources, [$symbol]));
        $users = [];

        foreach ($graph->edges as $edge) {
            if (strcasecmp($edge->to, $symbol) !== 0) {
                continue;
            }

            // The class that holds the edge, not every class declared beside it. One file
            // can declare several, and only the one the edge starts from actually uses
            // the symbol; crediting its neighbours would report tests that never touch it.
            if (strcasecmp($edge->from, $symbol) !== 0) {
                $users[$edge->from] = true;
            }
        }

        return array_keys($users);
    }

    /**
     * Paths depending on any of the given symbols, mapped to the symbol that was matched.
     *
     * @param  array<string, string>  $sources
     * @param  array<int, string>  $symbols
     * @return array<string, string>
     */
    private function pathsDependingOn(array $sources, array $symbols): array
    {
        if ($symbols === []) {
            return [];
        }

        $graph = $this->parse($this->matching($sources, $symbols));
        $wanted = [];

        foreach ($symbols as $symbol) {
            $wanted[strtolower($symbol)] = $symbol;
        }

        $paths = [];

        foreach ($graph->edges as $edge) {
            $match = $wanted[strtolower($edge->to)] ?? null;

            if ($match !== null && ! isset($paths[$edge->path])) {
                $paths[$edge->path] = $match;
            }
        }

        return $paths;
    }

    /**
     * Sources whose text contains the short name of any wanted symbol. Everything else
     * cannot reference them, so parsing it would be wasted work.
     *
     * @param  array<string, string>  $sources
     * @param  array<int, string>  $symbols
     * @return array<string, string>
     */
    private function matching(array $sources, array $symbols): array
    {
        // Lowercased on both sides: PHP class names are case-insensitive and the graph
        // compares them that way, so `new \App\Actions\sendinvoice()` builds a real edge
        // to SendInvoice. A case-sensitive filter would drop that file before parsing and
        // silently report the symbol as untested.
        $needles = array_unique(array_map(
            fn (string $symbol): string => strtolower($this->shortName($symbol)),
            $symbols,
        ));

        return array_filter(
            $sources,
            static function (string $source) use ($needles): bool {
                $haystack = strtolower($source);

                foreach ($needles as $needle) {
                    if (str_contains($haystack, $needle)) {
                        return true;
                    }
                }

                return false;
            },
        );
    }

    /**
     * @param  array<string, string>  $sources
     */
    private function parse(array $sources): ProjectGraphSnapshot
    {
        $builder = new ProjectGraphBuilder;

        foreach ($sources as $path => $contents) {
            $builder->add(new FileContext($path, $contents));
        }

        return $builder->finish();
    }

    /**
     * @return array<string, string>
     */
    private function sources(string $directory): array
    {
        $absolute = $this->basePath.'/'.$directory;

        if (! $this->files->isDirectory($absolute)) {
            return [];
        }

        $sources = [];

        foreach ($this->files->allFiles($absolute) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $sources[ProjectPath::relative($this->basePath, $file->getPathname())] = $this->files->get($file->getPathname());
        }

        return $sources;
    }

    private function shortName(string $symbol): string
    {
        $position = strrpos($symbol, '\\');

        return $position === false ? $symbol : substr($symbol, $position + 1);
    }
}
