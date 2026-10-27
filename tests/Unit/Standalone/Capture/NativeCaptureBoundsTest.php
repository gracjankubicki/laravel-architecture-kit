<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\Capture;

use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsOptions;
use GracjanKubicki\ArchitectureKit\Analysis\BasicSource;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureRecord;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureStore;
use PHPUnit\Framework\TestCase;

final class NativeCaptureBoundsTest extends TestCase
{
    public function test_segment_rotation_coverage_and_real_write_failure(): void
    {
        $base = dirname(__DIR__, 4).'/build/graph-performance-rust/R01/bounds/'.bin2hex(random_bytes(6));
        mkdir($base, 0700, true);
        $options = new BasicFactsOptions;
        $records = $files = [];
        for ($i = 0; $i < 65; $i++) {
            $source = new SourceInput(sprintf('file-%03d.php', $i), '', '8.3');
            $files[$source->path] = '1:0:'.$source->hash;
            $records[] = CaptureRecord::create($i, 'php', BasicSource::plain($source), $options, new FileGraphEntry([], []));
        }
        $plan = ['files' => $files, 'php_version' => '8.3'];
        $identity = ['test' => 'bounds'];
        $store = new CaptureStore($base.'/good', $plan, $identity, $options->toArray(), static function (): void {});
        foreach ($records as $record) {
            $store->append($record);
        }
        $store->commit();
        $manifest = CaptureStore::manifest($base.'/good', $identity);
        $this->assertSame([32, 32, 1], array_column($manifest['segments'], 'records'));
        $this->assertCount(65, iterator_to_array(CaptureStore::replay($base.'/good', $identity)));
        $store = new CaptureStore($base.'/bad', $plan, $identity, $options->toArray(), static function (): void {});
        mkdir($base.'/bad/segment-000000.ndjson', 0700);
        try {
            $store->append($records[0]);
            $this->fail('write failure accepted');
        } catch (\RuntimeException $error) {
            $this->assertSame('capture_segment_open', $error->getMessage());
        }
        $store->abort('capture_write_failed');
        $this->assertFileDoesNotExist($base.'/bad/complete');
        $this->assertFileExists($base.'/bad/failure.json');
        $store = new CaptureStore($base.'/duplicate', $plan, $identity, $options->toArray(), static function (): void {});
        $store->append($records[0]);
        $this->expectException(\RuntimeException::class);
        $store->append($records[0]);
    }
}
