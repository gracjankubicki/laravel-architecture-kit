<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphEpoch;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\PackageFingerprint;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogSettings;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogSequence;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

/** Metadata-only reactor. Builders and query workers never run in this heap. */
final class GraphCoordinator
{
    /** @var array<string, array<string, mixed>> */
    private array $jobs = [];

    /** @var array<int, array{stream: mixed, input: string, output: string, deadline: float, authenticated: bool}> */
    private array $clients = [];

    /** @var array<int, array{stream: mixed, client: int, job: string, input: string, arguments: array<string, mixed>}> */
    private array $forwards = [];

    private bool $stopping = false;

    private ?string $building = null;

    /** @var array<string, string> */
    private array $active = [];

    private readonly GraphRetention $retention;

    private readonly StateStore $state;

    private readonly GraphRuntime $runtime;

    private readonly string $fingerprint;

    private readonly ObservationPolicy $observation;

    private readonly GraphResources $resources;

    private readonly CatalogSettings $settings;

    private readonly string $implementationStamp;

    private string $installedImplementation;

    private bool $restartRequired = false;

    private readonly string $routingIdentity;

    private float $lastToolCheck = 0;

    private string $configurationSequence = '';

    private float $lastConfigurationCheck = 0;

    private float $lastPeriodic = 0;

    private float $scanInterval = 30;

    private float $lastRegistry = 0;

    private float $lastRecovery = 0;

    public function __construct(private readonly RepositoryContext $context, private readonly string $directory, private readonly string $token, private readonly string $endpoint)
    {
        $this->state = new StateStore($directory, $context->home);
        $this->runtime = new GraphRuntime($context);
        // Registration precedes all content scans and cache restoration.
        $this->observation = new ObservationPolicy($context);
        $this->resources = new GraphResources($context);
        $this->routingIdentity = $context->routingIdentity();
        $this->settings = CatalogSettings::load(new Filesystem, $context->rootPath);
        $this->observation->extend($this->settings->scope->directories);
        $this->fingerprint = SourceManifest::identity($context->rootPath, AnalysisMode::Basic);
        $this->retention = new GraphRetention(microtime(true));
        $this->implementationStamp = $this->installedImplementation = PackageFingerprint::current();
        $this->lastToolCheck = microtime(true);
    }

