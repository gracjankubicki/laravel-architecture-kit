<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisLimitReason;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\GraphBuildEligibility;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CatalogState;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphSegmentStore;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Context\GraphSource;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class GraphEligibilityTest extends TestCase
{
    private function fixture(): Filesystem
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/A.php', '<?php namespace App; class A {}');
        $files->put($this->tempPath.'/config/architectures.php', '<?php return ["audit" => ["cache" => true]];');

        return $files;
    }

    public function test_nested_and_sequential_scopes_are_isolated_including_exceptions(): void
    {
        $outer = GraphBuildEligibility::begin();
        GraphBuildEligibility::report(AnalysisLimitReason::Structure);
        $inner = GraphBuildEligibility::begin();
        GraphBuildEligibility::report(AnalysisLimitReason::Memory);
        $this->assertFalse(GraphBuildEligibility::current()['cacheable']);
        $inner->close(false);
        $this->assertTrue(GraphBuildEligibility::current()['cacheable']);
        $outer->close();
        $next = GraphBuildEligibility::begin();
        $this->assertSame([], GraphBuildEligibility::current()['reasons']);
        $next->close();
        $files = $this->fixture();
        try {
            (new GraphSource($files, $this->tempPath, static function (): void {
                GraphBuildEligibility::report(AnalysisLimitReason::Memory);
                throw new \RuntimeException('controlled failure');
            }))->load();
            $this->fail('Expected controlled failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('controlled failure', $exception->getMessage());
        }
        $this->assertSame(['v' => 1, 'cacheable' => false, 'reasons' => ['unknown']], GraphBuildEligibility::current());
        $this->assertSame('success', (new GraphSource($files, $this->tempPath))->load()['cache']['write']['status']);
    }

    public function test_fixed_source_size_limits_have_positive_fresh_process_cache_roundtrip(): void
    {
        $files = $this->fixture();
        $files->put($this->tempPath.'/app/Large.php', '<?php '.str_repeat(' ', 100001));
        $this->assertSame(AnalysisLimitReason::Structure, ImpactExtractor::sourceLimitReason(100001));
        $this->assertSame(AnalysisLimitReason::Structure, ComposerCatalogExtractor::sourceLimitReason(4000001));
        $first = (new GraphSource($files, $this->tempPath))->load();
        $this->assertSame('success', $first['cache']['write']['status']);
        $diagnostics = $first['index']->diagnostics;
        $this->assertContains('source_limit', array_column($diagnostics, 'code'));
        $this->assertFalse($first['analysis']['checks_complete']);
        $output = $this->tempPath.'/restored.json';
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', dirname(__DIR__).'/Fixtures/Graph/generation-worker.php', $this->tempPath, 'restore', $output]);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $restored = json_decode((string) file_get_contents($output), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame($diagnostics, $restored['state']['diagnostics']);
        $this->assertSame('hit', $restored['cache']['read']['status']);
        $this->assertSame($first['index']->exportState(), $restored['state']);
        // A later update also reuses the fixed partial contribution rather than discarding it.
        $files->put($this->tempPath.'/app/B.php', '<?php namespace App; class B {}');
        $parses = [];
        $updated = (new GraphSource($files, $this->tempPath, static function (string $path) use (&$parses): void {
            $parses[] = $path;
        }))->load();
        $this->assertSame('hit', $updated['cache']['contributions_read']['status']);
        $this->assertNotContains('app/Large.php', $parses);
        $this->assertSame('success', $updated['cache']['write']['status']);
    }

    public function test_global_memory_or_unknown_does_not_persist_ready_but_preserves_local_contributions(): void
    {
        $files = $this->fixture();
        foreach ([AnalysisLimitReason::Memory, AnalysisLimitReason::Unknown] as $reason) {
            AnalysisRuntime::activate(AnalysisRuntime::session()->withStageObserver(static function (string $stage) use ($reason): void {
                if ($stage === 'catalog.compose') {
                    GraphBuildEligibility::report($reason);
                }
            }));
            $partial = (new GraphSource($files, $this->tempPath))->load();
            $this->assertSame('skip', $partial['cache']['write']['status']);
            $this->assertSame('analysis_'.$reason->value, $partial['cache']['write']['reason']);
            $this->assertContains($partial['cache']['contributions_write']['status'], ['success', 'skip']);
            AnalysisRuntime::activate(AnalysisRuntime::session()->withStageObserver(null));
            $clean = (new GraphSource($files, $this->tempPath))->load();
            $this->assertSame($partial['index']->exportState(), $clean['index']->exportState());
            $this->assertSame('hit', $clean['cache']['contributions_read']['status']);
            $this->assertSame('success', $clean['cache']['write']['status']);
            (new ProjectGraphCache($files, $this->tempPath))->clear();
        }
    }

    public function test_real_memory_denial_skips_contribution_io_and_legacy_probes_do_not_start_runtime(): void
    {
        foreach (['outside', 'memory'] as $action) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=64M', dirname(__DIR__).'/Fixtures/Graph/eligibility-worker.php', $action, (string) realpath($this->tempPath).'/memory-store']);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            if ($action === 'outside') {
                $this->assertFalse($result['scope_loaded']);
                $this->assertFalse($result['session_loaded']);
                $this->assertSame([], $result['autoload']);
            } else {
                $this->assertSame('memory', $result['impact']);
                $this->assertSame('memory', $result['composer']);
                $this->assertSame(['status' => 'skip', 'reason' => 'analysis_memory'], $result['write']);
                $this->assertFalse($result['io_directory_exists']);
            }
        }
    }

    public function test_local_memory_and_unknown_are_not_contribution_hits_and_do_not_change_public_facts(): void
    {
        $files = $this->fixture();
        foreach ([AnalysisLimitReason::Memory, AnalysisLimitReason::Unknown] as $reason) {
            $partial = (new GraphSource($files, $this->tempPath, static function (string $path) use ($reason): void {
                if ($path === 'app/A.php') {
                    GraphBuildEligibility::report($reason);
                }
            }))->load();
            $this->assertSame('analysis_'.$reason->value, $partial['cache']['contributions_write']['reason']);
            $this->assertSame('analysis_'.$reason->value, $partial['cache']['write']['reason']);
            $clean = (new GraphSource($files, $this->tempPath))->load();
            $this->assertSame('miss', $clean['cache']['contributions_read']['status']);
            $this->assertSame($partial['index']->exportState(), $clean['index']->exportState());
            (new ProjectGraphCache($files, $this->tempPath))->clear();
        }
    }

    public function test_reader_checks_private_eligibility_before_hydrating_ready_state(): void
    {
        $files = $this->fixture();
        $first = (new GraphSource($files, $this->tempPath))->load();
        $cache = new ProjectGraphCache($files, $this->tempPath);
        $store = new GraphSegmentStore($files, $cache->canonicalDirectory().'/catalog-ready-v1');
        $identity = SourceManifest::identity($this->tempPath, AnalysisMode::Basic);
        foreach (['memory', 'unknown'] as $cause) {
            $stored = $store->read($identity, CatalogState::FIELDS);
            $metadata = $stored['metadata'];
            $metadata['eligibility'] = ['v' => 1, 'cacheable' => false, 'reasons' => [$cause]];
            $store->write($identity, $stored['state'], $metadata);
            $recomposed = (new GraphSource($files, $this->tempPath))->load();
            $this->assertSame('analysis_'.$cause, $recomposed['cache']['read']['reason']);
            $this->assertSame('hit', $recomposed['cache']['contributions_read']['status']);
            $this->assertSame($first['index']->exportState(), $recomposed['index']->exportState());
        }
        $this->assertSame('eligibility_missing_or_invalid', GraphBuildEligibility::rejection(['v' => 1, 'cacheable' => true, 'reasons' => ['memory']]));
        $this->assertSame('eligibility_missing_or_invalid', GraphBuildEligibility::rejection(null));
    }
}
