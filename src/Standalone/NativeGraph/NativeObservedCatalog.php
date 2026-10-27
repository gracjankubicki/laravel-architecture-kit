<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsOptions;
use GracjanKubicki\ArchitectureKit\Analysis\StreamingBasicCollector;
use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphEpoch;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogSettings;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Composer\ProjectLayout;
use GracjanKubicki\ArchitectureKit\Context\ArchitectureContext;
use GracjanKubicki\ArchitectureKit\Context\GraphPage;
use GracjanKubicki\ArchitectureKit\Discovery\ArchitectureDiscovery;
use GracjanKubicki\ArchitectureKit\Impact\ArchitecturePath;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimeInstallation;
use GracjanKubicki\ArchitectureKit\Standalone\GraphProducts;
use GracjanKubicki\ArchitectureKit\Standalone\GraphProtocol;
use GracjanKubicki\ArchitectureKit\Standalone\GraphResources;
use GracjanKubicki\ArchitectureKit\Standalone\GraphRuntime;
use GracjanKubicki\ArchitectureKit\Standalone\PinnedSourceFilesystem;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Fresh native worker. PHP keeps only source signatures and one extraction batch. */
final class NativeObservedCatalog
{
    public NativeGraphClient $client;

    public NativeBasic $basic;

    public string $generation;

    private ?string $retainedLease = null;

    /** @var array<string, string> */
    public array $identity;

    /** @var array<string, mixed> */
    private array $source;

    /** @param array<string, mixed> $job */
    public function __construct(private readonly RepositoryContext $context, private readonly string $directory, private readonly array $job, private ?self $previous = null) {}