    public function run(): void
    {
        $instanceLock = fopen($this->directory.'/controller-'.$this->token.'.lock', 'c');
        if ($instanceLock === false || ! flock($instanceLock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('controller_instance_lock');
        }
        chmod($this->directory.'/controller-'.$this->token.'.lock', 0600);
        $server = GraphProtocol::socket($this->directory, $this->endpoint, true);
        stream_set_blocking($server, false);
        $this->state->update('coordinator', fn () => ['protocol' => GraphProtocol::VERSION, 'repository_id' => $this->context->id,
            'instance_token' => $this->token, 'endpoint' => $this->endpoint, 'pid' => getmypid(), 'analysis_fingerprint' => $this->fingerprint]);
        try {
            while (true) {
                if ($this->stopping && $this->clients === [] && $this->forwards === []) {
                    break;
                }
                if (microtime(true) - $this->lastRecovery >= 2) {
                    $this->resources->recover();
                    $this->lastRecovery = microtime(true);
                }
                $this->observation->drain();
                $this->collect();
                $this->schedule();
                $this->startNext();
                $this->drainRetirements();
                $this->retention->record('code', $this->observation->lastMeaningfulChange);
                if ($this->clients === [] && $this->retention->expired(microtime(true), $this->building !== null, count($this->forwards))) {
                    break;
                }
                $read = [$server];
                $write = [];
                foreach ($this->clients as $client) {
                    if ($client['output'] === '') {
                        $read[] = $client['stream'];
                    } else {
                        $write[] = $client['stream'];
                    }
                }
                foreach ($this->forwards as $forward) {
                    $read[] = $forward['stream'];
                }
                $except = null;
                @stream_select($read, $write, $except, 0, 20000);
                foreach ($write as $stream) {
                    $id = (int) $stream;
                    $sent = @fwrite($stream, $this->clients[$id]['output']);
                    if ($sent === false || $sent === 0) {
                        $this->close($id);

                        continue;
                    }
                    $this->clients[$id]['output'] = substr($this->clients[$id]['output'], $sent);
                    if ($this->clients[$id]['output'] === '') {
                        $this->close($id);
                    }
                }
                foreach ($read as $stream) {
                    if ($stream === $server) {
                        while (($client = @stream_socket_accept($server, 0)) !== false) {
                            if (count($this->clients) >= 128) {
                                fclose($client);

                                continue;
                            }
                            stream_set_blocking($client, false);
                            $this->clients[(int) $client] = ['stream' => $client, 'input' => '', 'output' => '', 'deadline' => microtime(true) + 25, 'authenticated' => false];
                        }

                        continue;
                    }
                    $id = (int) $stream;
                    $chunk = @fread($stream, 65536);
                    if (isset($this->forwards[$id])) {
                        $this->forwardRead($id, $chunk);

                        continue;
                    }
                    if (! isset($this->clients[$id])) {
                        continue;
                    }
                    if ($chunk === false || $chunk === '' && feof($stream)) {
                        $this->close($id);

                        continue;
                    }
                    $this->clients[$id]['input'] .= $chunk;
                    if (strlen($this->clients[$id]['input']) > GraphProtocol::REQUEST_BYTES) {
                        $this->answer($id, ['ok' => false, 'reason' => 'runtime_message_limit']);
                    } elseif (str_contains($this->clients[$id]['input'], "\n")) {
                        try {
                            $request = GraphProtocol::decode($this->clients[$id]['input']);
                            $this->validate($request);
                            $this->clients[$id]['authenticated'] = true;
                            $response = $this->handle($id, $request);
                            if ($response !== null) {
                                $this->answer($id, $response);
                            }
                        } catch (Throwable $error) {
                            $this->answer($id, ['ok' => false, 'availability' => 'unavailable', 'reason' => $error->getMessage()]);
                        }
                        // A forwarded client's EOF must not cancel the independent job.
                        if (isset($this->clients[$id])) {
                            $this->clients[$id]['input'] = '';
                        }
                    }
                }
                foreach ($this->clients as $id => $client) {
                    if (microtime(true) > $client['deadline']) {
                        $this->close($id);
                    }
                }
            }
        } finally {
            $this->resources->forgetController($this->token);
            $this->observation->close();
            foreach ($this->jobs as $job) {
                $this->resources->release($job['job_id']);
                $this->resources->forget($job['instance_token']);
                $this->retire($job);
            }
            fclose($server);
            @unlink($this->directory.'/'.$this->endpoint);
            flock($instanceLock, LOCK_UN);
            fclose($instanceLock);
        }
    }

    /** @param array<string, mixed> $request */
    private function validate(array $request): void
    {
        if (! is_string($request['request_id'] ?? null) || ! preg_match('/^[a-f0-9]{32}$/D', $request['request_id'])) {
            throw new RuntimeException('runtime_request_id');
        }
        foreach (['protocol' => GraphProtocol::VERSION, 'repository_id' => $this->context->id,
            'routing_identity' => $request['action'] === 'shutdown' ? ($request['routing_identity'] ?? null) : $this->routingIdentity, 'instance_token' => $this->token] as $key => $value) {
            if (($request[$key] ?? null) !== $value) {
                throw new RuntimeException('runtime_identity:'.$key);
            }
        }
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>|null */
    private function handle(int $client, array $request): ?array
    {
        if (($request['loaded_implementation'] ?? null) !== $this->implementationStamp) {
            $this->installedImplementation = PackageFingerprint::of(dirname(__DIR__));
            $this->restartRequired = $this->installedImplementation !== $this->implementationStamp;
        }
        if ($request['action'] !== 'shutdown' && ($this->restartRequired || ($request['loaded_implementation'] ?? null) !== $this->implementationStamp)) {
            return ['ok' => false, 'availability' => 'unavailable', 'freshness' => 'unverified', 'reason' => 'restart_required',
                'implementation_stamp' => $this->implementationStamp, 'installed_implementation' => $this->installedImplementation, 'coordinator_restart_required' => $this->restartRequired];
        }
        if ($request['action'] === 'shutdown') {
            $this->stopping = true;

            return ['ok' => true];
        }
        if ($request['action'] === 'ping') {
            return ['ok' => true, 'pid' => getmypid()];
        }
        if ($request['action'] === 'ready-query') {
            $this->retention->record('query', microtime(true));
            if (($request['product'] ?? null) !== null) {
                throw new RuntimeException('runtime_product');
            }
            // The catalog key is exact. Full products keep their existing path.
            $productKey = hash('sha256', $this->fingerprint.GraphProtocol::encode(['kind' => 'catalog']));
            $id = $this->active[$productKey] ?? null;
            if ($id !== null && $this->jobs[$id]['availability'] === 'ready' && (new GraphEpoch($this->context))->token === $this->jobs[$id]['epoch']) {
                GraphRuntime::stage('query.route', $this->jobs[$id]);
                // Historical ready/current is only a routing hint. The worker
                // checks epoch, identity and source bytes before and after query.
                $this->forward($client, $id, ['operation' => $request['operation'] ?? '', 'arguments' => $request['arguments'] ?? []]);

                return null;
            }

            return ['ok' => false, 'availability' => 'unavailable', 'freshness' => 'unverified', 'reason' => 'no_ready_catalog'];
        }
        if ($request['action'] === 'observe-prefix') {
            $this->observation->drain();
            $id = $request['job_id'] ?? '';
            $job = $this->jobs[$id] ?? null;
            $observation = $this->observation->snapshot();
            $valid = $job !== null && $job['product_key'] === ($request['product_key'] ?? null)
                && $job['scope'] === ($request['scope'] ?? null)
                && $job['epoch'] === ($request['epoch'] ?? null)
                && $job['base_snapshot_id'] === ($request['base_snapshot_id'] ?? null)
                && $job['configuration_epoch'] === $observation['configuration_epoch']
                && $observation['watch_session'] === ($request['watch_session'] ?? null)
                && $observation['observed_sequence'] === ($request['through_sequence'] ?? null)
                && $observation['watch_health'] === 'observing' && ! $observation['full_required'];

            return ['ok' => $valid, 'observation' => $observation];
        }
        if ($request['action'] === 'prepare') {
            $product = $request['product'] ?? null;
            if ($product !== null && (! is_array($product) || ! in_array($product['kind'] ?? '', ['context', 'impact', 'occurrence'], true))) {
                throw new RuntimeException('runtime_product');
            }
            $productKey = hash('sha256', $this->fingerprint.GraphProtocol::encode($product ?? ['kind' => 'catalog']));
            $scope = $product['freshness_scope'] ?? $this->settings->scope->directories;
            $this->observation->extend($scope);
            $this->retention->record('query', microtime(true));
            $active = $this->active[$productKey] ?? null;
            if ($active !== null && (new GraphEpoch($this->context))->token !== $this->jobs[$active]['epoch']) {
                unset($this->active[$productKey]);
                $this->retireIfDrained($active);
                $active = null;
            }
            if ($active !== null && $this->jobs[$active]['availability'] === 'ready') {
                $confirmation = $this->confirmation($this->jobs[$active]);
                if ($confirmation['freshness'] !== 'last_confirmed' || $confirmation['watch_health'] === 'polling') {
                    foreach ($this->jobs as $job) {
                        if ($job['product_key'] === $productKey && $job['availability'] === 'building') {
                            return $this->public($job);
                        }
                    }
                    $id = $this->enqueue($product, $scope, $productKey, $request['request_id'], $this->jobs[$active], $confirmation['watch_health'] === 'polling' || $confirmation['freshness'] === 'unverified');

                    return $id === null ? ['availability' => 'unavailable', 'freshness' => 'unverified', 'reason' => 'runtime_job_limit'] : $this->public($this->jobs[$id]);
                }
                $this->forward($client, $active, ['operation' => 'fresh']);

                return null;
            }
            foreach ($this->jobs as $job) {
                if ($job['product_key'] === $productKey && $job['availability'] === 'building') {
                    return $this->public($job);
                }
            }
            $id = $this->enqueue($product, $scope, $productKey, $request['request_id']);

            return $id === null ? ['ok' => false, 'availability' => 'unavailable', 'freshness' => 'unverified', 'reason' => 'runtime_job_limit'] : $this->public($this->jobs[$id]);
        }
        $id = $request['job_id'] ?? null;
        if (! is_string($id) || ! isset($this->jobs[$id])) {
            return ['ok' => false, 'availability' => 'unavailable', 'freshness' => 'unverified', 'reason' => 'unknown_or_expired_job'];
        }
        if ($request['action'] === 'deadline') {
            $this->jobs[$id]['deadline_exceeded'] = true;
        }
        if ($request['action'] === 'query') {
            $this->retention->record('query', microtime(true));
        }
        if (in_array($request['action'], ['result', 'query'], true) && $this->jobs[$id]['availability'] === 'ready') {
            $this->forward($client, $id, ['operation' => $request['action'] === 'result' ? 'fresh' : ($request['operation'] ?? ''),
                'arguments' => $request['arguments'] ?? []]);

            return null;
        }
        if (! in_array($request['action'], ['status', 'result', 'deadline', 'query'], true)) {
            throw new RuntimeException('runtime_action');
        }

        $response = $this->public($this->jobs[$id]);

        return $response;
    }

    /** @param array<string, mixed>|null $product
     * @param list<string> $scope
     * @param array<string, mixed>|null $base */
    private function enqueue(?array $product, array $scope, string $productKey, string $requestId, ?array $base = null, bool $full = false): ?string
    {
        $this->releaseTerminalSlots();
        if (count($this->jobs) >= GraphProtocol::JOBS) {
            return null;
        }
        $id = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));
        $observation = $this->observation->snapshot();
        $this->jobs[$id] = ['job_id' => $id, 'request_id' => $requestId, 'repository_id' => $this->context->id,
            'analysis_fingerprint' => $this->fingerprint, 'input_manifest_digest' => $base['input_manifest_digest'] ?? '',
            'files' => [], 'product' => $product, 'product_key' => $productKey, 'scope' => $scope,
            'exclude' => $product['exclude'] ?? $this->settings->exclude, 'retain_tests' => $product !== null,
            'epoch' => (new GraphEpoch($this->context))->token, 'configuration_epoch' => $observation['configuration_epoch'],
            'watch_session' => $observation['watch_session'], 'through_sequence' => $observation['observed_sequence'],
            'changed_paths' => $this->observation->paths(), 'full_reconcile' => $full || $base === null || $observation['full_required'],
            'base_snapshot_id' => $base['snapshot_id'] ?? null, 'base_sequence' => $base['applied_sequence'] ?? '0',
            'base_job_id' => $base['job_id'] ?? null, 'next_reconcile_at' => $base['next_reconcile_at'] ?? null, 'last_full_scan_at' => $base['last_full_scan_at'] ?? null,
            'restore_reason' => $this->state->read('retired-'.$productKey)['reason'] ?? null,
            'coordinator_endpoint' => $this->endpoint, 'coordinator_instance' => $this->token,
            'availability' => 'building', 'freshness' => 'unverified', 'checks_complete' => false,
            'analysis' => ['mode' => 'basic', 'deep_run' => false, 'checks_complete' => false],
            'cache' => ['read' => ['status' => 'pending', 'reason' => 'build_in_progress'], 'write' => ['status' => 'pending', 'reason' => 'build_in_progress']],
            'deadline_exceeded' => false, 'budget_seconds' => $base === null ? 20 : 3, 'started_at' => null,
            'endpoint' => 'w-'.substr($token, 0, 16).'.sock', 'instance_token' => $token, 'attempts' => 0];
        if (! $this->resources->enqueue($id, $this->context->id, $productKey, $this->resourceOwner())) {
            unset($this->jobs[$id]);

            return null;
        }

        return $id;
    }

