<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Codec;
use GracjanKubicki\ArchitectureKit\Standalone\Measurement\GraphStageMeasurement;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

final class MagoAnalysisEngine implements BasicFactsEngine
{
    public const VERSION = '0.2.0';

    public const BASIC_BATCH_FILES = 32;

    public const BASIC_BATCH_BYTES = 4 * 1024 * 1024;

    public const MAGO_VERSION = '1.57.0';

    private ?EngineDescriptor $descriptor = null;

    private ?Process $process = null;

    private ?InputStream $input = null;

    private bool $failed = false;

    private ?string $binaryHash = null;

    /** @var array<string, int>|null */
    private ?array $binaryStamp = null;

    private string $responseOutput = '';

    private int $responseBytes = 0;

    public function __construct(private readonly string $binary, private readonly float $timeout = 30.0) {}

    public function descriptor(): EngineDescriptor
    {
        if ($this->failed) {
            throw new RuntimeException('The Mago engine session failed. Start a new analysis session; no implicit retry or PHP fallback was used.');
        }
        try {
            $hash = $this->identity();
            if ($this->descriptor !== null) {
                if ($hash !== $this->binaryHash) {
                    $this->close();
                    $this->failed = true;
                    throw new RuntimeException('Mago engine binary changed during this analysis session. Start a new session; no engines were mixed.');
                }

                return $this->descriptor;
            }
            $data = $this->exchange(['--version']);
            if ($this->identity() !== $hash) {
                throw new RuntimeException('Mago engine binary changed during version verification.');
            }
            if (($data['engine'] ?? null) !== 'mago' || ($data['version'] ?? null) !== self::VERSION
                || ($data['mago_version'] ?? null) !== self::MAGO_VERSION || ($data['schema'] ?? null) !== EngineDescriptor::SCHEMA
                || ! is_array($data['capabilities'] ?? null) || ! array_is_list($data['capabilities'])) {
                throw new RuntimeException('Mago engine version or protocol is incompatible. Install the matching engine or explicitly select PHP.');
            }

            $this->binaryHash = $hash;

            return $this->descriptor = new EngineDescriptor('mago', self::VERSION.'+mago.'.self::MAGO_VERSION, $data['capabilities'], implementation: $hash);
        } catch (Throwable $error) {
            $this->failed = true;
            $this->close();
            throw $error;
        }
    }

    public function analyze(SourceInput $source): AnalysisDocument
    {
        $descriptor = $this->descriptor();
        $descriptor->requireCapability('syntax-facts');
        $data = $this->request(json_encode(['schema' => EngineDescriptor::SCHEMA,
            'path' => $source->path, 'source' => $source->contents, 'source_hash' => $source->hash,
            'php_version' => $source->phpVersion], JSON_THROW_ON_ERROR)."\n");
        try {
            if (($data['schema'] ?? null) !== EngineDescriptor::SCHEMA || ($data['path'] ?? null) !== $source->path
                || ($data['source_hash'] ?? null) !== $source->hash || ($data['php_version'] ?? null) !== $source->phpVersion
                || ! is_array($data['facts'] ?? null) || ! is_array($data['diagnostics'] ?? null)
                || (in_array('syntax-tree-v1', $descriptor->capabilities, true) && ! is_array($data['syntax'] ?? null))) {
                throw new RuntimeException('Mago returned an invalid analysis response for the source snapshot.');
            }

            return new AnalysisDocument($source, $descriptor, $data['facts'], $data['diagnostics'],
                syntax: in_array('syntax-flat-v1', $descriptor->capabilities, true)
                    ? Codec::decodeFlat($data['syntax'], $source) : Codec::decodeList($data['syntax'] ?? [], $source));
        } catch (Throwable $error) {
            $this->failed = true;
            $this->close();
            throw $error;
        }
    }

