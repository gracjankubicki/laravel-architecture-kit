<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Revision\SnapshotInputs;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/** One source catalog and extraction shared by symbol and table queries. */
final readonly class DataAnalysis
{
    /** @param array<string, mixed> $facts */
    private function __construct(public ExecutionSources $sources, public array $facts, public DataCatalog $catalog, public DataExtractor $extractor, public ExecutionLinks $links, public bool $limited) {}

    /** @param list<string> $exclude
     * @param  array<string, mixed>  $httpSources
     */
    public static function collect(Filesystem $files, string $basePath, ProjectGraphSnapshot $graph, array $exclude, array $httpSources = [], ?SnapshotInputs $snapshot = null): self
    {
        $sources = new ExecutionSources($files, $basePath, ['database/migrations'], snapshot: $snapshot);
        $facts = $sources->discover($graph, $exclude, $httpSources['inputs'] ?? [], $httpSources['execution_facts'] ?? []);
        $catalog = new DataCatalog;
        $limited = $facts['limited'];
        foreach ($facts['analyzed_paths'] as $path) {
            if (ImpactExtractor::sourceLimit(0) !== null) {
                $limited = true;
                break;
            }
            try {
                $file = $sources->read($path);
                if ($file === null) {
                    $catalog->notices[] = ['path' => $path, 'line' => 1, 'reason' => 'DATA input changed or is unavailable.'];

                    continue;
                }
                $catalog->collect($file);
                $file->releaseAst();
            } catch (Throwable) {
                $catalog->notices[] = ['path' => $path, 'line' => 1, 'reason' => 'DATA source cannot be read.'];
            }
        }
        $extractor = new DataExtractor($catalog, $sources);
        foreach ($facts['analyzed_paths'] as $path) {
            if (ImpactExtractor::sourceLimit(0) !== null) {
                $limited = true;
                break;
            }
            try {
                $file = $sources->read($path);
                if ($file === null) {
                    $catalog->notices[] = ['path' => $path, 'line' => 1, 'reason' => 'DATA input changed or is unavailable.'];

                    continue;
                }
                $extractor->extract($file);
                $file->releaseAst();
            } catch (Throwable) {
                $catalog->notices[] = ['path' => $path, 'line' => 1, 'reason' => 'DATA extraction is incomplete.'];
            }
        }
        $links = new ExecutionLinks($facts);
        $links->authorizationHttp($httpSources['routes'] ?? [], $facts);
        $limited = $limited || $extractor->limited || $links->limited;

        return new self($sources, $facts, $catalog, $extractor, $links, $limited);
    }
}