    private function schedule(): void
    {
        $now = microtime(true);
        $periodic = $this->lastPeriodic > 0 && $now - $this->lastPeriodic >= $this->scanInterval;
        if ($now - $this->lastToolCheck >= 30 || $this->observation->toolChanged) {
            $this->lastToolCheck = $now;
            $this->installedImplementation = PackageFingerprint::of(dirname(__DIR__));
            $this->restartRequired = $this->installedImplementation !== $this->implementationStamp;
            $this->observation->toolChanged = false;
        }
        $configurationSequence = $this->observation->snapshot()['observed_sequence'];
        if ($configurationSequence !== $this->configurationSequence || $now - $this->lastConfigurationCheck >= 30) {
            $this->lastConfigurationCheck = $now;
            $this->configurationSequence = $configurationSequence;
            if ($this->context->routingIdentity() !== $this->routingIdentity) {
                $this->stopping = true;

                return;
            }
        }
        if ($this->restartRequired) {
            return;
        }
        $observation = $this->observation->snapshot();
        $periodic = array_filter($this->active, fn (string $id): bool => $now >= ($this->jobs[$id]['next_reconcile_at'] ?? INF)) !== [];
        if (! $periodic && ! $this->observation->due($now) && ! $observation['full_required']) {
            return;
        }
        foreach ($this->active as $key => $id) {
            $job = $this->jobs[$id];
            if (array_filter($this->jobs, static fn (array $candidate): bool => $candidate['product_key'] === $key
                && $candidate['availability'] === 'failed' && $candidate['through_sequence'] === $observation['observed_sequence']
                && $candidate['configuration_epoch'] === $observation['configuration_epoch']) !== []) {
                continue;
            }
            if ($job['availability'] !== 'ready' || array_filter($this->jobs, static fn (array $candidate): bool => $candidate['product_key'] === $key && $candidate['availability'] === 'building') !== []) {
                continue;
            }
            $periodic = $now >= ($job['next_reconcile_at'] ?? INF);
            $needs = $periodic || (new GraphEpoch($this->context))->token !== $job['epoch'] || $job['configuration_epoch'] !== $observation['configuration_epoch']
                || $job['watch_session'] !== $observation['watch_session']
                || $job['applied_sequence'] !== $observation['observed_sequence'];
            if ($needs) {
                $this->enqueue($job['product'], $job['scope'], $key, bin2hex(random_bytes(16)), $job, $periodic || $observation['full_required'] || (new GraphEpoch($this->context))->token !== $job['epoch']);
            }
        }
    }

