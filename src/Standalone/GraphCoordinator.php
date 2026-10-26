<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphEpoch;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphSegmentStore;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogSettings;
use GracjanKubicki\ArchitectureKit\Context\GraphSource;
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

    /** @var array<int, array{stream: mixed, client: int, job: string, input: string}> */
    private array $forwards = [];

    private bool $stopping = false;

    private ?string $building = null;

    /** @var array<string, string> */
    private array $active = [];

    private float $lastActivity;

    private readonly StateStore $state;

    private readonly GraphRuntime $runtime;

    private readonly string $fingerprint;

    public function __construct(private readonly RepositoryContext $context, private readonly string $directory, private readonly string $token, private readonly string $endpoint)
    {
        $this->state = new StateStore($directory, $context->home);
        $this->runtime = new GraphRuntime($context);
        $this->fingerprint = SourceManifest::identity($context->rootPath, AnalysisMode::Basic);
        $this->lastActivity = microtime(true);
    }

    public function run(): void
    {
        $server = GraphProtocol::socket($this->directory, $this->endpoint, true);
        stream_set_blocking($server, false);
        $this->state->update('coordinator', fn () => ['protocol' => GraphProtocol::VERSION, 'repository_id' => $this->context->id,
            'instance_token' => $this->token, 'endpoint' => $this->endpoint, 'pid' => getmypid(), 'analysis_fingerprint' => $this->fingerprint]);
        try {
            while (true) {
                if ($this->stopping && $this->clients === [] && $this->forwards === []) {
                    break;
                }
                $this->collect();
                $this->startNext();
                if ($this->building !== null) {
                    $this->lastActivity = microtime(true);
                }
                if ($this->building === null && $this->clients === [] && $this->forwards === [] && microtime(true) - $this->lastActivity >= GraphProtocol::IDLE_SECONDS) {
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
                            $this->lastActivity = microtime(true);
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
            foreach ($this->jobs as $job) {
                $this->retire($job);
            }
            fclose($server);
            @unlink($this->directory.'/'.$this->endpoint);
        }
    }

    /** @param array<string, mixed> $request */
    private function validate(array $request): void
    {
        if (! is_string($request['request_id'] ?? null) || ! preg_match('/^[a-f0-9]{32}$/D', $request['request_id'])) {
            throw new RuntimeException('runtime_request_id');
        }
        foreach (['protocol' => GraphProtocol::VERSION, 'repository_id' => $this->context->id,
            'analysis_fingerprint' => $this->fingerprint, 'instance_token' => $this->token] as $key => $value) {
            if (($request[$key] ?? null) !== $value) {
                throw new RuntimeException('runtime_identity:'.$key);
            }
        }
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>|null */
    private function handle(int $client, array $request): ?array
    {
        if ($request['action'] === 'shutdown') {
            $this->stopping = true;

            return ['ok' => true];
        }
        if ($request['action'] === 'ping') {
            return ['ok' => true, 'pid' => getmypid()];
        }
        if ($request['action'] === 'ready-query') {
            if (($request['product'] ?? null) !== null) {
                throw new RuntimeException('runtime_product');
            }
            // The catalog key is exact. Full products keep their existing path.
            $productKey = hash('sha256', $this->fingerprint.GraphProtocol::encode(['kind' => 'catalog']));
            $id = $this->active[$productKey] ?? null;
            if ($id !== null && $this->jobs[$id]['availability'] === 'ready') {
                GraphRuntime::stage('query.route', $this->jobs[$id]);
                // Historical ready/current is only a routing hint. The worker
                // checks epoch, identity and source bytes before and after query.
                $this->forward($client, $id, ['operation' => $request['operation'] ?? '', 'arguments' => $request['arguments'] ?? []]);

                return null;
            }

            return ['ok' => false, 'availability' => 'unavailable', 'freshness' => 'unverified', 'reason' => 'no_ready_catalog'];
        }
        if ($request['action'] === 'prepare') {
            $settings = CatalogSettings::load(new Filesystem, $this->context->rootPath);
            $product = $request['product'] ?? null;
            if ($product !== null && (! is_array($product) || ! in_array($product['kind'] ?? '', ['context', 'impact', 'occurrence'], true))) {
                throw new RuntimeException('runtime_product');
            }
            $scope = $product['freshness_scope'] ?? $settings->scope->directories;
            $exclude = $product['exclude'] ?? $settings->exclude;
            $productKey = hash('sha256', $this->fingerprint.GraphProtocol::encode($product ?? ['kind' => 'catalog']));
            $retainTests = $product !== null && ($product['kind'] === 'context' || in_array('tests', $scope, true));
            $files = SourceManifest::scan(new Filesystem, $this->context->rootPath, $scope, $exclude, $retainTests);
            $digest = SourceManifest::inputDigest($files, SourceManifest::scopeState($this->context->rootPath, $scope));
            foreach ($this->jobs as $id => $job) {
                if ($job['product_key'] === $productKey && $job['input_manifest_digest'] === $digest && ! in_array($job['availability'], ['failed', 'unavailable'], true)
                    && ! ($job['availability'] === 'ready' && $job['freshness'] !== 'current')) {
                    if ($job['availability'] === 'ready') {
                        $this->forward($client, $id, ['operation' => 'fresh']);

                        return null;
                    }

                    return $this->public($job);
                }
            }
            $this->releaseTerminalSlots();
            if (count($this->jobs) >= GraphProtocol::JOBS) {
                return ['ok' => false, 'availability' => 'unavailable', 'reason' => 'runtime_job_limit', 'freshness' => 'unverified'];
            }
            $id = bin2hex(random_bytes(16));
            $token = bin2hex(random_bytes(32));
            $previous = isset($this->active[$productKey]) ? $this->jobs[$this->active[$productKey]] : null;
            $previousFiles = $previous['files'] ?? null;
            $unchanged = false;
            if ($previous === null && $product === null) {
                $hint = (new GraphSource(new Filesystem, $this->context->rootPath))->storedMetadata();
                $previousFiles = $hint['input_files'] ?? null;
                $unchanged = ($hint['input_manifest_digest'] ?? null) === $digest;
            } elseif ($previous === null && ($product['cache'] ?? null) !== null) {
                $cache = new ProjectGraphCache(new Filesystem, $this->context->rootPath, $product['cache']);
                $store = new GraphSegmentStore(new Filesystem, $cache->canonicalDirectory().'/catalog-ready-v1/products/'.$productKey);
                foreach (glob($store->directory.'/active-*.json') ?: [] as $pointer) {
                    $hint = $store->metadata(substr(basename($pointer), 7, -5));
                    $previousFiles = $hint['signature']['files'] ?? null;
                    $unchanged = $previousFiles === $files;
                    if ($previousFiles !== null) {
                        break;
                    }
                }
            }
            $budget = $unchanged ? 2 : 20;
            if (is_array($previousFiles) && count(array_filter($previousFiles, 'is_string')) === count($previousFiles)) {
                $changed = array_unique([...array_keys(array_diff_assoc($files, $previousFiles)), ...array_keys(array_diff_assoc($previousFiles, $files))]);
                $added = array_diff_key($files, $previousFiles);
                $deleted = array_diff_key($previousFiles, $files);
                $singleMove = count($changed) === 2 && count($added) === 1 && count($deleted) === 1;
                $budget = $changed === [] ? 2 : (count($changed) === 1 || $singleMove ? 3 : 20);
            }
            $this->jobs[$id] = ['job_id' => $id, 'request_id' => $request['request_id'], 'repository_id' => $this->context->id,
                'analysis_fingerprint' => $this->fingerprint, 'configuration_fingerprint' => $this->context->fingerprint(), 'input_manifest_digest' => $digest, 'files' => $files,
                'product' => $product, 'product_key' => $productKey, 'scope' => $scope, 'exclude' => $exclude,
                'retain_tests' => $retainTests, 'epoch' => (new GraphEpoch($this->context))->token,
                'availability' => 'building', 'freshness' => 'unverified', 'checks_complete' => false,
                'analysis' => ['mode' => 'basic', 'deep_run' => false, 'checks_complete' => false],
                'cache' => ['read' => ['status' => 'pending', 'reason' => 'build_in_progress'], 'write' => ['status' => 'pending', 'reason' => 'build_in_progress']],
                'deadline_exceeded' => false, 'budget_seconds' => $budget, 'started_at' => null,
                'endpoint' => 'w-'.substr($token, 0, 16).'.sock', 'instance_token' => $token, 'attempts' => 0];

            return $this->public($this->jobs[$id]);
        }
        $id = $request['job_id'] ?? null;
        if (! is_string($id) || ! isset($this->jobs[$id])) {
            return ['ok' => false, 'availability' => 'unavailable', 'freshness' => 'unverified', 'reason' => 'unknown_or_expired_job'];
        }
        if ($request['action'] === 'deadline') {
            $this->jobs[$id]['deadline_exceeded'] = true;
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
        if ($request['action'] === 'status' && $response['availability'] === 'ready') {
            $response['last_verified_freshness'] = $response['freshness'];
            $response['freshness'] = 'unverified';
            $response['reason'] = 'status_does_not_reverify_inputs';
            $response['ok'] = false;
        }

        return $response;
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
                $this->building = null;
            }

            return;
        }
        $job = &$this->jobs[$id];
        foreach (['job_id', 'request_id', 'repository_id', 'analysis_fingerprint', 'input_manifest_digest', 'instance_token', 'endpoint'] as $key) {
            if (($ready[$key] ?? null) !== $job[$key]) {
                return;
            }
        }
        if (($ready['protocol'] ?? '') !== GraphProtocol::VERSION) {
            return;
        }
        if (($ready['retry_generation'] ?? null) !== null && $job['attempts'] < 3) {
            $job['rejected_generation'] = $ready['retry_generation'];
            $this->building = null;

            return;
        }
        if (($ready['availability'] ?? null) === 'ready') {
            if (! is_string($ready['snapshot_id'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $ready['snapshot_id'])
                || ($ready['manifest_digest'] ?? null) !== hash('sha256', serialize([$ready['snapshot_id'], $job['product_key'], $job['input_manifest_digest']]))) {
                return;
            }
            try {
                $socket = GraphProtocol::socket($this->directory, $job['endpoint']);
                stream_set_timeout($socket, 1);
                GraphProtocol::send($socket, [...$job, 'files' => [], 'operation' => 'ping', 'protocol' => GraphProtocol::VERSION]);
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
        $job['deadline_exceeded'] = $deadlineExceeded || ($ready['deadline_exceeded'] ?? false);
        if ($job['availability'] === 'ready' && $job['freshness'] === 'current') {
            $old = $this->active[$job['product_key']] ?? null;
            $this->active[$job['product_key']] = $id;
            if ($old !== null && $old !== $id) {
                $this->retireIfDrained($old);
            }
        }
        $this->building = null;
        $this->lastActivity = microtime(true);
    }

    /** @param array<string, mixed> $message */
    private function forward(int $client, string $id, array $message): void
    {
        $job = $this->jobs[$id];
        try {
            $socket = GraphProtocol::socket($this->directory, $job['endpoint']);
            GraphProtocol::send($socket, [...$message, 'protocol' => GraphProtocol::VERSION, 'repository_id' => $job['repository_id'],
                'analysis_fingerprint' => $job['analysis_fingerprint'], 'input_manifest_digest' => $job['input_manifest_digest'],
                'job_id' => $id, 'instance_token' => $job['instance_token']]);
            stream_set_blocking($socket, false);
            $this->forwards[(int) $socket] = ['stream' => $socket, 'client' => $client, 'job' => $id, 'input' => ''];
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
        $this->lastActivity = microtime(true);
        $jobId = $forward['job'];
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
        unset($job['files'], $job['attempts'], $job['rejected_generation'], $job['product'], $job['scope'], $job['exclude']);
        $job['ok'] = $job['availability'] === 'ready' && $job['freshness'] === 'current';
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
        $this->clients[$id]['output'] = GraphProtocol::encode(['checks_complete' => false, 'freshness' => 'unverified', ...$response, 'protocol' => GraphProtocol::VERSION,
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
