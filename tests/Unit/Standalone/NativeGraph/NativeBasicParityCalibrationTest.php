<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureStore;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeBasic;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeGraphClient;
use Symfony\Component\Process\Process;

/** One bounded shaped fixture. It does not measure or authorize CRM capacity. */
final class NativeBasicParityCalibrationTest extends NativeBasicFixture
{
    public function test_shaped_fixture_phase_accounting_patch_old_lease_queries_and_restore(): void
    {
        $base = realpath(sys_get_temp_dir()).'/ak-r03-measure-'.bin2hex(random_bytes(6));
        mkdir($base, 0700);
        $sampler = new Process([PHP_BINARY, dirname(__DIR__, 3).'/Support/native-graph-sampler.php', (string) getmypid(), $base.'/rss.ndjson', $base.'/observer.stop'], timeout: 30);
        $sampler->start();
        $files = [];
        for ($i = 0; $i < 12; $i++) {
            $parent = 'Node'.(($i + 11) % 12);
            $ancestor = 'act'.(($i + 11) % 12);
            $files['app/Domain/Node'.$i.'.php'] = '<?php namespace Synthetic; interface Contract'.$i.' {public function act'.$i.'();} trait Work'.$i.' {public function act'.$i.'(){}} class Node'.$i.' implements Contract'.$i.' {use Work'.$i.'; public function __construct(public '.$parent.' $parent){} public function run'.$i.'(){ $this->parent->'.$ancestor.'(); $this->parent->parent->parent->'.$ancestor.'(); $this->act'.$i.'();}} function entry'.$i.'(Node'.$i.' $node){$node->run'.$i.'();}';
        }
        $result = [];
        try {
            [$old, $oracle] = $this->load($files);
            $oldIdentity = $this->identity;
            $lease = $this->client->request(['op' => 'pin', 'generation' => $old, 'identity' => $oldIdentity])['lease'];
            $result['full'] = $this->client->request(['op' => 'status']);
            $this->assertNotEmpty($oracle->descriptorReceivers);
            $files['app/Domain/Node3.php'] = str_replace('public function act3(){}', 'public function act3(){ $unused = 33; }', $files['app/Domain/Node3.php']);
            [$generation, $oracle] = $this->patch($old, $files, ['app/Domain/Node3.php']);
            $result['patch_old_lease'] = $this->client->request(['op' => 'status']);
            $this->client->request(['op' => 'release', 'lease' => $lease]);
            $result['after_release'] = $this->client->request(['op' => 'status']);
            $this->assertLessThan($result['patch_old_lease']['owned_capacity_bytes'], $result['after_release']['owned_capacity_bytes']);
            $times = [];
            foreach ([['type' => 'search', 'query' => 'Node3'], ['type' => 'context', 'subject' => 'Synthetic\\Node3::run3'], ['type' => 'impact', 'subject' => 'Synthetic\\Node3']] as $query) {
                $start = hrtime(true);
                $answer = $this->basic->query($generation, $this->identity, $query);
                $times[] = ['query' => $query, 'wall_ns' => hrtime(true) - $start, 'status' => $answer['status']];
            }
            $result['ready_queries'] = $times;
            mkdir($base.'/cache', 0700);
            $pin = $this->client->request(['op' => 'pin', 'generation' => $generation, 'identity' => $this->identity])['lease'];
            $start = hrtime(true);
            $write = $this->client->request(['op' => 'snapshot', 'generation' => $generation, 'identity' => $this->identity, 'lease' => $pin, 'directory' => $base.'/cache']);
            $result['snapshot_layout'] = $write['basic_snapshot_layout'];
            $result['write_client_wall_ns'] = hrtime(true) - $start;
            $result['snapshot'] = $this->client->request(['op' => 'status']);
            $disk = ['files' => 0, 'bytes' => 0];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base.'/cache', \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $disk['files']++;
                    $disk['bytes'] += $file->getSize();
                }
            }
            $result['snapshot_disk'] = $disk;
            $this->client->close();
            AnalysisRuntime::activate(null);
            $this->client = new NativeGraphClient(getenv('ARCHITECTURE_KIT_TEST_GRAPH_RUNTIME'), 268435456, 536870912);
            $this->basic = new NativeBasic($this->client);
            $start = hrtime(true);
            $restored = $this->basic->restore($base.'/cache', $this->identity);
            $result['restore_client_wall_ns'] = hrtime(true) - $start;
            $this->assertSame($generation, $restored['generation']);
            $result['restored'] = $this->client->request(['op' => 'status']);
            $this->assertAccounting($generation);
            $this->assertSame($oracle->exportState(), $this->basic->state($generation, $this->identity));
            foreach (['compose', 'foundation', 'resolve', 'dependency', 'search', 'search_patch'] as $phase) {
                $this->assertArrayNotHasKey($phase, $result['restored']['phase_measurements']['phases']);
            }
            foreach (['decode', 'append', 'validate', 'compose', 'foundation', 'resolve', 'dependency', 'search', 'query', 'patch', 'cache_write'] as $phase) {
                $this->assertGreaterThan(0, $result['snapshot']['phase_measurements']['phases'][$phase]['wall_ns'], $phase);
                $this->assertNotNull($result['snapshot']['phase_measurements']['phases'][$phase]['cpu_user_us'], $phase);
            }
            foreach (['restore', 'cache_read'] as $phase) {
                $this->assertGreaterThan(0, $result['restored']['phase_measurements']['phases'][$phase]['wall_ns']);
            }
            $result['fixture'] = ['seed' => 'r03-shaped-v1-12', 'sources' => count($files), 'source_bytes' => array_sum(array_map('strlen', $files)), 'hashes' => array_map(fn ($s) => hash('sha256', $s), $files), 'counts' => array_map('count', $oracle->exportState())];
            $result['limits'] = ['resident_bytes' => 268435456, 'transition_bytes' => 536870912, 'php_memory_limit' => ini_get('memory_limit')];
            $result['measurement_scope'] = 'small synthetic fixture, client post-handshake timings; not CLI/MCP thresholds or CRM capacity';
            $result['instrumentation'] = 'bounded phase aggregates and 50ms observer are included, not subtracted; short phases may fall between samples';
            $result['raw_rss_samples'] = $base.'/rss.ndjson';
            $result['php_endpoint'] = ['used_bytes' => memory_get_usage(), 'allocated_bytes' => memory_get_usage(true), 'lifetime_peak_allocated_bytes' => memory_get_peak_usage(true)];
            $output = getenv('ARCHITECTURE_KIT_NATIVE_TEST_ARTIFACTS') ?: dirname(__DIR__, 4).'/build/graph-performance-rust/R03';
            if (! is_dir($output)) {
                mkdir($output, 0700, true);
            }
            CaptureStore::writePrivate($output.'/calibration-'.($this->identity['parser']).'-'.basename($base).'.json', TypedValue::json($result));
        } finally {
            CaptureStore::writePrivate($base.'/observer.stop', 'stop');
            $sampler->wait();
            $this->assertSame(0, $sampler->getExitCode(), $sampler->getErrorOutput());
        }
    }
}
