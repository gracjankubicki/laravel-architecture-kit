<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use PHPUnit\Framework\TestCase;

class StaticGraphPackageFortifyTest extends TestCase
{
    /** @return list<CatalogFacts> */
    private function facts(string $source, string $path = 'app/Fortify.php'): array
    {
        return (new ProjectGraphBuilder(catalog: true))->build([new FileContext($path, '<?php '.$source)])->catalogFacts;
    }

    private function package(string $version = '1.39.0', string $path = 'composer.lock'): CatalogFacts
    {
        return (new ComposerCatalogExtractor)->extract(new FileContext($path, json_encode(['packages' => [['name' => 'laravel/fortify', 'version' => $version]]], JSON_THROW_ON_ERROR)));
    }

    /** @return list<array<string, mixed>> */
    private function edges(CatalogIndex $index, string $kind): array
    {
        return array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
    }

    public function test_source_action_contracts_factories_views_and_auth_callbacks_keep_registration_separate(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Fortify\Fortify as Auth;
class BaseCreate implements \Laravel\Fortify\Contracts\CreatesNewUsers { public function create(array $input) {} }
class Create extends BaseCreate {}
class Reset implements \Laravel\Fortify\Contracts\ResetsUserPasswords { public function reset($user, array $input) {} }
class Profile implements \Laravel\Fortify\Contracts\UpdatesUserProfileInformation { public function update($user, array $input) {} }
class Password implements \Laravel\Fortify\Contracts\UpdatesUserPasswords { public function update($user, array $input) {} }
class Step { public function handle($request, $next) { return $next($request); } }
class Provider {
    public function boot() {
        Auth::createUsersUsing(callback: Create::class);
        Auth::resetUserPasswordsUsing(fn () => new Reset());
        Auth::updateUserProfileInformationUsing('App\Profile');
        Auth::updateUserPasswordsUsing(Password::class);
        Auth::loginView(view: 'auth.login');
        Auth::registerView(fn ($request) => view('auth.register'));
        Auth::authenticateUsing(fn ($request) => user());
        Auth::authenticateThrough(fn ($request) => [Step::class]);
    }
}
throw new \RuntimeException('source-only-secret-sentinel');
SOURCE);
        $this->assertStringNotContainsString('source-only-secret-sentinel', json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR));
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(8, $this->edges($index, 'registers-fortify-extension'));
        $actions = $this->edges($index, 'fortify-action-handler');
        $this->assertCount(4, $actions);
        $this->assertContains('App\\BaseCreate::create', array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $actions));
        $factories = $this->edges($index, 'fortify-action-factory');
        $this->assertCount(1, $factories);
        $this->assertTrue($factories[0]['metadata']['container_resolution_required']);
        $this->assertCount(3, $this->edges($index, 'fortify-request-callback'));
        $this->assertCount(1, $this->edges($index, 'fortify-renders-view'));
        $pipeline = $this->edges($index, 'fortify-authentication-stage');
        $this->assertCount(1, $pipeline);
        $this->assertSame(0, $pipeline[0]['metadata']['pipeline_position']);
        foreach ([...$actions, ...$pipeline] as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertTrue($edge['metadata']['runtime_extension_selected_required']);
            $this->assertTrue($edge['metadata']['request_required']);
            $this->assertNotEmpty($edge['metadata']['package_sources']);
        }
        foreach ([[], [$this->package('99.0.0')], [$this->package(), $this->package('1.0.0', 'vendor/composer/installed.json')]] as $packages) {
            $index = new CatalogIndex([...$facts, ...$packages]);
            $this->assertSame([], $this->edges($index, 'fortify-action-handler'));
            $this->assertSame([], $this->edges($index, 'fortify-request-callback'));
            $this->assertContains('package_fortify_analysis', array_column($index->diagnostics, 'code'));
        }
    }

    public function test_route_to_source_fortify_action_and_cached_registration_edits(): void
    {
        $routes = $this->facts(<<<'SOURCE'
\Illuminate\Support\Facades\Route::post('/register', [\Laravel\Fortify\Http\Controllers\RegisteredUserController::class, 'store']);
SOURCE, 'routes/web.php');
        $actions = $this->facts(<<<'SOURCE'
namespace App;
class First implements \Laravel\Fortify\Contracts\CreatesNewUsers { public function create(array $input) {} }
class Second implements \Laravel\Fortify\Contracts\CreatesNewUsers { public function create(array $input) {} }
SOURCE, 'app/Actions.php');
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), [...$routes, ...$actions]);
        foreach (['First', 'Second'] as $selected) {
            $registrations = $this->facts('\\Laravel\\Fortify\\Fortify::createUsersUsing(\\App\\'.$selected.'::class);', 'app/Provider.php');
            $index = new CatalogIndex([...$cached, ...$registrations, $this->package()]);
            $this->assertCount(1, $this->edges($index, 'fortify-extension-dispatch'));
            $handlers = $this->edges($index, 'fortify-action-handler');
            $this->assertCount(1, $handlers);
            $this->assertSame('App\\'.$selected.'::create', $index->elements[$handlers[0]['to']]['name']);
        }
        $index = new CatalogIndex([...$cached, $this->package()]);
        $this->assertSame([], $this->edges($index, 'fortify-extension-dispatch'));
    }

    public function test_lookalikes_wrong_contracts_dynamic_and_competing_registrations_are_explicit(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Decoy { public function create(array $input) {} public static function createUsersUsing($callback) {} }
class Wrong implements \Laravel\Fortify\Contracts\CreatesNewUsers { private function create(array $input) {} }
class First implements \Laravel\Fortify\Contracts\CreatesNewUsers { public function create(array $input) {} }
class Second implements \Laravel\Fortify\Contracts\CreatesNewUsers { public function create(array $input) {} }
Decoy::createUsersUsing(First::class);
\Laravel\Fortify\Fortify::createUsersUsing(Decoy::class);
\Laravel\Fortify\Fortify::createUsersUsing(Wrong::class);
\Laravel\Fortify\Fortify::createUsersUsing($dynamic);
\Laravel\Fortify\Fortify::createUsersUsing(First::class);
\Laravel\Fortify\Fortify::createUsersUsing(Second::class);
\Laravel\Fortify\Fortify::authenticateThrough(fn () => $dynamic);
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(2, $this->edges($index, 'fortify-action-handler'));
        $this->assertSame([], $this->edges($index, 'fortify-authentication-stage'));
        $this->assertContains('package_fortify_analysis', array_column($index->diagnostics, 'code'));
        $shadow = $this->facts('namespace Laravel\\Fortify; class Fortify {}', 'app/Shadow.php');
        $index = new CatalogIndex([...$facts, ...$shadow, $this->package()]);
        $this->assertSame([], $this->edges($index, 'registers-fortify-extension'));
    }
}
