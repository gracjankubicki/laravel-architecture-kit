<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone;

use GracjanKubicki\ArchitectureKit\Standalone\GraphAdmissionRefused;
use GracjanKubicki\ArchitectureKit\Standalone\GraphProcessMemory;
use GracjanKubicki\ArchitectureKit\Standalone\GraphResources;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class GraphRuntimeFairnessTest extends TestCase
{
    public function test_admission_counters_distinguish_global_php_and_negative_reservations(): void
    {
        $limit = MemoryLimit::bytes();
        if ($limit === null) {
            self::markTestSkipped('This synthetic PHP budget boundary requires a finite runner limit.');
        }
        $base = dirname(__DIR__, 3).'/build/graph-performance-implementation/W04/resource-fixtures/'.bin2hex(random_bytes(6));
        $home = realpath(sys_get_temp_dir()).'/kit-w04-counters-'.basename($base);
        mkdir($base.'/.architecture-kit', 0700, true);
        file_put_contents($base.'/composer.json', '{"name":"fixture/counters"}');
        file_put_contents($base.'/.git', 'synthetic-root');
        file_put_contents($base.'/.architecture-kit/settings.json', '{"analysis":{"engine":"php"}}');
        $ini = ini_get('memory_limit');
        $resource = new GraphResources(new RepositoryContext($base, $home));
        GraphResources::setGlobal($home, 1000000, 1000000);
        $resource->controller('controller', ['bytes' => 11]);
        $resource->resident('worker', ['bytes' => 13, 'last_activity' => 1]);
        $reports = [];
        foreach (['global' => 1000001, 'php' => $limit, 'negative' => -1] as $kind => $candidate) {
            if ($kind === 'php') {
                GraphResources::setGlobal($home, PHP_INT_MAX, PHP_INT_MAX);
            }
            try {
                $resource->admit($candidate, 17, false);
                self::fail('The '.$kind.' reservation must be refused.');
            } catch (GraphAdmissionRefused $error) {
                $report = $reports[$kind] = $error->measurements;
                $this->assertSame('preparation_required:resident_budget', $error->getMessage());
                $this->assertSame($candidate, $report['candidate_bytes']);
                $this->assertSame(11, $report['retained_controller_bytes']);
                $this->assertSame(13, $report['retained_worker_bytes']);
                $this->assertSame(24, $report['global_retained_bytes']);
                $this->assertSame($candidate + 17 + 24, $report['global_condition']['counted_bytes']);
                $this->assertSame($report['php_allocated'] + $candidate, $report['php_condition']['counted_bytes']);
                $this->assertFalse($report['admitted']);
                $this->assertSame(match ($kind) {
                    'global' => ['global_budget'], 'php' => ['php_budget'], default => ['negative_candidate']
                }, $report['failed_conditions']);
                $this->assertSame($kind === 'global', $report['global_condition']['exceeded']);
                $this->assertSame($kind === 'php', $report['php_condition']['exceeded']);
            }
        }
        $reports['accepted'] = $resource->admit(10, 17, false);
        $this->assertTrue($reports['accepted']['admitted']);
        $this->assertSame([], $reports['accepted']['failed_conditions']);
        $this->assertSame($ini, ini_get('memory_limit'));
        $unknown = GraphProcessMemory::tree(-1);
        $this->assertNull($unknown['rss_bytes']);
        $this->assertSame('process_root_rss_unavailable', $unknown['reason']);
        file_put_contents($base.'/admission-counters.json', json_encode(['purpose' => 'Synthetic numeric reservations; no candidate-sized memory allocation', 'reports' => $reports], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        (new Filesystem)->deleteDirectory($home);
    }

    public function test_global_fifo_progress_and_lru_respect_an_active_lease(): void
    {
        $base = dirname(__DIR__, 3).'/build/graph-performance-implementation/W04/resource-fixtures/'.bin2hex(random_bytes(6));
        $home = realpath(sys_get_temp_dir()).'/kit-w04-resource-'.basename($base);
        foreach (['hot', 'cold', 'worktree'] as $name) {
            mkdir($base.'/'.$name.'/.architecture-kit', 0700, true);
            file_put_contents($base.'/'.$name.'/composer.json', '{"name":"fixture/'.$name.'"}');
            file_put_contents($base.'/'.$name.'/.git', 'synthetic-root');
            file_put_contents($base.'/'.$name.'/.architecture-kit/settings.json', '{"analysis":{"engine":"php"},"graph_runtime":{"resident_budget_bytes":100,"transition_budget_bytes":100}}');
        }
        $resources = [];
        foreach (['hot', 'cold', 'worktree'] as $name) {
            $resources[$name] = new GraphResources(new RepositoryContext($base.'/'.$name, $home));
        }
        $this->assertTrue($resources['hot']->enqueue('hot-1', 'hot', 'catalog'));
        $this->assertTrue($resources['cold']->enqueue('cold-1', 'cold', 'catalog'));
        $this->assertTrue($resources['hot']->claim('hot-1', 'hot-token'));
        $this->assertTrue($resources['hot']->enqueue('hot-2', 'hot', 'catalog'));
        $this->assertFalse($resources['hot']->claim('hot-2', 'hot-token'));
        $resources['hot']->release('hot-1');
        $this->assertFalse($resources['hot']->claim('hot-2', 'hot-token'));
        $this->assertTrue($resources['cold']->claim('cold-1', 'cold-token'));
        $resources['cold']->release('cold-1');
        $this->assertTrue($resources['hot']->claim('hot-2', 'hot-token'));
        $resources['hot']->release('hot-2');
        $resources['hot']->resident('old-token', ['bytes' => 60, 'last_activity' => 1, 'leases' => 1, 'repository' => 'hot']);
        $resources['cold']->resident('new-token', ['bytes' => 60, 'last_activity' => 2, 'leases' => 0, 'repository' => 'cold']);
        $this->assertSame('resident_budget_lru', $resources['worktree']->retirement('old-token'));
        $this->assertNull($resources['worktree']->retirement('new-token'));
        $registry = (new StateStore($home))->read('graph-resources-v2');
        $this->assertSame(1, $registry['residents']['old-token']['leases']);
        $this->assertTrue($registry['residents']['old-token']['retiring']);
        $this->assertSame(120, array_sum(array_column($registry['residents'], 'bytes')));
        file_put_contents($base.'/registry.json', json_encode($registry, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        (new Filesystem)->deleteDirectory($home);
    }

    public function test_explicit_global_administration_refreshes_existing_resources_without_changing_php_limit(): void
    {
        $base = dirname(__DIR__, 3).'/build/graph-performance-implementation/W04/resource-fixtures/'.bin2hex(random_bytes(6));
        $home = realpath(sys_get_temp_dir()).'/kit-w04-admin-'.basename($base);
        mkdir($base.'/.architecture-kit', 0700, true);
        file_put_contents($base.'/composer.json', '{"name":"fixture/admin"}');
        file_put_contents($base.'/.git', 'synthetic-root');
        file_put_contents($base.'/.architecture-kit/settings.json', '{"analysis":{"engine":"php"}}');
        $before = ini_get('memory_limit');
        $resource = new GraphResources(new RepositoryContext($base, $home));
        $initial = GraphResources::globalStatus($home);
        $this->assertSame('configured', $initial['status']);
        $this->assertSame('global_user_tool_home', $initial['scope']);
        GraphResources::setGlobal($home, 100000000, 120000000);
        $this->assertSame(100000000, $resource->effective()['resident_effective_bytes']);
        GraphResources::setGlobal($home, 200000000, 240000000);
        $this->assertSame(200000000, $resource->effective()['resident_effective_bytes']);
        $this->assertSame(240000000, $resource->effective()['transition_effective_bytes']);
        $this->assertSame($before, ini_get('memory_limit'));
        $this->assertSame('explicit_runtime_global_limits_set', GraphResources::globalStatus($home)['limits']['origin']);
        (new Filesystem)->deleteDirectory($home);
    }

    public function test_queue_ceiling_and_instance_lock_recovery_do_not_trust_pid(): void
    {
        $base = dirname(__DIR__, 3).'/build/graph-performance-implementation/W04/resource-fixtures/'.bin2hex(random_bytes(6));
        $home = realpath(sys_get_temp_dir()).'/kit-w04-resource-'.basename($base);
        mkdir($base.'/.architecture-kit', 0700, true);
        file_put_contents($base.'/composer.json', '{"name":"fixture/recovery"}');
        file_put_contents($base.'/.git', 'synthetic-root');
        file_put_contents($base.'/.architecture-kit/settings.json', '{"analysis":{"engine":"php"}}');
        $resources = new GraphResources(new RepositoryContext($base, $home));
        $store = new StateStore($home);
        $token = str_repeat('a', 64);
        $lock = fopen($home.'/controller-'.$token.'.lock', 'c');
        flock($lock, LOCK_EX);
        $owner = ['directory' => $home, 'token' => $token];
        for ($i = 0; $i < 64; $i++) {
            $this->assertTrue($resources->enqueue('ticket-'.$i, 'repo', 'catalog', $owner));
        }
        $this->assertFalse($resources->enqueue('overflow', 'repo', 'catalog', $owner));
        $this->assertTrue($resources->claim('ticket-0', 'builder-token'));
        $resources->recover();
        $this->assertSame('ticket-0', $store->read('graph-resources-v2')['builder']['ticket']);
        flock($lock, LOCK_UN);
        fclose($lock);
        $resources->recover();
        $this->assertNull($store->read('graph-resources-v2')['builder']);
        $this->assertSame([], $store->read('graph-resources-v2')['queue']);
        (new Filesystem)->deleteDirectory($home);
    }
}
