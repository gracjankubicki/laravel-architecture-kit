<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

final class ControllerReadAuditTest extends TestCase
{
    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $source);
    }

    private function fixture(string $body = 'return Invoice::query()->get();', string $extra = ''): void
    {
        $this->write('app/Models/Invoice.php', '<?php namespace App\Models; final class Invoice extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Services/ViewService.php', '<?php namespace App\Services; use App\Models\Invoice; use Illuminate\Support\Facades\DB; final class ViewService { public function load(Invoice $invoice) { '.$body.' } '.$extra.' }');
        $this->write('app/Http/Controllers/PlanningController.php', <<<'PHP'
<?php
namespace App\Http\Controllers;
use App\Services\ViewService;
use App\Models\Invoice;
final class PlanningController {
    public function show(ViewService $service, Invoice $invoice) {
        return $service->load($invoice);
    }
}
PHP);
    }

    /** @return list<AuditFinding> */
    private function audit(array $verbs = ['GET', 'HEAD'], array $extra = []): array
    {
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers, Architecture::Actions, Architecture::Services, ...$extra],
            changedOnly: false,
            routes: new RouteMap(['app\http\controllers\planningcontroller::show' => $verbs]),
        );

        return array_values(array_filter($result->findings, fn ($f) => $f->rule === 'thin-controller'));
    }

    public function test_read_service_is_accepted_without_examining_its_unused_write_method(): void
    {
        $this->fixture(extra: 'public function store() { Invoice::create([]); }');
        $this->assertSame([], $this->audit());
    }

    public function test_write_service_is_reported_once_at_the_call_not_import_or_parameter(): void
    {
        $this->fixture();
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            $findings = $this->audit([$verb]);
            $this->assertCount(1, $findings);
            $this->assertSame('W_THIN_CONTROLLER_SERVICE_DEPENDENCY', $findings[0]->code);
            $this->assertSame(7, $findings[0]->line);
        }
    }

    public function test_import_and_unused_injected_parameter_are_not_dependencies(): void
    {
        $this->fixture();
        $path = $this->tempPath.'/app/Http/Controllers/PlanningController.php';
        file_put_contents($path, str_replace('return $service->load($invoice);', 'return 1;', file_get_contents($path)));
        $this->assertSame([], $this->audit(['POST']));
    }

    #[DataProvider('writes')]
    public function test_read_endpoint_reports_an_effect_with_its_call_chain(string $body): void
    {
        $this->fixture($body);
        $findings = $this->audit();
        $this->assertCount(1, $findings);
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $findings[0]->code);
        $this->assertStringContainsString('PlanningController::show -> App\Services\ViewService::load', $findings[0]->message);
        $this->assertStringContainsString('app/Services/ViewService.php:1', $findings[0]->message);
    }

    public static function writes(): iterable
    {
        foreach (['save', 'saveQuietly', 'delete', 'touch', 'increment'] as $method) {
            yield $method => ['$invoice->'.$method.'("total");'];
        }
        foreach (['update', 'insert', 'upsert', 'delete', 'increment'] as $method) {
            yield 'builder '.$method => ['Invoice::query()->where("id", 1)->'.$method.'([]);'];
        }
        yield 'DB update' => ['DB::update("update invoices set total = 1");'];
        yield 'DB table' => ['DB::table("invoices")->delete();'];
        yield 'transaction' => ['DB::transaction(function () use ($invoice) { $invoice->save(); });'];
        yield 'relation' => ['$invoice->belongsToMany(Invoice::class)->sync([1]);'];
        yield 'mail' => ['\Illuminate\Support\Facades\Mail::to("a@example.test")->send("message");'];
        yield 'dispatch' => ['dispatch("job");'];
        yield 'notification' => ['$invoice->notify("notification");'];
        yield 'notification route dispatch' => ['\Illuminate\Support\Facades\Notification::route("mail", "a@example.test")->notify("notification");'];
        yield 'bus dispatch' => ['\Illuminate\Support\Facades\Bus::dispatch("job");'];
        yield 'event dispatch' => ['\Illuminate\Support\Facades\Event::dispatch("event");'];
        yield 'notification send' => ['\Illuminate\Support\Facades\Notification::send([], "notification");'];
        yield 'match arm' => ['return match ($flag) { true => $invoice->save(), default => null };'];
        yield 'array key' => ['return [$invoice->save() => 1];'];
        yield 'assignment target' => ['$items[$invoice->save()] = 1;'];
        yield 'dynamic method expression' => ['$invoice->{$invoice->save()}();'];
    }

    #[DataProvider('unknowns')]
    public function test_uncertainty_is_explicit(string $body, string $extra): void
    {
        $this->fixture($body, $extra);
        $findings = $this->audit();
        $this->assertCount(1, $findings);
        $this->assertSame('W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', $findings[0]->code);
    }

    public static function unknowns(): iterable
    {
        yield 'dynamic method' => ['$name = "save"; $invoice->$name();', ''];
        yield 'unknown receiver' => ['$client->save();', ''];
        yield 'dynamic SQL' => ['DB::statement($sql);', ''];
        yield 'cycle' => ['return $this->again();', 'private function again() { return $this->load(null); }'];
        yield 'interface' => ['return $this->remote->load();', 'public \App\Ports\RemoteReader $remote;'];
        yield 'transaction callback' => ['DB::transaction($callback);', ''];
        yield 'branch ambiguity' => ['if ($flag) { $x = $invoice; } else { $x = new \App\Data\Unknown; } $x->save();', ''];
        yield 'ternary ambiguity' => ['$x = $flag ? $invoice : null; $x->save();', ''];
        yield 'short circuit assignment' => ['$x = $invoice; $flag && ($x = null); $x->save();', ''];
        yield 'unpacked arguments' => ['$this->save(...$args);', 'private function save($value) { $value->save(); }'];
        yield 'event inspection is not dispatch' => ['\Illuminate\Support\Facades\Event::hasListeners("event");', ''];
    }

    public function test_transaction_without_write_is_not_a_write(): void
    {
        $this->fixture('DB::transaction(fn () => Invoice::query()->count());');
        $this->assertSame([], $this->audit());
    }

    public function test_named_transaction_callback_without_write_is_not_a_write(): void
    {
        $this->fixture('DB::transaction(attempts: 2, callback: fn () => Invoice::query()->count());');
        $this->assertSame([], $this->audit(['HEAD']));
    }

    public function test_same_named_method_on_local_non_model_is_inspected(): void
    {
        $this->fixture('return (new \App\Data\Preferences)->save();');
        $this->write('app/Data/Preferences.php', '<?php namespace App\Data; final class Preferences { public function save() { return 1; } }');
        $this->assertSame([], $this->audit());
    }

    public function test_named_arguments_are_bound_by_parameter_name(): void
    {
        $this->fixture('$this->save(other: null, value: $invoice);', 'private function save($value, $other) { $value->save(); }');
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $this->audit()[0]->code);
    }

    public function test_statements_after_an_unconditional_return_are_not_reachable(): void
    {
        $this->fixture('return Invoice::query()->count(); $invoice->save();');
        $this->assertSame([], $this->audit());
    }

    public function test_concrete_write_is_retained_after_many_unknown_calls(): void
    {
        $this->fixture(str_repeat('$unknown->call();', 30).'$invoice->save();');
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $this->audit()[0]->code);
    }

    public function test_deep_helper_write_and_mixed_route_are_reported(): void
    {
        $this->fixture('return $this->fetch();', 'private function fetch() { Invoice::create([]); }');
        $finding = $this->audit(['GET', 'HEAD', 'POST'])[0];
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $finding->code);
        $this->assertStringContainsString('ViewService::fetch', $finding->message);
    }

    public function test_constructor_dependency_is_associated_with_actual_endpoint_usage(): void
    {
        $this->fixture();
        $this->write('app/Http/Controllers/PlanningController.php', <<<'PHP'
<?php
namespace App\Http\Controllers;
final class PlanningController {
    public function __construct(private \App\Services\ViewService $service) {}
    public function show(\App\Models\Invoice $invoice) { return $this->service->load($invoice); }
    public function store() { return 1; }
}
PHP);
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers, Architecture::Actions, Architecture::Services], false,
            routes: new RouteMap(['app\http\controllers\planningcontroller::show' => ['GET'], 'app\http\controllers\planningcontroller::store' => ['POST']]),
        );
        $this->assertSame([], $result->findings);
    }

    public function test_unresolved_route_is_reported_without_accusing_the_service_of_writing(): void
    {
        $this->fixture();
        $this->assertSame('W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', $this->audit([])[0]->code);
    }

    public function test_query_objects_have_a_distinct_read_advisory(): void
    {
        $this->fixture();
        $this->assertSame('W_THIN_CONTROLLER_READ_SERVICE', $this->audit(extra: [Architecture::QueryObjects])[0]->code);
    }

    public function test_notification_routing_without_sending_is_not_an_effect(): void
    {
        $this->fixture('\Illuminate\Support\Facades\Notification::route("mail", "a@example.test");');
        $this->assertSame([], $this->audit());
    }

    public function test_public_helper_is_only_analysed_when_reached_from_a_registered_endpoint(): void
    {
        $this->fixture('$invoice->save();');
        $this->write('app/Http/Controllers/PlanningController.php', <<<'PHP'
<?php
namespace App\Http\Controllers;
final class PlanningController {
    public function show() { return 1; }
    public function helper(\App\Services\ViewService $service, \App\Models\Invoice $invoice) { return $service->load($invoice); }
}
PHP);
        $this->assertSame([], $this->audit());
        $path = $this->tempPath.'/app/Http/Controllers/PlanningController.php';
        file_put_contents($path, str_replace('return 1;', 'return $this->helper(new \App\Services\ViewService, new \App\Models\Invoice);', file_get_contents($path)));
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $this->audit()[0]->code);
    }

    public function test_unavailable_routes_are_explicit_for_direct_writes_without_a_service(): void
    {
        $this->fixture();
        $this->write('app/Http/Controllers/PlanningController.php', '<?php namespace App\Http\Controllers; final class PlanningController { public function show(\App\Models\Invoice $invoice) { $invoice->save(); } }');
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers, Architecture::Actions, Architecture::Services], false,
            routes: new RouteMap(unavailable: 'Test route bootstrap failure.'),
        );
        $incomplete = array_values(array_filter($result->findings, fn ($f) => $f->code === 'W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE'));
        $this->assertCount(1, $incomplete);
        $this->assertStringContainsString('Test route bootstrap failure.', $incomplete[0]->message);
    }

    public function test_available_empty_route_collection_has_no_endpoints_to_analyse(): void
    {
        $this->fixture();
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers, Architecture::Actions, Architecture::Services], false,
            routes: new RouteMap,
        );
        $this->assertSame([], $result->findings);
    }

    public function test_unavailable_routes_are_reported_for_a_controller_with_only_inherited_endpoints(): void
    {
        $this->fixture();
        $this->write('app/Foundation/BaseController.php', '<?php namespace App\Foundation; class BaseController { public function show(\App\Models\Invoice $invoice) { $invoice->save(); } }');
        $this->write('app/Http/Controllers/PlanningController.php', '<?php namespace App\Http\Controllers; final class PlanningController extends \App\Foundation\BaseController {}');
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers, Architecture::Actions, Architecture::Services], false,
            routes: new RouteMap(unavailable: 'Test route bootstrap failure.'),
        );
        $this->assertCount(1, $result->findings);
        $this->assertSame('W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', $result->findings[0]->code);
        $this->assertSame('app/Http/Controllers/PlanningController.php', $result->findings[0]->path);
        $this->assertSame(1, $result->findings[0]->line);
    }

    public function test_read_effect_can_be_suppressed_at_the_controller_method(): void
    {
        $this->fixture('$invoice->save();');
        $path = $this->tempPath.'/app/Http/Controllers/PlanningController.php';
        file_put_contents($path, str_replace('    public function show', "    // @architecture-kit-ignore thin-controller -- reviewed endpoint\n    public function show", file_get_contents($path)));
        $this->assertSame([], $this->audit());
    }

    public function test_effects_in_service_constructors_are_not_ignored(): void
    {
        $this->fixture(extra: 'public function __construct() { Invoice::create([]); }');
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $this->audit()[0]->code);
    }

    public function test_type_from_a_local_assignment_is_followed(): void
    {
        $this->fixture('$query = Invoice::query(); $invoice = $query->first(); $invoice->save();');
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $this->audit()[0]->code);
    }

    public function test_method_depth_limit_reports_incomplete_instead_of_succeeding(): void
    {
        $methods = '';
        for ($i = 0; $i < 20; $i++) {
            $methods .= 'private function step'.$i.'() { return $this->step'.($i + 1).'(); }';
        }
        $this->fixture('return $this->step0();', $methods);
        $this->assertSame('W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', $this->audit()[0]->code);
        $this->assertStringContainsString('limit', $this->audit()[0]->message);
    }

    public function test_oversized_dependency_reports_incomplete_instead_of_succeeding(): void
    {
        $this->fixture('/*'.str_repeat('x', 100_001).'*/ return 1;');
        $this->assertSame('W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', $this->audit()[0]->code);
    }

    public function test_read_finding_uses_the_existing_baseline_contract(): void
    {
        $this->fixture('$invoice->save();');
        $audit = new ApplicationAudit(new Filesystem, $this->tempPath);
        $enabled = [Architecture::ThinControllers, Architecture::Actions, Architecture::Services];
        $routes = new RouteMap(['app\http\controllers\planningcontroller::show' => ['GET']]);
        $before = $audit->run($enabled, false, useBaseline: false, updateBaseline: true, routes: $routes);
        $this->assertCount(1, $before->findings);
        $after = $audit->run($enabled, false, useBaseline: true, routes: $routes);
        $this->assertSame([], $after->findings);
        $this->assertSame(1, $after->suppressedBaseline);
    }

    public function test_unchanged_controller_is_checked_after_its_service_changes_with_warm_graph_cache(): void
    {
        $this->fixture();
        foreach ([['init', '-b', 'main'], ['add', '.'], ['-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-m', 'fixture']] as $args) {
            (new Process(['git', ...$args], $this->tempPath))->mustRun();
        }
        $audit = new ApplicationAudit(new Filesystem, $this->tempPath);
        $cache = new ProjectGraphCache(new Filesystem, $this->tempPath);
        $enabled = [Architecture::ThinControllers, Architecture::Actions, Architecture::Services];
        $routes = new RouteMap(['app\http\controllers\planningcontroller::show' => ['GET']]);
        $audit->run($enabled, false, cache: $cache, routes: $routes);
        $this->fixture('$invoice->save();');
        clearstatcache();
        $result = $audit->run($enabled, true, cache: $cache, routes: $routes);
        $effects = array_values(array_filter($result->findings, fn ($f) => $f->code === 'E_THIN_CONTROLLER_READ_SIDE_EFFECT'));
        $this->assertCount(1, $effects);
        $this->assertSame('app/Http/Controllers/PlanningController.php', $effects[0]->path);
    }

    public function test_inherited_controller_endpoint_is_checked(): void
    {
        $this->fixture('$invoice->save();');
        $original = file_get_contents($this->tempPath.'/app/Http/Controllers/PlanningController.php');
        $this->write('app/Http/Controllers/BaseController.php', str_replace('final class PlanningController', 'class BaseController', $original));
        $this->write('app/Http/Controllers/PlanningController.php', '<?php namespace App\Http\Controllers; final class PlanningController extends BaseController {}');
        $effects = array_values(array_filter($this->audit(), fn ($f) => $f->code === 'E_THIN_CONTROLLER_READ_SIDE_EFFECT'));
        $this->assertCount(1, $effects);
    }

    #[DataProvider('routeInputs')]
    public function test_changed_route_inputs_recheck_unchanged_controllers(string $input, bool $deleted): void
    {
        $this->fixture('$invoice->save();');
        $this->write($input, '<?php // original');
        foreach ([['init', '-b', 'main'], ['add', '.'], ['-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-m', 'fixture']] as $args) {
            (new Process(['git', ...$args], $this->tempPath))->mustRun();
        }
        if ($deleted) {
            unlink($this->tempPath.'/'.$input);
        } else {
            $this->write($input, '<?php // edited');
        }
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers, Architecture::Actions, Architecture::Services], true,
            routes: new RouteMap(['app\http\controllers\planningcontroller::show' => ['GET']]),
        );
        $effects = array_values(array_filter($result->findings, fn ($f) => $f->code === 'E_THIN_CONTROLLER_READ_SIDE_EFFECT'));
        $this->assertCount(1, $effects);
    }

    public static function routeInputs(): iterable
    {
        yield 'route edit' => ['routes/web.php', false];
        yield 'route deletion' => ['routes/web.php', true];
        yield 'provider edit' => ['app/Providers/RoutesProvider.php', false];
        yield 'bootstrap edit' => ['bootstrap/app.php', false];
        yield 'config edit' => ['config/routes.php', false];
        yield 'dependency deletion' => ['app/Data/Deleted.php', true];
    }
}
