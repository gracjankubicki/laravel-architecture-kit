<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\HttpRouteDiscovery;
use GracjanKubicki\ArchitectureKit\Impact\ImpactSchema;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Impact;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class ArchitectureHttpRoutesTest extends TestCase
{
    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, '<?php '.$source);
        clearstatcache();
    }

    private function fixture(): void
    {
        $this->write('config/architectures.php', 'return ["enabled"=>["actions"]];');
        $this->write('app/Calculator.php', 'namespace App; class Calculator { public static function calculate() {} public static function other() {} }');
        $this->write('app/Action.php', 'namespace App; class Action { public function handle() { Calculator::calculate(); } }');
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { (new Action)->handle(); } public function show() { Calculator::other(); } public function __invoke() { $this->store(); } }');
        $this->write('bootstrap/app.php', 'use Illuminate\Foundation\Application; return Application::configure(basePath: dirname(__DIR__))->withRouting(web: __DIR__."/../routes/web.php", api: __DIR__."/../routes/api.php", apiPrefix: "v2")->create();');
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; use App\Controller; Route::post("orders", [Controller::class, "store"])->name("orders.store"); Route::get("orders/{id}", [Controller::class, "show"]);');
        $this->write('routes/api.php', 'use Illuminate\Support\Facades\Route; Route::get("ping", App\Controller::class);');
    }

    private function query(string $subject = 'Calculator::calculate', int $limit = 100, int $depth = 8, bool $cache = false, array $exclude = [], ?Filesystem $files = null, ?string $change = null): array
    {
        $files ??= new Filesystem;

        return (new ArchitectureImpact($files, $this->tempPath, new AuditScope, $cache ? new ProjectGraphCache($files, $this->tempPath) : null))->inspect($subject, $exclude, $limit, $depth, $change);
    }

    private function execution(string $subject = 'Calculator::calculate'): array
    {
        $result = $this->query($subject);
        $this->assertTrue($result['ok'], json_encode($result));

        return $result['execution'];
    }

    public function test_precise_direct_and_indirect_paths_do_not_link_another_controller_method(): void
    {
        $this->fixture();
        $execution = $this->execution();
        $this->assertSame('complete', $execution['status']);
        $this->assertEqualsCanonicalizing(['/orders', '/v2/ping'], array_column($execution['routes'], 'uri'));
        $route = $execution['routes'][0];
        $this->assertSame(['POST'], $route['verbs']);
        $this->assertSame('orders.store', $route['name']);
        $this->assertSame(['web'], $route['middleware']);
        $this->assertSame(['App\Controller::store', 'App\Action::handle', 'App\Calculator::calculate'], array_column($route['via'], 'to'));
        foreach ($route['via'] as $edge) {
            $this->assertGreaterThan(0, $edge['line']);
            $this->assertNotEmpty($edge['path']);
        }
        $this->assertCount(1, $this->execution('Calculator::other')['routes']);
        $this->assertCount(3, $this->execution('Controller')['routes']);
        $this->assertCount(3, $this->execution('app/Calculator.php')['routes']);
        $this->assertSame(['app'], $this->query()['scope']['paths']);
    }

    public function test_callbacks_are_immediate_only_and_same_line_identity_is_distinct(): void
    {
        $this->fixture();
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route as R; R::get("one", function (App\Action $a) { $a->handle(); }); R::post("one", fn () => App\Calculator::calculate()); R::get("other", fn () => App\Calculator::other()); R::get("nested", function () { return fn () => App\Calculator::calculate(); }); R::get("url", fn () => route("orders.store")); R::get("ref", fn () => App\Calculator::calculate(...));');
        $rows = $this->execution()['routes'];
        $this->assertCount(3, $rows);
        $one = array_values(array_filter($rows, fn ($r) => $r['uri'] === '/one'));
        $this->assertCount(2, $one);
        $this->assertNotSame($one[0]['id'], $one[1]['id']);
        $this->assertNotSame($one[0]['handler']['callback'], $one[1]['handler']['callback']);
        $this->assertNotSame($one[0]['source']['offset'], $one[1]['source']['offset']);
        $this->assertSame($one[0]['source']['line'], $one[1]['source']['line']);
        $this->assertNotContains('/nested', array_column($rows, 'uri'));
        $this->assertNotContains('/ref', array_column($rows, 'uri'));
        $this->assertNotContains('/url', array_column($rows, 'uri'));
    }

    public function test_same_file_under_groups_keeps_context_controller_and_metadata(): void
    {
        $this->fixture();
        $this->write('extra/shared.php', 'use Illuminate\Support\Facades\Route; Route::post("", "store")->name("store");');
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::controller(App\Controller::class)->prefix("a")->name("a.")->domain("a.example")->middleware(["auth"])->where("id", "[0-9]+")->group(base_path("extra/shared.php")); Route::controller(App\Controller::class)->prefix("b")->name("b.")->domain("b.example")->withoutMiddleware("csrf")->group(base_path("extra/shared.php"));');
        $rows = array_values(array_filter($this->execution()['routes'], fn ($r) => $r['source']['path'] === 'extra/shared.php'));
        $this->assertCount(2, $rows);
        $this->assertSame(['/a', '/b'], array_column($rows, 'uri'));
        $this->assertSame(['a.store', 'b.store'], array_column($rows, 'name'));
        $this->assertSame(['a.example', 'b.example'], array_column($rows, 'domain'));
        $this->assertSame(['web', 'auth'], $rows[0]['middleware']);
        $this->assertSame(['csrf'], $rows[1]['excluded_middleware']);
        $this->assertSame(['id' => '[0-9]+'], $rows[0]['constraints']);
        $this->assertNotSame($rows[0]['id'], $rows[1]['id']);
        $this->assertSame(['class' => 'App\Controller', 'method' => 'store'], $rows[0]['handler']);
    }

    public function test_standard_verbs_named_arguments_strings_and_typed_router(): void
    {
        $this->fixture();
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::group(["prefix"=>"shop", "as"=>"shop.", "namespace"=>"App"], function (Illuminate\Routing\Router $r) { $r->head("head", "Controller@store"); Route::match(["post", "patch"], "match", "Controller@store"); Route::any("any", "Controller@store"); Route::put(uri: "put", action: [App\Controller::class,"store"]); Route::delete("delete", [App\Controller::class,"store"]); Route::options("options", [App\Controller::class,"store"]); });');
        $rows = $this->execution()['routes'];
        $this->assertCount(7, $rows);
        $this->assertContains('/shop/head', array_column($rows, 'uri'));
        $match = array_values(array_filter($rows, fn ($r) => $r['uri'] === '/shop/match'))[0];
        $this->assertSame(['POST', 'PATCH'], $match['verbs']);
        $this->assertSame('App\Controller', $match['handler']['class']);
        $this->assertSame('complete', $this->execution()['status']);
    }

    public function test_resources_api_only_except_nested_names_parameters_and_shallow(): void
    {
        $this->fixture();
        $this->write('app/ResourceController.php', 'namespace App; class ResourceController { public function index() { Calculator::calculate(); } public function create() { Calculator::calculate(); } public function store() { Calculator::calculate(); } public function show() { Calculator::calculate(); } public function edit() { Calculator::calculate(); } public function update() { Calculator::calculate(); } public function destroy() { Calculator::calculate(); } }');
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::resource("photos", App\ResourceController::class)->only(["index", "store"]); Route::apiResource("users", App\ResourceController::class)->except(["destroy"]); Route::resource("albums.photos", App\ResourceController::class)->only(["show", "index"])->parameters(["photos"=>"picture"])->names(["show"=>"picture"])->shallow();');
        $rows = $this->execution()['routes'];
        $this->assertCount(9, $rows);
        $this->assertNotContains('/users/create', array_column($rows, 'uri'));
        $this->assertNotContains('/users/{user}/edit', array_column($rows, 'uri'));
        $this->assertContains('/photos/{picture}', array_column($rows, 'uri'));
        $this->assertContains('/albums/{album}/photos', array_column($rows, 'uri'));
        $this->assertContains('picture', array_column($rows, 'name'));
    }

    public function test_provider_bootstrap_extra_sources_are_static_and_never_executed(): void
    {
        $this->fixture();
        $marker = $this->tempPath.'/executed';
        $poison = 'file_put_contents('.var_export($marker, true).', "bad"); ';
        $this->write('bootstrap/app.php', $poison.'use Illuminate\Foundation\Application; return Application::configure()->withRouting(web: [routes_path("web.php")], using: function () { Route::get("ignored", fn()=>App\Calculator::calculate()); }, then: function () { require base_path("extra/then.php"); });');
        $this->write('bootstrap/providers.php', $poison.'return [Custom\HttpProvider::class];');
        (new Filesystem)->put($this->tempPath.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/', 'Custom\\' => 'custom/']]]));
        $this->write('custom/HttpProvider.php', 'namespace Custom; '.$poison.'class HttpProvider extends \Illuminate\Support\ServiceProvider { public function boot() { $this->loadRoutesFrom(__DIR__."/../extra/provider.php"); } }');
        $this->write('extra/provider.php', $poison.'Route::post("provider", [App\Controller::class,"store"]);');
        $this->write('extra/then.php', $poison.'Route::get("then", function () { '.$poison.'App\Calculator::calculate(); });');
        $rows = $this->execution()['routes'];
        $this->assertContains('/provider', array_column($rows, 'uri'));
        $this->assertContains('/then', array_column($rows, 'uri'));
        $this->assertContains('/orders', array_column($rows, 'uri'));
        $this->assertNotContains('/ignored', array_column($rows, 'uri'));
        $this->assertFileDoesNotExist($marker);
        $this->assertSame('incomplete', $this->execution()['status']);
    }

    public function test_using_only_does_not_claim_default_registration_and_unknown_receiver_is_ignored(): void
    {
        $this->fixture();
        $this->write('bootstrap/app.php', 'use Illuminate\Foundation\Application; return Application::configure()->withRouting(web: routes_path("web.php"), using: fn () => Route::get("custom", fn()=>App\Calculator::calculate()));');
        $this->write('routes/api.php', 'namespace Local; class Route {} Route::get("fake", [\App\Controller::class, "store"]); $router->get("unknown", [\App\Controller::class,"store"]); $custom->withRouting(web: base_path("extra/fake.php"));');
        $this->write('extra/fake.php', 'Route::get("false", [App\Controller::class,"store"]);');
        $rows = $this->execution()['routes'];
        $this->assertEqualsCanonicalizing(['/orders', '/custom'], array_column($rows, 'uri'));
        $orders = array_values(array_filter($rows, fn ($r) => $r['uri'] === '/orders'))[0];
        $this->assertSame('possible', $orders['certainty']);
        $this->assertSame([], $orders['registration']);
    }

    public function test_dynamic_metadata_macros_conditions_and_sources_are_explicit(): void
    {
        $this->fixture();
        $this->write('routes/web.php', 'Route::group(["prefix"=>config("prefix"), "middleware"=>["auth", $unknown]], function () { Route::post("known", [App\Controller::class,"store"])->name("known"); }); if (config("enabled")) { Route::post("conditional", [App\Controller::class,"store"]); } Route::macroRoute("x"); Route::group($dynamicPath); Route::get($uri, [App\Controller::class,"store"]); Route::post("macro", [App\Controller::class,"store"])->customAttribute("x");');
        $execution = $this->execution();
        $this->assertSame('incomplete', $execution['status']);
        $this->assertGreaterThanOrEqual(4, $execution['totals']['unresolved']);
        $rows = $execution['routes'];
        $this->assertContains(null, array_column($rows, 'uri'));
        $this->assertContains('/conditional', array_column($rows, 'uri'));
        foreach ($execution['unresolved'] as $notice) {
            $this->assertNotEmpty($notice['path']);
            $this->assertGreaterThan(0, $notice['line']);
            $this->assertNotEmpty($notice['reason']);
        }
        $known = array_values(array_filter($rows, fn ($r) => $r['name'] === 'known'))[0];
        $this->assertNull($known['middleware']);
        $this->assertNull($known['uri']);
    }

    public function test_cycles_missing_excluded_vendor_and_escaping_paths_do_not_disappear(): void
    {
        $this->fixture();
        $this->write('routes/web.php', 'require __DIR__."/web.php"; require base_path("missing.php"); require base_path("extra/excluded.php"); require base_path("../outside.php"); require base_path("vendor/custom.php"); Route::post("ok", [App\Controller::class,"store"]);');
        $this->write('extra/excluded.php', 'Route::post("excluded", [App\Controller::class,"store"]);');
        $execution = $this->query(exclude: ['extra/*'])['execution'];
        $reasons = implode(' ', array_column($execution['unresolved'], 'reason'));
        foreach (['cycle', 'missing', 'excluded', 'outside', 'generated'] as $reason) {
            $this->assertStringContainsString($reason, $reasons);
        }
        $this->assertNotContains('/excluded', array_column($execution['routes'], 'uri'));
    }

    public function test_warm_graph_cache_does_not_hide_route_edit_add_delete_or_midquery_change(): void
    {
        $this->fixture();
        $before = $this->query(cache: true);
        $warm = $this->query(cache: true);
        $this->assertSame($before['execution'], $warm['execution']);
        $this->write('routes/web.php', 'Route::post("edited-orders", [App\Controller::class,"store"]);');
        $edited = $this->query(cache: true);
        $this->assertSame('fresh', $edited['cache']);
        $this->assertNotSame($warm['snapshot'], $edited['snapshot']);
        $this->assertContains('/edited-orders', array_column($edited['execution']['routes'], 'uri'));
        $this->write('routes/added.php', 'Route::post("added", [App\Controller::class,"store"]);');
        $added = $this->query(cache: true);
        $this->assertNotSame($edited['execution']['source_signature'], $added['execution']['source_signature']);
        (new Filesystem)->delete($this->tempPath.'/routes/added.php');
        $deleted = $this->query(cache: true);
        $this->assertNotContains('/added', array_column($deleted['execution']['routes'], 'uri'));
        $discovery = new HttpRouteDiscovery(new Filesystem, $this->tempPath);
        $discovery->discover(new ProjectGraphSnapshot([], [], [], []), []);
        $this->write('routes/new.php', 'Route::post("new", [App\Controller::class,"store"]);');
        $this->assertFalse($discovery->freshness()['fresh']);
    }

    public function test_empty_incomplete_and_limited_are_separate_and_old_sections_preserved(): void
    {
        $this->fixture();
        $noRoute = $this->query('Calculator::other');
        (new Filesystem)->delete($this->tempPath.'/routes/web.php');
        $empty = $this->query('Calculator::other');
        $this->assertSame('incomplete', $empty['execution']['status']);
        $this->write('routes/web.php', 'Route::post("ok", [App\Controller::class,"store"]);');
        $this->assertSame('none', $this->execution('Calculator::other')['status']);
        $limited = $this->query(limit: 0);
        $this->assertSame('limit', $limited['execution']['status']);
        $this->assertSame([], $limited['execution']['routes']);
        $this->assertGreaterThan(0, $limited['execution']['totals']['routes']);
        $this->assertSame('limit', $this->query(depth: 1)['execution']['status']);
        $this->write('routes/web.php', str_repeat(' ', 100001));
        $this->assertSame('limit', $this->execution()['status']);
        foreach (['dependents', 'dependencies', 'possible', 'references', 'class_context', 'tests', 'analysis'] as $key) {
            $this->assertSame($noRoute[$key], $empty[$key]);
        }
        $this->write('routes/web.php', 'not valid PHP');
        $this->assertSame('incomplete', $this->execution()['status']);
    }

    public function test_inherited_trait_contract_and_construction_chains(): void
    {
        $this->fixture();
        $this->write('app/Other.php', 'namespace App; trait Shared { public function inherited() { Calculator::calculate(); } } class ParentController { use Shared; } class ChildController extends ParentController {} interface Contract { public function run(); } class Implementation implements Contract { public function run() { Calculator::calculate(); } } class ContractController { public function store(Contract $action) { $action->run(); } } class Made {}');
        $this->write('routes/web.php', 'Route::post("inherited", [App\ChildController::class, "inherited"]); Route::post("contract", [App\ContractController::class,"store"]); Route::post("made", fn()=>new App\Made); Route::post("new", function () { $a=new App\Action; $a->handle(); });');
        $rows = $this->execution()['routes'];
        $this->assertContains('/inherited', array_column($rows, 'uri'));
        $this->assertContains('/contract', array_column($rows, 'uri'));
        $this->assertContains('/new', array_column($rows, 'uri'));
        $contract = array_values(array_filter($rows, fn ($r) => $r['uri'] === '/contract'))[0];
        $this->assertSame('possible', $contract['certainty']);
        $this->assertCount(1, $this->execution('Shared::inherited')['routes']);
        $made = $this->execution('Made')['routes'];
        $this->assertCount(1, $made);
        $this->assertSame('new', $made[0]['via'][1]['kind']);
        $this->assertSame('App\Made', $made[0]['via'][1]['to']);
    }

    public function test_provider_route_callback_discovery_and_freshness(): void
    {
        $this->fixture();
        $this->write('app/Providers/HttpProvider.php', 'namespace App\Providers; class HttpProvider extends \Illuminate\Foundation\Support\Providers\RouteServiceProvider { public function boot() { $this->routes(function (\Illuminate\Routing\Router $r) { $r->prefix("extra")->group(base_path("extra/routes.php")); }); } }');
        $this->write('extra/routes.php', 'Route::post("provider",[App\Controller::class,"store"]);');
        $before = $this->query(cache: true);
        $this->assertContains('/extra/provider', array_column($before['execution']['routes'], 'uri'));
        $this->write('extra/routes.php', 'Route::post("provider-edited",[App\Controller::class,"store"]);');
        $after = $this->query(cache: true);
        $this->assertSame('fresh', $after['cache']);
        $this->assertNotSame($before['execution']['source_signature'], $after['execution']['source_signature']);
        $this->assertContains('/extra/provider-edited', array_column($after['execution']['routes'], 'uri'));
    }

    public function test_symlink_boundary_canonical_identity_and_parse_once_contexts(): void
    {
        $this->fixture();
        $this->write('extra/shared.php', 'Route::post("shared",[App\Controller::class,"store"]);');
        symlink($this->tempPath.'/extra/shared.php', $this->tempPath.'/extra/alias.php');
        symlink(dirname(__DIR__, 2).'/src/Impact/HttpRouteImpact.php', $this->tempPath.'/extra/outside.php');
        $this->write('routes/web.php', 'Route::prefix("a")->group(base_path("extra/shared.php")); Route::prefix("b")->group(base_path("extra/alias.php")); require base_path("extra/outside.php");');
        $files = new class extends Filesystem
        {
            public int $reads = 0;

            public function get($path, $lock = false)
            {
                if (str_ends_with($path, 'extra/shared.php')) {
                    $this->reads++;
                }

                return parent::get($path, $lock);
            }
        };
        $execution = $this->query(files: $files)['execution'];
        $this->assertSame(1, $files->reads);
        $shared = array_values(array_filter($execution['routes'], fn ($r) => $r['source']['path'] === 'extra/shared.php'));
        $this->assertCount(1, $shared);
        $this->assertSame(['/a/shared'], array_column($shared, 'uri'));
        $this->assertStringContainsString('symlink', implode(' ', array_column($execution['unresolved'], 'reason')));
    }

    public function test_dynamic_using_then_groups_and_resource_customization_do_not_claim_complete(): void
    {
        $this->fixture();
        $this->write('bootstrap/app.php', 'return \Illuminate\Foundation\Application::configure()->withRouting(web:routes_path("web.php"),using:$using,then:$then)->create();');
        $this->write('routes/web.php', 'Route::group($attributes,function () { Route::post("group",[App\Controller::class,"store"]); }); Route::resource("things",App\Controller::class,$options); Route::resource("things",App\Controller::class)->only($only)->names($names);');
        $execution = $this->execution();
        $this->assertSame('incomplete', $execution['status']);
        $this->assertContains(null, array_column($execution['routes'], 'uri'));
        $this->assertStringContainsString('using/then', implode(' ', array_column($execution['unresolved'], 'reason')));
        $this->assertStringContainsString('Resource customization', implode(' ', array_column($execution['unresolved'], 'reason')));
    }

    public function test_global_seed_budget_applies_to_many_methods_and_handlers(): void
    {
        $this->fixture();
        $methods = '';
        for ($i = 0; $i < 1100; $i++) {
            $methods .= 'public function m'.$i.'() {} ';
        }
        $this->write('app/Large.php', 'namespace App; class Large { '.$methods.' }');
        $this->write('routes/web.php', 'Route::post("large",[App\Large::class,"m0"]);');
        $execution = $this->execution('Large');
        $this->assertSame('limit', $execution['status']);
        $this->assertTrue($execution['truncated']);
    }

    public function test_http_is_additive_to_delete_move_and_signature_and_audit_guard(): void
    {
        $this->fixture();
        $before = [];
        foreach (['delete', 'signature'] as $change) {
            $before[$change] = $this->query(change: $change);
        }
        $move = $this->query('Calculator', change: 'move');
        $this->assertNotEmpty($move['execution']['routes']);
        Artisan::call('architecture-kit:audit', ['--agent' => true]);
        $audit = json_decode(Artisan::output(), true);
        Artisan::call('architecture-kit:guard', ['--agent' => true]);
        $guard = json_decode(Artisan::output(), true);
        $this->write('routes/web.php', 'Route::post("edited",[App\Controller::class,"store"]);');
        foreach (['delete', 'signature'] as $change) {
            $this->assertSame($before[$change][$change], $this->query(change: $change)[$change]);
        }
        $this->assertSame($move['move'], $this->query('Calculator', change: 'move')['move']);
        Artisan::call('architecture-kit:audit', ['--agent' => true]);
        $this->assertSame($audit, json_decode(Artisan::output(), true));
        Artisan::call('architecture-kit:guard', ['--agent' => true]);
        $this->assertSame($guard, json_decode(Artisan::output(), true));
    }

    public function test_no_http_snapshot_and_changed_unmatched_route_source(): void
    {
        $this->fixture();
        (new Filesystem)->deleteDirectory($this->tempPath.'/routes');
        (new Filesystem)->delete($this->tempPath.'/bootstrap/app.php');
        $before = $this->query();
        $this->write('bootstrap/app.php', 'return null;');
        $after = $this->query();
        $this->assertFalse($after['execution']['has_sources']);
        $this->assertSame($before['snapshot'], $after['snapshot']);
        $this->write('routes/web.php', 'Route::get("unused",[App\Controller::class,"show"]);');
        $unused = $this->query();
        $this->assertSame('none', $unused['execution']['status']);
        $this->write('routes/web.php', 'Route::get("changed-unused",[App\Controller::class,"show"]);');
        $this->assertNotSame($unused['snapshot'], $this->query()['snapshot']);
    }

    public function test_ast_source_limit_is_explicit_and_missing_input_addition_invalidates(): void
    {
        $this->fixture();
        $this->write('routes/web.php', str_repeat('Route::get("x",[App\Controller::class,"store"]);', 1800));
        $this->assertSame('limit', $this->execution()['status']);
        $this->write('routes/web.php', 'require base_path("extra/missing.php");');
        $discovery = new HttpRouteDiscovery(new Filesystem, $this->tempPath);
        $discovery->discover(new ProjectGraphSnapshot([], [], [], []), []);
        $this->write('extra/missing.php', 'Route::post("appeared",[App\Controller::class,"store"]);');
        $this->assertFalse($discovery->freshness()['fresh']);
        $this->assertContains('/appeared', array_column($this->execution()['routes'], 'uri'));
    }

    public function test_resource_variadic_filters_base_names_and_unknown_options(): void
    {
        $this->fixture();
        $this->write('routes/api.php', '');
        $this->write('routes/web.php', 'Route::resource("orders",App\Controller::class)->only("store","show")->names("renamed");');
        $this->assertSame(['renamed.store'], array_column($this->execution()['routes'], 'name'));
        $this->assertCount(1, $this->execution('Calculator::other')['routes']);
        $this->write('routes/web.php', 'Route::resource("orders",App\Controller::class,$options);');
        $execution = $this->execution();
        $this->assertSame('incomplete', $execution['status']);
        $this->assertStringContainsString('resource options', implode(' ', array_column($execution['unresolved'], 'reason')));
    }

    public function test_review_f1_explicit_handler_and_invokable_are_preserved_in_controller_group(): void
    {
        $this->fixture();
        $this->write('app/OtherController.php', 'namespace App; class OtherController { public function show() { Calculator::other(); } public function __invoke() { Calculator::other(); } }');
        $this->write('routes/web.php', 'Route::controller(App\Controller::class)->group(function () { Route::get("explicit","App\\OtherController@show"); Route::get("invoke","App\\OtherController"); Route::post("group","store"); });');
        $other = $this->execution('Calculator::other');
        $this->assertSame('complete', $other['status']);
        $this->assertSame(['/explicit', '/invoke'], array_column($other['routes'], 'uri'));
        $this->assertSame(['show', '__invoke'], array_column(array_column($other['routes'], 'handler'), 'method'));
        $this->assertContains('/group', array_column($this->execution()['routes'], 'uri'));
    }

    public function test_review_f2_dynamic_values_override_parent_without_recovering_known_values(): void
    {
        $this->fixture();
        $this->write('routes/web.php', 'Route::controller(App\Controller::class)->domain("known.example")->where("id","[0-9]+")->group(base_path("extra/dynamic.php"));');
        $this->write('extra/dynamic.php', 'Route::post("domain","store")->domain($domain)->where($constraints); Route::controller($controller)->group(function () { Route::post("wrong","store"); }); Route::post("preserved","store");');
        $execution = $this->execution();
        $this->assertSame('incomplete', $execution['status']);
        $this->assertNotContains('/wrong', array_column($execution['routes'], 'uri'));
        $domain = array_values(array_filter($execution['routes'], fn ($r) => $r['uri'] === '/domain'))[0];
        $this->assertNull($domain['domain']);
        $this->assertNull($domain['constraints']);
        $preserved = array_values(array_filter($execution['routes'], fn ($r) => $r['uri'] === '/preserved'))[0];
        $this->assertSame('known.example', $preserved['domain']);
        $this->assertSame(['id' => '[0-9]+'], $preserved['constraints']);
        $this->assertStringContainsString('controller', implode(' ', array_column($execution['unresolved'], 'reason')));
    }

    public function test_review_f3_conditional_expressions_retain_possible_route_declarations(): void
    {
        $this->fixture();
        $this->write('routes/web.php', '$enabled && Route::post("and",[App\Controller::class,"store"]); $enabled ? Route::post("yes",[App\Controller::class,"store"]) : Route::post("no",[App\Controller::class,"store"]); $enabled || Route::post("or",[App\Controller::class,"store"]);');
        $execution = $this->execution();
        $this->assertSame('incomplete', $execution['status']);
        foreach (['/and', '/yes', '/no', '/or'] as $uri) {
            $row = array_values(array_filter($execution['routes'], fn ($r) => $r['uri'] === $uri))[0];
            $this->assertSame('possible', $row['certainty']);
            $this->assertStringContainsString('Conditional expression', implode(' ', $row['reasons']));
        }
        $this->assertStringContainsString('Conditional expression', implode(' ', array_column($execution['unresolved'], 'reason')));
    }

    public function test_review_f4_unknown_call_keeps_unmatched_http_analysis_incomplete(): void
    {
        $this->fixture();
        $this->write('routes/api.php', '');
        $this->write('app/UnknownController.php', 'namespace App; class UnknownController { public function store() { $unknown->calculate(); } public function indirect() { (new UnknownAction)->handle(); } } class UnknownAction { public function handle() { $unknown->calculate(); } }');
        $this->write('routes/web.php', 'Route::post("unknown",[App\UnknownController::class,"store"]); Route::post("indirect",[App\UnknownController::class,"indirect"]);');
        $execution = $this->execution();
        $this->assertSame([], $execution['routes']);
        $this->assertSame('incomplete', $execution['status']);
        $this->assertContains('App\UnknownController::store', array_column($execution['unresolved'], 'from'));
        $this->assertContains('App\UnknownAction::handle', array_column($execution['unresolved'], 'from'));
        foreach ($execution['unresolved'] as $notice) {
            $this->assertSame('app/UnknownController.php', $notice['path']);
            $this->assertGreaterThan(0, $notice['line']);
        }
        $this->write('app/UnknownController.php', 'namespace App; class UnknownController { public function store() { Calculator::other(); } public function indirect() {} }');
        $this->assertSame('none', $this->execution()['status']);
    }

    public function test_review_f5_slash_prefixed_resource_and_apiresource_metadata(): void
    {
        $this->fixture();
        $this->write('routes/api.php', '');
        $this->write('routes/web.php', 'Route::resource("admin/photos",App\Controller::class)->only(["store","show"]); Route::apiResource("v2/albums.photos",App\Controller::class)->only("show");');
        $store = $this->execution()['routes'][0];
        $this->assertSame('/admin/photos', $store['uri']);
        $this->assertSame('photos.store', $store['name']);
        $showRows = $this->execution('Calculator::other')['routes'];
        $this->assertSame('/admin/photos/{photo}', $showRows[0]['uri']);
        $this->assertSame('photos.show', $showRows[0]['name']);
        $show = $showRows[1];
        $this->assertSame('/v2/albums/{album}/photos/{photo}', $show['uri']);
        $this->assertSame('albums.photos.show', $show['name']);
        $this->assertSame('show', $show['handler']['method']);
    }

    public function test_cli_mcp_and_schema_share_execution_report(): void
    {
        $this->fixture();
        $this->assertSame(0, Artisan::call('architecture-kit:impact', ['subject' => 'Calculator::calculate', '--agent' => true]));
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        ArchitectureKitServer::tool(Impact::class, ['subject' => 'Calculator::calculate'])->assertOk()->assertStructuredContent(fn ($json) => $json->where('execution', $cli['execution'])->etc());
        $properties = ImpactSchema::get()['oneOf'][0]['properties']['execution']['properties'];
        foreach ($cli['execution'] as $key => $value) {
            $this->assertArrayHasKey($key, $properties);
        }
        $this->assertSame(0, Artisan::call('architecture-kit:impact', ['subject' => 'Calculator::calculate']));
        $output = Artisan::output();
        $this->assertStringContainsString('/orders', $output);
        $this->assertStringContainsString('HTTP', $output);
    }
}
