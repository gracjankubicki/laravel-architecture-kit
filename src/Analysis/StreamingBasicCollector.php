<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\BladePhpSource;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureRecord;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\SourceWitness;
use GracjanKubicki\ArchitectureKit\Standalone\Measurement\GraphStageMeasurement;
use RuntimeException;

/** Local-only extraction. Never addEntry/finish, CatalogIndex or CatalogUpdater. */
final readonly class StreamingBasicCollector
{
    public function __construct(private AnalysisSession $session, private BasicFactsOptions $options, private string $root) {}

    /**
     * @param  array<array-key, mixed>  $plan
     */
    public function collect(array $plan, \Closure $append, \Closure $checkpoint): void
    {
        $builder = new ProjectGraphBuilder(roles: $this->options->roles, impact: $this->options->impact,
            preserveOccurrences: $this->options->preserveOccurrences, catalog: $this->options->catalog, basePath: $this->root, mode: AnalysisMode::Basic);
        $pending = [];
        $bytes = 0;
        $ordinal = $batch = 0;
        $measurement = GraphStageMeasurement::current();
        /** @param list<FileContext> $pending */
        $flush = function (array &$pending, int &$bytes) use (&$ordinal, &$batch, $builder, $append, $checkpoint, $measurement): void {
            if ($pending === []) {
                return;
            }
            $checkpoint();
            if ($measurement !== null) {
                $measurement->batch = $batch++;
            }
            $collect = fn () => $builder->collectBasicMany($pending);
            $entries = $measurement === null ? $collect() : $measurement->measure('batch.extract', $collect);
            if (count($entries) !== count($pending)) {
                throw new RuntimeException('capture_batch_incomplete');
            }
            foreach ($pending as $index => $file) {
                $source = new SourceInput($file->path, $file->contents, $this->session->phpVersion);
                $input = $this->options->catalog && str_ends_with($file->path, '.blade.php') ? (new BladePhpSource)->input($file) : BasicSource::plain($source);
                $kind = in_array($file->path, ComposerCatalogExtractor::FILES, true) ? 'composer' : (str_ends_with($file->path, '.blade.php') ? 'blade' : 'php');
                $append(CaptureRecord::create($ordinal++, $kind, $input, $this->options, $entries[$index]));
                unset($entries[$index]);
                $file->releaseAst();
            }
            $pending = [];
            $bytes = 0;
            $checkpoint();
        };
        foreach ($plan['files'] as $path => $signature) {
            $checkpoint();
            if (! SourceManifest::safe($this->root, $path, in_array($path, ComposerCatalogExtractor::FILES, true))) {
                throw new RuntimeException('capture_unsafe_source');
            }
            $absolute = $this->root.'/'.$path;
            clearstatcache(true, $absolute);
            if (! is_file($absolute) || filesize($absolute) > SourceWitness::MAX_SOURCE_BYTES * 4) {
                throw new RuntimeException('capture_source_limit');
            }
            $contents = file_get_contents($absolute);
            if ($contents === false || ! str_ends_with($signature, ':'.hash('sha256', $contents))) {
                throw new RuntimeException('capture_source_drift');
            }
            if ($pending !== [] && (count($pending) >= MagoAnalysisEngine::BASIC_BATCH_FILES || $bytes + strlen($contents) > MagoAnalysisEngine::BASIC_BATCH_BYTES)) {
                $flush($pending, $bytes);
            }
            $pending[] = new FileContext($path, $contents, analysis: $this->session);
            $bytes += strlen($contents);
            if ($bytes >= MagoAnalysisEngine::BASIC_BATCH_BYTES) {
                $flush($pending, $bytes);
            }
        }
        $flush($pending, $bytes);
    }
}
