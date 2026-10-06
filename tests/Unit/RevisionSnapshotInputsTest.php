<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Impact\DataAnalysis;
use GracjanKubicki\ArchitectureKit\Impact\ExecutionSources;
use GracjanKubicki\ArchitectureKit\Impact\HttpRouteDiscovery;
use GracjanKubicki\ArchitectureKit\Revision\SnapshotInputs;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class RevisionSnapshotInputsTest extends TestCase
{
    public function test_virtual_inputs_never_fall_back_to_current_files(): void
    {
        $inputs = $this->snapshot(['app/Old.php' => '<?php class Old {}', 'vendor/Bad.php' => '<?php', 'bootstrap/cache/Bad.php' => '<?php']);
        $this->assertSame(['app/Old.php'], $inputs->listing(['app', 'vendor', 'bootstrap']));
        $this->assertSame([0, 0], $inputs->stat('app'));
        $this->assertSame([0, 18], $inputs->stat('app/Old.php'));
        foreach (['app/Current.php', '../app/Old.php', 'vendor/Bad.php', '/app/Old.php', 'bootstrap/cache/Bad.php'] as $path) {
            $this->assertNull($inputs->read($path));
        }
    }

    public function test_http_execution_and_data_use_frozen_sources_without_disk_or_execution(): void
    {
        $inputs = $this->snapshot([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"app/"}}}',
            'bootstrap/app.php' => '<?php use Illuminate\Foundation\Application; return Application::configure(basePath: dirname(__DIR__))->withRouting(web: __DIR__."/../routes/web.php");',
            'routes/web.php' => '<?php use Illuminate\Support\Facades\Route; Route::get("old", [App\Controller::class, "run"]);',
            'app/Controller.php' => '<?php namespace App; class Controller { public function run() { Job::dispatch(); return Service::rows(); } } throw new \RuntimeException("must never execute");',
            'app/Job.php' => '<?php namespace App; class Job { public function handle() { Service::rows(); } }',
            'app/Service.php' => '<?php namespace App; use Illuminate\Support\Facades\DB; class Service { public static function rows() { return DB::table("historic_orders")->get(); } }',
        ]);
        $files = $this->createMock(Filesystem::class);
        foreach (['get', 'isFile', 'size'] as $method) {
            $files->expects($this->never())->method($method);
        }
        $builder = new ProjectGraphBuilder(impact: true);
        foreach ($inputs->snapshot->files as $path => $source) {
            if (str_ends_with($path, '.php')) {
                $builder->add(new FileContext($path, $source));
            }
        }
        $graph = $builder->finish();
        $base = '/__revision_snapshot_test__';
        $http = new HttpRouteDiscovery($files, $base, true, $inputs);
        $routes = $http->discover($graph, []);
        $this->assertCount(1, $routes['routes']);
        $this->assertSame('/old', $routes['routes'][0]['uri']);
        $this->assertSame('App\Controller', $routes['routes'][0]['handler']['class']);
        $this->assertSame('declared', $routes['routes'][0]['certainty']);
        $this->assertTrue($http->freshness()['fresh']);
        $sources = new ExecutionSources($files, $base, snapshot: $inputs);
        $facts = $sources->discover($graph, []);
        $this->assertArrayHasKey('app\job', $facts['classes']);
        $this->assertNotEmpty($facts['operations']);
        $this->assertTrue($sources->fresh());
        $this->assertNull($sources->read('app/OnlyCurrent.php'));
        $data = DataAnalysis::collect($files, $base, $graph, [], $routes, $inputs);
        $this->assertContains('historic_orders', array_column($data->extractor->effects, 'table'));
        $this->assertNotEmpty($data->links->out);
    }

    /** @param array<string, string> $files */
    private function snapshot(array $files): SnapshotInputs
    {
        return new SnapshotInputs(new SourceSnapshot('git', str_repeat('a', 40), 'frozen', $files, [], array_keys($files)));
    }
}
