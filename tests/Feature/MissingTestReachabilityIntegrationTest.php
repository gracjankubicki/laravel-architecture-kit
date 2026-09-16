<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\TestReachability;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

final class MissingTestReachabilityIntegrationTest extends TestCase
{
    public function test_real_endpoint_asserts_the_write_and_static_audit_links_the_same_sources(): void
    {
        $this->app['config']->set('database.default', 'testing');
        $this->app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->app['db']->connection()->getSchemaBuilder()->create('gh13_projects', function ($table) {
            $table->increments('id');
            $table->string('name');
        });
        $this->app['db']->table('gh13_projects')->insert(['id' => 1, 'name' => 'before']);
        $this->write('app/Actions/Gh13Update.php', <<<'CODE'
<?php
namespace Gh13Fixture;
class Gh13Update {
    public function handle(): void { \Illuminate\Support\Facades\DB::table('gh13_projects')->where('id', 1)->update(['name' => 'after']); }
}
CODE);
        $this->write('app/Http/Controllers/Gh13Controller.php', <<<'CODE'
<?php
namespace Gh13Fixture;
class Gh13Controller {
    public function update(Gh13Update $action) { $action->handle(); return response()->json(['ok' => true]); }
}
CODE);
        require $this->tempPath.'/app/Actions/Gh13Update.php';
        require $this->tempPath.'/app/Http/Controllers/Gh13Controller.php';
        $this->app['router']->put('gh13-projects/1', ['Gh13Fixture\\Gh13Controller', 'update']);
        $this->putJson('/gh13-projects/1')->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('after', $this->app['db']->table('gh13_projects')->where('id', 1)->value('name'));
        $this->write('tests/Feature/ProjectTest.php', "<?php it('updates', function () { \$this->putJson('/gh13-projects/1')->assertOk(); });");
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false, missingTestLevel: MissingTestLevel::Warn, routes: RouteMap::fromRoutes($this->app['router']->getRoutes()));
        $this->assertSame([], $result->findings);
    }

    public function test_cache_metadata_round_trip_and_fresh_route_edit_preserve_full_findings(): void
    {
        $this->write('app/Http/Controllers/ProjectController.php', '<?php namespace App\\Http\\Controllers; class ProjectController { public function update(): void {} public function destroy(): void {} }');
        $this->write('app/Services/DisabledService.php', '<?php namespace App\\Services; class DisabledService {}');
        $this->write('tests/Feature/ProjectTest.php', "<?php it('updates', function () { \$this->put('/projects/1'); });");
        $this->withRoutes("<?php \\Illuminate\\Support\\Facades\\Route::put('/projects/{project}', ['App\\Http\\Controllers\\ProjectController', 'update']);");
        $files = new Filesystem;
        $cache = new ProjectGraphCache($files, $this->tempPath);
        $audit = new ApplicationAudit($files, $this->tempPath);
        $run = fn ($cache) => $audit->run([], false, missingTestLevel: MissingTestLevel::Warn, cache: $cache);
        $disabled = $run(null);
        $cold = $run($cache);
        $warm = $run($cache);
        $this->assertNotSame([], $disabled->findings, 'Known unrelated finding anchors parity.');
        $this->assertEquals($disabled->findings, $cold->findings);
        $this->assertEquals($cold->findings, $warm->findings);
        $loader = new ProjectGraphLoader($files, $this->tempPath, new AuditScope(['app', 'tests']), $cache);
        $plan = $loader->plan();
        $this->assertSame([], $plan->toParse);
        $this->assertCount(1, $plan->reusable['tests/Feature/ProjectTest.php']->testInvocations);
        $this->write('routes/web.php', '<?php // route removed');
        $changed = $run($cache);
        $this->assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($changed->findings, 'code'));
        $this->assertEquals($run(null)->findings, $changed->findings);
    }

    public function test_repeated_endpoint_is_memoized_and_off_keeps_unrelated_findings(): void
    {
        $this->write('app/Http/Controllers/ProjectController.php', '<?php namespace App\\Http\\Controllers; class ProjectController { public function update(): void {} }');
        $this->write('app/Services/DisabledService.php', '<?php namespace App\\Services; class DisabledService {}');
        $files = new Filesystem;
        $before = (new ApplicationAudit($files, $this->tempPath))->run([], false, scope: new AuditScope(['app', 'tests']));
        $this->write('tests/Feature/ProjectTest.php', "<?php it('updates', function () {".str_repeat("\$this->put('/projects/1');", 500).'});');
        $after = (new ApplicationAudit($files, $this->tempPath))->run([], false, scope: new AuditScope(['app', 'tests']));
        $this->assertNotSame([], $before->findings);
        $this->assertEquals($before->findings, $after->findings);
        $graph = (new ProjectGraphLoader($files, $this->tempPath, new AuditScope(['app', 'tests'])))->load();
        $reads = new class extends Filesystem
        {
            public array $reads = [];

            public function get($path, $lock = false)
            {
                $this->reads[] = $path;

                return parent::get($path, $lock);
            }
        };
        $routes = new RouteMap(entries: [new RouteEntry(['PUT'], 'projects/{project}', class: 'App\\Http\\Controllers\\ProjectController', method: 'update')]);
        $result = (new TestReachability($reads, $this->tempPath))->analyze($graph, $routes);
        $this->assertArrayHasKey('app\\http\\controllers\\projectcontroller', $result->symbols);
        $this->assertCount(1, array_filter($reads->reads, fn ($path) => str_ends_with($path, 'ProjectController.php')));
        $this->assertSame([], $result->diagnostics);
    }

    public function test_changed_audit_restores_test_facts_without_reading_the_test_file(): void
    {
        $this->write('app/Http/Controllers/ProjectController.php', '<?php namespace App\\Http\\Controllers; class ProjectController { public function update(): void {} }');
        $this->write('tests/Feature/ProjectTest.php', "<?php it('updates', function () { \$this->put('/projects/1'); });");
        foreach ([['git', 'init', '-q'], ['git', 'add', 'app', 'tests'], ['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-qm', 'fixture']] as $command) {
            // This Git repository exists only inside the disposable test directory.
            (new Process($command, $this->tempPath))->mustRun();
        }
        $routes = new RouteMap(entries: [new RouteEntry(['PUT'], 'projects/{project}', class: 'App\\Http\\Controllers\\ProjectController', method: 'update')]);
        $cache = new ProjectGraphCache(new Filesystem, $this->tempPath);
        (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false, missingTestLevel: MissingTestLevel::Warn, cache: $cache, routes: $routes);
        $this->write('app/Http/Controllers/ProjectController.php', '<?php namespace App\\Http\\Controllers; class ProjectController { public function update(): void { /* edited */ } }');
        clearstatcache();
        $files = new class extends Filesystem
        {
            public array $reads = [];

            public function get($path, $lock = false)
            {
                $this->reads[] = $path;

                return parent::get($path, $lock);
            }
        };
        $warm = (new ApplicationAudit($files, $this->tempPath))->run([], true, missingTestLevel: MissingTestLevel::Warn, cache: $cache, routes: $routes);
        $this->assertSame([], $warm->findings);
        $this->assertNotContains($this->tempPath.'/tests/Feature/ProjectTest.php', $files->reads);
        $disabled = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], true, missingTestLevel: MissingTestLevel::Warn, routes: $routes);
        $this->assertEquals($disabled->findings, $warm->findings);
    }

    public function test_changed_test_keeps_unchanged_handler_diagnostics_and_blocks_strict_guard(): void
    {
        $this->write('app/Http/Controllers/ProjectController.php', '<?php namespace App\\Http\\Controllers; class ProjectController { public function update(): void { $unknown->run(); } }');
        $this->write('tests/Feature/ProjectTest.php', "<?php it('updates', function () { \$this->put('/projects/1'); });");
        foreach ([['git', 'init', '-q'], ['git', 'add', 'app', 'tests'], ['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-qm', 'fixture']] as $command) {
            (new Process($command, $this->tempPath))->mustRun();
        }
        $this->withRoutes("<?php \\Illuminate\\Support\\Facades\\Route::put('/projects/{project}', ['App\\Http\\Controllers\\ProjectController', 'update']);");
        $this->write('config/architectures.php', "<?php return ['enabled' => ['actions'], 'audit' => ['missing_test' => 'warn']];");
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath, new Filesystem);
        $enabled = [Architecture::Actions];
        foreach ([$resources->guideline($enabled), ...array_values($resources->skills($enabled))] as $generated) {
            $this->write(substr($generated->path, strlen($this->tempPath) + 1), $generated->contents);
        }
        $cache = new ProjectGraphCache(new Filesystem, $this->tempPath);
        (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false, missingTestLevel: MissingTestLevel::Warn, cache: $cache);
        file_put_contents($this->tempPath.'/tests/Feature/ProjectTest.php', "\n// changed test only", FILE_APPEND);
        clearstatcache();
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], true, missingTestLevel: MissingTestLevel::Warn, cache: $cache);
        $this->assertCount(1, $result->findings);
        $this->assertSame('W_MISSING_TEST_ANALYSIS_INCOMPLETE', $result->findings[0]->code);
        $this->assertSame('app/Http/Controllers/ProjectController.php', $result->findings[0]->path);
        $this->assertSame(1, Artisan::call('architecture-kit:guard', ['--changed' => true, '--strict' => true, '--agent' => true]));
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $payload['doctor']);
        $this->assertSame('W_MISSING_TEST_ANALYSIS_INCOMPLETE', $payload['find'][0]['m']);
    }

    public function test_changed_handler_keeps_diagnostic_from_unchanged_called_service(): void
    {
        $this->write('app/Http/Controllers/ProjectController.php', '<?php namespace App\\Http\\Controllers; class ProjectController { public function update(\\App\\Support\\Worker $worker): void { $worker->run(); } }');
        $this->write('app/Support/Worker.php', '<?php namespace App\\Support; class Worker { public function run(): void { $dynamic->call(); } }');
        $this->write('tests/Feature/ProjectTest.php', "<?php it('updates', function () { \$this->put('/projects/1'); });");
        foreach ([['git', 'init', '-q'], ['git', 'add', 'app', 'tests'], ['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-qm', 'fixture']] as $command) {
            (new Process($command, $this->tempPath))->mustRun();
        }
        file_put_contents($this->tempPath.'/app/Http/Controllers/ProjectController.php', "\n// changed handler only", FILE_APPEND);
        $routes = new RouteMap(entries: [new RouteEntry(['PUT'], 'projects/{id}', class: 'App\\Http\\Controllers\\ProjectController', method: 'update')]);
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], true, missingTestLevel: MissingTestLevel::Warn, routes: $routes);
        $this->assertCount(1, $result->findings);
        $this->assertSame('W_MISSING_TEST_ANALYSIS_INCOMPLETE', $result->findings[0]->code);
        $this->assertSame('app/Support/Worker.php', $result->findings[0]->path);
    }

    public function test_plain_phpunit_http_named_methods_do_not_boot_laravel_routes(): void
    {
        $this->write('tests/Feature/PlainTest.php', '<?php class PlainTest extends \\PHPUnit\\Framework\\TestCase { public function test_call(): void { $this->get("/anything"); } }');
        $this->withRoutes("<?php file_put_contents(__DIR__.'/booted', 'yes');");
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false, missingTestLevel: MissingTestLevel::Warn);
        $this->assertSame([], $result->findings);
        $this->assertFileDoesNotExist($this->tempPath.'/routes/booted');
    }

    private function write(string $path, string $contents): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $contents);
    }
}
