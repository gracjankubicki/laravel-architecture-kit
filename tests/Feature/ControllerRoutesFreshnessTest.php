<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\AuditChanged;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Guard;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class ControllerRoutesFreshnessTest extends TestCase
{
    public function test_fresh_boot_uses_edited_routes_and_preserves_the_existing_route_cache(): void
    {
        $files = new Filesystem;
        foreach (['bootstrap/cache', 'routes', 'storage/framework/views', 'storage/logs'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        symlink(dirname(__DIR__, 2).'/vendor', $this->tempPath.'/vendor');
        $files->put($this->tempPath.'/bootstrap/app.php', <<<'PHP'
<?php
return Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: dirname(__DIR__).'/routes/web.php')
    ->withExceptions()
    ->create();
PHP);
        $files->put($this->tempPath.'/bootstrap/cache/routes-v7.php', '<?php throw new LogicException("Stale cache must not be loaded or deleted.");');
        $cacheBefore = file_get_contents($this->tempPath.'/bootstrap/cache/routes-v7.php');
        $files->put($this->tempPath.'/routes/web.php', '<?php Illuminate\Support\Facades\Route::get("/planning", [\App\Http\Controllers\PlanningController::class, "show"]);');
        $enabled = [Architecture::ThinControllers, Architecture::Actions, Architecture::Services];
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->write($enabled);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath);
        foreach ([$resources->guideline($enabled), ...array_values($resources->skills($enabled))] as $resource) {
            $files->ensureDirectoryExists(dirname($resource->path));
            $files->put($resource->path, $resource->contents);
        }
        foreach (['app/Http/Controllers', 'app/Services'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/app/Services/ViewService.php', '<?php namespace App\Services; final class ViewService { public function load() { return 1; } }');
        $files->put($this->tempPath.'/app/Http/Controllers/PlanningController.php', '<?php namespace App\Http\Controllers; final class PlanningController { public function show(\App\Services\ViewService $view) { return $view->load(); } }');
        try {
            $first = RouteMap::fresh($this->tempPath);
            $this->assertNull($first->unavailable);
            $this->assertSame(['GET', 'HEAD'], $first->verbs('App\Http\Controllers\PlanningController', 'show'));
            $this->assertSame(0, Artisan::call('architecture-kit:audit', ['--strict' => true, '--agent' => true]));
            $this->assertSame(0, Artisan::call('architecture-kit:guard', ['--strict' => true, '--agent' => true]));
            ArchitectureKitServer::tool(AuditChanged::class, ['changed' => false])->assertOk()
                ->assertStructuredContent(fn ($json) => $json->where('warn', 0)->where('err', 0)->etc());
            $files->put($this->tempPath.'/routes/web.php', '<?php Illuminate\Support\Facades\Route::post("/planning", [\App\Http\Controllers\PlanningController::class, "show"]);');
            $second = RouteMap::fresh($this->tempPath);
            $this->assertNull($second->unavailable);
            $this->assertSame(['POST'], $second->verbs('App\Http\Controllers\PlanningController', 'show'));
            $this->assertSame(1, Artisan::call('architecture-kit:audit', ['--strict' => true, '--agent' => true]));
            $payload = json_decode(Artisan::output(), true);
            $this->assertSame('W_THIN_CONTROLLER_SERVICE_DEPENDENCY', $payload['find'][0]['m']);
            // Same MCP server/test process, fresh route knowledge on every call.
            ArchitectureKitServer::tool(AuditChanged::class, ['changed' => false])->assertOk()
                ->assertStructuredContent(fn ($json) => $json->where('find.0.m', 'W_THIN_CONTROLLER_SERVICE_DEPENDENCY')->etc());
            ArchitectureKitServer::tool(Guard::class, ['changed' => false, 'strict' => true])->assertOk()
                ->assertStructuredContent(fn ($json) => $json->where('ok', false)->where('find.0.m', 'W_THIN_CONTROLLER_SERVICE_DEPENDENCY')->etc());
            $this->assertSame($cacheBefore, file_get_contents($this->tempPath.'/bootstrap/cache/routes-v7.php'));
        } finally {
            unlink($this->tempPath.'/vendor');
        }
    }
}
