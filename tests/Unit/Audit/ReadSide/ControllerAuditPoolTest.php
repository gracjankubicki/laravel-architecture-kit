<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectSymbol;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\ControllerReadAudit;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Tests\Support\EngineFixture;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ControllerAuditPoolTest extends TestCase
{
    public function test_worker_sends_each_full_route_witness_once_and_restores_all_result_channels(): void
    {
        $files = new Filesystem;
        $home = $this->tempPath.'-witness-home';
        $files->put($this->tempPath.'/config/architectures.php', '<?php return '.var_export(['analysis' => ['engine' => EngineFixture::selected()], 'enabled' => []], true).';');
        if (EngineFixture::selected() === 'mago') {
            EngineFixture::install($home);
        }
        $files->ensureDirectoryExists($this->tempPath.'/app/Http/Controllers');
        $files->put($this->tempPath.'/app/Http/Controllers/Probe.php', '<?php namespace App\\Http\\Controllers; class Probe { public function index() { '.str_repeat("\\Vendor\\Unknown::handle();\n", 30).' } }');
        $context = new RepositoryContext(realpath($this->tempPath), $home);
        $graph = new ProjectGraphSnapshot([new ProjectSymbol('App\\Http\\Controllers\\Probe', 'app/Http/Controllers/Probe.php', 1, 'App\\Http\\Controllers', 'class', 'delivery')], []);
        $routes = new RouteMap(entries: [
            new RouteEntry(['GET'], 'first', class: 'App\\Http\\Controllers\\Probe', method: 'index', middleware: ['auth:web']),
            new RouteEntry(['POST'], 'second', class: 'App\\Http\\Controllers\\Probe', method: 'index', middleware: ['auth:api']),
        ], context: ['status' => 'known', 'providers' => [], 'middleware' => [], 'middlewareGroups' => [], 'middlewareAliases' => [], 'packageVersions' => []]);
        $request = ['root' => $context->rootPath, 'home' => $home, 'profile' => null, 'execute' => false, 'runtime_routes' => false,
            'symbols' => array_map(get_object_vars(...), $graph->symbols), 'controllers' => [0], 'selection' => [0], 'enabled' => [],
            'routes' => ['methods' => $routes->methods, 'unavailable' => null, 'entries' => array_map(static fn (RouteEntry $entry): array => $entry->toArray(), $routes->entries), 'context' => $routes->context, 'notices' => []],
            'facts' => null, 'initial' => true, 'fortify' => true];
        try {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', dirname(__DIR__, 4).'/src/Audit/ReadSide/controller-audit-worker.php', dirname(__DIR__, 4).'/vendor/autoload.php']);
            $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
            $process->setTimeout(30);
            self::assertSame(0, $process->run(), $process->getErrorOutput());
            $witnesses = [];
            $decoded = ['findings' => [], 'suggestions' => [], 'notices' => []];
            $references = 0;
            foreach (explode("\n", trim($process->getOutput())) as $line) {
                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (isset($row['done'])) {
                    $status = $row['done'];
                } elseif (! isset($row['channel'])) {
                    self::assertArrayNotHasKey($row['witness'], $witnesses);
                    self::assertSame(hash('sha256', json_encode($row['route'], JSON_THROW_ON_ERROR)), $row['witness']);
                    $witnesses[$row['witness']] = $row['route'];
                } else {
                    if ($row['witness'] !== null) {
                        self::assertArrayNotHasKey('route', $row['item']);
                        self::assertArrayHasKey($row['witness'], $witnesses);
                        $row['item']['route'] = $witnesses[$row['witness']];
                        $references++;
                    }
                    $decoded[$row['channel']][] = $row['item'];
                }
            }
            $serial = (new ControllerReadAudit($files, $context->rootPath))->analyzePartition($graph, [], $routes, [0], [0], true, true);
            self::assertCount(2, $witnesses);
            self::assertGreaterThan(20, $references);
            self::assertSame($serial->status, $status);
            foreach ($decoded as $channel => $items) {
                self::assertEquals(array_map(get_object_vars(...), $serial->$channel), $items, $channel);
            }
        } finally {
            $files->deleteDirectory($home);
        }
    }

    public function test_output_budget_failure_drains_workers_without_rethrowing_from_cleanup(): void
    {
        $files = new Filesystem;
        $home = $this->tempPath.'-pool-home';
        $files->put($this->tempPath.'/config/architectures.php', '<?php return '.var_export(['analysis' => ['engine' => EngineFixture::selected()], 'enabled' => []], true).';');
        if (EngineFixture::selected() === 'mago') {
            EngineFixture::install($home);
        }
        $files->ensureDirectoryExists($this->tempPath.'/app/Http/Controllers');
        $files->put($this->tempPath.'/app/Http/Controllers/Probe.php', '<?php namespace App\\Http\\Controllers; class Probe { public function index() { '.str_repeat("\\Vendor\\Unknown::handle();\n", 400).' } }');
        $program = <<<'PHP'
require $_SERVER['argv'][1];
$root = $_SERVER['argv'][2];
$context = new GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext($root, $_SERVER['argv'][3]);
GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime::activate($context->analysis());
$graph = new GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot([
    new GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectSymbol('App\Http\Controllers\Probe', 'app/Http/Controllers/Probe.php', 1, 'App\Http\Controllers', 'class', 'delivery'),
], []);
$entries = [];
for ($index = 0; $index < 1000; $index++) {
    $entries[] = new GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry(['GET'], $index.str_repeat('x', 100), class: 'App\Http\Controllers\Probe', method: 'index');
}
$routes = new GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap(entries: $entries,
    context: ['status'=>'known', 'providers'=>[], 'middleware'=>[], 'middlewareGroups'=>[], 'middlewareAliases'=>[], 'packageVersions'=>[]]);
$audit = new GracjanKubicki\ArchitectureKit\Audit\ReadSide\ControllerReadAudit(new Illuminate\Filesystem\Filesystem, $root);
class_exists(Symfony\Component\Process\Process::class);
class_exists(GracjanKubicki\ArchitectureKit\Audit\ReadSide\ControllerAuditPool::class);
class_exists(GracjanKubicki\ArchitectureKit\Audit\AnalysisNotice::class);
$padding = str_repeat('x', 100 * 1024 * 1024 - memory_get_usage(true));
$begin = memory_get_usage(true);
$result = GracjanKubicki\ArchitectureKit\Audit\ReadSide\ControllerAuditPool::collect($context, $audit, $graph, [], $routes, null);
echo json_encode(['fallback'=>$result===null, 'begin'=>$begin, 'peak'=>memory_get_peak_usage(true)], JSON_THROW_ON_ERROR);
PHP;
        try {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $program, '--', dirname(__DIR__, 4).'/vendor/autoload.php', realpath($this->tempPath), $home]);
            $process->setTimeout(30);
            self::assertSame(0, $process->run(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($result['fallback'], json_encode($result));
            self::assertGreaterThan($result['begin'] + 4_000_000, $result['peak']);
        } finally {
            $files->deleteDirectory($home);
        }
    }
}
