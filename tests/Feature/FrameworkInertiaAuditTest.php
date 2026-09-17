<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class FrameworkInertiaAuditTest extends TestCase
{
    public function test_direct_prop_callback_is_analysed_without_execution(): void
    {
        $this->model();
        $this->controller("return \\Inertia\\Inertia::render('Projects/Index', ['projects' => fn () => \\App\\Models\\Project::query()->update([])]);");

        $findings = $this->thinFindings();
        $this->assertCount(1, $findings);
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $findings[0]->code);
        $this->assertStringContainsString('{callback}', $findings[0]->message);
    }

    public function test_provider_share_callback_is_analysed_for_render(): void
    {
        $this->model();
        $this->write('app/Providers/AppServiceProvider.php', <<<'PHP'
<?php namespace App\Providers;
use App\Models\Project;
use Inertia\Inertia;
final class AppServiceProvider {
    public function boot(): void { $this->registerSharedData(); }
    private function registerSharedData(): void { Inertia::share('auth', fn () => Project::query()->update([])); }
}
PHP);
        $this->controller("return \\Inertia\\Inertia::render('Projects/Index');");

        $findings = $this->thinFindings(['App\\Providers\\AppServiceProvider']);
        $this->assertCount(1, $findings);
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $findings[0]->code);
        $this->assertStringContainsString('app/Providers/AppServiceProvider.php', $findings[0]->message);
    }

    public function test_registered_share_callback_is_not_analysed_until_render_uses_it(): void
    {
        $this->model();
        $this->write('app/Providers/AppServiceProvider.php', <<<'PHP'
<?php namespace App\Providers;
final class AppServiceProvider {
    public function boot(): void { \Inertia\Inertia::share('projects', fn () => \App\Models\Project::query()->update([])); }
}
PHP);
        $this->controller('return 1;');

        $this->assertSame([], $this->thinFindings(['App\\Providers\\AppServiceProvider']));
    }

    public function test_callable_array_prop_reaches_only_the_selected_method(): void
    {
        $this->model();
        $this->write('app/Support/ProjectProps.php', <<<'PHP'
<?php namespace App\Support;
final class ProjectProps {
    public static function load(): array { \App\Models\Project::query()->update([]); return []; }
    public static function unused(): array { return []; }
}
PHP);
        $this->controller("return \\Inertia\\Inertia::render('Projects/Index', ['projects' => [\\App\\Support\\ProjectProps::class, 'load']]);");

        $findings = $this->thinFindings();
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('ProjectProps::load', $findings[0]->message);
        $this->assertStringNotContainsString('::unused', $findings[0]->message);
    }

    public function test_nested_resource_prop_uses_serialization_instead_of_direct_response_path(): void
    {
        $this->model();
        $this->write('app/Http/Resources/ProjectResource.php', <<<'PHP'
<?php namespace App\Http\Resources;
final class ProjectResource extends \Illuminate\Http\Resources\Json\JsonResource {
    public function toArray($request): array { \App\Models\Project::query()->update([]); return []; }
    public function toResponse($request) { return response()->noContent(); }
}
PHP);
        $this->controller("return \\Inertia\\Inertia::render('Projects/Index', ['project' => \\App\\Http\\Resources\\ProjectResource::make([])]);");

        $findings = $this->thinFindings();
        $this->assertCount(1, $findings);
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $findings[0]->code);
        $this->assertStringContainsString('ProjectResource::toArray', $findings[0]->message);
    }

    public function test_dynamic_prop_remains_incomplete(): void
    {
        $this->controller("return \\Inertia\\Inertia::render('Projects/Index', ['projects' => \$dynamic]);");

        $findings = $this->thinFindings();
        $this->assertCount(1, $findings);
        $this->assertSame('W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', $findings[0]->code);
        $this->assertStringContainsString('Inertia prop or callback is dynamic', $findings[0]->message);
    }

    public function test_partly_dynamic_array_merge_in_shared_props_remains_incomplete(): void
    {
        $this->write('app/Providers/AppServiceProvider.php', <<<'PHP'
<?php namespace App\Providers;
final class AppServiceProvider {
    public function boot(): void {
        \Inertia\Inertia::share(array_merge(config('inertia.shared'), ['version' => 'one']));
    }
}
PHP);
        $this->controller("return \\Inertia\\Inertia::render('Projects/Index');");

        $findings = $this->thinFindings(['App\\Providers\\AppServiceProvider']);
        $this->assertCount(1, $findings);
        $this->assertSame('W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', $findings[0]->code);
        $this->assertStringContainsString('Inertia prop or callback is dynamic', $findings[0]->message);
    }

    public function test_unresolved_route_middleware_alias_makes_inertia_context_incomplete(): void
    {
        $this->write('app/Http/Middleware/DangerousInertia.php', '<?php namespace App\Http\Middleware; final class DangerousInertia { public function share(): array { return []; } }');
        $this->controller("return \\Inertia\\Inertia::render('Projects/Index');");
        $routes = new RouteMap(entries: [
            new RouteEntry(['GET', 'HEAD'], 'projects', class: 'App\\Http\\Controllers\\FrameworkController', method: 'show', middleware: ['dangerous-inertia']),
        ], context: [
            'status' => 'known',
            'providers' => [],
            'middleware' => [],
            'middlewareGroups' => [],
            'middlewareAliases' => [],
            'packageVersions' => [],
        ]);

        $findings = $this->thinFindingsWithRoutes($routes);
        $this->assertCount(1, $findings);
        $this->assertSame('W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', $findings[0]->code);
        $this->assertStringContainsString('alias or group is unavailable for dangerous-inertia', $findings[0]->message);
    }

    public function test_unavailable_custom_namespace_middleware_source_makes_context_incomplete(): void
    {
        $this->controller("return \\Inertia\\Inertia::render('Projects/Index');");
        $routes = new RouteMap(entries: [
            new RouteEntry(['GET', 'HEAD'], 'projects', class: 'App\\Http\\Controllers\\FrameworkController', method: 'show', middleware: ['inertia']),
        ], context: [
            'status' => 'known',
            'providers' => [],
            'middleware' => [],
            'middlewareGroups' => [],
            'middlewareAliases' => ['inertia' => 'Company\\Middleware\\HandleInertiaRequests'],
            'packageVersions' => [],
        ]);

        $findings = $this->thinFindingsWithRoutes($routes);
        $this->assertCount(1, $findings);
        $this->assertSame('W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', $findings[0]->code);
        $this->assertStringContainsString('source is unavailable for Company\\Middleware\\HandleInertiaRequests', $findings[0]->message);
    }

    public function test_route_middleware_share_captures_request_and_is_scoped_to_that_route(): void
    {
        $this->model();
        $this->write('app/Http/Middleware/SafeInertia.php', <<<'PHP'
<?php namespace App\Http\Middleware;
final class SafeInertia {
    public function share(\Illuminate\Http\Request $request): array {
        return array_merge(parent::share($request), ['theme' => fn () => $request->cookie('theme')]);
    }
}
PHP);
        $this->write('app/Http/Middleware/DangerousInertia.php', <<<'PHP'
<?php namespace App\Http\Middleware;
final class DangerousInertia {
    public function share(\Illuminate\Http\Request $request): array {
        return ['projects' => fn () => \App\Models\Project::query()->update([])];
    }
}
PHP);
        $this->controller("return \\Inertia\\Inertia::render('Projects/Index');");
        $context = [
            'status' => 'known',
            'providers' => [],
            'middleware' => [],
            'middlewareGroups' => [],
            'middlewareAliases' => [
                'safe-inertia' => 'App\\Http\\Middleware\\SafeInertia',
                'dangerous-inertia' => 'App\\Http\\Middleware\\DangerousInertia',
            ],
            'packageVersions' => [],
        ];
        $routes = new RouteMap(entries: [
            new RouteEntry(['GET', 'HEAD'], 'projects', class: 'App\\Http\\Controllers\\FrameworkController', method: 'show', middleware: ['safe-inertia']),
            new RouteEntry(['POST'], 'projects/refresh', class: 'App\\Http\\Controllers\\FrameworkController', method: 'show', middleware: ['dangerous-inertia']),
        ], context: $context);

        $this->assertSame([], $this->thinFindingsWithRoutes($routes));

        $dangerousGet = new RouteMap(entries: [
            new RouteEntry(['GET', 'HEAD'], 'projects', class: 'App\\Http\\Controllers\\FrameworkController', method: 'show', middleware: ['dangerous-inertia']),
        ], context: $context);
        $findings = $this->thinFindingsWithRoutes($dangerousGet);
        $this->assertCount(1, $findings);
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $findings[0]->code);
        $this->assertStringContainsString('app/Http/Middleware/DangerousInertia.php', $findings[0]->message);
    }

    public function test_http_reachability_memo_keeps_route_specific_shared_props_separate(): void
    {
        $this->write('app/Actions/SafeSharedProp.php', '<?php namespace App\Actions; final class SafeSharedProp { public function handle(): array { return []; } }');
        $this->write('app/Actions/DangerousSharedProp.php', '<?php namespace App\Actions; final class DangerousSharedProp { public function handle(): array { return []; } }');
        $this->write('app/Http/Middleware/SafeInertia.php', '<?php namespace App\Http\Middleware; final class SafeInertia { public function share(): array { return ["data" => fn () => (new \App\Actions\SafeSharedProp)->handle()]; } }');
        $this->write('app/Http/Middleware/DangerousInertia.php', '<?php namespace App\Http\Middleware; final class DangerousInertia { public function share(): array { return ["data" => fn () => (new \App\Actions\DangerousSharedProp)->handle()]; } }');
        $this->controller("return \\Inertia\\Inertia::render('Projects/Index');");
        $this->write('tests/Feature/ProjectTest.php', "<?php it('shows safe', function () { \$this->get('/safe'); });");
        $context = [
            'status' => 'known',
            'providers' => [],
            'middleware' => [],
            'middlewareGroups' => [],
            'middlewareAliases' => [
                'safe-inertia' => 'App\\Http\\Middleware\\SafeInertia',
                'dangerous-inertia' => 'App\\Http\\Middleware\\DangerousInertia',
            ],
            'packageVersions' => [],
        ];
        $routes = new RouteMap(entries: [
            new RouteEntry(['GET', 'HEAD'], 'safe', class: 'App\\Http\\Controllers\\FrameworkController', method: 'show', middleware: ['safe-inertia']),
            new RouteEntry(['GET', 'HEAD'], 'dangerous', class: 'App\\Http\\Controllers\\FrameworkController', method: 'show', middleware: ['dangerous-inertia']),
        ], context: $context);

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false, missingTestLevel: MissingTestLevel::Warn, routes: $routes);
        $missing = array_column(array_filter($result->findings, fn ($finding): bool => $finding->code === 'W_MISSING_TEST'), 'path');

        $this->assertNotContains('app/Actions/SafeSharedProp.php', $missing);
        $this->assertContains('app/Actions/DangerousSharedProp.php', $missing);
    }

    private function model(): void
    {
        $this->write('app/Models/Project.php', '<?php namespace App\Models; final class Project extends \Illuminate\Database\Eloquent\Model {}');
    }

    private function controller(string $body): void
    {
        $this->write('app/Http/Controllers/FrameworkController.php', '<?php namespace App\Http\Controllers; final class FrameworkController { public function show() { '.$body.' } }');
    }

    /** @param list<string> $providers
     * @return list<object>
     */
    private function thinFindings(array $providers = []): array
    {
        return $this->thinFindingsWithRoutes(new RouteMap(
            ['app\\http\\controllers\\frameworkcontroller::show' => ['GET', 'HEAD']],
            context: [
                'status' => 'known',
                'providers' => $providers,
                'middleware' => [],
                'middlewareGroups' => [],
                'middlewareAliases' => [],
                'packageVersions' => [],
            ],
        ));
    }

    /** @return list<object> */
    private function thinFindingsWithRoutes(RouteMap $routes): array
    {
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers, Architecture::Actions],
            changedOnly: false,
            routes: $routes,
        );

        return array_values(array_filter($result->findings, fn ($finding): bool => $finding->rule === 'thin-controller'));
    }

    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $source);
    }
}
