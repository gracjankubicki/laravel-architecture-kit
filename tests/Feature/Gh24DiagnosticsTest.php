<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\ControllerAnalysisResult;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\ControllerReadAudit;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;

final class Gh24DiagnosticsTest extends TestCase
{
    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $source);
    }

    private function fixture(string $body, string $declarations = ''): void
    {
        $this->write('app/Models/Invoice.php', '<?php namespace App\Models; class Invoice extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Http/Controllers/ProbeController.php', '<?php namespace App\Http\Controllers; use App\Models\Invoice; '.$declarations.' class ProbeController { public function show() { '.$body.' } }');
    }

    private function audit(bool $effectsOnly = false): ApplicationAuditResult|ControllerAnalysisResult
    {
        if ($effectsOnly) {
            $files = new Filesystem;
            $contexts = array_map(fn ($file) => new FileContext('app/'.$file->getRelativePathname(), $files->get($file->getPathname())), $files->allFiles($this->tempPath.'/app'));

            return (new ControllerReadAudit($files, $this->tempPath))->analyze((new ProjectGraphBuilder)->build($contexts),
                [Architecture::ThinControllers, Architecture::Actions, Architecture::Services], null,
                new RouteMap(['app\\http\\controllers\\probecontroller::show' => ['GET']]));
        }

        return (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers, Architecture::Actions, Architecture::Services],
            changedOnly: false,
            routes: new RouteMap(['app\\http\\controllers\\probecontroller::show' => ['GET']]),
        );
    }

    #[DataProvider('knownCalls')]
    public function test_known_calls_do_not_create_incomplete_notices(string $body): void
    {
        $this->fixture($body);
        $this->write('app/Enums/Status.php', '<?php namespace App\Enums; enum Status: string { case Active = "active"; public function label(): string { return "Active"; } }');
        $result = $this->audit();
        $this->assertSame([], $result->notices);
        $this->assertSame([], $result->suggestions);
    }

    public static function knownCalls(): iterable
    {
        yield ['return json_decode("{}");'];
        yield ['return hash("sha256", "value");'];
        yield ['return hash_equals("one", "two");'];
        yield ['preg_match("/a/", "a", $matches); return count($matches);'];
        yield ['return \Carbon\CarbonImmutable::now()->copy()->format("Y");'];
        yield ['return now()->toImmutable()->addDay()->format("Y");'];
        yield ['return \App\Enums\Status::from("active")->label();'];
        yield ['return \App\Enums\Status::tryFrom("active")?->label();'];
        yield ['return \App\Enums\Status::cases()[0]->label();'];
        yield ['return array_map(fn ($status) => $status->label(), \App\Enums\Status::cases());'];
        yield ['return array_map(null, [1]);'];
        yield ['return array_filter([1]);'];
        yield ['return preg_match(pattern: "/a/", subject: "a", matches: $matches);'];
    }

    #[DataProvider('symbolNames')]
    public function test_unresolved_names_do_not_change_the_structural_reason(string $name): void
    {
        $this->fixture('return \App\Models\\'.$name.'::missing();');
        $this->write('app/Models/'.$name.'.php', '<?php namespace App\Models; class '.$name.' {}');
        $notices = $this->audit()->notices;
        $this->assertCount(1, $notices);
        $this->assertSame('unresolved_call', $notices[0]->reason);
        $this->assertSame('A_CALL_UNRESOLVED', $notices[0]->code);
    }

    public static function symbolNames(): iterable
    {
        foreach (['ProjectGoogleResource', 'SourceBudgetLimitSql', 'CycleResource', 'GoogleResourceType'] as $name) {
            yield [$name];
        }
    }

    public function test_true_source_budget_keeps_its_reason_and_location(): void
    {
        $this->fixture('return \App\Models\LargeResource::missing();');
        $this->write('app/Models/LargeResource.php', '<?php namespace App\Models; /*'.str_repeat('x', 100_000).'*/ class LargeResource {}');
        $notices = $this->audit()->notices;
        $this->assertCount(1, $notices);
        $this->assertSame('source_budget', $notices[0]->reason);
        $this->assertSame('A_SOURCE_UNAVAILABLE', $notices[0]->code);
        $this->assertSame('app/Http/Controllers/ProbeController.php', $notices[0]->path);
    }

    public function test_actual_cycle_keeps_analysis_limit(): void
    {
        $this->fixture('return $this->source();');
        $this->write('app/Http/Controllers/ProbeController.php', '<?php namespace App\Http\Controllers; class ProbeController { public function show() { return $this->source(); } public function source() { return $this->source(); } }');
        $notices = $this->audit()->notices;
        $this->assertCount(1, $notices);
        $this->assertSame('analysis_limit', $notices[0]->reason);
    }

    public function test_middleware_source_budget_survives_framework_context_and_materialization(): void
    {
        $this->fixture('return \Inertia\Inertia::render("Page");');
        $this->write('app/Http/Middleware/LargeResource.php', '<?php namespace App\Http\Middleware; /*'.str_repeat('x', 100_000).'*/ class LargeResource {}');
        $routes = new RouteMap(entries: [new RouteEntry(['GET'], 'probe', class: 'App\\Http\\Controllers\\ProbeController', method: 'show', middleware: ['large'])],
            context: ['status' => 'known', 'providers' => [], 'middleware' => [], 'middlewareGroups' => [], 'middlewareAliases' => ['large' => 'App\\Http\\Middleware\\LargeResource'], 'packageVersions' => []]);
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([Architecture::ThinControllers], changedOnly: false, routes: $routes);
        $this->assertCount(1, $result->notices);
        $this->assertSame('source_budget', $result->notices[0]->reason);
        $this->assertSame('A_SOURCE_UNAVAILABLE', $result->notices[0]->code);
    }

    #[DataProvider('frameworkCallables')]
    public function test_framework_callable_source_budget_is_distinct_from_missing_entrypoint(string $kind, bool $oversized): void
    {
        $this->write('app/Auth/SourceLimitSql.php', '<?php namespace App\Auth; /*'.str_repeat('x', $oversized ? 100_000 : 1).'*/ class SourceLimitSql {}');
        if ($kind === 'fortify') {
            $this->write('app/Providers/FortifyServiceProvider.php', '<?php namespace App\Providers; class FortifyServiceProvider { public function boot() { \Laravel\Fortify\Fortify::authenticateThrough(fn () => [\App\Auth\SourceLimitSql::class]); } }');
            $routes = new RouteMap(entries: [new RouteEntry(['POST'], 'login', class: 'Laravel\\Fortify\\Http\\Controllers\\AuthenticatedSessionController', method: 'store')],
                context: ['status' => 'known', 'providers' => ['App\\Providers\\FortifyServiceProvider'], 'middleware' => [], 'middlewareGroups' => [], 'middlewareAliases' => [], 'packageVersions' => ['laravel/fortify' => '1.x']]);
            $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([Architecture::ThinControllers], changedOnly: false, routes: $routes);
        } else {
            $this->fixture($kind === 'lazy' ? 'return \Inertia\Inertia::lazy([\App\Auth\SourceLimitSql::class, "build"]);'
                : 'return \Inertia\Inertia::render("Page", ["value" => [\App\Auth\SourceLimitSql::class, "build"]]);');
            $result = $this->audit();
        }
        $this->assertCount(1, $result->notices);
        $this->assertSame($oversized ? 'source_budget' : 'unresolved_call', $result->notices[0]->reason);
        $this->assertSame($oversized ? 'A_SOURCE_UNAVAILABLE' : 'A_CALL_UNRESOLVED', $result->notices[0]->code);
    }

    public static function frameworkCallables(): iterable
    {
        foreach (['lazy', 'render', 'fortify'] as $kind) {
            foreach ([false, true] as $oversized) {
                yield [$kind, $oversized];
            }
        }
    }

    #[DataProvider('overrides')]
    public function test_project_functions_take_precedence_and_keep_effects(string $body, string $local, string $helper): void
    {
        $this->fixture($body, $local);
        if ($helper !== '') {
            $this->write('app/helpers.php', '<?php '.$helper);
            $this->write('composer.json', '{"autoload":{"files":["app/helpers.php"]}}');
        }
        $result = $this->audit(true);
        $this->assertSame([], $result->notices);
        $this->assertContains('S_MOVE_WRITE_TO_ACTION', array_column($result->suggestions, 'code'));
    }

    public static function overrides(): iterable
    {
        yield ['return hash("sha256", "value");', 'function hash($algorithm, $data) { Invoice::create([]); return "x"; }', ''];
        yield ['return local_hash("sha256", "value");', 'use function App\Helpers\hash as local_hash;', 'namespace App\Helpers; function hash($a, $b) { \App\Models\Invoice::create([]); return "x"; }'];
        yield ['return now()->toImmutable();', '', 'function now() { \App\Models\Invoice::create([]); return \Carbon\CarbonImmutable::now(); }'];
        yield ['return hash("sha256", "value");', '', 'namespace App\Http\Controllers; throw new \RuntimeException("Do not execute helpers"); function hash($a, $b) { \App\Models\Invoice::create([]); return "x"; }'];
    }

    public function test_explicit_global_builtin_does_not_use_namespace_shadow(): void
    {
        $this->fixture('return \hash("sha256", "value");', 'function hash($a, $b) { Invoice::create([]); return "x"; }');
        $result = $this->audit();
        $this->assertSame([], $result->notices);
        $this->assertSame([], $result->suggestions);
    }

    public function test_carbon_override_and_enum_return_types_preserve_writes(): void
    {
        $this->fixture('\App\Models\CustomDate::now(); return \App\Enums\Status::cases()[0]->persist();');
        $this->write('app/Models/CustomDate.php', '<?php namespace App\Models; class CustomDate extends \Carbon\CarbonImmutable { public static function now($timezone = null) { Invoice::create([]); return new self; } }');
        $this->write('app/Enums/Status.php', '<?php namespace App\Enums; enum Status: string { case Active = "active"; public function persist() { \App\Models\Invoice::create([]); } }');
        $result = $this->audit(true);
        $this->assertSame([], $result->notices);
        $this->assertContains('S_MOVE_WRITE_TO_ACTION', array_column($result->suggestions, 'code'));
    }

    #[DataProvider('callbackCalls')]
    public function test_callback_effects_are_analyzed(string $body): void
    {
        $this->fixture($body);
        $result = $this->audit(true);
        $this->assertSame([], $result->notices);
        $this->assertContains('S_MOVE_WRITE_TO_ACTION', array_column($result->suggestions, 'code'));
    }

    public static function callbackCalls(): iterable
    {
        yield ['return array_map(fn ($item) => Invoice::create([]), [1]);'];
        yield ['return array_filter([1], fn ($item) => Invoice::create([]));'];
        yield ['return array_reduce([1], fn ($carry, $item) => Invoice::create([]));'];
        yield ['return preg_replace_callback("/a/", fn ($matches) => Invoice::create([]), "a");'];
        yield ['$callback = fn ($item) => Invoice::create([]); return array_map($callback, [1]);'];
    }

    #[DataProvider('dynamicCalls')]
    public function test_dynamic_or_invalid_cases_remain_incomplete(string $body, string $declarations): void
    {
        $this->fixture($body, $declarations);
        $this->assertNotEmpty($this->audit()->notices);
    }

    public static function dynamicCalls(): iterable
    {
        yield ['return array_map($unknown, [1]);', ''];
        yield ['return $unknown();', ''];
        yield ['return array_map("unresolved_callback", [1]);', ''];
        yield ['return json_decode("{}")->save();', ''];
        yield ['return \App\Http\Controllers\Unit::from("a");', 'enum Unit { case Active; }'];
        yield ['return hash("sha256", "value");', 'if (true) { function hash($a, $b) { Invoice::create([]); } }'];
    }
}