    public function analyzeBasic(array $sources, BasicFactsOptions $options): array
    {
        $descriptor = $this->descriptor();
        $descriptor->requireCapability(BasicFileFacts::PRODUCT);
        if (! array_is_list($sources) || $sources === [] || count($sources) > self::BASIC_BATCH_FILES) {
            throw new RuntimeException('Basic extraction requires a nonempty batch of at most 32 sources.');
        }
        $requests = array_map(static fn (BasicSource $source): array => $source->request($descriptor, $options), $sources);
        $measurement = GraphStageMeasurement::current();
        if ($measurement !== null) {
            foreach ($requests as $ordinal => &$request) {
                $request['measurement'] = ['batch' => $measurement->batch, 'request' => $ordinal];
            }
            unset($request);
        }
        $input = json_encode(['schema' => EngineDescriptor::SCHEMA, 'product' => 'basic-file-facts-batch-v1', 'requests' => $requests], JSON_THROW_ON_ERROR)."\n";
        $single = false;
        if (strlen($input) > self::BASIC_BATCH_BYTES) {
            if (count($sources) !== 1) {
                throw new RuntimeException('Basic extraction batch exceeds 4 MiB. Split the batch; no source was accepted.');
            }
            // Batching must not lower the existing per-source 32 MiB limit.
            $input = json_encode($requests[0], JSON_THROW_ON_ERROR)."\n";
            $single = true;
        }
        $data = $this->request($input);
        try {
            if ($single) {
                $data = ['schema' => EngineDescriptor::SCHEMA, 'product' => 'basic-file-facts-batch-v1', 'results' => [$data]];
            }
            if (array_keys($data) !== ['schema', 'product', 'results'] || $data['schema'] !== EngineDescriptor::SCHEMA
                || $data['product'] !== 'basic-file-facts-batch-v1' || ! is_array($data['results']) || ! array_is_list($data['results'])
                || count($data['results']) !== count($sources)) {
                throw new RuntimeException('Mago returned an invalid or incomplete Basic batch.');
            }
            $results = [];
            $strings = new BasicRecordStrings;
            foreach ($sources as $index => $source) {
                if ($measurement !== null) {
                    $measurement->event('engine.timeline', ['request' => $index, 'events' => $data['results'][$index]['trace']['measurement'] ?? null]);
                    if (! isset($data['results'][$index]['trace']['measurement'])) {
                        $measurement->unavailable('engine.phases', 'binary_does_not_emit_phase_boundaries');
                    }
                }
                $materialize = static fn () => BasicFileFacts::fromNative($data['results'][$index], $source, $descriptor, $options, $strings);
                $results[] = $measurement === null ? $materialize() : $measurement->measure('ipc.materialize', $materialize);
                unset($data['results'][$index]);
            }

            return $results;
        } catch (Throwable $error) {
            $this->failed = true;
            $this->close();
            throw $error;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /** One process per engine session, with one bounded response per snapshot.
     * @return array<string, mixed> */
    private function request(string $input): array
    {
        if ($this->failed) {
            throw new RuntimeException('The Mago engine session failed. Start a new analysis session; no implicit retry or PHP fallback was used.');
        }
        if (strlen($input) > 32 * 1024 * 1024) {
            throw new RuntimeException('Mago source request exceeds 32 MiB. Analysis is incomplete.');
        }
        try {
            if ($this->process === null) {
                $this->identity();
                if (! hash_equals($this->binaryHash ?? '', hash_file('sha256', $this->binary) ?: '')) {
                    throw new RuntimeException('Mago engine binary changed before launch. No result was accepted.');
                }
                $this->input = new InputStream;
                $this->process = new Process([$this->binary], input: $this->input, timeout: null);
                $reference = \WeakReference::create($this);
                $this->process->start(static function (string $type, string $chunk) use ($reference): void {
                    $engine = $reference->get();
                    if ($engine === null || $engine->failed) {
                        return;
                    }
                    $engine->responseBytes += strlen($chunk);
                    if ($engine->responseBytes > 64 * 1024 * 1024) {
                        throw new RuntimeException('Mago engine output exceeds 64 MiB. Analysis is incomplete.');
                    }
                    if ($type === Process::OUT) {
                        $engine->responseOutput .= $chunk;
                    }
                });
            }
            // A callback receives every pipe read, including those triggered by
            // status checks. Clearing Process buffers cannot discard unread chunks.
            $this->process->isRunning();
            if ($this->responseOutput !== '') {
                throw new RuntimeException('Mago engine returned extra protocol output.');
            }
            $measurement = GraphStageMeasurement::current();
            $span = $measurement?->begin('ipc.roundtrip');
            $measurement?->event('ipc.send', ['bytes' => strlen($input)]);
            $this->responseBytes = 0;
            $this->input?->write($input);
            $deadline = hrtime(true) + (int) ($this->timeout * 1000000000);
            do {
                $receivedBytes = $this->responseBytes;
                $running = $this->pollProcess($this->process);
                if ($receivedBytes === 0 && $this->responseBytes > 0) {
                    $measurement?->event('ipc.first_byte');
                }
                $this->process->clearOutput();
                $this->process->clearErrorOutput();
                if (str_contains($this->responseOutput, "\n")) {
                    if (! str_ends_with($this->responseOutput, "\n") || substr_count($this->responseOutput, "\n") !== 1) {
                        throw new RuntimeException('Mago engine returned extra protocol output.');
                    }
                    $measurement?->event('ipc.last_byte', ['bytes' => $this->responseBytes]);
                    if ($span !== null) {
                        $measurement->end($span);
                    }
                    $data = $this->decode($this->responseOutput);
                    $this->responseOutput = '';

                    return $data;
                }
                if (! $running) {
                    throw new RuntimeException('Mago engine stopped before completing analysis. No fallback was used.');
                }
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('Mago engine response timed out. Analysis is incomplete.');
                }
                // Drain available output before waiting, including progress on stderr.
                if ($this->responseBytes === $receivedBytes) {
                    usleep(1000);
                }
            } while (true);
        } catch (Throwable $error) {
            $this->failed = true;
            $this->close();
            throw $error;
        }
    }

    /** @phpstan-impure Polling invokes the callback that updates this session's response buffers. */
    private function pollProcess(Process $process): bool
    {
        return $process->isRunning();
    }

    private function close(): void
    {
        $this->input?->close();
        $this->process?->stop(0);
        $this->process = null;
        $this->input = null;
    }

    private function identity(): string
    {
        clearstatcache(true, $this->binary);
        $stat = @stat($this->binary);
        if ($stat === false || ! is_file($this->binary) || ! is_executable($this->binary)) {
            throw new RuntimeException('Mago engine binary is unavailable. Install the matching engine or explicitly select PHP.');
        }
        $stamp = array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime']));
        if ($this->binaryStamp !== null && $this->binaryStamp !== $stamp) {
            throw new RuntimeException('Mago engine binary changed during this analysis session. Start a new session; no engines were mixed.');
        }
        if ($this->binaryHash === null) {
            $hash = hash_file('sha256', $this->binary);
            if ($hash === false) {
                throw new RuntimeException('Cannot verify the Mago engine binary.');
            }
            clearstatcache(true, $this->binary);
            $after = @stat($this->binary);
            if ($after === false || array_intersect_key($after, array_flip(array_keys($stamp))) !== $stamp) {
                throw new RuntimeException('Mago engine binary changed during verification.');
            }
            $this->binaryStamp = $stamp;
            $this->binaryHash = $hash;
        }

        // The persistent process never restarts after failure. Its implementation is
        // pinned at launch; metadata detects replacement without rereading a large
        // executable several times for each source file.
        return $this->binaryHash;
    }

    /** @param list<string> $arguments
     * @return array<string, mixed> */
    private function exchange(array $arguments, ?string $input = null): array
    {
        if (! is_file($this->binary) || ! is_executable($this->binary)) {
            throw new RuntimeException('Mago engine binary is unavailable. Install the matching engine or explicitly select PHP.');
        }
        $process = new Process([$this->binary, ...$arguments], input: $input, timeout: $this->timeout);
        $bytes = 0;
        try {
            $process->run(static function (string $type, string $chunk) use (&$bytes): void {
                $bytes += strlen($chunk);
                if ($bytes > 64 * 1024 * 1024) {
                    throw new RuntimeException('Mago engine output exceeds 64 MiB. Analysis is incomplete.');
                }
            });
        } catch (ProcessTimedOutException $error) {
            throw new RuntimeException('Mago engine version verification timed out. Analysis is incomplete.', previous: $error);
        }
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Mago engine failed with exit code '.$process->getExitCode().'. Analysis is incomplete; no fallback was used.');
        }

        return $this->decode($process->getOutput());
    }

    /** @return array<string, mixed> */
    private function decode(string $output): array
    {
        try {
            $measurement = GraphStageMeasurement::current();
            $data = $measurement === null ? json_decode(trim($output), true, 32768, JSON_THROW_ON_ERROR)
                : $measurement->measure('ipc.decode', static fn () => json_decode(trim($output), true, 32768, JSON_THROW_ON_ERROR));
        } catch (\JsonException $error) {
            throw new RuntimeException('Mago engine returned invalid JSON.', previous: $error);
        }
        if (! is_array($data)) {
            throw new RuntimeException('Mago engine response must be an object.');
        }

        return $data;
    }
}
