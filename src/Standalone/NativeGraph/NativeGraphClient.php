<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use RuntimeException;

/** Private experimental child process. Synchronous frames provide backpressure. */
final class NativeGraphClient
{
    public const PROTOCOL = 'graph-store-v1';

    public const FRAME_BYTES = 16842752;

    public const RECORD_BYTES = 16777216;

    public const CONTROL_BYTES = 4194304;

    public const DECODE_MULTIPLIER = 128;

    private mixed $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $session;

    private int $sequence = 0;

    private bool $closed = false;

    /** @var array<array-key, mixed> */
    public readonly array $descriptor;

    /** @var array<array-key, mixed> */
    public array $lastMeasurement = [];

    public function __construct(string $binary, int $residentBytes, int $transitionBytes, private readonly float $timeout = 10.0)
    {
        if (! is_file($binary) || ! is_executable($binary) || $residentBytes < 1 || $transitionBytes < 1 || $timeout <= 0) {
            throw new RuntimeException('native_graph_admission');
        }
        $env = getenv();
        $env['ARCHITECTURE_KIT_GRAPH_RESIDENT_BYTES'] = (string) $residentBytes;
        $env['ARCHITECTURE_KIT_GRAPH_TRANSITION_BYTES'] = (string) $transitionBytes;
        $this->process = proc_open([$binary, '--graph-store-v1'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $this->pipes, null, $env);
        if (! is_resource($this->process)) {
            throw new RuntimeException('native_graph_start');
        }
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        try {
            $this->descriptor = $this->receive((hrtime(true) / 1000000000) + $this->timeout);
            if (($this->descriptor['protocol'] ?? null) !== self::PROTOCOL
                || ! in_array(self::PROTOCOL, $this->descriptor['capabilities'] ?? [], true)
                || ($this->descriptor['max_frame_bytes'] ?? null) !== self::FRAME_BYTES
                || ! is_string($this->descriptor['session'] ?? null)) {
                throw new RuntimeException('native_graph_capability');
            }
            $this->session = $this->descriptor['session'];
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    /**
     * @param  array<array-key, mixed>  $operation
     * @return array<array-key, mixed>
     */
    public function request(array $operation): array
    {
        if ($this->closed) {
            throw new RuntimeException('native_graph_closed');
        }
        $started = hrtime(true);
        $phpBefore = getrusage();
        $id = (string) ++$this->sequence;
        $body = TypedValue::json(['protocol' => self::PROTOCOL, 'session' => $this->session, 'request_id' => $id, 'operation' => $operation]);
        $sentBytes = strlen($body) + 4;
        $limit = in_array($operation['op'] ?? null, ['append', 'patch_append'], true) ? self::FRAME_BYTES : self::CONTROL_BYTES;
        if (strlen($body) > $limit) {
            throw new RuntimeException('native_graph_frame_limit');
        }
        $deadline = (hrtime(true) / 1000000000) + $this->timeout;
        $this->send(pack('N', strlen($body)), $deadline);
        $this->send($body, $deadline);
        unset($body);
        $response = $this->receive($deadline);
        if (($response['protocol'] ?? null) !== self::PROTOCOL || ($response['request_id'] ?? null) !== $id) {
            throw new RuntimeException('native_graph_response_identity');
        }
        if (($response['ok'] ?? null) !== true) {
            throw new RuntimeException('native_graph_'.($response['reason'] ?? 'response_error'));
        }
        $after = getrusage();
        $this->lastMeasurement = ['operation' => $operation['op'] ?? null, 'wall_ns' => hrtime(true) - $started,
            'sent_bytes' => $sentBytes, 'php_used_bytes' => memory_get_usage(), 'php_allocated_bytes' => memory_get_usage(true),
            'php_cpu_user_us' => ($after['ru_utime.tv_sec'] - $phpBefore['ru_utime.tv_sec']) * 1000000 + $after['ru_utime.tv_usec'] - $phpBefore['ru_utime.tv_usec'],
            'php_cpu_system_us' => ($after['ru_stime.tv_sec'] - $phpBefore['ru_stime.tv_sec']) * 1000000 + $after['ru_stime.tv_usec'] - $phpBefore['ru_stime.tv_usec'],
            'clock' => 'client_monotonic', 'rss' => 'not_available_in_php_client'];

        return $response['result'] ?? $response;
    }

    private function wait(mixed $pipe, bool $writing, float $deadline): void
    {
        $remaining = $deadline - (hrtime(true) / 1000000000);
        if ($remaining <= 0) {
            throw new RuntimeException('native_graph_timeout');
        }
        $read = $writing ? [] : [$pipe];
        $write = $writing ? [$pipe] : [];
        $except = [];
        $seconds = (int) $remaining;
        if (stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1000000)) !== 1) {
            throw new RuntimeException('native_graph_timeout');
        }
    }

    private function send(string $bytes, float $deadline): void
    {
        for ($offset = 0, $length = strlen($bytes); $offset < $length;) {
            $this->wait($this->pipes[0], true, $deadline);
            $n = @fwrite($this->pipes[0], substr($bytes, $offset, 65536));
            if ($n === false || $n === 0) {
                throw $this->transportError('write');
            }
            $offset += $n;
        }
    }

    private function exact(int $length, float $deadline): string
    {
        $body = '';
        while (strlen($body) < $length) {
            $this->wait($this->pipes[1], false, $deadline);
            $part = fread($this->pipes[1], min(65536, $length - strlen($body)));
            if ($part === false || $part === '' && feof($this->pipes[1])) {
                throw $this->transportError('truncated');
            }
            $body .= $part;
        }

        return $body;
    }

    /** @return array<array-key, mixed> */
    private function receive(float $deadline): array
    {
        $length = unpack('Nlength', $this->exact(4, $deadline))['length'];
        if ($length < 1 || $length > self::FRAME_BYTES) {
            $this->close();
            throw new RuntimeException('native_graph_response_limit');
        }
        $phpLimit = MemoryLimit::bytes();
        if ($phpLimit !== null && memory_get_usage(true) + 2 * $length + 262144 > $phpLimit) {
            $this->close();
            throw new RuntimeException('native_graph_decode_budget');
        }
        try {
            $body = $this->exact($length, $deadline);
            if ($phpLimit !== null && memory_get_usage(true) + self::decodeReservation($body) > $phpLimit) {
                throw new RuntimeException('native_graph_decode_budget');
            }
            $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($result)) {
                throw new RuntimeException('native_graph_response_shape');
            }

            return $result;
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** Reserve string copies and PHP containers before json_decode, without
     * treating a large scalar string as millions of PHP array entries. */
    private static function decodeReservation(string $body): int
    {
        $tokens = 0;
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            if ($body[$i] === '"') {
                $tokens++;
                $start = $i;
                $end = strpos($body, '"', $i + 1);
                while ($end !== false) {
                    $slashes = 0;
                    for ($j = $end - 1; $j > $start && $body[$j] === '\\'; $j--) {
                        $slashes++;
                    }
                    if ($slashes % 2 === 0) {
                        break;
                    }
                    $end = strpos($body, '"', $end + 1);
                }
                if ($end === false) {
                    throw new RuntimeException('native_graph_response_json');
                }
                $i = $end;
            } elseif (str_contains('{}[],:', $body[$i])) {
                $tokens++;
            }
        }

        // Includes the subsequently decoded typed representation. Dense arrays
        // reserve per token; escaped bytes inside scalar strings remain bytes.
        return 2 * $length + 512 * $tokens + 262144;
    }

    private function transportError(string $fallback): RuntimeException
    {
        $reason = stream_get_contents($this->pipes[2], 2048);
        // Runtime emits only stable codes. Never include arbitrary child output.
        if (is_string($reason) && preg_match('/^[a-z][a-z0-9_]*\\n?$/D', $reason)) {
            return new RuntimeException('native_graph_'.trim($reason));
        }

        return new RuntimeException('native_graph_'.$fallback);
    }

    public function healthy(): bool
    {
        return ! $this->closed && is_resource($this->process) && proc_get_status($this->process)['running'];
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
