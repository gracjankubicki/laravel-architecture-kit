<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\Measurement;

use GracjanKubicki\ArchitectureKit\Standalone\Measurement\GraphStageMeasurement;
use GracjanKubicki\ArchitectureKit\Standalone\Measurement\ProcessSamples;
use PHPUnit\Framework\TestCase;

final class GraphStageMeasurementTest extends TestCase
{
    public function test_overlap_cpu_self_php_memory_and_interrupted_boundaries(): void
    {
        $path = dirname(__DIR__, 4).'/build/graph-performance-rust/R01/measurement-'.bin2hex(random_bytes(6)).'.ndjson';
        $meter = new GraphStageMeasurement($path);
        $whole = $meter->begin('whole', 1);
        $parser = $meter->begin('parser', 1);
        $transport = $meter->begin('transport', 1);
        $cpu = $meter->begin('cpu', 1);
        $hash = '';
        for ($i = 0; $i < 100000; $i++) {
            $hash = hash('sha256', $hash);
        }
        $meter->end($cpu);
        $meter->measure('io_wait', static fn () => usleep(40000));
        $buffer = str_repeat('x', 8 * 1024 * 1024);
        $meter->event('allocated');
        unset($buffer);
        $meter->event('freed');
        $meter->end($parser);
        $meter->end($transport);
        try {
            $meter->measure('failed', static function (): void {
                throw new \RuntimeException('synthetic');
            });
        } catch (\RuntimeException) {
        }
        $meter->unavailable('unreached', 'not_reached_after_failure');
        $meter->end($whole);
        $meter->close();
        $rows = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES));
        $ends = [];
        foreach ($rows as $row) {
            $this->assertIsInt($row['monotonic_ns']);
            $this->assertSame(getmypid(), $row['pid']);
            $this->assertNotEmpty($row['instance']);
            if ($row['event'] === 'end') {
                $ends[$row['phase']] = $row;
            }
        }
        $this->assertGreaterThan(1000, $ends['cpu']['cpu_user_us']);
        $this->assertGreaterThan(30000000, $ends['io_wait']['wall_ns']);
        $this->assertLessThan($ends['io_wait']['wall_ns'] / 1000, $ends['io_wait']['cpu_user_us'] + $ends['io_wait']['cpu_system_us']);
        $this->assertGreaterThan($ends['whole']['wall_ns'], $ends['parser']['wall_ns'] + $ends['transport']['wall_ns']);
        $this->assertNull($ends['failed']['wall_ns']);
        $this->assertSame('interrupted', $ends['failed']['status']);
        $points = array_values(array_filter($rows, static fn ($row) => $row['event'] === 'point'));
        $this->assertGreaterThan($points[1]['sample']['php_used_bytes'], $points[0]['sample']['php_used_bytes']);
        $this->assertGreaterThanOrEqual($points[0]['sample']['php_used_bytes'], $points[0]['sample']['php_used_lifetime_peak_bytes']);
        $this->assertArrayHasKey('php_allocated_bytes', $points[0]['sample']);
        $this->assertSame('NOT_AVAILABLE', array_values(array_filter($rows, static fn ($row) => $row['event'] === 'unavailable'))[0]['status']);
    }

    public function test_process_sum_is_per_sample_with_restart_reparent_and_pid_reuse(): void
    {
        $row = static fn ($pid, $ppid, $start, $rss) => ['pid' => $pid, 'ppid' => $ppid, 'start' => $start, 'rss_bytes' => $rss];
        $first = ProcessSamples::select([$row(1, 0, 'a', 100), $row(2, 1, 'b', 900)], 1);
        $this->assertSame(1000, $first['rss_bytes']);
        $second = ProcessSamples::select([$row(1, 0, 'a', 900), $row(2, 999, 'b', 100), $row(3, 1, 'c', 25)], 1, $first['known']);
        $this->assertSame(1025, $second['rss_bytes']);
        $this->assertCount(3, $second['processes']);
        $reuse = ProcessSamples::select([$row(1, 0, 'a', 100), $row(2, 999, 'NEW', 9999)], 1, $second['known']);
        $this->assertSame(100, $reuse['rss_bytes']);
        $this->assertCount(1, $reuse['processes']);
        $sample = ProcessSamples::observe(getmypid(), [], -1);
        $this->assertSame('sampled', $sample['status']);
        $this->assertStringStartsWith('NOT_AVAILABLE', $sample['coverage']['short_lived_children']);
        $this->assertStringStartsWith('NOT_AVAILABLE', $sample['coverage']['unobserved_reparenting']);
    }
}
