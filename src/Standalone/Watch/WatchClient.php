<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Watch;

use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimeBinary;
use RuntimeException;
use Throwable;

/** One privately owned helper per coordinator. The coordinator retains durable work on disconnect. */
final class WatchClient
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $buffer = '';

    private string $stderr = '';

    public ?WatchSession $session = null;

    /** @var list<WatchEvent> */
    private array $bootstrapEvents = [];

    /** @param array<string, string> $environment */
    public function __construct(private readonly RuntimeBinary $binary, private readonly WatchBoundary $boundary, private readonly array $environment = []) {}

    public function start(): WatchSession
    {
        if (is_resource($this->process)) {
            throw new RuntimeException('Watch helper is already running.');
        }
        try {
            $this->binary->descriptor();
            // All metadata, hash and --version checks precede a root-free handshake.
            $this->binary->verify();
            $pipes = [];
            $process = proc_open([$this->binary->path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', $this->environment === [] ? null : [...(getenv() ?: []), ...$this->environment], ['bypass_shell' => true]);
            if (! is_resource($process)) {
                throw new RuntimeException('native_watch_unavailable: cannot start helper.');
            }
            $this->process = $process;
            $this->pipes = $pipes;
            foreach ($pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }
            $this->buffer = '';
            $this->stderr = '';
            $this->bootstrapEvents = [];
            $this->session = new WatchSession(WatchProtocol::frame($this->readFrame(2)), $this->binary->platform, $this->boundary, ($this->environment['ARCHITECTURE_KIT_WATCH_TEST_MODE'] ?? '') === '1');
            $this->send('watch', array_map(base64_encode(...), $this->boundary->roots));
            $deadline = microtime(true) + 3;
            $bootstrapBytes = $bootstrapPaths = 0;
            while (! $this->session->ready) {
                $frame = $this->readFrame(max(0.001, $deadline - microtime(true)));
                $event = $this->session->accept($frame);
                $bootstrapBytes += strlen($frame);
                $bootstrapPaths += count($event->paths);
                if (count($this->bootstrapEvents) < 64 && $bootstrapBytes <= 1048576 && $bootstrapPaths <= 64) {
                    $this->bootstrapEvents[] = $event;
                } else {
                    // Already-required full scan covers this bounded bootstrap buffer.
                    $this->session->lose('bootstrap_queue_overflow');
                }
                if ($event->kind === 'loss' || microtime(true) >= $deadline) {
                    throw new RuntimeException($event->reason ?? 'watch_ready_timeout');
                }
            }

            return $this->session;
        } catch (Throwable $error) {
            $this->session?->lose($error->getMessage());
            $this->close();
            throw $error;
        }
    }

    /** Events observed while watch registration was finishing, before the caller starts its scan.
     * @return list<WatchEvent>
     */
    public function bootstrapEvents(): array
    {
        $events = $this->bootstrapEvents;
        $this->bootstrapEvents = [];

        return $events;
    }

    public function next(float $timeout = 0): ?WatchEvent
    {
        if ($this->session === null || ! is_resource($this->process)) {
            throw new RuntimeException('native_watch_unavailable: no active helper.');
        }
        try {
            $frame = $this->readFrame($timeout, true);

            return $frame === '' ? null : $this->session->accept($frame);
        } catch (Throwable $error) {
            if (str_starts_with($error->getMessage(), 'helper_exited:') || str_starts_with($error->getMessage(), 'transport_error:')) {
                $this->session->end($error->getMessage());
            } else {
                $this->session->lose($error->getMessage());
            }
            throw $error;
        }
    }

    /** A barrier orders IPC commands only. It never flushes filesystem notifications. */
    public function barrier(): void
    {
        $this->send('barrier');
    }

    /** Explicitly labelled synthetic OS loss; never substitutes for real queue overflow tests. */
    public function syntheticLoss(): void
    {
        if (($this->environment['ARCHITECTURE_KIT_WATCH_TEST_MODE'] ?? '') !== '1') {
            throw new RuntimeException('Synthetic loss requires explicit test mode.');
        }
        $this->send('simulate-loss');
    }

    /** @param list<string>|null $paths */
    private function send(string $kind, ?array $paths = null): void
    {
        if ($this->session === null || ! isset($this->pipes[0])) {
            throw new RuntimeException('native_watch_unavailable: no authenticated helper.');
        }
        $value = ['protocol' => WatchProtocol::PROTOCOL, 'session' => $this->session->id, 'request_id' => bin2hex(random_bytes(16)), 'kind' => $kind];
        if ($paths !== null) {
            $value['paths_encoding'] = 'base64-os-bytes';
            $value['paths'] = $paths;
        }
        $body = json_encode($value, JSON_THROW_ON_ERROR)."\n";
        if (strlen($body) > WatchProtocol::FRAME_BYTES) {
            throw new RuntimeException('protocol_error: WatchRoots frame_limit.');
        }
        $offset = 0;
        $deadline = microtime(true) + 2;
        while ($offset < strlen($body)) {
            $written = @fwrite($this->pipes[0], substr($body, $offset));
            if ($written === false || microtime(true) > $deadline) {
                throw new RuntimeException('transport_error: cannot write helper command.');
            }
            $offset += $written;
            if ($written === 0) {
                usleep(1000);
            }
        }
    }

    private function readFrame(float $timeout, bool $optional = false): string
    {
        $deadline = microtime(true) + max(0, $timeout);
        do {
            $newline = strpos($this->buffer, "\n");
            if ($newline !== false) {
                $frame = substr($this->buffer, 0, $newline + 1);
                $this->buffer = substr($this->buffer, $newline + 1);

                return $frame;
            }
            if (strlen($this->buffer) >= WatchProtocol::FRAME_BYTES) {
                throw new RuntimeException('protocol_error: frame_limit.');
            }
            if (! isset($this->pipes[1], $this->pipes[2])) {
                throw new RuntimeException('transport_error: helper closed.');
            }
            $read = [$this->pipes[1], $this->pipes[2]];
            $write = $except = [];
            $remaining = max(0, $deadline - microtime(true));
            $seconds = (int) $remaining;
            $micros = (int) (($remaining - $seconds) * 1000000);
            if (stream_select($read, $write, $except, $seconds, $micros) === false) {
                throw new RuntimeException('transport_error: select failed.');
            }
            foreach ($read as $pipe) {
                if ($pipe === $this->pipes[2]) {
                    $this->stderr .= (string) fread($pipe, 4096);
                    if (strlen($this->stderr) > 8192) {
                        throw new RuntimeException('native_error: helper stderr limit.');
                    }
                } else {
                    $this->buffer .= (string) fread($pipe, WatchProtocol::FRAME_BYTES - strlen($this->buffer));
                }
            }
            if (str_contains($this->buffer, "\n")) {
                continue;
            }
            if (feof($this->pipes[1])) {
                throw new RuntimeException('helper_exited: '.($this->stderr === '' ? 'continuity lost' : trim($this->stderr)));
            }
        } while (microtime(true) < $deadline || str_contains($this->buffer, "\n"));
        if ($optional) {
            return '';
        }
        throw new RuntimeException('watch_timeout: incomplete helper response.');
    }

    /** Only a live proc_open handle owned by this object is stopped; no PID lookup or public kill API. */
    public function close(): void
    {
        if (! is_resource($this->process)) {
            return;
        }
        try {
            if ($this->session !== null) {
                $this->send('stop');
            }
        } catch (Throwable) {
            // A broken transport already invalidates continuity.
        }
        if (isset($this->pipes[0])) {
            fclose($this->pipes[0]);
            unset($this->pipes[0]);
        }
        $deadline = microtime(true) + 0.5;
        do {
            foreach ($this->pipes as $pipe) {
                // Drain private pipes so a blocked writer can finish. Durable work belongs to its coordinator.
                fread($pipe, WatchProtocol::FRAME_BYTES);
            }
            $status = proc_get_status($this->process);
            if (! $status['running']) {
                break;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        if ($status['running']) {
            proc_terminate($this->process);
            $deadline = microtime(true) + 0.2;
            do {
                $status = proc_get_status($this->process);
                if (! $status['running']) {
                    break;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            if ($status['running']) {
                proc_terminate($this->process, 9);
            }
        }
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($this->process);
        $this->process = null;
        $this->pipes = [];
        $this->session?->end('session_closed');
    }

    public function __destruct()
    {
        $this->close();
    }
}
