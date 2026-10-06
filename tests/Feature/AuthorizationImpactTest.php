<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\ArchitecturePath;
use GracjanKubicki\ArchitectureKit\Impact\ExecutionExtractor;
use GracjanKubicki\ArchitectureKit\Impact\ExecutionLinks;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Impact;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Path;
use GracjanKubicki\ArchitectureKit\Reach\ArchitectureReach;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class AuthorizationImpactTest extends TestCase
{
    private function write(string $path, string $code): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, '<?php '.$code);
        clearstatcache();
    }

    private function fixture(): void
    {
        $this->write('app/Models/Invoice.php', 'namespace App\Models; class Invoice {}');
        $this->write('app/Models/User.php', 'namespace App\Models; class User extends \Illuminate\Foundation\Auth\User {}');
        $this->write('app/Policies/InvoicePolicy.php', 'namespace App\Policies; class InvoicePolicy { public function before(?\App\Models\User $user) { return null; } public function update(\App\Models\User $user, \App\Models\Invoice $invoice) { return true; } public function delete(\App\Models\User $user, \App\Models\Invoice $invoice) { return false; } }');
        $this->write('app/InvoiceAccess.php', 'namespace App; use Illuminate\Support\Facades\Gate; class InvoiceAccess { public function ensureCanEdit(\App\Models\User $user, \App\Models\Invoice $invoice) { Gate::forUser($user)->authorize("update", $invoice); } }');
        $this->write('app/InvoiceController.php', 'namespace App; class InvoiceController { use \Illuminate\Foundation\Auth\Access\AuthorizesRequests; public function update(\App\Models\Invoice $invoice, InvoiceAccess $access, \App\Models\User $user) { $access->ensureCanEdit($user, $invoice); } public function show(\App\Models\Invoice $invoice) {} }');
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::put("/invoices/{invoice}", [\App\InvoiceController::class,"update"])->can("update", "invoice"); Route::get("/invoices/{invoice}", [\App\InvoiceController::class,"show"]);');
    }

    private function path(string $from, string $to): array
    {
        return (new ArchitecturePath(new Filesystem, $this->tempPath, new AuditScope))->inspect($from, $to, [], 100, 12);
    }

    public function test_policy_method_is_precise_and_wrapper_is_a_real_path(): void
    {
        $this->fixture();
        $r = $this->path('InvoiceController::update', 'InvoicePolicy::update');
        $this->assertTrue($r['execution']['found'], json_encode($r));
        $this->assertFalse($this->path('InvoiceController::update', 'InvoicePolicy::delete')['execution']['found']);
        $impact = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect('InvoicePolicy::update', [], 100, 12);
        $this->assertNotEmpty($impact['execution']['flows'], json_encode($impact));
        $this->assertStringContainsString('authorization-policy', json_encode($impact['execution']['flows']));
        $this->assertFalse($this->path('InvoiceController::show', 'InvoicePolicy::update')['execution']['found']);
    }

    public function test_policy_precedes_defined_ability_and_hooks_have_order_and_conditions(): void
    {
        $this->fixture();
        $this->write('app/Providers/AuthServiceProvider.php', 'namespace App\Providers; use Illuminate\Support\Facades\Gate; class AuthServiceProvider extends \Illuminate\Support\ServiceProvider { public function boot() { Gate::before(fn (?\App\Models\User $user) => null); Gate::after(fn (?\App\Models\User $user) => null); Gate::define("update", [\App\Alternative::class,"run"]); } }');
        $this->write('app/Alternative.php', 'namespace App; class Alternative { public function run($user) {} }');
        $r = $this->path('InvoiceAccess::ensureCanEdit', 'InvoicePolicy::before');
        $this->assertTrue($r['execution']['found'], json_encode($r));
        $this->assertStringContainsString('requested ability method is callable', json_encode($r['execution']));
        $this->assertFalse($this->path('InvoiceAccess::ensureCanEdit', 'Alternative::run')['execution']['found']);
        $r = $this->path('InvoiceAccess::ensureCanEdit', 'app/Providers/AuthServiceProvider.php');
        $this->assertTrue($r['execution']['found'], json_encode($r));
        $this->assertStringContainsString('including non-null before result', json_encode($r['execution']));
    }

    public function test_inline_boolean_has_no_callback_or_policy_and_closure_has_only_inline_edge(): void
    {
        $this->fixture();
        $this->write('app/Inline.php', 'namespace App; use Illuminate\Support\Facades\Gate; class Inline { public function boolean() { Gate::allowIf(true); Gate::denyIf(false); } public function callback() { Gate::allowIf(fn (\App\Models\User $user) => \App\Work::run()); } }');
        $this->write('app/Work.php', 'namespace App; class Work { public static function run() {} }');
        $this->assertFalse($this->path('Inline::boolean', 'InvoicePolicy')['execution']['found']);
        $this->assertFalse($this->path('Inline::boolean', 'Work')['execution']['found']);
        $r = $this->path('Inline::callback', 'Work::run');
        $this->assertTrue($r['execution']['found'], json_encode($r));
        $this->assertStringContainsString('authorization-inline', json_encode($r));
        $this->assertStringNotContainsString('authorization-policy', json_encode($r['execution']['paths']));
    }

    public function test_form_request_is_connected_only_to_its_handler_parameter(): void
    {
        $this->fixture();
        $this->write('app/EditInvoice.php', 'namespace App; class EditInvoice extends \Illuminate\Foundation\Http\FormRequest { public function authorize() { return \Illuminate\Support\Facades\Gate::allows("update", \App\Models\Invoice::class); } }');
        $this->write('app/InvoiceController.php', 'namespace App; class InvoiceController { public function update(EditInvoice $request) {} public function show() {} }');
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::put("/invoices", [\App\InvoiceController::class,"update"]); Route::get("/invoices", [\App\InvoiceController::class,"show"]);');
        $impact = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect('EditInvoice::authorize', [], 100, 12);
        $this->assertCount(1, $impact['execution']['flows'], json_encode($impact));
        $this->assertSame(['PUT'], $impact['execution']['flows'][0]['entry']['route']['verbs']);
        $this->assertFalse($this->path('InvoiceController::show', 'EditInvoice::authorize')['execution']['found']);
    }

    public function test_usage_and_recognized_catch_are_source_metadata(): void
    {
        $code = '<?php namespace App; use Illuminate\Support\Facades\Gate; class Usage { public function run() { Gate::allows("edit"); if (Gate::allows("edit")) {} try { Gate::authorize("edit"); } catch (\Illuminate\Auth\Access\AuthorizationException $e) {} return Gate::allows("edit"); } }';
        $facts = (new ExecutionExtractor)->extract(new FileContext('app/Usage.php', $code));
        $checks = (new ExecutionLinks($facts))->authorizationChecks;
        $this->assertSame(['ignored', 'conditional_branch', 'ignored', 'returned'], array_column($checks, 'usage'));
        $this->assertSame(['Illuminate\Auth\Access\AuthorizationException'], $checks[2]['caught']);
        $this->assertSame('throws_on_denial', $checks[2]['result']);
    }

    public function test_application_authorize_method_is_not_a_framework_check(): void
    {
        $this->fixture();
        $this->write('app/Own.php', 'namespace App; class Own { public function authorize($ability, $model) { return true; } public function run() { $this->authorize("update", new \App\Models\Invoice); } }');
        $this->assertFalse($this->path('Own::run', 'InvoicePolicy::update')['execution']['found']);
        $this->assertTrue($this->path('Own::run', 'Own::authorize')['execution']['found']);
    }

    public function test_user_many_abilities_and_controller_authorization(): void
    {
        $this->fixture();
        $this->write('app/Checks.php', 'namespace App; class Checks { use \Illuminate\Foundation\Auth\Access\AuthorizesRequests; public function user(\App\Models\User $user) { return $user->canAny(["update", "delete"], \App\Models\Invoice::class); } public function inverted(\App\Models\User $user) { return $user->cannot("delete", \App\Models\Invoice::class); } public function update(\App\Models\Invoice $invoice) { $this->authorize($invoice); } }');
        $r = $this->path('Checks::user', 'InvoicePolicy::delete');
        $this->assertTrue($r['execution']['found'], json_encode($r));
        $this->assertStringContainsString('evaluation may stop earlier', json_encode($r['execution']['paths']));
        $this->assertTrue($this->path('Checks::update', 'InvoicePolicy::update')['execution']['found']);
        $this->assertTrue($this->path('Checks::inverted', 'InvoicePolicy::delete')['execution']['found']);
    }

    public function test_explicit_and_attribute_policy_and_ambiguity_are_not_guesses(): void
    {
        $this->fixture();
        $this->write('app/CustomPolicy.php', 'namespace App; class CustomPolicy { public function update($user) {} }');
        $this->write('app/Providers/AuthServiceProvider.php', 'namespace App\Providers; class AuthServiceProvider extends \Illuminate\Foundation\Support\Providers\AuthServiceProvider { protected $policies = [\App\Models\Invoice::class => \App\CustomPolicy::class]; }');
        $this->assertTrue($this->path('InvoiceAccess::ensureCanEdit', 'CustomPolicy::update')['execution']['found']);
        $this->assertFalse($this->path('InvoiceAccess::ensureCanEdit', 'InvoicePolicy::update')['execution']['found']);
        unlink($this->tempPath.'/app/Providers/AuthServiceProvider.php');
        $this->write('app/Models/Invoice.php', 'namespace App\Models; #[\Illuminate\Database\Eloquent\Attributes\UsePolicy(\App\CustomPolicy::class)] class Invoice {}');
        $this->assertTrue($this->path('InvoiceAccess::ensureCanEdit', 'CustomPolicy::update')['execution']['found']);
        $this->write('app/Models/Invoice.php', 'namespace App\Models; class Invoice {}');
        $this->write('app/Models/Policies/InvoicePolicy.php', 'namespace App\Models\Policies; class InvoicePolicy { public function update($user) {} }');
        $r = $this->path('InvoiceAccess::ensureCanEdit', 'App\Policies\InvoicePolicy::update');
        $this->assertFalse($r['execution']['found']);
        $this->assertStringContainsString('Several policy convention candidates', json_encode($r['execution']['notices']));
    }

    public function test_dynamic_policy_and_ability_do_not_prove_paths(): void
    {
        $this->fixture();
        $this->write('app/Providers/AuthServiceProvider.php', 'namespace App\Providers; class AuthServiceProvider { public function boot($model, $policy) { \Illuminate\Support\Facades\Gate::policy($model, $policy); } }');
        $r = $this->path('InvoiceAccess::ensureCanEdit', 'InvoicePolicy::update');
        $this->assertFalse($r['execution']['found']);
        $this->assertStringContainsString('unresolved', json_encode($r['execution']['notices']));
    }

    public function test_resource_authorization_and_custom_middleware_registration(): void
    {
        $this->fixture();
        $this->write('app/InvoiceController.php', 'namespace App; class InvoiceController { use \Illuminate\Foundation\Auth\Access\AuthorizesRequests; public function __construct() { $this->authorizeResource(\App\Models\Invoice::class); } public function update(\App\Models\Invoice $invoice) {} public function show(\App\Models\Invoice $invoice) {} }');
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::put("/invoices/{invoice}", [\App\InvoiceController::class,"update"]);');
        $impact = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect('InvoicePolicy::update', [], 100, 12);
        $this->assertCount(1, $impact['execution']['flows'], json_encode($impact));
        $this->write('app/AccessMiddleware.php', 'namespace App; class AccessMiddleware { public function handle($request, $next) { \Illuminate\Support\Facades\Gate::authorize("update", \App\Models\Invoice::class); return $next($request); } }');
        $this->write('bootstrap/app.php', 'use Illuminate\Foundation\Application; return Application::configure()->withMiddleware(function (\Illuminate\Foundation\Configuration\Middleware $middleware) { $middleware->alias(["invoice-access" => \App\AccessMiddleware::class]); })->create();');
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::put("/invoices/{invoice}", [\App\InvoiceController::class,"update"])->middleware("invoice-access");');
        $impact = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect('InvoicePolicy::update', [], 100, 12);
        $this->assertStringContainsString('http-middleware', json_encode($impact['execution']['flows']));
    }

    public function test_shared_rules_have_site_counts_and_reach_pages_are_invalidated(): void
    {
        $this->fixture();
        $this->write('app/Second.php', 'namespace App; class Second { public function run() { \Illuminate\Support\Facades\Gate::authorize("update", \App\Models\Invoice::class); } }');
        $impact = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect('InvoicePolicy::update', [], 100, 12);
        $summary = array_values(array_filter($impact['execution']['authorization']['rules'], fn ($rule) => $rule['kind'] === 'authorization-policy'));
        $this->assertCount(1, $summary);
        $this->assertSame(3, $summary[0]['recognized_site_count']);
        $reach = new ArchitectureReach(new Filesystem, $this->tempPath);
        $r = $reach->inspect('InvoicePolicy::update', 1, 12);
        $this->assertTrue($r['ok'], json_encode($r));
        $id = $r['reach']['pagination']['report_id'];
        $this->assertCount(1, $r['execution']['authorization']['consumers']);
        $this->assertSame(3, $r['execution']['authorization']['totals']['consumers']);
        $second = $reach->inspect('', 1, 12, $id, 2);
        $this->assertTrue($second['ok']);
        $this->write('app/Second.php', 'namespace App; class Second { public function run() {} }');
        $this->assertSame('E_REACH_REPORT', $reach->inspect('', 1, 12, $id, 2)['m']);
        $limited = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect('InvoiceAccess::ensureCanEdit', [], 0, 1);
        $this->assertTrue($limited['execution']['authorization']['total_is_lower_bound']);
    }

    public function test_cli_mcp_source_only_parity_and_dynamic_configuration_is_not_executed(): void
    {
        $this->fixture();
        $this->write('config/architectures.php', 'return ["enabled" => ["actions"], "audit" => ["cache" => false]];');
        $this->assertSame(0, Artisan::call('architecture-kit:impact', ['subject' => 'InvoicePolicy::update', '--agent' => true, '--depth' => 12]));
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        ArchitectureKitServer::tool(Impact::class, ['subject' => 'InvoicePolicy::update', 'depth' => 12])->assertOk()->assertStructuredContent(fn ($json) => $json->where('execution', $cli['execution'])->etc());
        $this->assertSame(0, Artisan::call('architecture-kit:path', ['from' => 'InvoiceAccess::ensureCanEdit', 'to' => 'InvoicePolicy::update', '--agent' => true, '--depth' => 12]));
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        ArchitectureKitServer::tool(Path::class, ['from' => 'InvoiceAccess::ensureCanEdit', 'to' => 'InvoicePolicy::update', 'depth' => 12])->assertOk()->assertStructuredContent(fn ($json) => $json->where('execution', $cli['execution'])->etc());
        $this->write('config/architectures.php', 'file_put_contents(__DIR__."/executed", "bad"); return [];');
        $this->assertSame(1, Artisan::call('architecture-kit:impact', ['subject' => 'InvoicePolicy::update', '--agent' => true]));
        ArchitectureKitServer::tool(Path::class, ['from' => 'InvoiceAccess', 'to' => 'InvoicePolicy'])->assertSee('E_PATH_FAILED');
        $this->assertFileDoesNotExist($this->tempPath.'/config/executed');
    }

    public function test_defined_callable_is_exact_and_missing_policy_method_does_not_run_policy_before(): void
    {
        $this->fixture();
        $this->write('app/Ability.php', 'namespace App; class Ability { public function run(?\App\Models\User $user) {} public function __invoke($user) {} }');
        $this->write('app/Providers/AuthServiceProvider.php', 'namespace App\Providers; use Illuminate\Support\Facades\Gate; class AuthServiceProvider { public function boot() { Gate::define("download", "App\\Ability@run"); Gate::define("wrong", "App\\Ability@missing"); } }');
        $this->write('app/AbilityCaller.php', 'namespace App; class AbilityCaller { public function run() { return \Illuminate\Support\Facades\Gate::inspect("download", \App\Models\Invoice::class); } public function wrong() { return \Illuminate\Support\Facades\Gate::check("wrong"); } }');
        $r = $this->path('AbilityCaller::run', 'Ability::run');
        $this->assertTrue($r['execution']['found'], json_encode($r));
        $this->assertStringContainsString('guests', json_encode($r['execution']['paths']));
        $this->assertFalse($this->path('AbilityCaller::run', 'InvoicePolicy::before')['execution']['found']);
        $this->assertFalse($this->path('AbilityCaller::wrong', 'Ability::__invoke')['execution']['found']);
    }

    public function test_endpoint_report_includes_route_and_form_request_rules(): void
    {
        $this->fixture();
        $this->write('app/EditInvoice.php', 'namespace App; class EditInvoice extends \Illuminate\Foundation\Http\FormRequest { public function authorize() { return $this->user()->can("update", \App\Models\Invoice::class); } }');
        $this->write('app/InvoiceController.php', 'namespace App; class InvoiceController { public function update(EditInvoice $request, \App\Models\Invoice $invoice) {} public function show() {} }');
        $impact = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect('InvoiceController::update', [], 100, 12);
        $this->assertSame(2, $impact['execution']['authorization']['totals']['outgoing'], json_encode($impact));
        $this->assertCount(2, $impact['execution']['authorization']['checks']);
        $this->assertStringContainsString('InvoicePolicy::update', json_encode($impact['execution']['authorization']['rules']));
        $other = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect('InvoiceController::show', [], 100, 12);
        $this->assertSame([], $other['execution']['authorization']['checks']);
    }

    public function test_source_configuration_and_route_files_are_not_executed_by_path_or_impact(): void
    {
        $this->fixture();
        $this->write('routes/web.php', 'file_put_contents(__DIR__."/executed", "bad"); use Illuminate\Support\Facades\Route; Route::put("/invoices/{invoice}", [\App\InvoiceController::class, "update"])->can("update", "invoice");');
        $this->assertTrue($this->path('routes/web.php', 'InvoicePolicy::update')['execution']['found']);
        $this->assertFileDoesNotExist($this->tempPath.'/routes/executed');
        $this->write('vendor/ForeignPolicy.php', 'file_put_contents(__DIR__."/executed", "bad"); namespace Foreign; class Policy { public function update($user) {} }');
        $this->write('app/Providers/AuthServiceProvider.php', 'namespace App\Providers; class AuthServiceProvider { public function boot() { \Illuminate\Support\Facades\Gate::policy(\App\Models\Invoice::class, \Foreign\Policy::class); } }');
        $r = $this->path('InvoiceAccess::ensureCanEdit', 'InvoicePolicy::update');
        $this->assertFalse($r['execution']['found']);
        $this->assertStringContainsString('unresolved', json_encode($r['execution']['notices']));
        $this->assertFileDoesNotExist($this->tempPath.'/vendor/executed');
    }

    public function test_uninvoked_resource_registration_method_does_not_attach_checks(): void
    {
        $this->fixture();
        $this->write('app/InvoiceController.php', 'namespace App; class InvoiceController { use \Illuminate\Foundation\Auth\Access\AuthorizesRequests; public function unrelated() { $this->authorizeResource(\App\Models\Invoice::class); } public function update(\App\Models\Invoice $invoice) {} public function show() {} }');
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::put("/invoices/{invoice}", [\App\InvoiceController::class, "update"]);');
        $r = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect('InvoicePolicy::update', [], 100, 12);
        $this->assertSame([], $r['execution']['flows']);
    }

    public function test_resource_arrays_preserve_all_declared_arguments(): void
    {
        $this->fixture();
        $this->write('app/Other.php', 'namespace App; class Other {}');
        $this->write('app/InvoiceController.php', 'namespace App; class InvoiceController { use \Illuminate\Foundation\Auth\Access\AuthorizesRequests; public function __construct() { $this->authorizeResource([\App\Models\Invoice::class, Other::class], ["invoice", "other"]); } public function update(\App\Models\Invoice $invoice, Other $other) {} }');
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::put("/invoices/{invoice}/{other}", [\App\InvoiceController::class, "update"]);');
        $r = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect('InvoiceController::update', [], 100, 12);
        $check = $r['execution']['authorization']['checks'][0];
        $this->assertSame(['App\\Models\\Invoice', 'App\\Other'], array_column($check['arguments'], 'class'));
        $this->assertStringContainsString('InvoicePolicy::update', json_encode($r['execution']['authorization']['rules']));
    }

    public function test_unread_policy_parent_never_falls_back_to_defined_ability(): void
    {
        $this->fixture();
        $this->write('app/Policies/InvoicePolicy.php', 'namespace App\Policies; class InvoicePolicy extends \Vendor\BasePolicy {}');
        $this->write('app/Alternative.php', 'namespace App; class Alternative { public static function run($user) {} }');
        $this->write('app/Providers/AuthServiceProvider.php', 'namespace App\Providers; class AuthServiceProvider { public function boot() { \Illuminate\Support\Facades\Gate::define("update", [\App\Alternative::class, "run"]); } }');
        $r = $this->path('InvoiceAccess::ensureCanEdit', 'Alternative::run');
        $this->assertFalse($r['execution']['found']);
        $this->assertStringContainsString('ability fallback cannot be assumed', json_encode($r['execution']['notices']));
    }

    public function test_duplicate_policy_declaration_never_falls_back_to_defined_ability(): void
    {
        $this->fixture();
        $this->write('app/Policies/InvoicePolicyCopy.php', 'namespace App\Policies; class InvoicePolicy { public function update($user) {} }');
        $this->write('app/Alternative.php', 'namespace App; class Alternative { public static function run($user) {} }');
        $this->write('app/Providers/AuthServiceProvider.php', 'namespace App\Providers; class AuthServiceProvider { public function boot() { \Illuminate\Support\Facades\Gate::define("update", [\App\Alternative::class, "run"]); } }');
        $r = $this->path('InvoiceAccess::ensureCanEdit', 'Alternative::run');
        $this->assertFalse($r['execution']['found']);
        $this->assertStringContainsString('Duplicate execution class declaration', json_encode($r['execution']['notices']));
        $this->assertStringContainsString('ability fallback cannot be assumed', json_encode($r['execution']['notices']));
    }

    public function test_route_closure_and_arrow_request_parameters_keep_endpoint_identity(): void
    {
        $this->fixture();
        $this->write('app/EditInvoice.php', 'namespace App; class EditInvoice extends \Illuminate\Foundation\Http\FormRequest { public function authorize() { return \Illuminate\Support\Facades\Gate::allows("update", \App\Models\Invoice::class); } }');
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::post("/closure", function (\App\EditInvoice $request) {}); Route::post("/arrow", fn (\App\EditInvoice $request) => true); Route::get("/unrelated", fn () => true);');
        $impact = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect('EditInvoice::authorize', [], 100, 12);
        $this->assertCount(2, $impact['execution']['flows'], json_encode($impact));
        $this->assertSame(['/closure', '/arrow'], array_column(array_column(array_column($impact['execution']['flows'], 'entry'), 'route'), 'uri'));
        $impact = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect('InvoicePolicy::update', [], 100, 12);
        $this->assertCount(2, $impact['execution']['flows']);
        $this->assertStringNotContainsString('/unrelated', json_encode($impact['execution']['flows']));
    }

    public function test_route_callback_can_maps_its_typed_model_parameter(): void
    {
        $this->fixture();
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::post("/closure/{invoice}", function (\App\Models\Invoice $invoice) {})->can("update", "invoice"); Route::post("/arrow/{invoice}", fn (\App\Models\Invoice $invoice) => true)->middleware("can:update,invoice"); Route::get("/unrelated", fn () => true);');
        $impact = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect('InvoicePolicy::update', [], 100, 12);
        $this->assertCount(2, $impact['execution']['flows'], json_encode($impact));
        $this->assertSame(['/closure/{invoice}', '/arrow/{invoice}'], array_column(array_column(array_column($impact['execution']['flows'], 'entry'), 'route'), 'uri'));
    }

    public function test_mixed_parameters_allow_guests_and_untyped_parameters_do_not(): void
    {
        $this->fixture();
        $this->write('app/Policies/InvoicePolicy.php', 'namespace App\Policies; class InvoicePolicy { public function before(mixed $user) { return null; } public function update(mixed $user, \App\Models\Invoice $invoice) { return true; } }');
        $this->write('app/Providers/AuthServiceProvider.php', 'namespace App\Providers; class AuthServiceProvider { public function boot() { \Illuminate\Support\Facades\Gate::before(fn (mixed $user) => null); \Illuminate\Support\Facades\Gate::after(fn ($user) => null); } }');
        $r = $this->path('InvoiceAccess::ensureCanEdit', 'InvoicePolicy::update');
        $this->assertStringContainsString('Authenticated users and guests are eligible', json_encode($r['execution']['paths']));
        $r = $this->path('InvoiceAccess::ensureCanEdit', 'app/Providers/AuthServiceProvider.php');
        $this->assertStringContainsString('Callback signature allows guests', json_encode($r['execution']['paths']));
        $this->assertStringContainsString('Callback requires a non-null user', json_encode($r['execution']['paths']));
    }

    public function test_consumed_results_require_verification_without_changing_direct_usage(): void
    {
        $code = '<?php namespace App; use Illuminate\Support\Facades\Gate; class Usage { public function run() { consume(Gate::allows("edit")); return transform(Gate::allows("edit")); } public function branch() { if (Gate::allows("edit")) {} Gate::allows("edit"); return Gate::allows("edit"); } }';
        $facts = (new ExecutionExtractor)->extract(new FileContext('app/Usage.php', $code));
        $checks = (new ExecutionLinks($facts))->authorizationChecks;
        $this->assertSame(['argument_requires_check', 'argument_requires_check', 'conditional_branch', 'ignored', 'returned'], array_column($checks, 'usage'));
    }

    public function test_union_callback_requests_do_not_invent_form_request_execution(): void
    {
        $this->fixture();
        $this->write('app/EditInvoice.php', 'namespace App; class EditInvoice extends \Illuminate\Foundation\Http\FormRequest { public function authorize() { return true; } }');
        $this->write('app/OtherRequest.php', 'namespace App; class OtherRequest extends \Illuminate\Foundation\Http\FormRequest { public function authorize() { return true; } }');
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::post("/union", fn (\App\EditInvoice|\App\OtherRequest $request) => true);');
        foreach (['EditInvoice::authorize', 'OtherRequest::authorize'] as $subject) {
            $impact = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect($subject, [], 100, 12);
            $this->assertSame([], $impact['execution']['flows']);
            $this->assertStringContainsString('not a resolvable named class type', json_encode($impact['execution']['flow_analysis']['unresolved']));
        }
    }

    public function test_union_model_parameter_does_not_prove_can_policy_mapping(): void
    {
        $this->fixture();
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::post("/union/{invoice}", fn (\App\Models\Invoice|int $invoice) => true)->can("update", "invoice");');
        $impact = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect('InvoicePolicy::update', [], 100, 12);
        $this->assertSame([], $impact['execution']['flows']);
        $this->assertStringContainsString('argument binding is unresolved', json_encode($impact['execution']['flow_analysis']['unresolved']));
        $this->write('app/InvoiceController.php', 'namespace App; class InvoiceController { public function update(\App\Models\Invoice|int $invoice) {} }');
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::post("/union/{invoice}", [\App\InvoiceController::class, "update"])->can("update", "invoice");');
        $impact = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect('InvoicePolicy::update', [], 100, 12);
        $this->assertSame([], $impact['execution']['flows']);
    }

    public function test_nullable_callback_class_parameters_remain_resolvable(): void
    {
        $this->fixture();
        $this->write('app/EditInvoice.php', 'namespace App; class EditInvoice extends \Illuminate\Foundation\Http\FormRequest { public function authorize() { return true; } }');
        $this->write('routes/web.php', 'use Illuminate\Support\Facades\Route; Route::post("/nullable", fn (?\App\EditInvoice $request) => true); Route::post("/named-null", fn (\App\EditInvoice|null $request) => true);');
        $impact = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope))->inspect('EditInvoice::authorize', [], 100, 12);
        $this->assertCount(2, $impact['execution']['flows'], json_encode($impact));
    }
}