    /** @param array<string, mixed> $job
     * @return array<string, mixed> */
    private function confirmation(array $job): array
    {
        $observation = $this->observation->snapshot();
        $freshness = 'last_confirmed';
        $reason = null;
        if ($this->restartRequired || $this->observation->toolChanged) {
            return ConfirmationState::make($job, $observation, 'unverified', 'restart_required');
        }
        if (in_array($job['availability'], ['failed', 'unavailable'], true)) {
            return ConfirmationState::make($job, $observation, 'unverified', $job['reason'] ?? 'generation_unavailable');
        }
        if (($job['snapshot_id'] ?? null) === null || $job['configuration_epoch'] !== $observation['configuration_epoch']
            || $job['watch_session'] !== $observation['watch_session']
            || $observation['full_required'] && ! ($observation['watch_health'] === 'polling' && ($job['basis'] ?? '') === 'full_content_pre_post')) {
            $freshness = 'unverified';
            $reason = $observation['reason'] ?? 'reconciliation_required';
        } elseif (($job['applied_sequence'] ?? '0') !== $observation['observed_sequence']) {
            $freshness = 'stale';
            $reason = 'observed_changes_pending';
        }
        if ((new GraphEpoch($this->context))->token !== $job['epoch']) {
            $freshness = 'unverified';
            $reason = 'cache_epoch_changed';
        }

        return ConfirmationState::make($job, $observation, $freshness, $reason);
    }

