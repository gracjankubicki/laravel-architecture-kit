<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsOptions;
use GracjanKubicki\ArchitectureKit\Analysis\BasicSource;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureRecord;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureStore;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeGraphClient;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeReplay;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Parser-independent synthetic native records, never a CRM capture or parity oracle. */
final class NativeSnapshotAdmissionTest extends TestCase
{
    private string $base;

    private string $artifactDirectory;

    private array $identity;

    private array $samples = [];

    private Process $sampler;

    private array $clients = [];

    protected function setUp(): void
    {
        if (! getenv('ARCHITECTURE_KIT_TEST_GRAPH_RUNTIME')) {
            $this->markTestSkipped('Explicit private graph runtime required.');
        }
        $this->artifactDirectory = getenv('ARCHITECTURE_KIT_NATIVE_TEST_ARTIFACTS') ?: dirname(__DIR__, 4).'/build/graph-performance-rust/R02/r2';
        if (! is_dir($this->artifactDirectory)) {
            mkdir($this->artifactDirectory, 0700, true);
        }
        $this->base = realpath(sys_get_temp_dir()).'/ak-r02-admission-'.bin2hex(random_bytes(8));
        mkdir($this->base, 0700);
        mkdir($this->base.'/cache', 0700);
        $this->identity = ['repository' => hash('sha256', $this->base), 'profile' => 'r2-synthetic', 'product' => 'basic-local',
            'parser' => 'synthetic', 'tool' => 'r02-r2', 'configuration' => 'r2-fixture', 'capture' => 'r2-fixture'];
        $this->sampler = new Process([PHP_BINARY, dirname(__DIR__, 3).'/Support/native-graph-sampler.php', (string) getmypid(), $this->base.'/rss.ndjson', $this->base.'/observer.stop'], timeout: 35);
        $this->sampler->start();
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        if (isset($this->sampler)) {
            CaptureStore::writePrivate($this->base.'/observer.stop', 'stop');
            $this->sampler->wait();
            $this->assertSame(0, $this->sampler->getExitCode(), $this->sampler->getErrorOutput());
            file_put_contents($this->artifactDirectory.'/snapshot-admission.ndjson', TypedValue::json(['scope' => 'synthetic_native_records_only',
                'raw_samples' => $this->base.'/rss.ndjson', 'cache_directory' => $this->base.'/cache',
                'identity' => $this->identity, 'phases' => $this->samples])."\n", FILE_APPEND);
        }
    }

    private function client(int $transition): NativeGraphClient
    {
        $client = new NativeGraphClient(getenv('ARCHITECTURE_KIT_TEST_GRAPH_RUNTIME'), 1073741824, $transition, 20.0);
        $this->clients[] = $client;

        return $client;
    }

    private function record(int $ordinal, ?string $error): array
    {
        return CaptureRecord::create($ordinal, 'php', BasicSource::plain(new SourceInput(sprintf('f%03d.php', $ordinal), '', '8.3')),
            new BasicFactsOptions, new FileGraphEntry([], [], syntaxError: $error));
    }

    private function load(NativeGraphClient $client, int $count, int $payload, bool $unique = false): string
    {
        $empty = $this->record(0, null);
        $scope = [];
        for ($i = 0; $i < $count; $i++) {
            $scope[] = ['owner' => sprintf('f%03d.php', $i), 'sha256' => $empty['sha256'], 'kind' => 'php', 'php_version' => '8.3'];
        }
        $ingest = $client->request(['op' => 'begin', 'identity' => $this->identity, 'options' => $empty['options'], 'scope' => $scope])['ingest'];
        $error = $payload > 0 ? str_repeat('r', $payload) : null;
        for ($i = 0; $i < $count; $i++) {
            $message = $unique ? sprintf('%04d', $i).$error : $error;
            $client->request(['op' => 'append', 'ingest' => $ingest, 'record' => $this->record($i, $message)]);
        }

        return $client->request(['op' => 'commit', 'ingest' => $ingest])['generation'];
    }

    private function pin(NativeGraphClient $client, string $generation): string
    {
        return $client->request(['op' => 'pin', 'generation' => $generation, 'identity' => $this->identity])['lease'];
    }

    private function sample(string $phase, NativeGraphClient $client): void
    {
        $status = $client->request(['op' => 'status']);
        $at = hrtime(true);
        $ps = new Process(['ps', '-p', (string) $status['process_usage']['pid'], '-o', 'pid=,rss=,lstart='], timeout: 1);
        $ps->run();
        $this->assertTrue($ps->isSuccessful());
        $this->samples[] = ['phase' => $phase, 'begin_ns' => $at, 'end_ns' => hrtime(true), 'runtime_descriptor' => $client->descriptor,
            'runtime_status' => $status, 'endpoint_ps' => trim($ps->getOutput()), 'rss_unit' => 'KiB', 'endpoint_not_peak' => true,
            'php_used_bytes' => memory_get_usage(), 'php_allocated_bytes' => memory_get_usage(true)];
    }

