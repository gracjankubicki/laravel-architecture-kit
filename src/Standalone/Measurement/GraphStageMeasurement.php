<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Measurement;

use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureStore;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use Throwable;

/** Append-only spans. Whole wall is measured independently, never summed from phases. */
final class GraphStageMeasurement
{
    private string $instance;

    private int $ordinal = 0;

    /** @var array<array-key, mixed> */
    private array $active = [];

    private mixed $stream;

    private ?string $failure = null;

    private static ?self $current = null;

    public ?int $batch = null;

    public function __construct(string $path)
    {
        $this->instance = bin2hex(random_bytes(16));
        CaptureStore::writePrivate($path, '');
        $this->stream = fopen($path, 'ab');
        $this->emit(['event' => 'anchor', 'host_unix_ns' => (int) (microtime(true) * 1e9),
            'clock' => 'hrtime', 'units' => ['wall' => 'ns', 'cpu' => 'us', 'memory' => 'bytes'],
            'start_identity' => ProcessSamples::identity(getmypid()), 'cpu_scope' => 'RUSAGE_SELF only; children excluded',
            'php_peak_scope' => 'process lifetime; phase-local peaks unavailable without reset',
            'instrumentation' => 'enabled; overhead not subtracted']);
    }

    public static function activate(?self $measurement): void
    {
        self::$current = $measurement;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public function begin(string $phase, ?int $batch = null): int
    {
        $id = ++$this->ordinal;
        $sample = $this->snapshot();
        $this->active[$id] = ['phase' => $phase, 'batch' => $batch ?? $this->batch, 'sample' => $sample];
        $this->emit(['event' => 'begin', 'span' => $id, 'phase' => $phase, 'batch' => $batch ?? $this->batch, 'sample' => $sample]);

        return $id;
    }

    public function end(int $id, string $status = 'complete'): void
    {
        $start = $this->active[$id];
        $end = $this->snapshot();
        $before = $start['sample'];
        $this->emit(['event' => 'end', 'span' => $id, 'phase' => $start['phase'], 'batch' => $start['batch'], 'status' => $status,
            'sample' => $end, 'wall_ns' => $status === 'complete' ? $end['monotonic_ns'] - $before['monotonic_ns'] : null,
            'observed_elapsed_ns' => $end['monotonic_ns'] - $before['monotonic_ns'],
            'cpu_user_us' => $end['cpu_user_us'] === null || $before['cpu_user_us'] === null ? null : $end['cpu_user_us'] - $before['cpu_user_us'],
            'cpu_system_us' => $end['cpu_system_us'] === null || $before['cpu_system_us'] === null ? null : $end['cpu_system_us'] - $before['cpu_system_us']]);
        unset($this->active[$id]);
    }

    public function measure(string $phase, \Closure $work): mixed
    {
        $id = $this->begin($phase);
        try {
            $value = $work();
            $this->end($id);

            return $value;
        } catch (Throwable $error) {
            if ($this->failure === null && isset($this->active[$id])) {
                try {
                    $this->end($id, 'interrupted');
                } catch (Throwable) {
                    // close() explicitly reports any unrecorded boundaries.
                }
            }
            throw $error;
        }
    }

    /**
     * @param  array<array-key, mixed>  $counters
     */
    public function event(string $phase, array $counters = []): void
    {
        $this->emit(['event' => 'point', 'phase' => $phase, 'batch' => $this->batch, 'sample' => $this->snapshot(), 'counters' => $counters]);
    }

    public function unavailable(string $phase, string $reason): void
    {
        $this->emit(['event' => 'unavailable', 'phase' => $phase, 'batch' => $this->batch, 'status' => 'NOT_AVAILABLE', 'reason' => $reason]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function snapshot(): array
    {
        $usage = function_exists('getrusage') ? getrusage() : false;

        return ['monotonic_ns' => hrtime(true),
            'cpu_user_us' => $usage === false ? null : $usage['ru_utime.tv_sec'] * 1000000 + $usage['ru_utime.tv_usec'],
            'cpu_system_us' => $usage === false ? null : $usage['ru_stime.tv_sec'] * 1000000 + $usage['ru_stime.tv_usec'],
            'cpu_reason' => $usage === false ? 'NOT_AVAILABLE:getrusage' : null,
            'php_used_bytes' => memory_get_usage(), 'php_allocated_bytes' => memory_get_usage(true),
            'php_used_lifetime_peak_bytes' => memory_get_peak_usage(), 'php_allocated_lifetime_peak_bytes' => memory_get_peak_usage(true),
            'io_input_blocks' => $usage['ru_inblock'] ?? null, 'io_output_blocks' => $usage['ru_oublock'] ?? null];
    }

    /**
     * @param  array<array-key, mixed>  $event
     */
    private function emit(array $event): void
    {
        $event = ['instance' => $this->instance, 'pid' => getmypid(), 'role' => 'php-capture', 'unit' => 'ns', 'monotonic_ns' => hrtime(true), ...$event];
        $line = TypedValue::json($event)."\n";
        if ($this->failure !== null) {
            throw new \RuntimeException($this->failure);
        }
        try {
            CaptureStore::writeStream($this->stream, $line);
        } catch (Throwable $error) {
            $this->failure = $error->getMessage() === 'capture_output_limit' ? 'capture_output_limit' : 'measurement_write_failed';
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    public function close(): array
    {
        try {
            foreach (array_keys($this->active) as $id) {
                if ($this->failure !== null) {
                    break;
                }
                try {
                    $this->end($id, 'interrupted');
                } catch (Throwable) {
                    break;
                }
            }
        } finally {
            fclose($this->stream);
            if (self::$current === $this) {
                self::$current = null;
            }
        }

        return ['status' => $this->failure === null ? 'recorded' : 'incomplete', 'reason' => $this->failure,
            'unclosed_spans' => array_keys($this->active)];
    }
}