    private function drainRetirements(): void
    {
        if (microtime(true) - $this->lastRegistry < 1) {
            return;
        }
        $this->lastRegistry = microtime(true);
        $memory = GraphProcessMemory::tree(getmypid());
        $this->resources->controller($this->token, ['bytes' => $memory['rss_bytes'] ?? memory_get_usage(true), 'measurement' => $memory, 'owner' => $this->resourceOwner()]);
        foreach ($this->active as $key => $id) {
            $job = $this->jobs[$id];
            $this->heartbeat($job);
            $this->resources->resident($job['instance_token'], ['bytes' => $job['measurements']['process_tree']['rss_bytes'] ?? $job['measurements']['rss_peak_bytes'] ?? $job['measurements']['php_allocated'] ?? 0,
                'last_activity' => $this->retention->lastActivity,
                'repository' => $this->context->id, 'product' => $key, 'owner' => $this->resourceOwner(), 'leases' => count(array_filter($this->forwards, static fn ($f) => $f['job'] === $id))]);
            if (($reason = $this->resources->retirement($job['instance_token'])) !== null) {
                $this->state->update('retired-'.$key, static fn () => ['reason' => $reason, 'snapshot_id' => $job['snapshot_id'], 'at' => gmdate('c')]);
                unset($this->active[$key]);
                $this->jobs[$id]['reason'] = $reason;
                $this->retireIfDrained($id);
            }
        }
    }

    /** Absolute last user/code activity is propagated, never the heartbeat's own time.
     * @param array<string, mixed> $job */
    private function heartbeat(array $job): void
    {
        try {
            $socket = GraphProtocol::socket($this->directory, $job['endpoint']);
            GraphProtocol::send($socket, ['protocol' => GraphProtocol::VERSION, 'repository_id' => $job['repository_id'],
                'analysis_fingerprint' => $job['analysis_fingerprint'], 'input_manifest_digest' => $job['input_manifest_digest'],
                'job_id' => $job['job_id'], 'instance_token' => $job['instance_token'], 'operation' => 'heartbeat',
                'last_user_activity' => $this->retention->lastActivity]);
            fclose($socket);
        } catch (Throwable) {
        }
    }

    /** @return array<string, string> */
    private function resourceOwner(): array
    {
        return ['directory' => $this->directory, 'token' => $this->token];
    }

    private function releaseTerminalSlots(): void
    {
        // Insertion order is job creation order; keep the newest bounded history.
        foreach ($this->jobs as $id => $job) {
            if (count($this->jobs) < GraphProtocol::JOBS) {
                break;
            }
            if (in_array($id, $this->active, true) || $id === $this->building
                || array_filter($this->forwards, static fn ($forward) => $forward['job'] === $id) !== []) {
                continue;
            }
            if ($job['availability'] === 'ready' && in_array($job['freshness'], ['stale', 'unverified'], true)) {
                $this->retire($job);
            } elseif (! in_array($job['availability'], ['failed', 'unavailable'], true)) {
                continue;
            }
            unset($this->jobs[$id]);
        }
    }

    private function startNext(): void
    {
        if ($this->building !== null) {
            return;
        }
        foreach ($this->jobs as $id => &$job) {
            if ($job['availability'] !== 'building') {
                continue;
            }
            if (($job['waiting_for_pressure'] ?? false) && ! $this->resources->pressureDrained()) {
                continue;
            }
            if (! $this->resources->claim($id, $job['instance_token'])) {
                continue;
            }
            if ($job['attempts'] > 0) {
                $job['instance_token'] = bin2hex(random_bytes(32));
                $job['endpoint'] = 'w-'.substr($job['instance_token'], 0, 16).'.sock';
            }
            $this->state->update('running-'.$id, static fn () => []);
            $job['started_at'] = microtime(true);
            $job['attempts']++;
            $this->state->update('job-'.$id, static fn () => []);
            $job['spawn_pid'] = $this->runtime->launch('worker', $this->directory, $job);
            $this->building = $id;
            break;
        }
    }

