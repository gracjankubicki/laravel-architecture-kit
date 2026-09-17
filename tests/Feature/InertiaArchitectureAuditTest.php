<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class InertiaArchitectureAuditTest extends TestCase
{
    public function test_actions_and_query_objects_must_not_depend_on_inertia(): void
    {
        $this->write('app/Actions/ShowProject.php', <<<'PHP'
<?php
namespace App\Actions;
use Inertia\Response;
final class ShowProject { public function handle(): Response {} }
PHP);
        $this->write('app/Queries/ListProjects.php', <<<'PHP'
<?php
namespace App\Queries;
use Inertia\Inertia;
final class ListProjects { public function handle(): array { Inertia::share('x', 1); return []; } }
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(2, $findings);
        $this->assertSame([
            'E_INERTIA_LAYER_DEPENDENCY',
            'E_INERTIA_LAYER_DEPENDENCY',
        ], array_column($findings, 'code'));
    }

    public function test_controller_may_render_inertia_with_explicit_props(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request)
{
    return \Inertia\Inertia::render('Projects/Index', [
        'filters' => $request->only(['status', 'owner']),
        'search' => $request->input('search'),
        'validated' => $request->validated(),
        'safe' => $request->safe()->only(['page']),
        'collection' => $request->collect('tags'),
    ]);
}
PHP);

        $this->assertSame([], $this->inertiaFindings());
    }

    public function test_filtered_request_chains_are_accepted(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Foundation\Http\FormRequest $request)
{
    return \Inertia\Inertia::render('Projects/Index', [
        'all' => $request->all(['status']),
        'safe' => $request->safe()->all(),
        'collection' => $request->collect('tags')->all(),
        'helper' => request('search'),
    ]);
}
PHP);

        $this->assertSame([], $this->inertiaFindings());
    }

    public function test_unfiltered_request_data_in_page_props_is_an_error(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request)
{
    return \Inertia\Inertia::render('Projects/Index', [
        'all' => $request->all(),
        'array' => $request->toArray(),
        'input' => $request->input(),
        'collection' => $request->collect(),
    ]);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(4, $findings);
        $this->assertSame(
            ['E_INERTIA_UNFILTERED_REQUEST_PROPS'],
            array_values(array_unique(array_column($findings, 'code'))),
        );
    }

    public function test_direct_request_in_render_or_shared_props_is_an_error(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request)
{
    \Inertia\Inertia::share('request', $request);

    return inertia('Projects/Index', ['request' => request()]);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(2, $findings);
        $this->assertSame(
            ['E_INERTIA_UNFILTERED_REQUEST_PROPS'],
            array_values(array_unique(array_column($findings, 'code'))),
        );
    }

    public function test_unresolved_request_transformation_is_a_warning(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request, string $method)
{
    return \Inertia\Inertia::render('Projects/Index', [
        'dynamic' => $request->{$method}(),
        'indirect' => ProjectFilters::fromRequest($request),
    ]);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(2, $findings);
        $this->assertSame(
            ['W_INERTIA_REQUEST_PROPS_ANALYSIS_INCOMPLETE'],
            array_values(array_unique(array_column($findings, 'code'))),
        );
    }

    public function test_dynamic_selectors_warn_and_null_selectors_are_unfiltered(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request, array $fields, string $field)
{
    return \Inertia\Inertia::render('Projects/Index', [
        'dynamicOnly' => $request->only($fields),
        'dynamicAll' => $request->all($fields),
        'dynamicInput' => $request->input($field),
        'dynamicCollect' => $request->collect($field),
        'nullInput' => $request->input(null),
        'nullCollect' => $request->collect(null),
        'emptyAll' => $request->all([]),
        'nullHelper' => request(null),
    ]);
}
PHP);

        $findings = $this->inertiaFindings();
        $codes = array_count_values(array_column($findings, 'code'));

        $this->assertCount(8, $findings);
        $this->assertSame(4, $codes['W_INERTIA_REQUEST_PROPS_ANALYSIS_INCOMPLETE']);
        $this->assertSame(4, $codes['E_INERTIA_UNFILTERED_REQUEST_PROPS']);
    }

    public function test_input_defaults_are_not_treated_as_field_selectors(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request, string $default)
{
    return \Inertia\Inertia::render('Projects/Index', [
        'literal' => $request->input('status', 'draft'),
        'variable' => $request->input('status', $default),
        'null' => $request->input('status', null),
        'helperVariable' => request('search', $default),
        'helperNull' => request('search', null),
    ]);
}
PHP);

        $this->assertSame([], $this->inertiaFindings());
    }

    public function test_named_default_without_a_key_is_unfiltered(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request)
{
    return \Inertia\Inertia::render('Projects/Index', [
        'input' => $request->input(default: 'draft'),
        'helper' => request(default: 'draft'),
    ]);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(2, $findings);
        $this->assertSame(
            ['E_INERTIA_UNFILTERED_REQUEST_PROPS'],
            array_values(array_unique(array_column($findings, 'code'))),
        );
    }

    public function test_request_derived_input_defaults_are_checked(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request)
{
    return \Inertia\Inertia::render('Projects/Index', [
        'input' => $request->input('status', $request),
        'helper' => request('search', request()),
    ]);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(2, $findings);
        $this->assertSame(
            ['E_INERTIA_UNFILTERED_REQUEST_PROPS'],
            array_values(array_unique(array_column($findings, 'code'))),
        );
    }

    public function test_standard_factory_and_middleware_entry_points_are_checked(): void
    {
        $this->writeController(<<<'PHP'
public function helper(\Illuminate\Http\Request $request)
{
    return inertia()->render('Projects/Index', ['request' => $request->all()]);
}

public function injected(\Inertia\ResponseFactory $inertia, \Illuminate\Http\Request $request)
{
    return $inertia->render('Projects/Index', ['request' => $request->all()]);
}
PHP);
        $this->write('app/Http/Middleware/HandleInertiaRequests.php', <<<'PHP'
<?php
namespace App\Http\Middleware;
use Illuminate\Http\Request;
use Inertia\Middleware;
final class HandleInertiaRequests extends Middleware
{
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'request' => $request->all(),
        ]);
    }
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(3, $findings);
        $this->assertSame(
            ['E_INERTIA_UNFILTERED_REQUEST_PROPS'],
            array_values(array_unique(array_column($findings, 'code'))),
        );
    }

    public function test_inertia_helper_factory_alias_is_checked(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request)
{
    $factory = inertia();

    return $factory->render('Projects/Index', [
        'data' => $request->all(),
    ]);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(1, $findings);
        $this->assertSame('E_INERTIA_UNFILTERED_REQUEST_PROPS', $findings[0]->code);
    }

    public function test_factory_alias_chains_and_nullsafe_render_are_checked(): void
    {
        $this->writeController(<<<'PHP'
public function chained(\Illuminate\Http\Request $request)
{
    $factory = inertia();
    $alias = $factory;

    return $alias->render('Projects/Index', [
        'data' => $request->all(),
    ]);
}

public function nullsafe(?\Inertia\ResponseFactory $factory, \Illuminate\Http\Request $request)
{
    return $factory?->render('Projects/Index', [
        'data' => $request->all(),
    ]);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(2, $findings);
        $this->assertSame(
            ['E_INERTIA_UNFILTERED_REQUEST_PROPS'],
            array_values(array_unique(array_column($findings, 'code'))),
        );
    }

    public function test_untyped_value_named_request_is_not_treated_as_an_http_request(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(string $request)
{
    return \Inertia\Inertia::render('Projects/Index', ['request' => $request]);
}
PHP);

        $this->assertSame([], $this->inertiaFindings());
    }

    public function test_request_types_are_scoped_to_the_declaring_method(): void
    {
        $this->writeController(<<<'PHP'
public function first(\Illuminate\Http\Request $request): void
{
}

public function second(string $request)
{
    return \Inertia\Inertia::render('Projects/Index', ['value' => $request]);
}
PHP);

        $this->assertSame([], $this->inertiaFindings());
    }

    public function test_request_types_are_scoped_inside_lazy_prop_closures(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request)
{
    return \Inertia\Inertia::render('Projects/Index', [
        'typed' => function (\Illuminate\Http\Request $inner) {
            return $inner->all();
        },
        'shadowed' => fn (string $request) => $request,
    ]);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(1, $findings);
        $this->assertSame('E_INERTIA_UNFILTERED_REQUEST_PROPS', $findings[0]->code);
    }

    public function test_closure_aliases_use_the_state_at_each_assignment(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request)
{
    return \Inertia\Inertia::render('Projects/Index', [
        'data' => function () use ($request) {
            $raw = $request;
            $request = [];

            return $raw;
        },
    ]);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(1, $findings);
        $this->assertSame('E_INERTIA_UNFILTERED_REQUEST_PROPS', $findings[0]->code);
    }

    public function test_filtered_request_alias_receiver_is_accepted(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke()
{
    $pageRequest = request();

    return \Inertia\Inertia::render('Projects/Index', [
        'filters' => $pageRequest->only(['status']),
    ]);
}
PHP);

        $this->assertSame([], $this->inertiaFindings());
    }

    public function test_request_inside_opaque_array_transformation_is_a_warning(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request)
{
    return \Inertia\Inertia::render('Projects/Index', [
        'filters' => transform(['value' => $request->all()]),
    ]);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(1, $findings);
        $this->assertSame('W_INERTIA_REQUEST_PROPS_ANALYSIS_INCOMPLETE', $findings[0]->code);
    }

    public function test_request_inside_opaque_method_transformation_is_a_warning(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request)
{
    return \Inertia\Inertia::render('Projects/Index', [
        'filters' => $this->map($request->all()),
    ]);
}

