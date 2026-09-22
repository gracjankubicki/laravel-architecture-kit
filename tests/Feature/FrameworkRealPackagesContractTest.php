<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Facade;
use Inertia\Inertia;
use Inertia\ResponseFactory;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use ReflectionClass;

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

    public function test_real_runtime_and_analyzer_serialization_contracts_are_aligned(): void
    {
        $this->requireFrameworkPackages();

        $reflection = new ReflectionClass(Connection::class);
        $this->assertFalse($reflection->hasMethod('serialize'));
        $this->assertFalse($reflection->hasMethod('unserialize'));

        $probe = new SerializationContractProbe;
        $serialized = serialize($probe);
        $this->assertSame(1, $probe->serializeCalls);
        $restored = unserialize($serialized);
        $this->assertInstanceOf(SerializationContractProbe::class, $restored);
        $this->assertSame(1, $restored->unserializeCalls);

        $legacy = new LegacySerializationProbe;
        $legacySerialized = serialize($legacy);
        $this->assertSame(1, $legacy->sleepCalls);
        $legacyRestored = unserialize($legacySerialized);
        $this->assertInstanceOf(LegacySerializationProbe::class, $legacyRestored);
        $this->assertSame(1, $legacyRestored->wakeupCalls);

        $this->write('app/Models/Invoice.php', '<?php namespace App\Models; final class Invoice extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Data/AnalyzerSerializationProbe.php', <<<'PHP'
<?php
namespace App\Data;
use App\Models\Invoice;
final class AnalyzerSerializationProbe {
    public function __serialize(): array { Invoice::query()->update([]); return []; }
}
PHP);
        $this->write('app/Http/Controllers/SerializationController.php', <<<'PHP'
<?php
namespace App\Http\Controllers;
final class SerializationController {
    public function show() { return serialize(new \App\Data\AnalyzerSerializationProbe); }
}
PHP);

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [],
            changedOnly: false,
            routes: new RouteMap(['app\http\controllers\serializationcontroller::show' => ['GET', 'HEAD']]),
        );
        $this->assertCount(1, $result->suggestions);
        $this->assertStringContainsString('AnalyzerSerializationProbe::__serialize', implode(' -> ', $result->suggestions[0]->trace));
        $this->assertSame([], $result->notices);
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
        $this->write('routes/ai.php', <<<'PHP'
<?php
use Laravel\Mcp\Facades\Mcp;
Mcp::web('/mcp/test', \GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer::class);
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
        $mcpRoute = array_values(array_filter(
            $routes->entries ?? [],
            fn ($entry): bool => $entry->uri === 'mcp/test' && in_array('POST', $entry->verbs, true),
        ));
        $this->assertCount(1, $mcpRoute);
        $this->assertContains('Laravel\\Mcp\\Server\\Middleware\\AddWwwAuthenticateHeader', $mcpRoute[0]->middleware);
        $this->assertNotEmpty(array_values(array_filter(
            $routes->entries ?? [],
            fn ($entry): bool => str_starts_with((string) $entry->class, 'Laravel\\Fortify\\Http\\Controllers\\'),
        )));

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers, Architecture::Actions],
            changedOnly: false,
            routes: $routes,
        );
        $messages = implode("\n", [
            ...array_map(fn ($suggestion): string => $suggestion->message.' '.$suggestion->reason.' '.implode(' -> ', $suggestion->trace).' at '.$suggestion->path.':'.$suggestion->line, $result->suggestions),
            ...array_column($result->notices, 'message'),
        ]);

        $this->assertStringNotContainsString('AddWwwAuthenticateHeader', $messages);
        $appNotices = array_values(array_filter($result->notices, fn ($notice): bool => str_starts_with($notice->path, 'app/')));
        $this->assertNotContains('A_CALL_UNRESOLVED', array_column($appNotices, 'code'), $messages);
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

final class SerializationContractProbe
{
    public int $serializeCalls = 0;

    public int $unserializeCalls = 0;

    public function __serialize(): array
    {
        $this->serializeCalls++;

        return [];
    }

    public function __unserialize(array $data): void
    {
        $this->unserializeCalls++;
    }
}

final class LegacySerializationProbe
{
    public int $sleepCalls = 0;

    public int $wakeupCalls = 0;

    public function __sleep(): array
    {
        $this->sleepCalls++;

        return [];
    }

    public function __wakeup(): void
    {
        $this->wakeupCalls++;
    }
}
