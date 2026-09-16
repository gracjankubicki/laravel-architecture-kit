<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;

final class MissingTestHttpTest extends TestCase
{
    private function fixture(string $test): RouteMap
    {
        $this->write('app/Http/Controllers/ProjectController.php', <<<'CODE'
<?php
namespace App\Http\Controllers;
class ProjectController {
    public function __construct(private \App\Actions\DeleteProject $unused) {}
    public function update(\App\Services\Projects $projects): void { $projects->update(); }
    public function destroy(\App\Services\Projects $projects): void { $projects->destroy(); }
}
CODE);
        $this->write('app/Services/Projects.php', <<<'CODE'
<?php
namespace App\Services;
class Projects {
    public function update(): void { (new \App\Actions\UpdateProject)->handle(); }
    public function destroy(): void { (new \App\Actions\DeleteProject)->handle(); }
}
CODE);
        foreach (['UpdateProject', 'DeleteProject', 'Calculator'] as $class) {
            $this->write('app/Actions/'.$class.'.php', '<?php namespace App\\Actions; class '.$class.' { public function handle(): int { return 42; } }');
        }
        $this->write('tests/TestCase.php', '<?php namespace Tests; abstract class TestCase extends \\Illuminate\\Foundation\\Testing\\TestCase {}');
        $this->write('tests/Feature/ProjectTest.php', $test);
        $container = new Container;
        $router = new Router(new Dispatcher($container), $container);
        $router->resource('projects', 'App\\Http\\Controllers\\ProjectController')->only(['update', 'destroy'])->register();
        $router->get('projects/{project}', ['App\\Http\\Controllers\\ProjectController', 'show'])->name('projects.show');

        return RouteMap::fromRoutes($router->getRoutes());
    }

    public function test_http_update_only_reaches_called_methods_even_in_an_intermediate_service(): void
    {
        $routes = $this->fixture(<<<'CODE'
<?php
namespace Tests\Feature;
class ProjectTest extends \Tests\TestCase {
    public function test_update(): void { $this->actingAs($user)->withHeaders([])->putJson('/projects/42', []); }
}
CODE);
        $result = $this->audit($routes);
        $this->assertSame(['app/Actions/Calculator.php', 'app/Actions/DeleteProject.php', 'app/Services/Projects.php'], array_column($result->findings, 'path'));
    }

    public function test_named_show_url_with_put_dispatches_update_not_show(): void
    {
        $routes = $this->fixture(<<<'CODE'
<?php
use function Pest\Laravel\putJson as updateJson;
it('updates', function () { updateJson(route('projects.show', ['project' => 42]), []); });
CODE);
        $this->assertSame(['app/Actions/Calculator.php', 'app/Actions/DeleteProject.php', 'app/Services/Projects.php'], array_column($this->audit($routes)->findings, 'path'));
    }

    public function test_symbolic_identifier_and_local_literal_prefix_are_supported(): void
    {
        $routes = $this->fixture(<<<'CODE'
<?php
it('updates', function () { $prefix = '/projects/'; $this->put($prefix.$project->id, []); });
CODE);
        $this->assertSame(['app/Actions/Calculator.php', 'app/Actions/DeleteProject.php', 'app/Services/Projects.php'], array_column($this->audit($routes)->findings, 'path'));
    }

    public function test_dynamic_url_reports_source_without_hiding_unrelated_classes(): void
    {
        $routes = $this->fixture("<?php\nit('updates', function () { \$this->put(\$url, []); });");
        $result = $this->audit($routes);
        $incomplete = array_values(array_filter($result->findings, fn ($finding) => $finding->code === 'W_MISSING_TEST_ANALYSIS_INCOMPLETE'));
        $this->assertCount(1, $incomplete);
        $this->assertSame('tests/Feature/ProjectTest.php', $incomplete[0]->path);
        $this->assertSame(2, $incomplete[0]->line);
        $this->assertContains('app/Actions/Calculator.php', array_column($result->findings, 'path'));
        $this->assertContains('app/Actions/UpdateProject.php', array_column($result->findings, 'path'));
    }

    public function test_dynamic_method_reports_incomplete_without_credit(): void
    {
        $routes = $this->fixture('<?php it("updates", function () { $this->{$verb}("/projects/1"); });');
        $result = $this->audit($routes);
        $this->assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($result->findings, 'code'));
        $this->assertContains('app/Actions/UpdateProject.php', array_column($result->findings, 'path'));
    }

    public function test_legacy_map_and_route_only_do_not_credit_http_dispatch(): void
    {
        $this->fixture("<?php it('updates', function () { \$this->put('/projects/42'); });");
        $this->assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($this->audit(new RouteMap)->findings, 'code'));
        $routes = $this->fixture('<?php // no request');
        $this->assertContains('app/Actions/UpdateProject.php', array_column($this->audit($routes)->findings, 'path'));
    }

    public function test_unrelated_get_and_phpunit_only_this_are_not_laravel_dispatch(): void
    {
        $routes = $this->fixture(<<<'CODE'
<?php
namespace Tests\Feature;
class ProjectTest extends \PHPUnit\Framework\TestCase {
    public function test_update(): void { $this->put('/projects/42'); $client->get('/projects/42'); get('/projects/42'); }
}
CODE);
        $findings = $this->audit($routes)->findings;
        $this->assertNotContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($findings, 'code'));
        $this->assertContains('app/Actions/UpdateProject.php', array_column($findings, 'path'));
    }

    public function test_incomplete_supports_inline_suppression(): void
    {
        $routes = $this->fixture(<<<'CODE'
<?php
it('updates', function () {
    // @architecture-kit-ignore missing-test
    $this->put($url);
});
CODE);
        $this->assertNotContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($this->audit($routes)->findings, 'code'));
    }

    private function audit(RouteMap $routes): ApplicationAuditResult
    {
        return (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false, scope: new AuditScope(['app', 'tests']), missingTestLevel: MissingTestLevel::Warn, routes: $routes);
    }

    private function write(string $path, string $contents): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $contents);
    }
}
