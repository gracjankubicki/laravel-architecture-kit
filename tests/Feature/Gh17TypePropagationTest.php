<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceIndex;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\FactoryResolver;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\MethodReachability;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class Gh17TypePropagationTest extends TestCase
{
    public function test_instanceof_narrowing_keeps_the_write_in_one_union_branch(): void
    {
        $this->write('app/Models/Invoice.php', '<?php namespace App\Models; final class Invoice extends \Illuminate\Database\Eloquent\Model {}');
        $this->controller(<<<'PHP'
public function show(string|\App\Models\Invoice $value): void {
    if ($value instanceof \App\Models\Invoice) {
        $value->save();
        return;
    }
    strlen($value);
}
PHP);

        $result = $this->analyse($this->legacyRoutes());

        $this->assertCount(1, $result->suggestions);
        $this->assertSame([], $result->notices);
    }

    public function test_nullable_first_keeps_null_uncertainty_after_instanceof_narrowing(): void
    {
        $this->write('app/Models/Invoice.php', '<?php namespace App\Models; final class Invoice extends \Illuminate\Database\Eloquent\Model {}');
        $this->controller(<<<'PHP'
public function show(\App\Models\Invoice $invoice): void {
    $value = \App\Models\Invoice::query()->first();
    if ($value instanceof \App\Models\Invoice) {
        $value->save();
    }
}
PHP);

        $result = $this->analyse($this->legacyRoutes());

        $this->assertCount(1, $result->suggestions);
        $this->assertCount(1, $result->notices);
        $this->assertSame('incomplete', $result->analysisStatus);
    }

    public function test_date_union_uses_verified_carbon_contracts_without_domain_write(): void
    {
        $this->controller(<<<'PHP'
public function show(): void {
    $this->fromDate(new \Carbon\Carbon);
}
private function fromDate(string|\Carbon\Carbon $date): void {
    if ($date instanceof \Carbon\Carbon) {
        $date->copy()->startOfWeek();
        return;
    }
    \Illuminate\Support\Facades\Date::parse($date)->startOfWeek();
}
PHP);

        $result = $this->analyse($this->legacyRoutes());

        $this->assertSame([], $result->suggestions);
        $this->assertSame([], $result->notices);
        $this->assertSame('complete', $result->analysisStatus);
    }

    public function test_request_user_uses_route_guard_model_without_invoking_resolvers(): void
    {
        $this->write('app/Models/Admin.php', '<?php namespace App\Models; final class Admin extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Models/User.php', '<?php namespace App\Models; final class User extends \Illuminate\Database\Eloquent\Model {}');
        $this->controller('public function show(\Illuminate\Http\Request $request): void { $request->user()->touch(); }');
        $routes = $this->routesWithAuth('auth:admin', [
            'default' => 'web',
            'guards' => [
                'web' => ['driver' => 'session', 'provider' => 'users', 'model' => 'App\\Models\\User', 'custom' => false],
                'admin' => ['driver' => 'session', 'provider' => 'admins', 'model' => 'App\\Models\\Admin', 'custom' => false],
            ],
        ]);

        $result = $this->analyse($routes);

        $this->assertCount(1, $result->suggestions);
        $this->assertStringContainsString('Admin::touch', $result->suggestions[0]->reason);
        $this->assertSame([], $result->notices);
    }

    public function test_custom_user_provider_is_an_explicit_uncertainty(): void
    {
        $this->controller('public function show(\Illuminate\Http\Request $request): void { $request->user()->touch(); }');
        $routes = $this->routesWithAuth('auth:api', [
            'default' => 'api',
            'guards' => [
                'api' => ['driver' => 'custom', 'provider' => null, 'model' => null, 'custom' => true],
            ],
        ]);

        $result = $this->analyse($routes);

        $this->assertSame([], $result->suggestions);
        $this->assertCount(1, $result->notices);
        $this->assertSame('unresolved_receiver', $result->notices[0]->reason);
        $this->assertSame('incomplete', $result->analysisStatus);
    }

    public function test_get_key_and_tokens_use_the_resolved_user_model_contract(): void
    {
        $this->write('app/Models/User.php', '<?php namespace App\Models; final class User extends \Illuminate\Database\Eloquent\Model {}');
        $this->controller('public function show(\Illuminate\Http\Request $request) { return [$request->user()->getKey(), $request->user()->tokens]; }');
        $routes = $this->routesWithAuth('auth', [
            'default' => 'web',
            'guards' => [
                'web' => ['driver' => 'session', 'provider' => 'users', 'model' => 'App\\Models\\User', 'custom' => false],
            ],
        ]);

        $result = $this->analyse($routes);

        $this->assertSame([], $result->suggestions);
        $this->assertSame([], $result->notices);
    }

    public function test_method_reachability_follows_collection_callbacks_without_fake_coverage(): void
    {
        $files = new Filesystem;
        $this->write('app/Models/Invoice.php', '<?php namespace App\Models; final class Invoice extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Root.php', <<<'PHP'
<?php
namespace App;
use App\Models\Invoice;
final class Root {
    public function handle(Invoice $invoice): void {
        Invoice::query()->get()->map(function ($item) { (new Covered)->run(); return $item; });
    }
}
PHP);
        $this->write('app/Covered.php', '<?php namespace App; final class Covered { public function run(): void {} }');
        $this->write('app/Unused.php', '<?php namespace App; final class Unused { public function run(): void {} }');
        $graph = (new ProjectGraphLoader($files, $this->tempPath))->load();
        $sources = new SourceIndex($files, $this->tempPath, $graph);

        $result = (new MethodReachability($sources, new FactoryResolver($sources)))->analyze('App\\Root', 'handle');

        $this->assertSame([], $result->diagnostics);
        $this->assertArrayHasKey('app\\covered', $result->symbols);
        $this->assertArrayNotHasKey('app\\unused', $result->symbols);
    }

    private function analyse(RouteMap $routes): ApplicationAuditResult
    {
        return (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [],
            changedOnly: false,
            routes: $routes,
        );
    }

    private function legacyRoutes(): RouteMap
    {
        return new RouteMap(['app\http\controllers\frameworkcontroller::show' => ['GET', 'HEAD']]);
    }

    private function routesWithAuth(string $middleware, array $auth): RouteMap
    {
        $entry = new RouteEntry(
            ['GET', 'HEAD'],
            'profile',
            name: 'profile.show',
            class: 'App\\Http\\Controllers\\FrameworkController',
            method: 'show',
            middleware: [$middleware],
        );

        return new RouteMap(entries: [$entry], context: [
            'status' => FrameworkContext::KNOWN,
            'providers' => [],
            'middleware' => [],
            'middlewareGroups' => [],
            'middlewareAliases' => [],
            'packageVersions' => [],
            'auth' => $auth,
        ]);
    }

    private function controller(string $source): void
    {
        $this->write('app/Http/Controllers/FrameworkController.php', '<?php namespace App\Http\Controllers; final class FrameworkController { '.$source.' }');
    }

    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $source);
    }
}
