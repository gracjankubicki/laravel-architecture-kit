<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\GraphBuildEligibility;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\FindingOccurrenceResolver;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\FreshGraphProcessRequired;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphCacheSignature;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphContributionStore;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphEpoch;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphSegmentStore;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Context\ArchitectureContext;
use GracjanKubicki\ArchitectureKit\Context\ArchitectureContextException;
use GracjanKubicki\ArchitectureKit\Context\GraphPage;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;
use GracjanKubicki\ArchitectureKit\Discovery\ArchitectureDiscovery;
use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\ArchitecturePath;
use GracjanKubicki\ArchitectureKit\Impact\ImpactPages;
use GracjanKubicki\ArchitectureKit\Reach\ArchitectureReach;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeObservedCatalog;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

/** One immutable generation in one fresh process. Never inherits another index. */
final class GraphWorker
{
    /** @var array<string, mixed> */
    private array $source;

    private readonly GraphEpoch $epoch;

    /** @var array<string, array<string, mixed>> */
    private array $nativeGenerations = [];

    private ?NativeObservedCatalog $native = null;

    private ?ProjectGraphLoader $productLoader = null;

    private ?GraphCacheSignature $builtProductSignature = null;

    /** @var array{diagnostics_digest: string, diagnostics_by_code: array<string, int>, diagnostics_digest_basis?: string} */
    private array $diagnosticMetadata;

    /** @var array<string, mixed> */
    private array $processMemory = [];

    private float $lastMemorySample = 0;

    /** @param array<string, mixed> $job */
    public function __construct(private readonly RepositoryContext $context, private readonly string $directory, private array $job)
    {
        GraphRuntime::pinEpoch($this->job['epoch']);
        $this->epoch = new GraphEpoch($context);
    }

