<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphEpoch;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use RuntimeException;
use Throwable;

/** Client and detached launcher. It never loads or hydrates a graph. */
final class GraphRuntime
{
    private static bool $worker = false;

    private static bool $enabled = false;

    private static ?int $requestStarted = null;

    public static function beginRequest(): void
    {
        self::$requestStarted = hrtime(true);
    }

    private static ?string $pinnedEpoch = null;

    public static function pinEpoch(string $epoch): void
    {
        self::$pinnedEpoch = $epoch;
    }

    public static function epoch(): ?string
    {
        return self::$pinnedEpoch;
    }

    private static ?string $entrypoint = null;

    private static ?\Closure $observer = null;

    /** Internal process instrumentation; never accepted from a CLI/MCP request. */
    public static function instrument(?string $entrypoint, ?\Closure $observer): void
    {
        self::$entrypoint = $entrypoint;
        self::$observer = $observer;
    }

    /** @param array<string, mixed> $job */
    public static function stage(string $stage, array $job): void
    {
        (self::$observer ?? static function (): void {})($stage, $job);
    }

    public static function enable(bool $enabled = true): void
    {
        self::$enabled = $enabled;
        if (! $enabled) {
            self::$requestStarted = null;
        }
    }

    /** @var array<string, mixed>|null */
    private static ?array $resident = null;

    /** @param array<string, mixed>|null $source
     * @return array<string, mixed>|null */
    public static function resident(?array $source = null): ?array
    {
        if ($source !== null) {
            self::$resident = $source;
        }

        return self::$resident;
    }

    public static function inWorker(): bool
    {
        return self::$worker;
    }

    public static function enterWorker(): void
    {
        self::$worker = true;
    }

    public static function context(string $root): ?RepositoryContext
    {
        return self::$worker || ! self::$enabled ? null : RepositoryContext::forRoot($root);
    }

    public function __construct(private readonly RepositoryContext $context) {}

    public function directory(): string
    {
        $identity = $this->context->fingerprint().':'.(new GraphEpoch($this->context))->token;
        $directory = $this->context->storage.'/runtime/'.substr(hash('sha256', $identity), 0, 32);
        new StateStore($directory, $this->context->home);

        return $directory;
    }

    /** @return array<string, mixed> */
    public function map(string $action, ?string $job = null): array
    {
        if (! in_array($action, ['prepare', 'status', 'result'], true) || $action !== 'prepare' && ! is_string($job)) {
            throw new RuntimeException('Use prepare, status JOB_ID or result JOB_ID.');
        }
        $start = self::$requestStarted ?? hrtime(true);
        $response = $this->request(['action' => $action, 'job_id' => $job]);
        if ($action !== 'prepare') {
            return $response;
        }
        $budget = $response['budget_seconds'] ?? 20;
        while (($response['availability'] ?? null) === 'building' && (hrtime(true) - $start) / 1e9 < $budget) {
            usleep(10000);
            $response = $this->request(['action' => 'result', 'job_id' => $response['job_id']]);
        }
        $exceeded = (hrtime(true) - $start) / 1e9 > $budget;
        if ($exceeded && isset($response['job_id'])) {
            $response = $this->request(['action' => 'deadline', 'job_id' => $response['job_id']]);
        }
        $response['timing_scope'] = 'tool_request_including_context; external process/session timing is measured by the harness';
        $response['request_elapsed_seconds'] = (hrtime(true) - $start) / 1e9;
        $response['request_deadline_exceeded'] = $exceeded;

        return $response;
    }

    /** @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $product
     * @return array<string, mixed> */
    public function query(string $operation, array $arguments, ?array $product = null): array
    {
        $start = self::$requestStarted ?? hrtime(true);
        if ($product === null) {
            $response = $this->request(['action' => 'ready-query', 'operation' => $operation, 'arguments' => $arguments]);
            // A returned result has already passed the worker's pre/post checks.
            // Preserve its stale marker if inputs changed during the read.
            if (array_key_exists('result', $response)) {
                return $this->finalizeQuery($response, $start);
            }
        }
        $ready = $product === null ? $this->map('prepare') : $this->prepareProduct($product);
        if (($ready['availability'] ?? '') !== 'ready' || ($ready['freshness'] ?? '') !== 'current') {
            return $this->finalizeQuery($ready, $start);
        }

        return $this->finalizeQuery($this->request(['action' => 'query', 'job_id' => $ready['job_id'], 'operation' => $operation, 'arguments' => $arguments]), $start);
    }

    /** @param array<string, mixed> $response
     * @return array<string, mixed> */
    private function finalizeQuery(array $response, int $start): array
    {
        if (isset($response['job_id']) && (hrtime(true) - $start) / 1e9 > ($response['budget_seconds'] ?? 20)) {
            $this->request(['action' => 'deadline', 'job_id' => $response['job_id']]);
            $response['deadline_exceeded'] = true;
        }

        return $response;
    }