    private function collect(): void
    {
        if ($this->building === null) {
            return;
        }
        $id = $this->building;
        $ready = $this->state->read('job-'.$id);
        if ($ready === []) {
            $running = $this->state->read('running-'.$id);
            $pid = ($running['instance_token'] ?? null) === $this->jobs[$id]['instance_token'] ? ($running['pid'] ?? null) : ($this->jobs[$id]['spawn_pid'] ?? null);
            if (is_int($pid) && function_exists('posix_kill') && ! posix_kill($pid, 0)) {
                $this->jobs[$id]['availability'] = 'failed';
                $this->jobs[$id]['reason'] = 'worker_exited_before_ready';
                $this->resources->release($id);
                $this->building = null;
            }

            return;
        }
        $job = &$this->jobs[$id];
        foreach (['job_id', 'request_id', 'repository_id', 'analysis_fingerprint', 'instance_token', 'endpoint'] as $key) {
            if (($ready[$key] ?? null) !== $job[$key]) {
                return;
            }
        }
        if (($ready['protocol'] ?? '') !== GraphProtocol::VERSION) {
            return;
        }
        if (array_key_exists('retry_generation', $ready) && $job['attempts'] < 3) {
            $this->resources->release($id);
            $job['no_cache_read'] = ($job['force_recompose'] ?? false);
            $job['force_recompose'] = true;
            $job['recompose_reason'] = 'invalid_product_set';
            $job['full_reconcile'] = true;
            $job['rejected_generation'] = $ready['retry_generation'];
            $this->resources->enqueue($id, $this->context->id, $job['product_key'], $this->resourceOwner());
            $this->building = null;

            return;
        }
        if (in_array($ready['reason'] ?? '', ['preparation_required:observation_not_continuous', 'changed_inputs_during_build', 'changed_inputs_during_full_product', 'patch_base_mismatch'], true) && $job['attempts'] < 3) {
            $this->resources->release($id);
            $observation = $this->observation->snapshot();
            $job['configuration_epoch'] = $observation['configuration_epoch'];
            $job['watch_session'] = $observation['watch_session'];
            $job['through_sequence'] = $observation['observed_sequence'];
            $job['changed_paths'] = $this->observation->paths();
            $job['full_reconcile'] = true;
            $job['force_recompose'] = true;
            $job['recompose_reason'] = 'newer_observed_prefix';
            $this->resources->enqueue($id, $this->context->id, $job['product_key'], $this->resourceOwner());
            $this->building = null;

            return;
        }
        if (($ready['reason'] ?? '') === 'preparation_required:resident_budget' && $job['attempts'] < 3 && $this->resources->relieve()) {
            $this->resources->release($id);
            $job['force_recompose'] = true;
            $job['recompose_reason'] = 'transition_budget';
            $job['full_reconcile'] = true;
            $job['waiting_for_pressure'] = true;
            $this->resources->enqueue($id, $this->context->id, $job['product_key'], $this->resourceOwner());
            $this->building = null;

            return;
        }
        if (($ready['transition_refused'] ?? false) && ! ($job['force_recompose'] ?? false) && $job['attempts'] < 3) {
            $this->resources->release($id);
            $job['force_recompose'] = true;
            $job['recompose_reason'] = 'transition_budget';
            $job['full_reconcile'] = true;
            $this->resources->enqueue($id, $this->context->id, $job['product_key'], $this->resourceOwner());
            $this->building = null;

            return;
        }
        if (($ready['availability'] ?? null) === 'ready') {
            if (! is_string($ready['snapshot_id'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $ready['snapshot_id'])
                || ($ready['manifest_digest'] ?? null) !== hash('sha256', serialize([$ready['snapshot_id'], $job['product_key'], $ready['input_manifest_digest']]))) {
                return;
            }
            try {
                $socket = GraphProtocol::socket($this->directory, $job['endpoint']);
                stream_set_timeout($socket, 1);
                GraphProtocol::send($socket, [...$job, 'input_manifest_digest' => $ready['input_manifest_digest'], 'files' => [], 'operation' => 'ping', 'protocol' => GraphProtocol::VERSION]);
                $handshake = GraphProtocol::receive($socket);
                fclose($socket);
                foreach (['job_id', 'repository_id', 'analysis_fingerprint', 'input_manifest_digest', 'instance_token', 'snapshot_id'] as $key) {
                    if (($handshake[$key] ?? null) !== ($ready[$key] ?? null)) {
                        throw new RuntimeException('generation_ready_identity');
                    }
                }
            } catch (Throwable $error) {
                $ready['availability'] = 'failed';
                $ready['freshness'] = 'unverified';
                $ready['reason'] = $error->getMessage();
            }
        }
        $deadlineExceeded = $job['deadline_exceeded'];
        $job = [...$job, ...$ready];
        $this->observation->drain();
        if ($job['freshness'] === 'current') {
            $this->observation->confirm($job['watch_session'] ?? '', $job['applied_sequence'] ?? '0');
        }
        $job['deadline_exceeded'] = $deadlineExceeded || ($ready['deadline_exceeded'] ?? false);
        if ($job['availability'] === 'ready') {
            $old = $this->active[$job['product_key']] ?? null;
            $this->active[$job['product_key']] = $id;
            GraphRuntime::stage('generation.publish', $job);
            if ($old !== null && $old !== $id) {
                $this->retireIfDrained($old);
            }
            $covered = null;
            $observation = $this->observation->snapshot();
            foreach ($this->active as $activeId) {
                $active = $this->jobs[$activeId];
                $sequence = $active['watch_session'] === $observation['watch_session'] && $active['configuration_epoch'] === $observation['configuration_epoch']
                    ? $active['applied_sequence'] : '0';
                if ($covered === null || CatalogSequence::compare($sequence, $covered) < 0) {
                    $covered = $sequence;
                }
            }
            if ($covered !== null) {
                $this->observation->releaseCovered($covered);
            }
        }
        $this->resources->release($id);
        $this->building = null;
        if (($job['basis'] ?? '') === 'full_content_pre_post') {
            $job['next_reconcile_at'] = microtime(true) + max(30, 20 * ($job['full_scan_seconds'] ?? $job['scan_seconds'] ?? 0))
                + (hexdec(substr($this->context->id, 0, 4)) % 1000) / 1000;
        }
        $this->scanInterval = max(30, 20 * ($job['scan_seconds'] ?? 0));
        $this->lastPeriodic = microtime(true);
    }

    /** @param array<string, mixed> $message */
    private function forward(int $client, string $id, array $message): void
    {
        $job = $this->jobs[$id];
        try {
            if (($retiring = $this->resources->retirement($job['instance_token'])) !== null) {
                $this->state->update('retired-'.$job['product_key'], static fn () => ['reason' => $retiring,
                    'snapshot_id' => $job['snapshot_id'], 'at' => gmdate('c')]);
                foreach ($this->active as $key => $activeId) {
                    if ($activeId === $id) {
                        unset($this->active[$key]);
                    }
                }
                $this->answer($client, ['ok' => false, 'availability' => 'unavailable', 'freshness' => 'unverified', 'reason' => $retiring]);
                $this->retireIfDrained($id);

                return;
            }
            $socket = GraphProtocol::socket($this->directory, $job['endpoint']);
            GraphProtocol::send($socket, [...$message, 'confirmation' => $this->confirmation($job), 'protocol' => GraphProtocol::VERSION, 'repository_id' => $job['repository_id'],
                'analysis_fingerprint' => $job['analysis_fingerprint'], 'input_manifest_digest' => $job['input_manifest_digest'],
                'job_id' => $id, 'instance_token' => $job['instance_token']]);
            stream_set_blocking($socket, false);
            $this->forwards[(int) $socket] = ['stream' => $socket, 'client' => $client, 'job' => $id, 'input' => '', 'arguments' => $message['arguments'] ?? []];
        } catch (Throwable $error) {
            $this->jobs[$id]['availability'] = 'unavailable';
            $this->jobs[$id]['freshness'] = 'unverified';
            $this->jobs[$id]['reason'] = $error->getMessage();
            $this->answer($client, $this->public($this->jobs[$id]));
        }
    }

    private function forwardRead(int $id, string|false $chunk): void
    {
        $forward = &$this->forwards[$id];
        $forward['input'] .= $chunk ?: '';
        if (strlen($forward['input']) > GraphProtocol::RESPONSE_BYTES || ($chunk === '' || $chunk === false) && feof($forward['stream'])) {
            $response = ['ok' => false, 'availability' => 'unavailable', 'freshness' => 'unverified', 'reason' => 'worker_disconnected'];
        } elseif (str_contains($forward['input'], "\n")) {
            try {
                $response = GraphProtocol::decode($forward['input'], GraphProtocol::RESPONSE_BYTES);
                $job = $this->jobs[$forward['job']];
                foreach (['protocol' => GraphProtocol::VERSION, 'job_id' => $job['job_id'], 'instance_token' => $job['instance_token'],
                    'repository_id' => $job['repository_id'], 'analysis_fingerprint' => $job['analysis_fingerprint'], 'input_manifest_digest' => $job['input_manifest_digest']] as $key => $value) {
                    if (($response[$key] ?? null) !== $value) {
                        throw new RuntimeException('worker_response_identity');
                    }
                }
                $response['deadline_exceeded'] = $job['deadline_exceeded'];
            } catch (Throwable $error) {
                $response = ['ok' => false, 'availability' => 'failed', 'reason' => $error->getMessage()];
            }
        } else {
            return;
        }
        $jobId = $forward['job'];
        $this->observation->drain();
        if (isset($response['measurements'])) {
            $this->jobs[$jobId]['measurements'] = $response['measurements'];
        }
        $confirmation = $this->confirmation($this->jobs[$jobId]);
        if (in_array($response['freshness'] ?? '', ['stale', 'unverified'], true)) {
            $confirmation['freshness'] = $response['freshness'];
            $confirmation['reason'] = $response['reason'] ?? 'source_reader_mismatch';
        } elseif (($response['ok'] ?? false) !== true) {
            $confirmation['freshness'] = 'unverified';
            $confirmation['reason'] = $response['reason'] ?? 'worker_result_unavailable';
        }
        if ($confirmation['freshness'] === 'stale') {
            $confirmation['pending_changes'] = true;
        }
        $response['confirmation'] = $confirmation;
        $response['freshness'] = $confirmation['freshness'];
        if ($confirmation['reason'] !== null) {
            $response['reason'] = $confirmation['reason'];
        }
        if (isset($response['result'])) {
            $runtime = [...array_diff_key($response, ['result' => true]), 'coordinator_token' => $this->token];
            $response['result'] = RuntimeResult::adapt($response['operation'] ?? '', $response['result'], $runtime, $forward['arguments']);
        }
        if (str_starts_with($response['reason'] ?? '', 'changed_inputs:')) {
            $this->observation->invalidate('source_reader_mismatch');
        }
        if (in_array($response['freshness'] ?? '', ['stale', 'unverified'], true)) {
            $this->jobs[$jobId]['freshness'] = $response['freshness'];
        }
        $this->answer($forward['client'], $response);
        fclose($forward['stream']);
        unset($this->forwards[$id]);
        $this->retireIfDrained($jobId);
    }

    private function retireIfDrained(string $id): void
    {
        if (in_array($id, $this->active, true) || array_filter($this->forwards, static fn ($f) => $f['job'] === $id) !== []) {
            return;
        }
        $this->retire($this->jobs[$id]);
        // A retired endpoint cannot satisfy prepare when source bytes later return to this digest.
        $this->jobs[$id]['availability'] = 'unavailable';
        $this->jobs[$id]['freshness'] = 'unverified';
        $this->jobs[$id]['reason'] = 'generation_retired';
    }

    /** @param array<string, mixed> $job */
    private function retire(array $job): void
    {
        $this->resources->forget($job['instance_token']);
        // Cooperative retirement authenticates the endpoint, never kills by stale PID.
        try {
            $socket = GraphProtocol::socket($this->directory, $job['endpoint']);
            GraphProtocol::send($socket, [...$job, 'files' => [], 'operation' => 'retire', 'protocol' => GraphProtocol::VERSION]);
            fclose($socket);
        } catch (Throwable) {
        }
    }

    /** @param array<string, mixed> $job
     * @return array<string, mixed> */
    private function public(array $job): array
    {
        $job['confirmation'] = $this->confirmation($job);
        $job['freshness'] = $job['confirmation']['freshness'];
        $job['runtime_semantics'] = 2;
        unset($job['files'], $job['attempts'], $job['rejected_generation'], $job['product'], $job['scope'], $job['exclude'],
            $job['changed_paths'], $job['coordinator_instance'], $job['coordinator_endpoint'], $job['spawn_pid']);
        $job['ok'] = $job['availability'] === 'ready' && in_array($job['freshness'], ['current', 'last_confirmed'], true);
        $job['next'] = ['tool' => 'architecture-map', 'arguments' => ['action' => 'result', 'job_id' => $job['job_id']],
            'guidance' => 'While building, continue with ordinary source search and inspection.'];

        return $job;
    }

    /** @param array<string, mixed> $response */
    private function answer(int $id, array $response): void
    {
        if (! isset($this->clients[$id])) {
            return;
        }
        $this->clients[$id]['output'] = GraphProtocol::encode(['runtime_semantics' => 2, 'checks_complete' => false, 'freshness' => 'unverified', ...$response, 'protocol' => GraphProtocol::VERSION,
            'repository_id' => $this->context->id,
            'coordinator_token' => $this->clients[$id]['authenticated'] ? $this->token : null,
            'instance_token' => $this->clients[$id]['authenticated'] ? ($response['instance_token'] ?? $this->token) : null]);
    }

    private function close(int $id): void
    {
        if (isset($this->clients[$id])) {
            fclose($this->clients[$id]['stream']);
            unset($this->clients[$id]);
        }
    }
}