private function map(array $value): array
{
    return $value;
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(1, $findings);
        $this->assertSame('W_INERTIA_REQUEST_PROPS_ANALYSIS_INCOMPLETE', $findings[0]->code);
    }

    public function test_local_request_and_props_aliases_are_resolved(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request)
{
    $pageRequest = request();
    $props = ['request' => $request->all()];

    \Inertia\Inertia::share('pageRequest', $pageRequest);

    return \Inertia\Inertia::render('Projects/Index', $props);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(2, $findings);
        $this->assertSame(
            ['E_INERTIA_UNFILTERED_REQUEST_PROPS'],
            array_values(array_unique(array_column($findings, 'code'))),
        );
    }

    public function test_mutated_props_aliases_preserve_request_sources(): void
    {
        $this->writeController(<<<'PHP'
public function arrayItem(\Illuminate\Http\Request $request)
{
    $props = [];
    $props['request'] = $request;

    return \Inertia\Inertia::render('Projects/Index', $props);
}

public function filteredAlias(\Illuminate\Http\Request $request)
{
    $props = $request->only(['status']);
    $props['raw'] = $request;

    return \Inertia\Inertia::render('Projects/Index', $props);
}

public function assignOperation(\Illuminate\Http\Request $request)
{
    $props = [];
    $props += ['request' => $request];

    return \Inertia\Inertia::render('Projects/Index', $props);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(3, $findings);
        $this->assertSame(
            ['E_INERTIA_UNFILTERED_REQUEST_PROPS'],
            array_values(array_unique(array_column($findings, 'code'))),
        );
    }

    public function test_mutating_an_unknown_props_alias_remains_an_incomplete_analysis(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request)
{
    $props = buildProps();
    $props['safe'] = $request->only(['status']);

    return \Inertia\Inertia::render('Projects/Index', $props);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(1, $findings);
        $this->assertSame('W_INERTIA_REQUEST_PROPS_ANALYSIS_INCOMPLETE', $findings[0]->code);
    }

    public function test_conditional_props_assignments_are_an_incomplete_analysis(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request, bool $raw)
{
    if ($raw) {
        $props = ['request' => $request];
    } else {
        $props = ['status' => $request->only(['status'])];
    }

    return \Inertia\Inertia::render('Projects/Index', $props);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(1, $findings);
        $this->assertSame('W_INERTIA_REQUEST_PROPS_ANALYSIS_INCOMPLETE', $findings[0]->code);
    }

    public function test_array_push_mutation_preserves_request_sources(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request)
{
    $props = [];
    array_push($props, $request);

    return \Inertia\Inertia::render('Projects/Index', $props);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(1, $findings);
        $this->assertSame('E_INERTIA_UNFILTERED_REQUEST_PROPS', $findings[0]->code);
    }

    public function test_array_push_nested_mutation_preserves_request_sources(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request, string $key)
{
    $props = ['items' => []];
    array_push($props[$key], $request);

    return \Inertia\Inertia::render('Projects/Index', $props);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(1, $findings);
        $this->assertSame('E_INERTIA_UNFILTERED_REQUEST_PROPS', $findings[0]->code);
    }

    public function test_unresolved_props_variable_is_an_incomplete_analysis_warning(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(array $props)
{
    return \Inertia\Inertia::render('Projects/Index', $props);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(1, $findings);
        $this->assertSame('W_INERTIA_REQUEST_PROPS_ANALYSIS_INCOMPLETE', $findings[0]->code);
    }

    public function test_unresolved_props_alias_is_an_incomplete_analysis_warning(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke()
{
    $props = buildProps();

    return \Inertia\Inertia::render('Projects/Index', $props);
}
PHP);

        $findings = $this->inertiaFindings();

        $this->assertCount(1, $findings);
        $this->assertSame('W_INERTIA_REQUEST_PROPS_ANALYSIS_INCOMPLETE', $findings[0]->code);
    }

    public function test_rule_is_disabled_without_the_inertia_profile(): void
    {
        $this->writeController(<<<'PHP'
public function __invoke(\Illuminate\Http\Request $request)
{
    return \Inertia\Inertia::render('Projects/Index', ['request' => $request->all()]);
}
PHP);
        $this->write('app/Actions/CreateProject.php', <<<'PHP'
<?php
namespace App\Actions;
use Illuminate\Http\Request;
final class CreateProject { public function handle(): void {} }
PHP);

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::Actions],
            false,
        );

        $this->assertCount(1, $result->findings);
        $this->assertSame('actions', $result->findings[0]->rule);
        $this->assertSame('app/Actions/CreateProject.php', $result->findings[0]->path);
    }

    /** @return array<int, AuditFinding> */
    private function inertiaFindings(): array
    {
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::Inertia],
            false,
        );

        return array_values(array_filter(
            $result->findings,
            static fn (AuditFinding $finding): bool => $finding->rule === 'inertia',
        ));
    }

    private function writeController(string $method): void
    {
        $this->write('app/Http/Controllers/ProjectController.php', "<?php\nnamespace App\\Http\\Controllers;\nfinal class ProjectController { {$method} }\n");
    }

    private function write(string $path, string $contents): void
    {
        $files = new Filesystem;
        $absolute = $this->tempPath.'/'.$path;
        $files->ensureDirectoryExists(dirname($absolute));
        $files->put($absolute, $contents);
    }
}
