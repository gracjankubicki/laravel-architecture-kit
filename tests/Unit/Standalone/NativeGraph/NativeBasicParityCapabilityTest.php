<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureStore;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeBasic;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeGraphClient;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeReplay;
use RuntimeException;

final class NativeBasicParityCapabilityTest extends NativeBasicFixture
{
    public function test_basic_capability_is_separate_and_historical_local_store_replay_remains_legal(): void
    {
        $this->assertContains('basic-graph-v1', $this->client->descriptor['capabilities']);
        $binary = dirname(__DIR__, 4).'/build/graph-performance-rust/R02/r2/bin/architecture-kit-runtime';
        if (! is_file($binary)) {
            $this->markTestSkipped('Preserved R02 binary required for compatibility proof.');
        }
        $this->assertSame('dd9b2b148bac14584f72b2920105f1e5ec0c5cf99992f6251b4be31297164e35', hash_file('sha256', $binary));
        [$records, , $options] = $this->collect(['app/Includes.php' => '<?php include "outside.php";']);
        $plan = ['files' => ['app/Includes.php' => 'fixture:'.$records[0]['sha256']], 'php_version' => '8.5'];
        $captureIdentity = ['fixture' => 'r03-old-store'];
        $directory = realpath(sys_get_temp_dir()).'/ak-r03-old-store-'.bin2hex(random_bytes(6));
        $capture = new CaptureStore($directory, $plan, $captureIdentity, $options->toArray(), static function (): void {});
        $capture->append($records[0]);
        $capture->commit();
        $old = new NativeGraphClient($binary, 268435456, 536870912);
        try {
            $this->assertNotContains('basic-graph-v1', $old->descriptor['capabilities']);
            $replay = new NativeReplay($old);
            $generation = $replay->load($directory, $captureIdentity, $this->identity);
            $this->assertSame($records, iterator_to_array($replay->records($generation, $this->identity)));
            try {
                new NativeBasic($old);
                $this->fail('Historical local store advertised a Basic product.');
            } catch (RuntimeException $error) {
                $this->assertSame('native_basic_capability', $error->getMessage());
            }
        } finally {
            $old->close();
        }
    }
}