    public function run(): void
    {
        GraphRuntime::enterWorker();
        $state = new StateStore($this->directory, $this->context->home);
        $state->update('running-'.$this->job['job_id'], fn () => ['pid' => getmypid(), 'instance_token' => $this->job['instance_token']]);
        $reserve = str_repeat('x', 262144);
        register_shutdown_function(function () use ($state, &$reserve): void {
            $reserve = '';
            $fatal = error_get_last();
            if ($fatal !== null && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $state->update('job-'.$this->job['job_id'], fn () => [...$this->job, 'files' => [], 'protocol' => GraphProtocol::VERSION,
                    'availability' => 'failed', 'freshness' => 'unverified', 'reason' => 'worker_fatal:'.$fatal['message']]);
            }
        });
        $lockPath = $this->context->home.'/graph-builder.lock';
        StateStore::assertBoundary($lockPath, $this->context->home);
        if (is_link($lockPath)) {
            throw new RuntimeException('builder_lock_boundary');
        }
        $builder = fopen($lockPath, 'c');
        if ($builder === false || ! flock($builder, LOCK_EX)) {
            throw new RuntimeException('builder_lock');
        }
        chmod($lockPath, 0600);
        try {
            GraphRuntime::stage('build.before', $this->job);
            if ($this->context->representation() === 'rust' && ($this->job['product'] === null || in_array($this->job['product']['kind'], ['context', 'impact'], true))) {
                $this->native = new NativeObservedCatalog($this->context, $this->directory, $this->job);
                $this->source = $this->native->load();
            } elseif ($this->job['product'] === null) {
                $this->source = (new ObservedCatalog($this->context, $this->directory, $this->job))->load();
                GraphRuntime::resident($this->source);
            } else {
                $this->prepareFullProduct();
            }
            GraphRuntime::stage('build.after', [...$this->job, 'snapshot_id' => $this->source['snapshot'],
                'applied_sequence' => $this->source['applied_sequence'] ?? $this->job['through_sequence'],
                'native_reusable_snapshot' => $this->native !== null && (($this->source['cache']['write']['status'] ?? null) === 'success' || (($this->source['cache']['read']['status'] ?? null) === 'hit' && ($this->source['execution_mode'] ?? null) === 'restore')),
                'execution_mode' => $this->source['execution_mode'] ?? 'full_recompose']);
            $this->diagnosticMetadata = $this->diagnosticsMetadata();
            $ready = $this->metadata();
            $this->rememberNative();
            $server = GraphProtocol::socket($this->directory, $this->job['endpoint'], true);
            $state->update('job-'.$this->job['job_id'], static fn () => $ready);
        } catch (FreshGraphProcessRequired $error) {
            $state->update('job-'.$this->job['job_id'], fn () => [...$this->job, 'files' => [], 'protocol' => GraphProtocol::VERSION,
                'availability' => 'failed', 'freshness' => 'unverified', 'reason' => $error->getMessage(), 'retry_generation' => $error->rejectedGeneration]);

            return;
        } catch (Throwable $error) {
            $state->update('job-'.$this->job['job_id'], fn () => [...$this->job, 'files' => [], 'protocol' => GraphProtocol::VERSION,
                'availability' => 'failed', 'freshness' => 'unverified', 'reason' => $error->getMessage(),
                'transition_refused' => str_contains($error->getMessage(), 'catalog_transition_budget'),
                ...($error instanceof GraphAdmissionRefused ? ['measurements' => ['admission' => $error->measurements]] : [])]);

            return;
        } finally {
            flock($builder, LOCK_UN);
            fclose($builder);
        }
        $last = microtime(true);
        try {
            while (microtime(true) - $last < GraphProtocol::IDLE_SECONDS && $this->coordinatorAlive()) {
                $client = @stream_socket_accept($server, 1);
                if ($client === false) {
                    continue;
                }
                stream_set_timeout($client, 2);
                try {
                    $line = fgets($client, GraphProtocol::REQUEST_BYTES + 1);
                    $request = GraphProtocol::decode($line ?: '');
                    if ($this->native !== null && isset($this->nativeGenerations[$request['job_id'] ?? ''])) {
                        $this->selectNative($request['job_id']);
                    }
                    foreach (['protocol' => GraphProtocol::VERSION, 'job_id' => $this->job['job_id'], 'repository_id' => $this->context->id,
                        'instance_token' => $this->job['instance_token'], 'analysis_fingerprint' => $this->job['analysis_fingerprint'],
                        'input_manifest_digest' => $this->source['metadata']['input_manifest_digest'] ?? $this->job['input_manifest_digest']] as $key => $value) {
                        if (($request[$key] ?? null) !== $value) {
                            throw new RuntimeException('worker_identity:'.$key);
                        }
                    }
                    if (($request['operation'] ?? '') === 'native-update') {
                        $this->updateNative($request['next_job'] ?? [], $state);

                        continue;
                    }
                    if (($request['operation'] ?? '') === 'heartbeat') {
                        if (is_float($request['last_user_activity'] ?? null) || is_int($request['last_user_activity'] ?? null)) {
                            $last = max($last, min(microtime(true), $request['last_user_activity']));
                        }

                        continue;
                    }
                    if (($request['operation'] ?? '') === 'ping') {
                        GraphRuntime::stage('native.ping.before', $this->job);
                        GraphProtocol::send($client, $this->metadata('unverified'));

                        continue;
                    }
                    if (($request['operation'] ?? '') === 'retire') {
                        if ($this->native !== null) {
                            $this->native->retire();
                            unset($this->nativeGenerations[$this->job['job_id']]);
                            if ($this->nativeGenerations !== []) {
                                $this->selectNative(array_key_last($this->nativeGenerations));

                                continue;
                            }
                        }
                        fclose($client);
                        break;
                    }
                    $operation = $request['operation'] ?? '';
                    if (! in_array($operation, ['fresh', 'ping'], true)) {
                        $last = microtime(true);
                    }
                    $confirmation = $request['confirmation'] ?? null;
                    $before = is_array($confirmation) ? ($confirmation['freshness'] ?? 'unverified') : 'unverified';
                    if (! $this->epoch->unchanged()) {
                        $before = 'unverified';
                    }
                    if ($operation === 'fresh') {
                        GraphProtocol::send($client, [...$this->metadata($before), 'confirmation' => $confirmation]);

                        continue;
                    }
                    GraphRuntime::stage('query.before', $this->job);
                    GraphRuntime::clearSourceMismatch();
                    $result = $this->dispatch($operation, $request['arguments'] ?? []);
                    GraphRuntime::stage('query.after', [...$this->job, 'operation' => $operation]);
                    $response = [...$this->metadata($before), 'operation' => $operation, 'confirmation' => $confirmation];
                    if (GraphRuntime::sourceMismatch() !== null) {
                        $response['freshness'] = 'stale';
                        $response['reason'] = 'changed_inputs:'.GraphRuntime::sourceMismatch();
                    }
                    if ($result !== null) {
                        $response['result'] = $result;
                    }
                    GraphProtocol::send($client, $response);
                } catch (Throwable $error) {
                    GraphRuntime::stage('native.worker.rejected', [...$this->job, 'rejection' => $error->getMessage()]);
                    try {
                        GraphProtocol::send($client, [...$this->metadata('unverified'), 'ok' => false, 'freshness' => str_starts_with($error->getMessage(), 'changed_inputs:') ? 'stale' : 'unverified', 'reason' => $error->getMessage(),
                            'error_code' => $error instanceof ArchitectureContextException ? $error->errorCode : null]);
                    } catch (Throwable) {
                    }
                } finally {
                    if (is_resource($client)) {
                        fclose($client);
                    }
                }
            }
        } finally {
            fclose($server);
            @unlink($this->directory.'/'.$this->job['endpoint']);
        }
    }