    /** @return array<string, mixed> */
    public function load(): array
    {
        $started = hrtime(true);
        $files = new Filesystem;
        $settings = CatalogSettings::load($files, $this->context->rootPath);
        $fullProduct = $this->job['product'] !== null;
        $scopePaths = array_values(array_unique($fullProduct ? $this->job['scope'] : [...$settings->scope->directories, ...$this->job['scope']]));
        // Full products retain app/tests witnesses independently of audit exclusions.
        $sourceExclude = $fullProduct ? [] : $settings->exclude;
        $settingsDiagnostics = array_map(static fn ($d) => [...$d->toArray(), 'path' => 'config/architectures.php'], $settings->diagnostics);
        $epoch = new GraphEpoch($this->context);
        $store = new StateStore($this->directory.'/native-'.$this->job['product_key'], $this->context->home);
        $cached = $settings->discovery->cache === null || ($this->job['no_cache_read'] ?? false) ? [] : $store->read('current');
        if ($this->previous !== null) {
            $cached = ['files' => $this->previous->source['metadata']['input_files'], 'identity' => $this->previous->identity,
                'epoch' => $this->job['epoch'], 'fingerprint' => $this->job['analysis_fingerprint'], 'watch_session' => $this->job['watch_session']];
        }
        if (($cached['epoch'] ?? null) !== $epoch->token || ($cached['fingerprint'] ?? null) !== $this->job['analysis_fingerprint']) {
            $cached = [];
        }
        // Watch registration already happened in the coordinator. Full bytes verify restore and new sessions.
        $full = $this->job['full_reconcile'] || $cached === [] || ($cached['watch_session'] ?? null) !== $this->job['watch_session'];
        $signatures = $full ? SourceManifest::scan($files, $this->context->rootPath, $scopePaths, $sourceExclude) : $cached['files'];
        if (! $full) {
            foreach ($this->job['changed_paths'] as $absolute) {
                if (! str_starts_with($absolute, $this->context->rootPath.'/')) {
                    continue;
                }
                $path = substr($absolute, strlen($this->context->rootPath) + 1);
                // Full scan also handles new roots, Blade and scope exclusions without widening selection.
                if (! str_ends_with($path, '.php') && ! in_array($path, ComposerCatalogExtractor::FILES, true)) {
                    $full = true;
                    break;
                }
                if (SourceManifest::safe($this->context->rootPath, $path, in_array($path, ComposerCatalogExtractor::FILES, true)) && is_file($absolute)) {
                    $selected = false;
                    foreach ($scopePaths as $root) {
                        $selected = $selected || str_starts_with($path, $root.'/');
                    }
                    if (($selected || in_array($path, ComposerCatalogExtractor::FILES, true)) && ! Str::is($sourceExclude, $path)) {
                        $signatures[$path] = SourceManifest::signature($absolute);
                    }
                } else {
                    unset($signatures[$path]);
                }
            }
            if ($full) {
                $signatures = SourceManifest::scan($files, $this->context->rootPath, $scopePaths, $sourceExclude);
            }
        }
        ksort($signatures);
        $scanSeconds = (hrtime(true) - $started) / 1e9;
        $scopeState = SourceManifest::scopeState($this->context->rootPath, $scopePaths);
        $digest = SourceManifest::inputDigest($signatures, $scopeState);
        $session = AnalysisRuntime::session();
        $this->identity = ['repository' => $this->context->id, 'profile' => $this->context->profile ?? 'default', 'product' => 'basic-rust-v1:'.$this->job['product_key'],
            'parser' => $session->fingerprint(), 'tool' => 'runtime-0.2.0:'.$this->job['analysis_fingerprint'],
            'configuration' => $this->job['analysis_fingerprint'], 'capture' => $digest];
        $grant = new NativeAdmission($this->context, $this->job['job_id'], ($this->job['base_snapshot_id'] ?? null) !== null, replaces: $this->job['replaces_pid'] ?? null);
        try {
            $binary = (new RuntimeInstallation($this->context->home))->binary();
            $descriptor = $binary->descriptor();
            if (! in_array('basic-graph-v1', $descriptor['capabilities'], true)) {
                throw new RuntimeException('native_basic_capability');
            }
            $this->client = $this->previous !== null ? $this->previous->client : new NativeGraphClient($binary->path, $grant->resident, $grant->transition, 20.0);
            if ($this->previous !== null) {
                $owned = $this->client->request(['op' => 'status'])['owned_capacity_bytes'];
                $this->client->request(['op' => 'set_limits', 'limits' => ['resident_bytes' => min((new GraphResources($this->context))->residentBudget, $owned + $grant->resident), 'transition_bytes' => min((new GraphResources($this->context))->transitionBudget, $owned + $grant->transition)]]);
            }
            $this->basic = new NativeBasic($this->client);
            $read = $this->previous !== null ? ['status' => 'skip', 'reason' => 'resident_base']
                : ($settings->discovery->cache === null ? ['status' => 'skip', 'reason' => 'disabled'] : ['status' => $cached === [] ? 'miss' : 'pending', 'reason' => $cached === [] ? 'native_product_missing' : null]);
            $base = $this->previous?->generation;
            if ($base === null && $cached !== [] && ! ($this->job['force_recompose'] ?? false)) {
                try {
                    StateStore::assertBoundary($cached['directory'], $this->directory);
                    $this->validateCachedIdentity($cached);
                    $base = $this->basic->restore($cached['directory'], $cached['identity'], $this->scope($cached['files'], $session->phpVersion))['generation'];
                    $read = ['status' => 'hit', 'reason' => null];
                } catch (Throwable $e) {
                    // Failed staged restore never publishes a raw or partial Basic product.
                    $read = ['status' => 'error', 'reason' => $e->getMessage()];
                    $base = null;
                }
            }
            $changed = array_filter($signatures, static fn ($sig, $path) => ($cached['files'][$path] ?? null) !== $sig, ARRAY_FILTER_USE_BOTH);
            $deleted = array_diff_key($cached['files'] ?? [], $signatures);
            $scope = $this->scope($signatures, $session->phpVersion);
            $options = new BasicFactsOptions(impact: true, catalog: true, preserveOccurrences: true, roles: RoleClassifier::forProject($files, $this->context->rootPath));
            $check = function () use ($epoch): void {
                if (! $epoch->unchanged() || SourceManifest::identity($this->context->rootPath, AnalysisMode::Basic) !== $this->job['analysis_fingerprint']) {
                    throw new RuntimeException('changed_inputs_during_build');
                }
            };
            if ($base !== null && $changed === [] && $deleted === []) {
                $this->generation = $base;
                $mode = 'restore';
            } else {
                $patch = $base !== null && ! ($this->job['force_recompose'] ?? false);
                $lease = $patch ? $this->client->request(['op' => 'pin', 'generation' => $base, 'identity' => $cached['identity']])['lease'] : null;
                $operation = $patch ? ['op' => 'patch_begin', 'generation' => $base, 'identity' => $cached['identity'], 'lease' => $lease, 'target_identity' => $this->identity, 'scope' => $scope]
                    : ['op' => 'begin', 'identity' => $this->identity, 'options' => $options->toArray(), 'scope' => $scope];
                $ingest = $this->client->request($operation)['ingest'];
                try {
                    $ordinals = array_flip(array_keys($signatures));
                    (new StreamingBasicCollector($session, $options, $this->context->rootPath))->collect(['files' => $patch ? $changed : $signatures], function (array $record) use ($ingest, $patch, $ordinals): void {
                        $record['ordinal'] = $ordinals[$record['owner']];
                        $this->client->request(['op' => $patch ? 'patch_append' : 'append', 'ingest' => $ingest, 'record' => $record]);
                        $this->basic->enrich($ingest, $record);
                        GraphRuntime::stage('native.append', ['owner' => $record['owner'], 'job_id' => $this->job['job_id']]);
                    }, $check);
                    $check();
                    $raw = $this->client->request(['op' => $patch ? 'patch_commit' : 'commit', 'ingest' => $ingest])['generation'];
                    $this->generation = $patch ? $raw : $this->basic->compose($raw, $this->identity);
                } finally {
                    if ($lease !== null) {
                        $this->client->request(['op' => 'release', 'lease' => $lease]);
                    }
                }
                $mode = $patch ? 'selective_patch' : 'full_recompose';
            }
            if ($mode === 'restore') {
                $this->identity = $cached['identity'];
            }
            $check();
            $current = fn (): bool => $this->inputsCurrent($files, $scopePaths, $sourceExclude, $signatures, $scopeState, $full);
            if (! $current()) {
                throw new RuntimeException('changed_inputs_during_build');
            }
            $write = ['status' => 'skip', 'reason' => $settings->discovery->cache === null ? 'disabled' : 'unchanged'];
            if ($settings->discovery->cache !== null && $mode !== 'restore') {
                $snapshotDirectory = $store->directory.'/snapshot-'.$this->generation;
                GraphRuntime::stage('native.cache.before', [...$this->job, 'snapshot_id' => $this->generation]);
                $lease = $this->client->request(['op' => 'pin', 'generation' => $this->generation, 'identity' => $this->identity])['lease'];
                try {
                    $epoch->publish(function () use ($snapshotDirectory, $lease, $store, $signatures, $check, $current, $scopeState): void {
                        new StateStore($snapshotDirectory, $this->context->home);
                        $this->client->request(['op' => 'snapshot', 'generation' => $this->generation, 'identity' => $this->identity, 'lease' => $lease, 'directory' => $snapshotDirectory]);
                        $check();
                        if (! $current()) {
                            throw new RuntimeException('changed_inputs_during_build');
                        }
                        $store->update('current', fn () => ['directory' => $snapshotDirectory, 'identity' => $this->identity, 'files' => $signatures,
                            'epoch' => $this->job['epoch'], 'fingerprint' => $this->job['analysis_fingerprint'], 'watch_session' => $this->job['watch_session'],
                            'scope_state' => $scopeState]);
                    });
                    $write = ['status' => 'success', 'reason' => null];
                } catch (Throwable $error) {
                    $write = ['status' => 'error', 'reason' => $error->getMessage()];
                } finally {
                    $this->client->request(['op' => 'release', 'lease' => $lease]);
                }
            }
            $check();
            if (! $current()) {
                throw new RuntimeException('changed_inputs_during_build');
            }
            $this->retainedLease = $this->client->request(['op' => 'pin', 'generation' => $this->generation, 'identity' => $this->identity])['lease'];
            $summary = $this->client->request(['op' => 'summary', 'generation' => $this->generation, 'identity' => $this->identity, 'lease' => $this->retainedLease]);
            $productDiagnostics = [];
            if ($fullProduct) {
                $product = $this->job['product'];
                $productScope = new NativeProductScope(AuditScope::package($product['scope']), $product['exclude'], $product['kind'] === 'impact', $product['kind'] === 'context');
                $productProjection = (new NativeAuditProjection($this->client))->diagnostics($this->generation, $this->identity, $this->retainedLease, $productScope, $grant->transition);
                $productDiagnostics = $productProjection['diagnostics'];
                $summary['diagnostics_by_code'] = array_count_values(array_column($productDiagnostics, 'code'));
                $summary['diagnostic_total'] = count($productDiagnostics);
                $summary['diagnostics_digest'] = TypedValue::digest($productProjection);
                $summary['checks_complete'] = $productProjection['checks_complete'];
            }
            $now = gmdate('c');
            $this->source = ['snapshot' => $this->generation, 'scope' => $scopePaths, 'changed' => false,
                'diagnostics' => $settingsDiagnostics, 'product_diagnostics' => $productDiagnostics, 'analysis' => ['mode' => 'basic', 'deep_run' => false, 'checks_complete' => $summary['checks_complete'] && $settingsDiagnostics === []],
                'metadata' => ['input_files' => $signatures, 'scope' => $scopePaths, 'exclude' => $sourceExclude, 'input_manifest_digest' => $digest],
                'basis' => $full ? 'full_content_pre_post' : 'observed_prefix', 'confirmed_at' => $now, 'last_full_scan_at' => $full ? $now : $this->job['last_full_scan_at'],
                'scan_seconds' => $scanSeconds, 'full_scan_seconds' => $full ? $scanSeconds : 0,
                'applied_sequence' => $this->job['through_sequence'], 'watch_session' => $this->job['watch_session'],
                'execution_mode' => $mode, 'execution_reason' => $mode === 'full_recompose' && $settings->discovery->cache === null ? 'cache_disabled' : null,
                'budget_seconds' => $mode === 'restore' ? 2 : ($cached !== [] && count($changed) + count($deleted) <= 2 ? 3 : 20),
                'cache' => ['read' => $read, 'write' => $write], 'build_measurements' => ['admission' => $grant->report],
                'representation' => 'rust', 'native_summary' => $summary];

            return $this->source;
        } finally {
            $grant->release();
            $this->previous = null;
        }
    }

