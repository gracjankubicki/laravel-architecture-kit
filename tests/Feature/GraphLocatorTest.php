<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CatalogState;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphSegmentStore;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use GracjanKubicki\ArchitectureKit\Context\GraphSource;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class GraphLocatorTest extends TestCase
{
    private function fixture(): Filesystem
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/A.php', '<?php namespace App; class A {}');
        $files->put($this->tempPath.'/config/architectures.php', '<?php return ["audit" => ["cache" => "storage/custom-graph"]];');

        return $files;
    }

    /** @return array<string, mixed> */
    private function worker(string $action, ?string $skip = null, int $exit = 0): array
    {
        $output = $this->tempPath.'/worker-'.bin2hex(random_bytes(4)).'.json';
        $command = [PHP_BINARY, '-d', 'memory_limit=1G', dirname(__DIR__).'/Fixtures/Graph/generation-worker.php', $this->tempPath, $action, $output];
        if ($skip !== null) {
            $command[] = $skip;
        }
        $process = new Process($command);
        $process->run();
        $this->assertSame($exit, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return json_decode((string) file_get_contents($output), true, 64, JSON_THROW_ON_ERROR);
    }

    public function test_custom_location_restores_without_parser_and_clear_removes_all_dedicated_products(): void
    {
        $files = $this->fixture();
        $first = $this->worker('build');
        $this->assertSame('success', $first['cache']['write']['status']);
        $cache = new ProjectGraphCache($files, $this->tempPath, 'storage/custom-graph');
        $this->assertDirectoryExists($cache->canonicalDirectory().'/catalog-ready-v1');
        $default = new ProjectGraphCache($files, $this->tempPath);
        $this->assertDirectoryDoesNotExist($default->canonicalDirectory().'/catalog-ready-v1');
        $this->assertDirectoryExists($default->canonicalDirectory().'/catalog-locator-v1');
        $warm = $this->worker('restore');
        $this->assertSame('hit', $warm['cache']['read']['status']);
        $this->assertSame($first['state'], $warm['state']);
        $this->assertGreaterThan(0, $cache->clear());
        $this->assertDirectoryDoesNotExist($cache->canonicalDirectory().'/catalog-ready-v1');
        $this->assertDirectoryDoesNotExist($default->canonicalDirectory().'/catalog-locator-v1');
        $this->assertSame('miss', $this->worker('build')['cache']['read']['status']);
    }

    public function test_invalid_ready_requires_fresh_process_and_retry_skips_only_rejected_generation(): void
    {
        $files = $this->fixture();
        $first = $this->worker('build');
        $cache = new ProjectGraphCache($files, $this->tempPath, 'storage/custom-graph');
        $store = new GraphSegmentStore($files, $cache->canonicalDirectory().'/catalog-ready-v1');
        $identity = SourceManifest::identity($this->tempPath, AnalysisMode::Basic);
        $valid = $store->read($identity, CatalogState::FIELDS);
        $invalid = $valid['state'];
        array_pop($invalid['names']);
        $bad = $store->write($identity, $invalid, $valid['metadata']);
        $attempt = $this->worker('attempt', exit: 75);
        $this->assertTrue($attempt['fresh_required']);
        $this->assertSame('ready', $attempt['product']);
        $this->assertSame($bad['generation'], $attempt['generation']);
        $this->assertSame([], $attempt['parses']);
        $this->assertSame([], $attempt['stages']);
        $retry = $this->worker('build', $attempt['generation']);
        $this->assertNotSame($attempt['pid'], $retry['pid']);
        $this->assertSame('rejected_generation', $retry['cache']['read']['reason']);
        $this->assertSame('success', $retry['cache']['write']['status']);
        $this->assertSame($first['state'], $retry['state']);
        // A concurrently published valid generation is not skipped or deleted by the old rejection token.
        $new = $store->write($identity, $valid['state'], $valid['metadata']);
        $restored = $this->worker('restore', $attempt['generation']);
        $this->assertSame($new['generation'], $restored['cache']['generation']);
        $this->assertSame($first['state'], $restored['state']);
    }

    public function test_locator_never_authorizes_a_traversal_directory_and_can_retry_without_a_loop(): void
    {
        $files = $this->fixture();
        (new GraphSource($files, $this->tempPath))->load();
        $default = new ProjectGraphCache($files, $this->tempPath);
        $locator = new GraphSegmentStore($files, $default->canonicalDirectory().'/catalog-locator-v1');
        $identity = SourceManifest::identity($this->tempPath, AnalysisMode::Basic);
        $old = $locator->read($identity, ['location']);
        $bad = $locator->write($identity, ['location' => ['directory' => '../escaped']], $old['metadata']);
        $attempt = $this->worker('attempt', exit: 75);
        $this->assertSame('locator_boundary', $attempt['reason']);
        $this->assertSame($bad['generation'], $attempt['generation']);
        $retry = $this->worker('build', $attempt['generation']);
        $this->assertSame('success', $retry['cache']['write']['status']);
        $this->assertSame('hit', $this->worker('restore')['cache']['read']['status']);
    }
}