    /** @return array{diagnostics_digest: string, diagnostics_by_code: array<string, int>, diagnostics_digest_basis?: string} */
    private function diagnosticsMetadata(): array
    {
        $diagnostics = $this->source['index']->diagnostics ?? $this->source['diagnostics'] ?? [];
        $digest = hash('sha256', json_encode($diagnostics, JSON_THROW_ON_ERROR));
        $codes = array_count_values(array_column($diagnostics, 'code'));
        if (isset($this->source['native_summary'])) {
            foreach ($this->source['native_summary']['diagnostics_by_code'] as $code => $count) {
                $codes[$code] = ($codes[$code] ?? 0) + $count;
            }

            return ['diagnostics_digest' => hash('sha256', $this->source['native_summary']['diagnostics_digest'].':'.$digest),
                'diagnostics_by_code' => $codes, 'diagnostics_digest_basis' => 'typed_native_and_php_json_settings_v1'];
        }

        return ['diagnostics_digest' => $digest, 'diagnostics_by_code' => $codes];
    }

    private function rememberNative(): void
    {
        if ($this->native !== null) {
            $this->nativeGenerations[$this->job['job_id']] = ['job' => $this->job, 'source' => $this->source, 'native' => $this->native, 'diagnostics' => $this->diagnosticMetadata];
        }
    }

    private function selectNative(string $id): void
    {
        $entry = $this->nativeGenerations[$id];
        $this->job = $entry['job'];
        $this->source = $entry['source'];
        $this->native = $entry['native'];
        $this->diagnosticMetadata = $entry['diagnostics'];
    }

    /** Small updates keep the Rust owner alive, including with disk cache disabled.
     * @param array<string, mixed> $next */
    private function updateNative(array $next, StateStore $state): void
    {
        foreach (['repository_id', 'analysis_fingerprint', 'coordinator_instance', 'endpoint', 'instance_token', 'epoch', 'product_key'] as $key) {
            if (($next[$key] ?? null) !== $this->job[$key]) {
                throw new RuntimeException('native_update_identity:'.$key);
            }
        }
        $oldId = $this->job['job_id'];
        try {
            $native = new NativeObservedCatalog($this->context, $this->directory, $next, $this->native);
            $source = $native->load();
            $this->native = $native;
            $this->source = $source;
            $this->job = $next;
            $this->diagnosticMetadata = $this->diagnosticsMetadata();
            $this->rememberNative();
            $state->update('job-'.$next['job_id'], fn () => $this->metadata());
        } catch (Throwable $error) {
            $this->selectNative($oldId);
            $state->update('job-'.$next['job_id'], static fn () => [...$next, 'files' => [], 'protocol' => GraphProtocol::VERSION,
                'availability' => 'failed', 'freshness' => 'unverified', 'reason' => $error->getMessage()]);
        }
    }

    /** A dead owner cannot renew or retire its map. Drain the current request before checking again. */
    private function coordinatorAlive(): bool
    {
        $token = $this->job['coordinator_instance'];
        $path = $this->directory.'/controller-'.$token.'.lock';
        if (is_link($path) || ! is_file($path)) {
            return false;
        }
        $lock = @fopen($path, 'r');
        if ($lock === false) {
            return false;
        }
        $released = flock($lock, LOCK_SH | LOCK_NB);
        if ($released) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);