    public function test_native_snapshot_streams_more_than_old_false_threshold_with_one_gib_and_preserves_leases(): void
    {
        $client = $this->client(1073741824);
        // 32 records each hold the same 512 KiB scalar. Disk exceeds 16 MiB;
        // the native intern pool owns one copy, not 32 expanded copies.
        $generation = $this->load($client, 32, 512 * 1024);
        $lease = $this->pin($client, $generation);
        $this->sample('ingest_1gib', $client);
        $client->request(['op' => 'snapshot', 'generation' => $generation, 'identity' => $this->identity, 'lease' => $lease, 'directory' => $this->base.'/cache']);
        $pointerPath = $this->base.'/cache/CURRENT';
        $pointer = json_decode(file_get_contents($pointerPath), true, 512, JSON_THROW_ON_ERROR);
        $manifestPath = $this->base.'/cache/'.$pointer['generation'].'/manifest.json';
        $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $encoded = array_sum(array_column($manifest['segments'], 'bytes'));
        $this->assertGreaterThan(16 * 1024 * 1024, $encoded);
        $this->assertGreaterThan(1073741824, $encoded * 64 + 4194304 * 64 + NativeGraphClient::FRAME_BYTES);
        $client->request(['op' => 'release', 'lease' => $lease]);
        $small = $this->load($client, 1, 0);
        $smallLease = $this->pin($client, $small);
        $started = hrtime(true);
        $restored = $client->request(['op' => 'restore', 'identity' => $this->identity, 'directory' => $this->base.'/cache']);
        $this->assertSame($generation, $restored['generation']);
        $this->samples[] = ['phase' => 'restore_1gib_wall', 'wall_ns' => hrtime(true) - $started, 'encoded_bytes' => $encoded];
        $this->sample('restore_1gib_with_old_lease', $client);
        $count = 0;
        foreach ((new NativeReplay($client))->records($generation, $this->identity) as $record) {
            $expected = $this->record($count, str_repeat('r', 512 * 1024));
            $this->assertSame(TypedValue::digest($expected), TypedValue::digest($record));
            $count++;
        }
        $this->assertSame(32, $count);

        $oldPage = $client->request(['op' => 'read_batch', 'generation' => $small, 'identity' => $this->identity, 'lease' => $smallLease, 'start' => 0, 'limit' => 1]);
        $this->assertSame($this->record(0, null), $oldPage['records'][0]);
        $this->assertLessThan(4 * 1024 * 1024, $client->request(['op' => 'status'])['owned_capacity_bytes']);

        // Actual old typed state is ~18 MiB. It fits while being built from
        // smaller frames, then makes the single 512 KiB decode reserve exceed64M.
        $bounded = $this->client(64 * 1024 * 1024);
        $old = $this->load($bounded, 48, 384 * 1024, unique: true);
        $oldLease = $this->pin($bounded, $old);
        $current = $this->load($bounded, 1, 0);
        $this->sample('64m_old_lease_plus_current', $bounded);
        try {
            $bounded->request(['op' => 'restore', 'identity' => $this->identity, 'directory' => $this->base.'/cache']);
            $this->fail('Live old lease was omitted from admission');
        } catch (\RuntimeException $error) {
            $this->assertSame('native_graph_transition_budget', $error->getMessage());
        }
        $this->assertSame($current, $bounded->request(['op' => 'status'])['generation']);
        $page = $bounded->request(['op' => 'read_batch', 'generation' => $old, 'identity' => $this->identity, 'lease' => $oldLease, 'start' => 47, 'limit' => 1]);
        $this->assertSame(47, $page['records'][0]['ordinal']);
        $bounded->request(['op' => 'release', 'lease' => $oldLease]);
        // Same process and exact same64M limit. Freeing the lease is sufficient.
        $this->assertSame($generation, $bounded->request(['op' => 'restore', 'identity' => $this->identity, 'directory' => $this->base.'/cache'])['generation']);
        $this->sample('64m_restore_after_release_same_limit', $bounded);

        foreach (['decoded', 'length', 'hash', 'path'] as $corruption) {
            $changed = $manifest;
            match ($corruption) {
                'decoded' => $changed['decoded_bytes'] = 1,
                'length' => $changed['segments'][0]['bytes'] = 1,
                'hash' => $changed['segments'][0]['sha256'] = str_repeat('0', 64),
                'path' => $changed['segments'][0]['file'] = '../outside',
            };
            if ($corruption === 'length') {
                $changed['decoded_bytes'] = array_sum(array_column($changed['segments'], 'bytes')) * 64;
            }
            $body = TypedValue::json($changed);
            file_put_contents($manifestPath, $body);
            file_put_contents($pointerPath, TypedValue::json([...$pointer, 'manifest_sha256' => hash('sha256', $body)]));
            try {
                $bounded->request(['op' => 'restore', 'identity' => $this->identity, 'directory' => $this->base.'/cache']);
                $this->fail('Corrupt snapshot accepted: '.$corruption);
            } catch (\RuntimeException $error) {
                $this->assertContains($error->getMessage(), ['native_graph_cache_decoded_bytes', 'native_graph_cache_segment_hash', 'native_graph_cache_segment_shape']);
            }
            $this->assertSame($generation, $bounded->request(['op' => 'status'])['generation']);
        }
        file_put_contents($manifestPath, TypedValue::json($manifest));
        file_put_contents($pointerPath, TypedValue::json([...$pointer, 'manifest_sha256' => hash_file('sha256', $manifestPath)]));
    }
}
