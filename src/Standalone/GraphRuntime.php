<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\PackageFingerprint;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeClientRegistration;
use Illuminate\Container\Container;
use RuntimeException;
use Throwable;

/** Client and detached launcher. It never loads or hydrates a graph. */
final class GraphRuntime
{
    private static ?string $implementationStamp = null;

    private static ?string $sourceMismatch = null;

    public static function markSourceMismatch(string $path): void
    {
        self::$sourceMismatch = $path;
    }

    public static function sourceMismatch(): ?string
    {
        return self::$sourceMismatch;
    }

    public static function clearSourceMismatch(): void
    {
        self::$sourceMismatch = null;
    }

    /** Pin once before serving a standalone session. Endpoint lookup never refreshes it. */
    public static function startProcess(): void
    {
        self::$implementationStamp ??= PackageFingerprint::current();
    }

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

    private static string $launchShell = '/bin/sh';

    private static ?\Closure $observer = null;

    /** Internal process instrumentation; never accepted from a CLI/MCP request. */
    public static function instrument(?string $entrypoint, ?\Closure $observer, ?string $launchShell = null): void
    {
        self::$entrypoint = $entrypoint;
        self::$launchShell = $launchShell ?? '/bin/sh';
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

    public static function releaseResident(): void
    {
        self::$resident = null;
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
        if (self::$worker) {
            return null;
        }
        $context = RepositoryContext::forRoot($root);
        if ($context !== null) {
            return self::$enabled || $context->representation() === 'rust' ? $context : null;
        }
        if (self::representation($root) === 'rust') {
            throw new RuntimeException('native_basic_context_required: select an explicit repository and tool home.');
        }

        return null;
    }

    public static function representation(string $root): string
    {
        if (($context = RepositoryContext::forRoot($root)) !== null) {
            return $context->representation();
        }
        $value = Container::getInstance()->bound('config') ? config('architectures.graph_runtime.representation', 'php') : 'php';
        if (! is_string($value) || ! in_array($value, ['php', 'rust'], true)) {
            throw new RuntimeException('graph_runtime.representation must be php or rust.');
        }

        return $value;
    }

    public function __construct(private readonly RepositoryContext $context) {}

    public function directory(): string
    {
        $identity = serialize([$this->context->id, $this->context->profile, $this->context->execute, $this->context->runtimeRoutes, $this->context->advisoryOnly, $this->context->representation()]);
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
        if (! self::inWorker() && $this->context->representation() === 'rust') {
            NativeClientRegistration::register($this->context);
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
            $budget = min($budget, $response['budget_seconds'] ?? $budget);
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
        if (! self::inWorker() && $this->context->representation() === 'rust') {
            NativeClientRegistration::register($this->context);
        }
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
        if (($ready['availability'] ?? '') !== 'ready') {
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
        if (! self::inWorker() && $this->context->representation() === 'rust') {
            NativeClientRegistration::register($this->context);
        }
        $start = self::$requestStarted ?? hrtime(true);
        $response = $this->request(['action' => 'prepare', 'product' => $product]);
        $budget = $response['budget_seconds'] ?? 20;
        while (($response['availability'] ?? '') === 'building' && (hrtime(true) - $start) / 1e9 < $budget) {
            usleep(10000);
            $response = $this->request(['action' => 'result', 'job_id' => $response['job_id']]);
            $budget = min($budget, $response['budget_seconds'] ?? $budget);
        }
        if ((hrtime(true) - $start) / 1e9 > $budget && isset($response['job_id'])) {
            $response = $this->request(['action' => 'deadline', 'job_id' => $response['job_id']]);
        }

        return $response;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed> */
    public function request(array $payload, bool $retryRestart = true): array
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
            if ($retryRestart && ($response['reason'] ?? '') === 'restart_required'
                && ($response['coordinator_restart_required'] ?? false)
                && self::$implementationStamp !== null && ($response['installed_implementation'] ?? null) === self::$implementationStamp) {
                $stop = GraphProtocol::socket($directory, $instance['endpoint']);
                GraphProtocol::send($stop, $this->envelope(['action' => 'shutdown'], $instance['instance_token']));
                GraphProtocol::receive($stop);
                fclose($stop);
                $until = microtime(true) + 2;
                do {
                    usleep(10000);
                    clearstatcache(true, $directory.'/'.$instance['endpoint']);
                } while (file_exists($directory.'/'.$instance['endpoint']) && microtime(true) < $until);

                return $this->request($payload, false);
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
            'routing_identity' => $this->context->routingIdentity(),
            'loaded_implementation' => self::$implementationStamp,
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
            if (($response['reason'] ?? null) === 'runtime_identity:routing_identity') {
                // The locator belongs to the previous configuration/Composer state.
                // Stop that authenticated instance cooperatively before replacing it.
                $stop = GraphProtocol::socket($directory, $instance['endpoint']);
                try {
                    stream_set_timeout($stop, 2);
                    GraphProtocol::send($stop, $this->envelope(['action' => 'shutdown'], $instance['instance_token']));
                    $stopped = GraphProtocol::receive($stop);
                    if (($stopped['ok'] ?? false) !== true || ($stopped['instance_token'] ?? null) !== $instance['instance_token']
                        || ($stopped['repository_id'] ?? null) !== $this->context->id) {
                        throw new RuntimeException('runtime_replacement_shutdown');
                    }
                } finally {
                    fclose($stop);
                }

                return false;
            }

            return ($response['protocol'] ?? null) === GraphProtocol::VERSION && ($response['instance_token'] ?? null) === $instance['instance_token']
                && ($response['repository_id'] ?? null) === $this->context->id && (($response['ok'] ?? false) || ($response['reason'] ?? '') === 'restart_required');
        } catch (Throwable) {
            return false;
        }
    }

    /** Detach before initializing a child, including its observer or parser. */
    public static function detachProcess(): void
    {
        self::assertSessionSupport();
        $pid = getmypid();
        if (posix_getsid(0) === $pid && posix_getpgrp() === $pid) {
            return;
        }
        if (@posix_setsid() !== $pid || posix_getsid(0) !== $pid || posix_getpgrp() !== $pid) {
            throw new RuntimeException('runtime_session_detach_failed: Cannot isolate the graph process session.');
        }
    }

    private static function assertSessionSupport(): void
    {
        foreach (['posix_setsid', 'posix_getsid', 'posix_getpgrp'] as $function) {
            if (! function_exists($function)) {
                throw new RuntimeException('runtime_session_unavailable: Enable PHP POSIX session functions for background maps.');
            }
        }
    }

    /** @param array<string, mixed> $spec */
    public function launch(string $role, string $directory, array $spec): int
    {
        self::assertSessionSupport();
        $spec['routing_identity'] = $this->context->routingIdentity();
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
        // The shell re-parents the child; Runner also gives it its own session.
        // nohup alone would leave it in the exited client's process group.
        // Replace inherited parent handles before exec, including client pipes and locks.
        // PHP maps arbitrary descriptor numbers; the shell only redirects standard I/O.
        $descriptors = is_dir('/dev/fd') ? scandir('/dev/fd') : (is_dir('/proc/self/fd') ? scandir('/proc/self/fd') : []);
        if ($descriptors === false || $descriptors === []) {
            throw new RuntimeException('runtime_descriptor_inventory');
        }
        $descriptorSpec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
        foreach ($descriptors as $descriptor) {
            if (ctype_digit($descriptor) && (int) $descriptor > 2) {
                $descriptorSpec[(int) $descriptor] = ['file', '/dev/null', 'w'];
            }
        }
        $command = 'nohup '.implode(' ', array_map(escapeshellarg(...), $args)).' >'.escapeshellarg($directory.'/'.$name.'.stdout')
            .' 2>'.escapeshellarg($directory.'/'.$name.'.stderr').' </dev/null & echo $!';
        $process = proc_open([self::$launchShell, '-c', $command], $descriptorSpec, $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('runtime_spawn_failed');
        }
        $output = trim(stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        $exit = proc_close($process);
        if ($exit !== 0 || ! ctype_digit($output)) {
            throw new RuntimeException('runtime_spawn_failed');
        }

        return (int) $output;
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