    /** @param array<string, mixed> $cached */
    private function validateCachedIdentity(array $cached): void
    {
        foreach (['repository', 'profile', 'product', 'parser', 'tool', 'configuration'] as $field) {
            if (($cached['identity'][$field] ?? null) !== $this->identity[$field]) {
                throw new RuntimeException('native_cache_identity:'.$field);
            }
        }
        if (! is_array($cached['scope_state'] ?? null) || ($cached['identity']['capture'] ?? null) !== SourceManifest::inputDigest($cached['files'], $cached['scope_state'])) {
            throw new RuntimeException('native_cache_source_manifest');
        }
    }

    /** @param array<string, string> $signatures
     * @return list<array<string, string>> */
    private function scope(array $signatures, string $phpVersion): array
    {
        $scope = [];
        foreach ($signatures as $path => $signature) {
            $scope[] = ['owner' => $path, 'sha256' => substr($signature, -64),
                'kind' => in_array($path, ComposerCatalogExtractor::FILES, true) ? 'composer' : (str_ends_with($path, '.blade.php') ? 'blade' : 'php'), 'php_version' => $phpVersion];
        }

        return $scope;
    }

    /** Filesystem and watcher state can change between two calls with the same arguments.
     * @phpstan-impure
     *
     * @param list<string> $scope
     * @param list<string> $exclude
     * @param array<string, string> $signatures
     * @param array<string, array{safe: bool, kind: string}> $scopeState */
    private function inputsCurrent(Filesystem $files, array $scope, array $exclude, array $signatures, array $scopeState, bool $full): bool
    {
        if (SourceManifest::scopeState($this->context->rootPath, $scope) !== $scopeState) {
            return false;
        }

        return $full ? SourceManifest::scan($files, $this->context->rootPath, $scope, $exclude) === $signatures : $this->probe();
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

    /** @param array<string, mixed> $args
     * @param array<string, mixed> $product
     * @return array<string, mixed> */
    public function context(array $args, array $product): array
    {
        $grant = new NativeAdmission($this->context, $this->job['job_id'].'-audit', true);
        try {
            $graph = (new NativeAuditProjection($this->client))->load($this->generation, $this->identity, $this->projectionBudget($grant), existingLease: $this->retainedLease);
            $mapPaths = array_values(array_unique([...$product['audit_scope'], ...ProjectLayout::read($this->context->rootPath)->graphSources()]));
            $testGraph = $graph;
            $graph = (new NativeProductScope(AuditScope::package($mapPaths), $product['exclude']))->project($graph);
            $result = (new ArchitectureContext(new PinnedSourceFilesystem($this->context->rootPath, $this->source['metadata']['input_files']), $this->context->rootPath,
                scope: AuditScope::package($product['audit_scope'])))->inspectNative($graph, $args['subject'], $args['enabled'], $args['limit'] ?? 20, $mapPaths, $this->source['analysis']['checks_complete'], $this->source['product_diagnostics'] ?? [], $testGraph);

            return json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            $grant->release();
        }
    }

    /** Legacy change assessments keep PHP rule execution separate from native restore/query.
     * @template T
     *
     * @param \Closure(): T $run
     * @return T */
    public function assessment(\Closure $run): mixed
    {
        $grant = new NativeAdmission($this->context, $this->job['job_id'].'-assessment', true);
        $start = hrtime(true);
        GraphRuntime::stage('legacy_assessment.before', $this->job);
        try {
            $graph = (new NativeAuditProjection($this->client))->load($this->generation, $this->identity, $this->projectionBudget($grant), true, $this->retainedLease);
            $scoped = (new NativeProductScope(AuditScope::package($this->job['product']['scope']), $this->job['product']['exclude'], true))->project($graph);

            return GraphProducts::withNativeAssessment($scoped, $run, $graph);
        } finally {
            $grant->release();
            GraphRuntime::stage('legacy_assessment.after', [...$this->job, 'wall_ns' => hrtime(true) - $start, 'php_allocated' => memory_get_usage(true)]);
        }
    }

    /** @return array<string, mixed> */
    public function measurements(): array
    {
        return $this->client->healthy() ? ['native' => $this->client->request(['op' => 'status']),
            'parser_phase_measurements' => ['status' => 'NOT_AVAILABLE', 'reason' => 'normal public engine contract does not publish phase counters; private capture provenance is separate']]
            : ['native' => ['status' => 'NOT_AVAILABLE', 'reason' => 'runtime_exited']];
    }

    public function retire(): void
    {
        if ($this->retainedLease !== null && $this->client->healthy()) {
            $this->client->request(['op' => 'release', 'lease' => $this->retainedLease]);
            $this->retainedLease = null;
        }
    }

    /** Native code owns the exact formula, constants and all live leases.
     * @return array<string, mixed> */
    private function preflight(): array
    {
        $plan = $this->client->request(['op' => 'preflight', 'generation' => $this->generation, 'identity' => $this->identity,
            'lease' => $this->retainedLease, 'request_bytes' => GraphProtocol::REQUEST_BYTES + 4096]);
        if (($plan['contract'] ?? null) !== 'native-basic-work-v1' || ($plan['generation'] ?? null) !== $this->generation
            || ! is_int($plan['scratch_bytes'] ?? null) || ! is_int($plan['required_transition_bytes'] ?? null)) {
            throw new RuntimeException('native_work_contract');
        }

        return $plan;
    }

    private function projectionBudget(NativeAdmission $grant): int
    {
        $plan = $this->preflight();
        $budget = $grant->transition - $plan['scratch_bytes'] - 8388608;
        if ($budget <= 8192 * NativeGraphClient::DECODE_MULTIPLIER) {
            throw new RuntimeException('native_audit_projection_budget');
        }
        $limits = $this->client->request(['op' => 'limits'])['limits'];
        $this->client->request(['op' => 'set_limits', 'limits' => ['resident_bytes' => $limits['resident_bytes'], 'transition_bytes' => $plan['required_transition_bytes']]]);

        return $budget;
    }

    /** @template T
     * @param \Closure(): T $run
     * @return T */
    public function queryGrant(\Closure $run): mixed
    {
        $plan = $this->preflight();
        $grant = new NativeAdmission($this->context, $this->job['job_id'].'-query', true, $plan['scratch_bytes'], 67108864);
        try {
            $limits = $this->client->request(['op' => 'limits'])['limits'];
            $this->client->request(['op' => 'set_limits', 'limits' => ['resident_bytes' => $limits['resident_bytes'], 'transition_bytes' => $plan['required_transition_bytes']]]);
            GraphRuntime::stage('native.query.pinned', [...$this->job, 'snapshot_id' => $this->generation, 'lease_pinned' => true]);

            return $run();
        } finally {
            $grant->release();
        }
    }

    /** @param array<string, mixed> $args
     * @return array<string, mixed> */
    public function legacy(string $operation, array $args): array
    {
        $limit = $args['limit'] ?? 20;
        if ($operation === 'discovery') {
            $text = $args['query'] ?? '';
            $kind = $args['kind'] ?? null;
            if (! is_string($text) || ! is_int($limit) || $limit < 0 || $limit > 500 || strlen($text) > 500
                || $kind !== null && (! is_string($kind) || ! in_array($kind, ArchitectureDiscovery::KINDS, true))) {
                return ArchitectureDiscovery::error('E_SEARCH_INPUT', 'Use a literal query up to 500 bytes, supported kind and limit 0..500.');
            }
            $args['query'] = trim($text);
            if ($args['query'] === '' && $kind === null) {
                return ArchitectureDiscovery::error('E_SEARCH_QUERY', 'Provide a name/path fragment or a kind filter.');
            }
            $query = ['type' => 'discovery', 'query' => $args['query'], 'kind' => $args['kind'] ?? null];
            $page = (new NativePages($this->client))->page($this->generation, $this->identity, $query, $args, $this->source['scope'], $this->source['native_summary']['diagnostics_by_code'], existingLease: $this->retainedLease);
            $result = $page['result'];

            return ['v' => 1, 'cmd' => 'search', 'ok' => $page['ok'], 'query' => $args['query'], 'kind' => $args['kind'] ?? null,
                'candidates' => $result['candidates'], 'total' => $result['total'] ?? 0, 'total_is_lower_bound' => $result['total_is_lower_bound'] ?? true,
                'ambiguous' => $result['ambiguous'] ?? false, 'truncated' => $page['truncated'], 'status' => $page['status'],
                'limited' => $result['limited'] ?? true, 'fresh' => ! $this->source['changed'], 'notices' => $page['diagnostics']['details'],
                'notice_total' => $this->source['native_summary']['diagnostic_total'], 'cache' => null,
                'analysis' => [...$this->source['analysis'], 'scope' => $this->source['scope'], 'source_signature' => $this->generation,
                    'limitations' => ['Basic source map; Deep execution analysis not run. Candidate tests are not coverage or PASS.', 'Read relevant source for unresolved relationships. Ask the user when missing information prevents a correct change.']]];
        }
        if (! is_string($args['from'] ?? null) || ! is_string($args['to'] ?? null) || trim($args['from']) === '' || trim($args['to']) === '') {
            return ArchitecturePath::error('E_PATH_ENDPOINT_REQUIRED', 'Provide both from and to as a class, method or PHP file.');
        }
        if (! is_int($limit) || $limit < 0 || $limit > 500 || ! is_int($args['depth'] ?? 8) || ($args['depth'] ?? 8) < 1 || ($args['depth'] ?? 8) > 32) {
            return ArchitecturePath::error('E_PATH_LIMIT_INVALID', 'Use limit 0..500 and depth 1..32.');
        }
        $page = (new NativePages($this->client))->page($this->generation, $this->identity,
            ['type' => 'path', 'subject' => $args['from'] ?? '', 'target' => $args['to'] ?? '', 'depth' => min(20, $args['depth'] ?? 8), 'short_names' => true],
            ['limit' => $limit], $this->source['scope'], $this->source['native_summary']['diagnostics_by_code'], 500, $this->retainedLease);
        $query = $page['result'];
        unset($query['execution']);

        return ['v' => 1, 'cmd' => 'path', 'ok' => ! in_array($query['status'], ['invalid_input', 'not_found', 'ambiguous'], true),
            'analysis' => [...$this->source['analysis'], 'checks_complete' => $this->source['analysis']['checks_complete'] && ($page['analysis']['checks_complete'] ?? false)],
            'diagnostics' => ['items' => $page['diagnostics']['details'], 'total' => $this->source['native_summary']['diagnostic_total'],
                'truncated' => $page['diagnostics']['details_truncated']],
            'source_path' => $query, 'snapshot' => $this->generation, 'scope' => ['paths' => $this->source['scope'], 'audit_paths' => $args['audit_scope'] ?? []]];
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed> */
    public function page(string $operation, array $arguments): array
    {
        $field = $operation === 'search' ? 'candidates' : 'records';
        $invalid = $operation === 'search'
            ? ! is_string($arguments['query'] ?? null) || isset($arguments['kind']) && ! is_string($arguments['kind']) || isset($arguments['path']) && ! is_string($arguments['path'])
            : ! is_string($arguments['subject'] ?? null) || isset($arguments['target']) && ! is_string($arguments['target']) || ! is_int($arguments['depth'] ?? 4)
                || ! in_array($arguments['mode'] ?? 'context', ['context', 'path', 'impact'], true);
        if ($invalid) {
            return GraphPage::make($operation === 'search' ? 'architecture-search' : 'architecture-graph', [], $this->generation,
                $this->source['scope'], [], ['status' => 'invalid_input', $field => []], $field);
        }
        $query = $operation === 'search' ? ['type' => 'search', 'query' => $arguments['query'] ?? '', 'kind' => $arguments['kind'] ?? null, 'path' => $arguments['path'] ?? null]
            : ['type' => $arguments['mode'] ?? 'context', 'subject' => $arguments['subject'] ?? '', 'target' => $arguments['target'] ?? null, 'depth' => $arguments['depth'] ?? 4];
        $page = (new NativePages($this->client))->page($this->generation, $this->identity, $query, $arguments, $this->source['scope'], $this->source['native_summary']['diagnostics_by_code'], existingLease: $this->retainedLease);
        $page['analysis'] = [...$this->source['analysis'], ...($page['analysis'] ?? []), 'checks_complete' => $this->source['analysis']['checks_complete'] && ($page['analysis']['checks_complete'] ?? false)];
        $page['analysis_complete'] = $page['analysis_complete'] && $this->source['diagnostics'] === [];
        foreach ($this->source['diagnostics'] as $diagnostic) {
            $code = $diagnostic['code'];
            $page['diagnostics']['summary'][$code] = ($page['diagnostics']['summary'][$code] ?? 0) + 1;
        }
        $page['diagnostics']['details'] = array_slice([...$this->source['diagnostics'], ...$page['diagnostics']['details']], 0, 20);

        return $page;
    }
}
