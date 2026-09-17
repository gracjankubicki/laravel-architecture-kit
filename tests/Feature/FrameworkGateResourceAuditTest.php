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

final class FrameworkGateResourceAuditTest extends TestCase
{
    public function test_gate_reaches_only_the_selected_policy_method(): void
    {
        $this->model();
        $this->write('app/Providers/AuthServiceProvider.php', <<<'PHP'
<?php namespace App\Providers;
use App\Models\Project;
use App\Policies\ProjectPolicy;
use Illuminate\Support\Facades\Gate;
final class AuthServiceProvider { public function boot(): void { Gate::policy(Project::class, ProjectPolicy::class); } }
PHP);
        $this->write('app/Policies/ProjectPolicy.php', <<<'PHP'
<?php namespace App\Policies;
use App\Models\Project;
final class ProjectPolicy {
    public function view($user, Project $project): bool { Project::query()->update([]); return true; }
    public function delete($user, Project $project): bool { return true; }
}
PHP);
        $this->controller("public function show(\\App\\Models\\Project \$project) { \\Illuminate\\Support\\Facades\\Gate::authorize('view', \$project); return 1; }");

        $findings = $this->thinFindings(['App\\Providers\\AuthServiceProvider']);
        $this->assertCount(1, $findings);
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $findings[0]->code);
        $this->assertStringContainsString('App\\Policies\\ProjectPolicy::view', $findings[0]->message);
        $this->assertStringNotContainsString('::delete', $findings[0]->message);
    }

    public function test_camel_case_gate_abilities_keep_the_policy_method_name(): void
    {
        $this->model();
        $this->write('app/Policies/ProjectPolicy.php', <<<'PHP'
<?php namespace App\Policies;
use App\Models\Project;
final class ProjectPolicy {
    public function viewAny($user, Project $project): bool { Project::query()->update([]); return true; }
    public function forceDelete($user, Project $project): bool { Project::query()->delete(); return true; }
}
PHP);

        foreach (['viewAny', 'forceDelete'] as $ability) {
            $this->controller("public function show(\\App\\Models\\Project \$project) { \\Illuminate\\Support\\Facades\\Gate::authorize('{$ability}', \$project); return 1; }");
            $findings = $this->thinFindings();

            $this->assertCount(1, $findings, $ability);
            $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $findings[0]->code, $ability);
            $this->assertStringContainsString('ProjectPolicy::'.$ability, $findings[0]->message);
        }
    }

    public function test_implicit_resource_response_reaches_to_array(): void
    {
        $this->model();
        $this->write('app/Http/Resources/ProjectResource.php', <<<'PHP'
<?php namespace App\Http\Resources;
use App\Models\Project;
final class ProjectResource extends \Illuminate\Http\Resources\Json\JsonResource {
    public function toArray($request): array { Project::query()->update([]); return []; }
    public function unused(): void { throw new \RuntimeException('not reached'); }
}
PHP);
        $this->controller('public function show() { return \App\Http\Resources\ProjectResource::make([]); }');

        $findings = $this->thinFindings();
        $this->assertCount(1, $findings);
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $findings[0]->code);
        $this->assertStringContainsString('ProjectResource::toArray', $findings[0]->message);
    }

    public function test_resource_nested_in_json_response_reaches_to_array(): void
    {
        $this->model();
        $this->write('app/Http/Resources/ProjectResource.php', <<<'PHP'
<?php namespace App\Http\Resources;
use App\Models\Project;
final class ProjectResource extends \Illuminate\Http\Resources\Json\JsonResource {
    public function toArray($request): array { Project::query()->update([]); return []; }
    public function toResponse($request) { return response()->noContent(); }
}
PHP);
        $this->controller("public function show() { return response()->json(['project' => \\App\\Http\\Resources\\ProjectResource::make([])]); }");

        $findings = $this->thinFindings();
        $this->assertCount(1, $findings);
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $findings[0]->code);
        $this->assertStringContainsString('ProjectResource::toArray', $findings[0]->message);

        $this->write('app/Http/Resources/ProjectResource.php', <<<'PHP'
<?php namespace App\Http\Resources;
use App\Models\Project;
final class ProjectResource extends \Illuminate\Http\Resources\Json\JsonResource {
    public function toArray($request): array { return []; }
    public function toResponse($request) { Project::query()->delete(); return response()->noContent(); }
}
PHP);
        $this->assertSame([], $this->thinFindings());
    }

    public function test_new_resource_and_conditional_callback_reach_only_serialized_code(): void
    {
        $this->model();
        $this->write('app/Http/Resources/ProjectResource.php', <<<'PHP'
<?php namespace App\Http\Resources;
use App\Models\Project;
final class ProjectResource extends \Illuminate\Http\Resources\Json\JsonResource {
    public function toArray($request): array { return ['secret' => $this->when(true, fn () => Project::query()->update([]))]; }
    public function unused(): void { Project::query()->delete(); }
}
PHP);
        $this->controller('public function show() { return new \App\Http\Resources\ProjectResource([]); }');

        $findings = $this->thinFindings();
        $this->assertCount(1, $findings);
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $findings[0]->code);
        $this->assertStringContainsString('{callback}', $findings[0]->message);
        $this->assertStringNotContainsString('::unused', $findings[0]->message);
    }

    public function test_explicit_resource_methods_follow_their_own_transformation_path(): void
    {
        $this->model();
        $this->write('app/Http/Resources/ProjectResource.php', <<<'PHP'
<?php namespace App\Http\Resources;
use App\Models\Project;
final class ProjectResource extends \Illuminate\Http\Resources\Json\JsonResource {
    public function toArray($request): array { return []; }
    public function toResponse($request) { Project::query()->delete(); return response()->noContent(); }
}
PHP);
        foreach (['resolve', 'toArray'] as $method) {
            $this->controller("public function show() { return \\App\\Http\\Resources\\ProjectResource::make([])->{$method}(); }");
            $this->assertSame([], $this->thinFindings(), $method);
        }
        $this->controller('public function show() { return \App\Http\Resources\ProjectResource::make([])->toResponse(null); }');
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $this->thinFindings()[0]->code);

        $this->write('app/Http/Resources/ProjectResource.php', <<<'PHP'
<?php namespace App\Http\Resources;
use App\Models\Project;
final class ProjectResource extends \Illuminate\Http\Resources\Json\JsonResource {
    public function toArray($request): array { Project::query()->update([]); return []; }
    public function toResponse($request) { return response()->noContent(); }
}
PHP);
        foreach (['resolve', 'toArray'] as $method) {
            $this->controller("public function show() { return \\App\\Http\\Resources\\ProjectResource::make([])->{$method}(); }");
            $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $this->thinFindings()[0]->code, $method);
        }
        $this->controller('public function show() { return \App\Http\Resources\ProjectResource::make([])->toResponse(null); }');
        $this->assertSame([], $this->thinFindings());
    }

    public function test_dynamic_gate_selection_remains_incomplete(): void
    {
        $this->model();
        $this->controller('public function show(\App\Models\Project $project, string $ability) { return \Illuminate\Support\Facades\Gate::allows($ability, $project); }');

        $findings = $this->thinFindings();
        $this->assertCount(1, $findings);
        $this->assertSame('W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', $findings[0]->code);
        $this->assertStringContainsString('Gate ability is dynamic', $findings[0]->message);
    }

    public function test_http_test_reaches_selected_policy_and_resource_transformation_only(): void
    {
        $this->model();
        $this->write('app/Policies/ProjectPolicy.php', <<<'PHP'
<?php namespace App\Policies;
final class ProjectPolicy {
    public function view($user, \App\Models\Project $project): bool { (new \App\Actions\ViewedProject)->handle(); return true; }
    public function delete($user, \App\Models\Project $project): bool { (new \App\Actions\UnusedProject)->handle(); return true; }
}
PHP);
        $this->write('app/Actions/ViewedProject.php', '<?php namespace App\Actions; final class ViewedProject { public function handle(): void {} }');
        $this->write('app/Actions/ResourceProject.php', '<?php namespace App\Actions; final class ResourceProject { public function handle(): void {} }');
        $this->write('app/Actions/UnusedProject.php', '<?php namespace App\Actions; final class UnusedProject { public function handle(): void {} }');
        $this->write('app/Http/Resources/ProjectResource.php', <<<'PHP'
<?php namespace App\Http\Resources;
final class ProjectResource extends \Illuminate\Http\Resources\Json\JsonResource {
    public function toArray($request): array { (new \App\Actions\ResourceProject)->handle(); return []; }
}
PHP);
        $this->controller("public function show(\\App\\Models\\Project \$project) { \\Illuminate\\Support\\Facades\\Gate::authorize('view', \$project); return response()->json(\\App\\Http\\Resources\\ProjectResource::make(\$project)); }");
        $this->write('tests/Feature/ProjectTest.php', "<?php it('shows', function () { \$this->get('/projects/1'); });");
        $routes = new RouteMap(entries: [
            new RouteEntry(['GET', 'HEAD'], 'projects/{project}', class: 'App\\Http\\Controllers\\FrameworkController', method: 'show'),
        ], context: [
            'status' => 'known',
            'providers' => [],
            'middleware' => [],
            'middlewareGroups' => [],
            'middlewareAliases' => [],
            'packageVersions' => [],
        ]);

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false, missingTestLevel: MissingTestLevel::Warn, routes: $routes);
        $missing = array_column(array_filter($result->findings, fn ($finding): bool => $finding->code === 'W_MISSING_TEST'), 'path');

        $this->assertNotContains('app/Actions/ViewedProject.php', $missing);
        $this->assertNotContains('app/Actions/ResourceProject.php', $missing);
        $this->assertContains('app/Actions/UnusedProject.php', $missing);
    }

    public function test_internal_php_function_remains_known_to_test_reachability(): void
    {
        $this->write('app/Actions/InspectProject.php', <<<'PHP'
<?php namespace App\Actions;
final class InspectProject { public function handle(): bool { return str_contains('project', 'ject'); } }
PHP);
        $this->controller('public function show() { return (new \App\Actions\InspectProject)->handle(); }');
        $this->write('tests/Feature/ProjectTest.php', "<?php it('shows', function () { \$this->get('/projects'); });");
        $routes = new RouteMap(entries: [
            new RouteEntry(['GET', 'HEAD'], 'projects', class: 'App\\Http\\Controllers\\FrameworkController', method: 'show'),
        ], context: [
            'status' => 'known',
            'providers' => [],
            'middleware' => [],
            'middlewareGroups' => [],
            'middlewareAliases' => [],
            'packageVersions' => [],
        ]);

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false, missingTestLevel: MissingTestLevel::Warn, routes: $routes);
        $this->assertNotContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($result->findings, 'code'));
        $missing = array_column(array_filter($result->findings, fn ($finding): bool => $finding->code === 'W_MISSING_TEST'), 'path');
        $this->assertNotContains('app/Actions/InspectProject.php', $missing);
    }

    private function model(): void
    {
        $this->write('app/Models/Project.php', '<?php namespace App\Models; final class Project extends \Illuminate\Database\Eloquent\Model {}');
    }

    private function controller(string $method): void
    {
        $this->write('app/Http/Controllers/FrameworkController.php', '<?php namespace App\Http\Controllers; final class FrameworkController { '.$method.' }');
    }

    /** @param list<string> $providers
     * @return list<object>
     */
    private function thinFindings(array $providers = []): array
    {
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers, Architecture::Actions],
            changedOnly: false,
            routes: new RouteMap(
                ['app\\http\\controllers\\frameworkcontroller::show' => ['GET', 'HEAD']],
                context: [
                    'status' => 'known',
                    'providers' => $providers,
                    'middleware' => [],
                    'middlewareGroups' => [],
                    'middlewareAliases' => [],
                    'packageVersions' => [],
                ],
            ),
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