        return ! $released;
    }

    private function prepareFullProduct(): void
    {
        $product = $this->job['product'];
        $files = new Filesystem;
        $scanStarted = hrtime(true);
        $inputFiles = SourceManifest::scan($files, $this->context->rootPath, $this->job['scope'], $product['exclude'], true);
        $scope = AuditScope::package($product['scope']);
        $cache = ($product['cache'] ?? null) === null ? null
            : new ProjectGraphCache($files, $this->context->rootPath, $product['cache']);
        $contributions = $cache === null ? null : new GraphContributionStore(
            new GraphSegmentStore($files, $cache->canonicalDirectory().'/catalog-ready-v1/products/'.$this->job['product_key']),
            $this->job['rejected_generation'] ?? null);
        $loader = new ProjectGraphLoader($files, $this->context->rootPath,
            $scope, $cache, $product['configuration'], impact: $product['kind'] === 'impact', sourceOnly: true, catalog: $product['kind'] === 'context', contributions: $contributions);
        $eligibility = GraphBuildEligibility::begin();
        $complete = false;
        try {
            $plan = $loader->plan($product['exclude']);
            $graph = GraphProducts::prepare($loader, $plan);
            $this->productLoader = $loader;
            $this->builtProductSignature = $plan->signature;
            $diagnostics = [];
            foreach ($graph->catalogFacts as $facts) {
                foreach ($facts->diagnostics as $diagnostic) {
                    $diagnostics[] = [...$diagnostic->toArray(), 'path' => $facts->path];
                }
            }
            foreach ($graph->impactFacts as $facts) {
                foreach ($facts->notices as $notice) {
                    $diagnostics[] = ['code' => 'impact_notice', 'path' => $facts->path, ...$notice];
                }
            }
            if ($inputFiles !== SourceManifest::scan($files, $this->context->rootPath, $this->job['scope'], $product['exclude'], true)
                || ! $this->epoch->unchanged()
                || SourceManifest::identity($this->context->rootPath, AnalysisMode::Basic) !== $this->job['analysis_fingerprint']) {
                throw new RuntimeException('changed_inputs_during_full_product');
            }
            $this->source = ['snapshot' => hash('sha256', $loader->productKey($plan)), 'scope' => $scope->directories,
                'changed' => false, 'diagnostics' => $diagnostics, 'metadata' => ['scope' => $this->job['scope'], 'exclude' => $product['exclude'], 'input_files' => $inputFiles,
                    'input_manifest_digest' => SourceManifest::inputDigest($inputFiles, SourceManifest::scopeState($this->context->rootPath, $this->job['scope']))],
                'basis' => 'full_content_pre_post', 'confirmed_at' => gmdate('c'), 'last_full_scan_at' => gmdate('c'),
                'scan_seconds' => (hrtime(true) - $scanStarted) / 1e9,
                'analysis' => ['mode' => 'basic', 'deep_run' => false, 'checks_complete' => $graph->checksComplete()],
                'cache' => ['read' => $contributions->lastRead ?? ['status' => 'skip', 'reason' => 'disabled'],
                    'write' => $contributions->lastWrite ?? ['status' => 'skip', 'reason' => 'disabled']]];
            $complete = true;
        } finally {
            $eligibility->close($complete);
        }
    }

    private function freshness(): string
    {
        try {
            if (isset($this->job['configuration_fingerprint']) && $this->job['configuration_fingerprint'] !== $this->context->fingerprint()) {
                return 'stale';
            }
            $this->context->fingerprint();
            $metadata = $this->source['metadata'];

            $current = $this->epoch->unchanged() && ! $this->source['changed']
                && ($this->job['product'] !== null || $metadata['input_manifest_digest'] === $this->job['input_manifest_digest'])
                && ($this->productLoader === null || $this->builtProductSignature?->toArray() === $this->productLoader->currentSignature($metadata['exclude'])->toArray())
                && SourceManifest::identity($this->context->rootPath, AnalysisMode::Basic) === $this->job['analysis_fingerprint']
                && SourceManifest::inputDigest(SourceManifest::scan(new Filesystem, $this->context->rootPath, $metadata['scope'], $metadata['exclude'], $this->job['retain_tests'] ?? false),
                    SourceManifest::scopeState($this->context->rootPath, $metadata['scope'])) === $this->job['input_manifest_digest'];

            return $current ? 'current' : 'stale';
        } catch (Throwable) {
            return 'unverified';
        }
    }

    /** @return array<string, mixed> */
    private function metadata(?string $freshness = null): array
    {
        if (microtime(true) - $this->lastMemorySample >= 1) {
            $this->processMemory = GraphProcessMemory::tree(getmypid());
            $this->lastMemorySample = microtime(true);
        }
        if ($this->native !== null && ! $this->native->client->healthy()) {
            $freshness = 'unverified';
        }
        $freshness ??= ($this->source['basis'] ?? null) === 'full_content_pre_post' ? 'current' : ($this->job['product'] === null ? 'last_confirmed' : $this->freshness());

        return ['protocol' => GraphProtocol::VERSION, 'repository_id' => $this->context->id,
            'analysis_fingerprint' => $this->job['analysis_fingerprint'], 'input_manifest_digest' => $this->source['metadata']['input_manifest_digest'] ?? $this->job['input_manifest_digest'],
            'budget_seconds' => $this->source['budget_seconds'] ?? $this->job['budget_seconds'], 'request_id' => $this->job['request_id'], 'job_id' => $this->job['job_id'], 'instance_token' => $this->job['instance_token'],
            'manifest_digest' => hash('sha256', serialize([$this->source['snapshot'], $this->job['product_key'], $this->source['metadata']['input_manifest_digest'] ?? $this->job['input_manifest_digest']])),
            ...$this->diagnosticMetadata,
            'endpoint' => $this->job['endpoint'], 'pid' => getmypid(), 'snapshot_id' => $this->source['snapshot'],
            'snapshot_ref' => ['job_id' => $this->job['job_id'], 'snapshot_id' => $this->source['snapshot']],
            'availability' => $this->native !== null && ! $this->native->client->healthy() ? 'unavailable' : 'ready', 'runtime_semantics' => 2, 'freshness' => $freshness, 'verified_at' => $this->source['confirmed_at'] ?? gmdate('c'),
            'applied_sequence' => $this->source['applied_sequence'] ?? $this->job['through_sequence'],
            'watch_session' => $this->source['watch_session'] ?? $this->job['watch_session'],
            'basis' => $this->source['basis'] ?? 'full_content_pre_post', 'confirmed_at' => $this->source['confirmed_at'] ?? gmdate('c'),
            'last_full_scan_at' => $this->source['last_full_scan_at'] ?? gmdate('c'),
            'native_reusable_snapshot' => $this->native !== null && (($this->source['cache']['write']['status'] ?? null) === 'success' || (($this->source['cache']['read']['status'] ?? null) === 'hit' && ($this->source['execution_mode'] ?? null) === 'restore')),
            'execution_mode' => $this->source['execution_mode'] ?? 'full_recompose', 'execution_reason' => $this->source['execution_reason'] ?? null,
            'restore_reason' => $this->job['restore_reason'] ?? null,
            'scan_seconds' => $this->source['scan_seconds'] ?? 0, 'full_scan_seconds' => $this->source['full_scan_seconds'] ?? 0,
            'ok' => in_array($freshness, ['current', 'last_confirmed'], true), 'checks_complete' => $this->source['analysis']['checks_complete'], 'analysis' => $this->source['analysis'],
            'cache' => $this->source['cache'], 'deadline_exceeded' => false,
            'resource_policy' => [...(new GraphPolicy($this->context->settings['graph_runtime'] ?? []))->describe(),
                ...(new GraphResources($this->context))->effective()],
            'measurements' => [...($this->native?->measurements() ?? []), 'process_tree' => $this->processMemory, 'rss_peak_bytes' => (getrusage()['ru_maxrss'] ?? 0) * (PHP_OS_FAMILY === 'Darwin' ? 1 : 1024), 'memory_limit' => ini_get('memory_limit'), 'php_used' => memory_get_usage(), 'php_allocated' => memory_get_usage(true), 'php_peak' => memory_get_peak_usage(true), 'build' => array_intersect_key($this->source['build_measurements'] ?? [], array_fill_keys(['executed_calls', 'reused_calls', 'transition_working_bytes', 'foundation_ns', 'total_ns', 'generation_digest_ns', 'set_read_ns', 'admission'], true))]];
    }

    /** @param array<string, mixed> $args
     * @return array<string, mixed>|null */
    private function dispatch(string $operation, array $args): ?array
    {
        if ($this->native !== null && in_array($operation, ['impact', 'reach', 'impact-pages'], true)) {
            return $this->native->assessment(fn () => $this->dispatchProduct($operation, $args));
        }

        return $this->dispatchProduct($operation, $args);
    }

    /** @param array<string, mixed> $args
     * @return array<string, mixed>|null */
    private function dispatchProduct(string $operation, array $args): ?array
    {
        if ($operation === 'fresh') {
            return null;
        }
        $kind = $this->job['product']['kind'] ?? 'catalog';
        $allowed = match ($kind) {
            'catalog' => ['discovery', 'search', 'graph', 'path'], 'context' => ['context'], 'impact' => ['impact', 'reach', 'impact-pages'], 'occurrence' => ['occurrence'], default => []
        };
        if (! in_array($operation, $allowed, true)) {
            throw new RuntimeException('runtime_product_operation');
        }
        if ($operation === 'occurrence') {
            return (new FindingOccurrenceResolver(new Filesystem,
                dirname(__DIR__, 2), $this->context->rootPath))->resolve(...$args)->toArray();
        }
        if ($this->native !== null && $operation === 'context') {
            return $this->native->context($args, $this->job['product']);
        }
        if (in_array($operation, ['context', 'impact', 'reach', 'impact-pages'], true)) {
            $product = $this->job['product'];
            $files = new PinnedSourceFilesystem($this->context->rootPath, $this->source['metadata']['input_files']);
            $scope = AuditScope::package($product['audit_scope']);
            $cache = ($product['cache'] ?? null) === null ? null
                : new ProjectGraphCache($files, $this->context->rootPath, $product['cache']);
            if ($operation === 'context') {
                $result = (new ArchitectureContext($files, $this->context->rootPath,
                    scope: $scope, cache: $cache, cacheConfiguration: $product['configuration']))->inspect(...$args);

                return json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            }
            if ($operation === 'impact-pages') {
                return (new ImpactPages($files, $this->context->rootPath))->inspect(...$args);
            }
            if ($operation === 'reach') {
                return (new ArchitectureReach($files, $this->context->rootPath))->inspect(...$args);
            }

            return (new ArchitectureImpact($files, $this->context->rootPath,
                scope: $scope, cache: $cache, cacheConfiguration: $product['configuration']))->inspect(...$args);
        }
        if ($this->native !== null && in_array($operation, ['search', 'graph'], true)) {
            return $this->native->queryGrant(fn () => $this->native->page($operation, $args));
        }
        if ($this->native !== null && $operation === 'discovery') {
            return $this->native->queryGrant(fn () => $this->native->legacy($operation, $args));
        }
        if ($operation === 'path') {
            $requestedScope = $args['audit_scope'] ?? null;
            if (! is_array($requestedScope) || ! array_is_list($requestedScope)) {
                throw new RuntimeException('runtime_path_scope');
            }
            $directories = [];
            foreach ($requestedScope as $directory) {
                if (! is_string($directory) || ! DiscoverySettings::safe($this->context->rootPath, $directory)) {
                    throw new RuntimeException('runtime_path_scope');
                }
                $directories[] = $directory;
            }
            $scope = AuditScope::package($directories);
            if ($scope->directories !== $directories) {
                throw new RuntimeException('runtime_path_scope');
            }
            if ($this->native !== null) {
                return $this->native->queryGrant(fn () => $this->native->legacy('path', $args));
            }
            unset($args['audit_scope']);

            return (new ArchitecturePath(new Filesystem, $this->context->rootPath, scope: $scope))->inspect(...$args);
        }
        if ($operation === 'discovery') {
            return (new ArchitectureDiscovery(new Filesystem, $this->context->rootPath))->search($args['query'] ?? '', $args['kind'] ?? null, $args['limit'] ?? 20);
        }
        if ($operation === 'search') {
            $search = new GraphSearch($this->source['index']);
            $result = $search->find($args['query'] ?? '', $args['kind'] ?? null, $args['path'] ?? null);
            $result['execution'] = $search->execution();

            return GraphPage::make('architecture-search', $args, $this->source['snapshot'], $this->source['scope'], $this->source['index']->diagnostics,
                [...$result, 'analysis' => $this->source['analysis']], 'candidates', 4096);
        }

        $result = (new GraphQuery($this->source['index']))->query($args['subject'] ?? '', $args['mode'] ?? 'context', $args['target'] ?? null, $args['depth'] ?? 4);
        $diagnostics = $this->source['index']->diagnostics;
        if (! $result['traversal_complete']) {
            $diagnostics[] = ['code' => 'traversal_limit', 'message' => 'Traversal reached a depth, node or edge budget. Counts are lower bounds; a missing path is not a no-path result.'];
        }
        $result['analysis'] = [...$this->source['analysis'], ...$result['analysis'],
            'checks_complete' => $this->source['analysis']['checks_complete'] && $result['analysis']['checks_complete']];

        return GraphPage::make('architecture-graph', $args, $this->source['snapshot'], $this->source['scope'], $diagnostics,
            $result, 'records', 4096);

    }
}