    /** @param array<string, mixed> $product
     * @return array<string, mixed> */
    private function prepareProduct(array $product): array
    {
        $start = self::$requestStarted ?? hrtime(true);
        $response = $this->request(['action' => 'prepare', 'product' => $product]);
        $budget = $response['budget_seconds'] ?? 20;
        while (($response['availability'] ?? '') === 'building' && (hrtime(true) - $start) / 1e9 < $budget) {
            usleep(10000);
            $response = $this->request(['action' => 'result', 'job_id' => $response['job_id']]);
        }
        if ((hrtime(true) - $start) / 1e9 > $budget && isset($response['job_id'])) {
            $response = $this->request(['action' => 'deadline', 'job_id' => $response['job_id']]);
        }

        return $response;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed> */
    public function request(array $payload): array
    {
        $directory = $this->directory();
        $state = new StateStore($directory, $this->context->home);
        $instance = $state->read('coordinator');
        if (! $this->alive($directory, $instance)) {
            $lock = fopen($directory.'/start.lock', 'c');
            if ($lock === false || ! flock($lock, LOCK_EX)) {
                throw new RuntimeException('runtime_start_lock');
            }
            try {
                $instance = $state->read('coordinator');
                if (! $this->alive($directory, $instance)) {
                    // Stale PID records never authorize signalling any process.
                    $token = bin2hex(random_bytes(32));
                    $endpoint = 'c-'.substr($token, 0, 16).'.sock';
                    $this->launch('coordinator', $directory, ['token' => $token, 'endpoint' => $endpoint]);
                    $until = microtime(true) + 5;
                    do {
                        usleep(10000);
                        $instance = $state->read('coordinator');
                        if (($instance['instance_token'] ?? '') === $token && $this->alive($directory, $instance)) {
                            break;
                        }
                    } while (microtime(true) < $until);
                    if (($instance['instance_token'] ?? '') !== $token || ! $this->alive($directory, $instance)) {
                        throw new RuntimeException('runtime_start_failed');
                    }
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        $socket = GraphProtocol::socket($directory, $instance['endpoint']);
        stream_set_timeout($socket, 25);
        try {
            GraphProtocol::send($socket, $this->envelope($payload, $instance['instance_token']), GraphProtocol::REQUEST_BYTES);
            $response = GraphProtocol::receive($socket);
            if (($response['protocol'] ?? null) !== GraphProtocol::VERSION || ($response['repository_id'] ?? null) !== $this->context->id
                || ($response['coordinator_token'] ?? null) !== $instance['instance_token']) {
                throw new RuntimeException('runtime_response_identity');
            }

            return $response;
        } finally {
            fclose($socket);
        }
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed> */
    public function envelope(array $payload, string $token): array
    {
        return [...$payload, 'protocol' => GraphProtocol::VERSION, 'repository_id' => $this->context->id,
            'analysis_fingerprint' => SourceManifest::identity($this->context->rootPath, AnalysisMode::Basic),
            'instance_token' => $token, 'request_id' => bin2hex(random_bytes(16))];
    }

    /** @param array<string, mixed> $instance */
    private function alive(string $directory, array $instance): bool
    {
        if (! is_string($instance['endpoint'] ?? null) || ! is_string($instance['instance_token'] ?? null)) {
            return false;
        }
        try {
            $socket = GraphProtocol::socket($directory, $instance['endpoint']);
            stream_set_timeout($socket, 1);
            GraphProtocol::send($socket, $this->envelope(['action' => 'ping'], $instance['instance_token']));
            $response = GraphProtocol::receive($socket);
            fclose($socket);

            return ($response['protocol'] ?? null) === GraphProtocol::VERSION && ($response['instance_token'] ?? null) === $instance['instance_token']
                && ($response['repository_id'] ?? null) === $this->context->id && ($response['ok'] ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $spec */
    public function launch(string $role, string $directory, array $spec): int
    {
        $spec['configuration_fingerprint'] = $this->context->fingerprint();
        $spec['advisory_only'] = $this->context->advisoryOnly;
        $name = 'launch-'.bin2hex(random_bytes(16));
        (new StateStore($directory, $this->context->home))->update($name, static fn () => $spec);
        $args = [PHP_BINARY, '-d', 'memory_limit='.ini_get('memory_limit'), self::$entrypoint ?? self::defaultEntrypoint(),
            '--project', $this->context->rootPath, '--tool-home', $this->context->home];
        if ($this->context->profile !== null) {
            array_push($args, '--profile', $this->context->profile);
        }
        foreach (['allow-execution' => $this->context->execute, 'allow-write' => $this->context->write, 'runtime-routes' => $this->context->runtimeRoutes] as $flag => $granted) {
            if ($granted) {
                $args[] = '--'.$flag;
            }
        }
        array_push($args, '__graph-runtime', $role, $directory, $name);
        // A short-lived shell re-parents a nohup child. All stdio is redirected;
        // process ownership does not depend on leaking a proc_open handle.
        $command = 'nohup '.implode(' ', array_map(escapeshellarg(...), $args)).' >'.escapeshellarg($directory.'/'.$name.'.stdout')
            .' 2>'.escapeshellarg($directory.'/'.$name.'.stderr').' </dev/null & echo $!';
        exec($command, $output, $exit);
        if ($exit !== 0 || ! ctype_digit($output[0] ?? '')) {
            throw new RuntimeException('runtime_spawn_failed');
        }

        return (int) $output[0];
    }

    private static function defaultEntrypoint(): string
    {
        // Composer's proxy supplies the tool autoloader, including custom bin-dir
        // installations. Never discover an autoloader in the analyzed project.
        $binDirectory = $GLOBALS['_composer_bin_dir'] ?? null;
        if (is_string($binDirectory) && $binDirectory !== '') {
            $proxy = $binDirectory.'/architecture-kit';
            if (is_file($proxy) && is_readable($proxy)) {
                return $proxy;
            }
        }

        return dirname(__DIR__, 2).'/bin/architecture-kit';
    }
}
