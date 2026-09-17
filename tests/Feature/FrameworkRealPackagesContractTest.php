<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Facade;
use Inertia\Inertia;
use Inertia\ResponseFactory;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

final class FrameworkRealPackagesContractTest extends TestCase
{
    public function test_installed_laravel_inertia_and_fortify_expose_the_modelled_contract(): void
    {
        $this->requireFrameworkPackages();

        $this->assertTrue(is_subclass_of(Inertia::class, Facade::class));
        $this->assertTrue(method_exists(ResponseFactory::class, 'render'));
        $this->assertTrue(method_exists(ResponseFactory::class, 'share'));
        $this->assertTrue(method_exists(Fortify::class, 'createUsersUsing'));
        $this->assertTrue(method_exists(Fortify::class, 'resetUserPasswordsUsing'));
        $this->assertTrue(method_exists(Fortify::class, 'authenticateThrough'));
        $this->assertTrue(method_exists(Features::class, 'canManageTwoFactorAuthentication'));
        $this->assertTrue(is_subclass_of(FormRequest::class, Request::class));
        $this->assertTrue(method_exists(JsonResource::class, 'resolve'));
    }

    public function test_real_laravel_route_context_drives_inertia_shared_resource_audit(): void
    {
        $this->requireFrameworkPackages();
        $this->withRoutes(<<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
Route::get('/shared', [\App\Http\Controllers\ProjectController::class, 'shared'])->middleware('inertia');
Route::get('/resource', [\App\Http\Controllers\ProjectController::class, 'resource']);
PHP);
        $this->write('bootstrap/app.php', <<<'PHP'
<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: dirname(__DIR__).'/routes/web.php')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['inertia' => \App\Http\Middleware\HandleInertiaRequests::class]);
    })
    ->withExceptions()
    ->create();
PHP);
        $this->write('app/Models/Project.php', '<?php namespace App\Models; final class Project extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Http/Middleware/HandleInertiaRequests.php', <<<'PHP'
<?php namespace App\Http\Middleware;
final class HandleInertiaRequests extends \Inertia\Middleware {
    public function share(\Illuminate\Http\Request $request): array {
        return ['shared' => fn () => \App\Models\Project::query()->update([])];
    }
}
PHP);
        $this->write('app/Http/Resources/ProjectResource.php', <<<'PHP'
<?php namespace App\Http\Resources;
final class ProjectResource extends \Illuminate\Http\Resources\Json\JsonResource {
    public function toArray($request): array { \App\Models\Project::query()->delete(); return []; }
}
PHP);
        $this->write('app/Http/Controllers/ProjectController.php', <<<'PHP'
<?php namespace App\Http\Controllers;
final class ProjectController {
    public function __construct() { throw new \RuntimeException('Route discovery must not instantiate controllers.'); }
    public function shared() { return \Inertia\Inertia::render('Projects/Shared'); }
    public function resource() { return \Inertia\Inertia::render('Projects/Resource', ['project' => \App\Http\Resources\ProjectResource::make([])]); }
}
PHP);

        $routes = RouteMap::fresh($this->tempPath);
        $this->assertNull($routes->unavailable);
        $this->assertSame('App\\Http\\Middleware\\HandleInertiaRequests', $routes->context['middlewareAliases']['inertia'] ?? null);

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers, Architecture::Actions],
            changedOnly: false,
            routes: $routes,
        );
        $findings = array_values(array_filter($result->findings, fn ($finding): bool => $finding->rule === 'thin-controller'));
        $messages = implode("\n", array_column($findings, 'message'));

        $this->assertNotContains('W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', array_column($findings, 'code'), $messages);
        $this->assertStringContainsString('HandleInertiaRequests.php', $messages);
        $this->assertStringContainsString('ProjectResource::toArray', $messages);
    }

    private function requireFrameworkPackages(): void
    {
        $required = getenv('ARCHITECTURE_KIT_FRAMEWORK_CONTRACT') === '1';
        if (! class_exists(Inertia::class) || ! class_exists(Fortify::class)) {
            if ($required) {
                $this->fail('The dedicated framework-contract job must install Inertia 3 and Fortify 1.');
            }

            $this->markTestSkipped('Real Inertia and Fortify contracts run in the dedicated Laravel 12/13 CI matrix.');
        }
    }

    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $source);
    }
}
