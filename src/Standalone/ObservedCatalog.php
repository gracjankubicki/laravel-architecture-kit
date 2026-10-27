<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CatalogProductSet;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\FreshGraphProcessRequired;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphEpoch;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphSegmentStore;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogSettings;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogContribution;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogGeneration;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogIdentity;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogObservedPrefix;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogSequence;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogUpdater;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\SemanticVersions;
use GracjanKubicki\ArchitectureKit\Catalog\Search\SearchState;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/** Fresh builder for one generation. Old query leases remain in another process. */
final readonly class ObservedCatalog
{
    /** @param array<string, mixed> $job */
    public function __construct(private RepositoryContext $context, private string $directory, private array $job) {}

    /** @return array<string, mixed> */
    public function load(): array
    {
        $started = hrtime(true);
        $files = new Filesystem;
        $settings = CatalogSettings::load($files, $this->context->rootPath);
        $identity = $this->identity($settings);
        $set = new CatalogProductSet(new GraphSegmentStore($files, $this->directory.'/product-'.$this->job['product_key']));
        $admission = null;
        $beforeDecode = function (int $reservation) use (&$admission): void {
            GraphRuntime::stage('restore.before', $this->job);
            $admission = $this->admit(0, $reservation, ($this->job['base_snapshot_id'] ?? null) !== null && ! ($this->job['force_recompose'] ?? false));
        };
        $readStarted = hrtime(true);
        $read = ($this->job['no_cache_read'] ?? false) ? ['status' => 'skip', 'reason' => 'rejected_product_set', 'decoded' => false]
            : (($this->job['force_recompose'] ?? false)
            ? $set->readSources($this->job['product_key'], $identity, fn (array $metadata): bool => ($metadata['cache_epoch'] ?? null) === $this->job['epoch'], $beforeDecode) : $set->read($this->job['product_key'], $identity, fn (array $metadata): bool => ($metadata['cache_epoch'] ?? null) === $this->job['epoch'], $beforeDecode));
        if (($read['decoded'] ?? false) && $read['status'] !== 'hit') {
            throw new FreshGraphProcessRequired($read['reason'] ?? 'invalid_product_set', $read['generation'] ?? null, $set->store->directory, 'product_set');
        }
        $readNs = hrtime(true) - $readStarted;
        $base = $read['catalog'] ?? null;
        $search = $read['search'] ?? null;
        if ($base !== null && $search !== null && ($this->job['base_snapshot_id'] ?? null) !== null
            && $base->id() !== $this->job['base_snapshot_id']) {
            // Disk segments can retain the previous session after an unchanged
            // restore. Recreate only the exact base already pinned by the controller.
            $rebound = new CatalogGeneration($identity, $this->job['watch_session'] ?? 'polling', $this->job['base_sequence'],
                $base->contributions, $base->operations, $base->exportState(), $base->measurements);
            if ($rebound->id() === $this->job['base_snapshot_id']) {
                $base = $rebound;
                $search = $search->rebind($base);
            }
            unset($rebound);
        }
        $prior = $read['metadata']['input_files'] ?? [];
        $knownSources = $base === null ? ($read['sources'] ?? []) : $base->contributions;
        $full = $this->job['full_reconcile'] || $base === null || $base->session !== ($this->job['watch_session'] ?? 'polling');
        $scanStarted = hrtime(true);
        $signatures = $full ? SourceManifest::scan($files, $this->context->rootPath, $settings->scope->directories, $settings->exclude) : $prior;
        $fullScanSeconds = $full ? (hrtime(true) - $scanStarted) / 1e9 : 0;
        $changed = [];
        if (! $full) {
            foreach ($this->job['changed_paths'] as $absolute) {
                $path = substr($absolute, strlen($this->context->rootPath) + 1);
                if (! str_starts_with($absolute, $this->context->rootPath.'/') || ! $this->selected($path, $settings)) {
                    continue;
                }
                if (! str_ends_with($path, '.php') && ! in_array($path, ComposerCatalogExtractor::FILES, true)) {
                    $full = true;
                    break;
                }
                if (SourceManifest::safe($this->context->rootPath, $path, in_array($path, ComposerCatalogExtractor::FILES, true)) && is_file($absolute)) {
                    $signatures[$path] = SourceManifest::signature($absolute);
                } else {
                    unset($signatures[$path]);
                }
            }
            if ($full) {
                $signatures = SourceManifest::scan($files, $this->context->rootPath, $settings->scope->directories, $settings->exclude);
            }
        }
        ksort($signatures);
        foreach ($signatures as $path => $signature) {
            if (($prior[$path] ?? null) !== $signature || ! isset($knownSources[$path])) {
                $changed[$path] = $signature;
            }
        }
        $deleted = array_keys(array_diff_key($prior, $signatures));
        $contents = $this->contents();
        $upserts = $this->collect($changed, $contents);
        $sequence = $this->job['through_sequence'];
        $session = $this->job['watch_session'] ?? 'polling';
        $updater = new CatalogUpdater((new GraphResources($this->context))->transitionBudget);
        $mode = 'full_recompose';
        $reason = $base === null ? 'preparation_required' : 'full_content_reconcile';
        if (! $full && $base !== null && $search !== null && ($this->job['force_recompose'] ?? false) !== true) {
            // Include the restored base, decoder/hash/serializer buffers, new foundation,
            // changed facts, dependency/search COW, and parser process margin before composing.
            $factsBytes = array_sum(array_map(static fn (CatalogContribution $source): int => $source->facts->estimatedBytes(), $knownSources));
            $reservation = max(3 * $factsBytes, 3 * ($base->measurements['transition_working_bytes'] ?? 0)) + 3 * 1024 * 1024;
            $admission = $this->admit($factsBytes, $reservation, true);
            if ($base->id() !== $this->job['base_snapshot_id']) {
                throw new RuntimeException('patch_base_mismatch');
            }
            $patch = $updater->patch($base, $upserts, $deleted, $identity, $session, CatalogSequence::next($base->sequence), $sequence);
            $prefix = new CatalogObservedPrefix(fn (): bool => $this->probe(), $sequence);
            $generation = $patch->applyObserved($base, $prefix, $contents)['generation'];
            $search = $search->apply($patch);
            $mode = 'selective_patch';
            $reason = null;
            unset($patch);
        } elseif ($base !== null && $changed === [] && $deleted === []) {
            // A full byte check can rebind a new session without parser/resolver work.
            $generation = new CatalogGeneration($identity, $session, $sequence, $base->contributions, $base->operations,
                $base->exportState(), $base->measurements);
            $search = $search->rebind($generation);
            $mode = 'restore';
            $reason = 'full_content_reconcile';
        } else {
            $sources = $knownSources;
            foreach ($deleted as $path) {
                unset($sources[$path]);
            }
            foreach ($upserts as $source) {
                $sources[$source->facts->path] = $source;
            }
            unset($base, $search, $read['catalog'], $read['search'], $knownSources, $read['sources']);
            $factsBytes = array_sum(array_map(static fn (CatalogContribution $source): int => $source->facts->estimatedBytes(), $sources));
            $admission = $this->admit($factsBytes, 3 * $factsBytes + 3 * 1024 * 1024, false);
            $generation = (new CatalogUpdater((new GraphResources($this->context))->residentBudget))->prepare(array_values($sources), $identity, $session, $sequence, $contents);
            $search = SearchState::fromGeneration($generation, $this->context->settings['graph_runtime']['posting_budget'] ?? SearchState::DEFAULT_POSTING_BUDGET);
            $reason = ($this->job['force_recompose'] ?? false) ? ($this->job['recompose_reason'] ?? 'preparation_required') : $reason;
        }
        unset($base, $upserts, $read['catalog'], $read['search']);
        $scopeState = SourceManifest::scopeState($this->context->rootPath, $settings->scope->directories);
        $current = fn (): bool => (new GraphEpoch($this->context))->unchanged()
            && SourceManifest::identity($this->context->rootPath, AnalysisMode::Basic) === $this->job['analysis_fingerprint']
            && ($full ? SourceManifest::scan($files, $this->context->rootPath, $settings->scope->directories, $settings->exclude) === $signatures : $this->probe());
        $valid = $current();
        if (! $valid) {
            throw new RuntimeException('changed_inputs_during_build');
        }
        $now = gmdate('c');
        $diagnostics = array_map(static fn ($notice): array => [...$notice->toArray(), 'path' => 'config/architectures.php'], $settings->diagnostics);
        $metadata = ['cache_epoch' => $this->job['epoch'], 'input_files' => $signatures, 'input_manifest_digest' => SourceManifest::inputDigest($signatures, $scopeState),
            'scope' => $settings->scope->directories, 'exclude' => $settings->exclude, 'settings_diagnostics' => $diagnostics,
            'last_full_scan_at' => $full ? $now : $this->job['last_full_scan_at']];
        $write = $settings->discovery->cache === null ? ['status' => 'skip', 'reason' => 'disabled']
            : ($mode === 'restore' && $read['status'] === 'hit'
                ? ['status' => 'skip', 'reason' => 'unchanged_product_set', 'generation' => $read['generation']]
                : $set->write($this->job['product_key'], $generation, $search, $metadata, $current));
        $index = $generation->index($contents);
        $index->attachSearchState($search);
        array_push($index->diagnostics, ...$diagnostics);

        return ['index' => $index, 'snapshot' => $generation->id(), 'scope' => $settings->scope->directories,
            'changed' => false, 'metadata' => $metadata,
            'analysis' => ['mode' => 'basic', 'deep_run' => false, 'checks_complete' => $index->checksComplete()],
            'cache' => ['read' => ['status' => $read['status'], 'reason' => $read['reason']], 'write' => $write,
                'dependency' => ['status' => $read['status'] === 'hit' ? 'restored' : 'prepared', 'reason' => $read['reason']],
                'search' => ['status' => $read['status'] === 'hit' ? 'restored' : 'prepared', 'reason' => $read['reason']]],
            'budget_seconds' => $mode === 'restore' ? 2 : ($prior !== [] && (count($changed) + count($deleted) === 1 || count($changed) === 1 && count($deleted) === 1) ? 3 : 20),
            'full_scan_seconds' => $fullScanSeconds, 'execution_mode' => $mode, 'execution_reason' => $reason, 'applied_sequence' => $sequence, 'watch_session' => $this->job['watch_session'],
            'basis' => $full ? 'full_content_pre_post' : 'observed_prefix', 'confirmed_at' => $now,
            'last_full_scan_at' => $metadata['last_full_scan_at'], 'scan_seconds' => (hrtime(true) - $started) / 1e9,
            'build_measurements' => [...$generation->measurements, ...($admission === null ? [] : ['admission' => $admission]), 'set_read_ns' => $readNs, 'restore_measurements' => $read['restore_measurements'] ?? []]];
    }

    private function identity(CatalogSettings $settings): CatalogIdentity
    {
        $session = AnalysisRuntime::session();

        return new CatalogIdentity(['repository' => $this->context->rootPath,
            'configuration' => $this->context->selection->identity(), 'scope' => SemanticVersions::digest([$settings->scope->directories, $settings->exclude]),
            'composer' => SemanticVersions::digest($settings->packages), 'classification' => SemanticVersions::digest($settings->fingerprint),
            'backend' => $session->engine->fingerprint(), 'php' => $session->phpVersion,
            'tool' => $this->job['analysis_fingerprint'], 'product' => 'basic-catalog-set-v1']);
    }

    private function contents(): \Closure
    {
        $root = $this->context->rootPath;

        return static function (string $path) use ($root): ?string {
            if (! SourceManifest::safe($root, $path, in_array($path, ComposerCatalogExtractor::FILES, true)) || ! is_file($root.'/'.$path)) {
                return null;
            }

            return (new Filesystem)->get($root.'/'.$path);
        };
    }

    /** @param array<string, string> $changed
     * @return list<CatalogContribution> */
    private function collect(array $changed, \Closure $contents): array
    {
        $builder = new ProjectGraphBuilder(roles: RoleClassifier::forProject(new Filesystem, $this->context->rootPath), catalog: true,
            basePath: $this->context->rootPath, mode: AnalysisMode::Basic);
        $result = [];
        $pending = $hashes = [];
        $pendingBytes = 0;
        // Match ProjectGraphLoader's bounded outer queue. The builder keeps its
        // encoded-frame splitting, single-source limits and backend/metadata paths.
        /** @param array<string, FileContext> $pending
         * @param array<string, string> $hashes
         * @return list<CatalogContribution> */
        $flush = function (array $pending, array $hashes, int $pendingBytes) use ($builder): array {
            if ($pending === []) {
                return [];
            }
            GraphRuntime::stage('collection.batch', [...$this->job, 'path_count' => count($pending), 'source_bytes' => $pendingBytes]);
            $entries = $builder->collectBasicMany(array_values($pending));
            if (count($entries) !== count($pending)) {
                throw new RuntimeException('Incomplete Basic contribution batch.');
            }
            $contributions = [];
            foreach (array_keys($pending) as $ordinal => $path) {
                $facts = $entries[$ordinal]->catalog;
                if ($facts === null || $facts->path !== $path) {
                    throw new RuntimeException('Basic catalog contribution differs from its pinned source.');
                }
                $contributions[] = new CatalogContribution($facts, $hashes[$path]);
            }

            return $contributions;
        };
        foreach ($changed as $path => $signature) {
            $bytes = $contents($path);
            $hash = $bytes === null ? null : hash('sha256', $bytes);
            if ($bytes === null || $hash === null || ! str_ends_with($signature, ':'.$hash)) {
                throw new RuntimeException('changed_inputs:'.$path);
            }
            if (count($pending) >= MagoAnalysisEngine::BASIC_BATCH_FILES || $pendingBytes + strlen($bytes) > MagoAnalysisEngine::BASIC_BATCH_BYTES) {
                array_push($result, ...$flush($pending, $hashes, $pendingBytes));
                $pending = $hashes = [];
                $pendingBytes = 0;
            }
            GraphRuntime::stage('source.parse', [...$this->job, 'path' => $path]);
            $pending[$path] = new FileContext($path, $bytes);
            $hashes[$path] = $hash;
            $pendingBytes += strlen($bytes);
        }
        array_push($result, ...$flush($pending, $hashes, $pendingBytes));

        return $result;
    }

    /** Reclaim unused parser arenas before applying the unchanged reservation formula.
     * @return array<string, mixed> */
    private function admit(int $factsBytes, int $candidateBytes, bool $transition): array
    {
        $snapshot = static function (): array {
            $process = GraphProcessMemory::tree(getmypid());

            return ['php_used' => memory_get_usage(), 'php_allocated' => memory_get_usage(true),
                'rss_bytes' => $process['rss_bytes'], 'rss_reason' => $process['reason']];
        };
        $before = $snapshot();
        $cycles = gc_collect_cycles();
        $freed = gc_mem_caches();
        $after = $snapshot();
        $details = ['facts_bytes' => $factsBytes, 'measured_rss_bytes' => $after['rss_bytes'],
            'process_bytes_basis' => $after['rss_bytes'] === null ? 'php_allocated_fallback' : 'process_tree_rss', 'rss_reason' => $after['rss_reason'],
            'gc' => ['before' => $before, 'after' => $after, 'collected_cycles' => $cycles, 'freed_allocator_bytes' => $freed]];
        try {
            $report = (new GraphResources($this->context))->admit($candidateBytes, $after['rss_bytes'] ?? $after['php_allocated'], $transition, $details);
        } catch (GraphAdmissionRefused $error) {
            GraphRuntime::stage('resource.admission', [...$this->job, 'admission' => $error->measurements]);
            throw $error;
        }
        GraphRuntime::stage('resource.admission', [...$this->job, 'admission' => $report]);

        return $report;
    }

    private function selected(string $path, CatalogSettings $settings): bool
    {
        foreach ($settings->exclude as $pattern) {
            if (fnmatch($pattern, $path)) {
                return false;
            }
        }
        foreach ([...$settings->scope->directories, ...ComposerCatalogExtractor::FILES] as $root) {
            if ($path === $root || str_starts_with($path, rtrim($root, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private function probe(): bool
    {
        $socket = GraphProtocol::socket($this->directory, $this->job['coordinator_endpoint']);
        try {
            stream_set_timeout($socket, 2);
            GraphProtocol::send($socket, (new GraphRuntime($this->context))->envelope(['action' => 'observe-prefix',
                'job_id' => $this->job['job_id'], 'product_key' => $this->job['product_key'], 'scope' => $this->job['scope'],
                'epoch' => $this->job['epoch'], 'base_snapshot_id' => $this->job['base_snapshot_id'],
                'watch_session' => $this->job['watch_session'], 'through_sequence' => $this->job['through_sequence']], $this->job['coordinator_instance']));

            return (GraphProtocol::receive($socket)['ok'] ?? false) === true;
        } finally {
            fclose($socket);
        }
    }
}
