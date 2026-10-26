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
use GracjanKubicki\ArchitectureKit\Context\GraphSource;
use GracjanKubicki\ArchitectureKit\Discovery\ArchitectureDiscovery;
use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\ArchitecturePath;
use GracjanKubicki\ArchitectureKit\Impact\ImpactPages;
use GracjanKubicki\ArchitectureKit\Reach\ArchitectureReach;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

/** One immutable generation in one fresh process. Never inherits another index. */
final class GraphWorker
{
    /** @var array<string, mixed> */
    private array $source;

    private readonly GraphEpoch $epoch;

    private ?ProjectGraphLoader $productLoader = null;

    private ?GraphCacheSignature $builtProductSignature = null;

    /** @var array{diagnostics_digest: string, diagnostics_by_code: array<string, int>} */
    private array $diagnosticMetadata;

    /** @param array<string, mixed> $job */
    public function __construct(private readonly RepositoryContext $context, private readonly string $directory, private readonly array $job)
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
            if ($this->job['product'] === null) {
                $this->source = (new GraphSource(new Filesystem, $this->context->rootPath, onParse: fn (string $path) => GraphRuntime::stage('source.parse', $this->job), mode: AnalysisMode::Basic))->load($this->job['rejected_generation'] ?? null);
                GraphRuntime::resident($this->source);
            } else {
                $this->prepareFullProduct();
            }
            GraphRuntime::stage('build.after', $this->job);
            $diagnostics = $this->source['index']->diagnostics ?? $this->source['diagnostics'] ?? [];
            $this->diagnosticMetadata = [
                'diagnostics_digest' => hash('sha256', json_encode($diagnostics, JSON_THROW_ON_ERROR)),
                'diagnostics_by_code' => array_count_values(array_column($diagnostics, 'code')),
            ];
            $ready = $this->metadata();
            $server = GraphProtocol::socket($this->directory, $this->job['endpoint'], true);
            $state->update('job-'.$this->job['job_id'], static fn () => $ready);
        } catch (FreshGraphProcessRequired $error) {
            $state->update('job-'.$this->job['job_id'], fn () => [...$this->job, 'files' => [], 'protocol' => GraphProtocol::VERSION,
                'availability' => 'failed', 'freshness' => 'unverified', 'reason' => $error->getMessage(), 'retry_generation' => $error->rejectedGeneration]);

            return;
        } catch (Throwable $error) {
            $state->update('job-'.$this->job['job_id'], fn () => [...$this->job, 'files' => [], 'protocol' => GraphProtocol::VERSION,
                'availability' => 'failed', 'freshness' => 'unverified', 'reason' => $error->getMessage()]);

            return;
        } finally {
            flock($builder, LOCK_UN);
            fclose($builder);
        }
        $last = microtime(true);
        try {
            while (microtime(true) - $last < GraphProtocol::IDLE_SECONDS) {
                $client = @stream_socket_accept($server, 1);
                if ($client === false) {
                    continue;
                }
                $last = microtime(true);
                stream_set_timeout($client, 2);
                try {
                    $line = fgets($client, GraphProtocol::REQUEST_BYTES + 1);
                    $request = GraphProtocol::decode($line ?: '');
                    foreach (['protocol' => GraphProtocol::VERSION, 'job_id' => $this->job['job_id'], 'repository_id' => $this->context->id,
                        'instance_token' => $this->job['instance_token'], 'analysis_fingerprint' => $this->job['analysis_fingerprint'],
                        'input_manifest_digest' => $this->job['input_manifest_digest']] as $key => $value) {
                        if (($request[$key] ?? null) !== $value) {
                            throw new RuntimeException('worker_identity:'.$key);
                        }
                    }
                    if (($request['operation'] ?? '') === 'ping') {
                        GraphProtocol::send($client, $this->metadata('unverified'));

                        continue;
                    }
                    if (($request['operation'] ?? '') === 'retire') {
                        fclose($client);
                        break;
                    }
                    GraphRuntime::stage('query.freshness.before', $this->job);
                    $before = $this->freshness();
                    GraphRuntime::stage('query.freshness.after', $this->job);
                    if (($request['operation'] ?? '') === 'fresh') {
                        GraphProtocol::send($client, $this->metadata($before));

                        continue;
                    }
                    GraphRuntime::stage('query.before', $this->job);
                    $result = $before === 'current' ? $this->dispatch($request['operation'] ?? '', $request['arguments'] ?? []) : null;
                    GraphRuntime::stage('query.after', [...$this->job, 'operation' => $request['operation'] ?? '']);
                    GraphRuntime::stage('query.recheck.before', $this->job);
                    $freshness = $before === 'current' ? $this->freshness() : $before;
                    GraphRuntime::stage('query.recheck.after', $this->job);
                    $response = $this->metadata($freshness);
                    if ($result !== null) {
                        if ($freshness !== 'current') {
                            $result['freshness'] = $freshness;
                            if (isset($result['fresh'])) {
                                $result['fresh'] = false;
                            }
                            if (isset($result['analysis']['checks_complete'])) {
                                $result['analysis']['checks_complete'] = false;
                            }
                        }
                        $response['result'] = $result;
                    }
                    GraphProtocol::send($client, $response);
                } catch (Throwable $error) {
                    try {
                        GraphProtocol::send($client, [...$this->metadata('unverified'), 'ok' => false, 'reason' => $error->getMessage(),
                            'error_code' => $error instanceof ArchitectureContextException ? $error->errorCode : null]);
                    } catch (Throwable) {
                    }
                } finally {
                    if (is_resource($client)) {
                        fclose($client);
                    }
                    $last = microtime(true);
                }
            }
        } finally {
            fclose($server);
            @unlink($this->directory.'/'.$this->job['endpoint']);
        }
    }

    private function prepareFullProduct(): void
    {
        $product = $this->job['product'];
        $files = new Filesystem;
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
            $this->source = ['snapshot' => hash('sha256', $loader->productKey($plan)), 'scope' => $scope->directories,
                'changed' => false, 'diagnostics' => $diagnostics, 'metadata' => ['scope' => $this->job['scope'], 'exclude' => $product['exclude']],
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
        $freshness ??= $this->freshness();

        return ['protocol' => GraphProtocol::VERSION, 'repository_id' => $this->context->id,
            'analysis_fingerprint' => $this->job['analysis_fingerprint'], 'input_manifest_digest' => $this->job['input_manifest_digest'],
            'budget_seconds' => $this->job['budget_seconds'], 'request_id' => $this->job['request_id'], 'job_id' => $this->job['job_id'], 'instance_token' => $this->job['instance_token'],
            'manifest_digest' => hash('sha256', serialize([$this->source['snapshot'], $this->job['product_key'], $this->job['input_manifest_digest']])),
            ...$this->diagnosticMetadata,
            'endpoint' => $this->job['endpoint'], 'pid' => getmypid(), 'snapshot_id' => $this->source['snapshot'],
            'snapshot_ref' => ['job_id' => $this->job['job_id'], 'snapshot_id' => $this->source['snapshot']],
            'availability' => 'ready', 'freshness' => $freshness, 'verified_at' => gmdate('c'),
            'ok' => $freshness === 'current', 'checks_complete' => $this->source['analysis']['checks_complete'], 'analysis' => $this->source['analysis'],
            'cache' => $this->source['cache'], 'deadline_exceeded' => false,
            'measurements' => ['php_used' => memory_get_usage(), 'php_allocated' => memory_get_usage(true), 'php_peak' => memory_get_peak_usage(true)]];
    }

    /** @param array<string, mixed> $args
     * @return array<string, mixed>|null */
    private function dispatch(string $operation, array $args): ?array
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
        if (in_array($operation, ['context', 'impact', 'reach', 'impact-pages'], true)) {
            $product = $this->job['product'];
            $files = new Filesystem;
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
            unset($args['audit_scope']);

            return (new ArchitecturePath(new Filesystem, $this->context->rootPath, scope: $scope))->inspect(...$args);
        }
        if ($operation === 'discovery') {
            return (new ArchitectureDiscovery(new Filesystem, $this->context->rootPath))->search($args['query'] ?? '', $args['kind'] ?? null, $args['limit'] ?? 20);
        }
        if ($operation === 'search') {
            $result = (new GraphSearch($this->source['index']))->find($args['query'] ?? '', $args['kind'] ?? null, $args['path'] ?? null);

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
