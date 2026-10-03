<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Framework;

use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkContext;
use GracjanKubicki\ArchitectureKit\Audit\Framework\FrameworkContextBuilder;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceIndex;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;

final class FrameworkContextTest extends TestCase
{
    #[DataProvider('containerFactories')]
    public function test_container_factory_types_are_read_without_executing_the_factory(string $registration, array $expected, ?bool $unknown = null): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app/Providers');
        $files->put($this->tempPath.'/app/Providers/AppServiceProvider.php', '<?php namespace App\Providers; use App\Contracts\Processor; use App\Images\LocalProcessor as Local; use App\Images\CloudProcessor as Cloud; final class AppServiceProvider { public function register(): void { '.$registration.' } }');
        $context = (new FrameworkContextBuilder($this->sources($files), new RouteMap(context: [
            'status' => 'known', 'providers' => ['App\\Providers\\AppServiceProvider'],
            'middleware' => [], 'middlewareGroups' => [], 'middlewareAliases' => [], 'packageVersions' => [],
        ])))->build();

        $this->assertNull($context->unavailable);
        foreach ($expected as $contract => $types) {
            $candidate = $context->bindingCandidates[$contract];
            $this->assertSame($types, $candidate->typeNames());
            $isUnknown = $unknown ?? $types === [];
            $this->assertSame(count($types) === 1 && ! $isUnknown ? $types[0] : null, $context->bindings[$contract] ?? null);
            $this->assertSame($isUnknown, $candidate->isUnknown());
        }
    }

    public static function containerFactories(): array
    {
        $local = 'App\\Images\\LocalProcessor';
        $cloud = 'App\\Images\\CloudProcessor';
        $contract = 'App\\Contracts\\Processor';

        return [
            'singleton concrete' => ['$this->app->singleton(fn (): Local => new Local);', [$local => [$local]]],
            'bind contract' => ['$this->app->bind(fn (): Processor => new Local);', [$contract => [$local]]],
            'scoped anonymous' => ['$this->app->scoped(function (): Processor { return new Local; });', [$contract => [$local]]],
            'explicit contract' => ['$this->app->bind(Processor::class, fn () => new Local);', [$contract => [$local]]],
            'ternary' => ['$this->app->singleton(fn (): Processor => config("cloud") ? new Cloud : new Local);', [$contract => [$cloud, $local]]],
            'if else' => ['$this->app->bind(function (): Processor { if (config("cloud")) { return new Cloud; } return new Local; });', [$contract => [$cloud, $local]]],
            'branch assignments' => ['$this->app->scoped(function (): Processor { if (config("cloud")) { $p = new Cloud; } else { $p = new Local; } return $p; });', [$contract => [$cloud, $local]]],
            'match' => ['$this->app->singleton(fn (): Processor => match (config("cloud")) { true => new Cloud, default => new Local });', [$contract => [$cloud, $local]]],
            'union contracts' => ['$this->app->bind(fn (): Local|Cloud => config("cloud") ? new Cloud : new Local);', [$local => [$cloud, $local], $cloud => [$cloud, $local]]],
            'partial dynamic' => ['$this->app->singleton(fn (): Processor => config("cloud") ? new Local : ExternalFactory::make());', [$contract => [$local]], true],
            'condition mutates local' => ['$this->app->bind(function (): Processor { $p = new Local; if ($p = new Cloud) { return $p; } return $p; });', [$contract => []]],
            'nested assignment mutates local' => ['$this->app->bind(function (): Processor { $p = new Local; $unused = $p = new Cloud; return $p; });', [$contract => []]],
            'match arm condition mutates local' => ['$this->app->bind(function (): Processor { $p = new Local; return match (true) { ($p = new Cloud) instanceof Cloud => $p, default => $p }; });', [$contract => []]],
            'external call may mutate local by reference' => ['$this->app->bind(function (): Processor { $p = new Local; $unused = ExternalFactory::mutate($p); return $p; });', [$contract => []]],
            'parameter is unknown' => ['$this->app->bind(function (Processor $p): Processor { return $p; });', [$contract => []]],
            'fallthrough is unknown' => ['$this->app->bind(function (): Processor { if (config("cloud")) { return new Local; } });', [$contract => [$local]], true],
            'dynamic' => ['$this->app->singleton(fn (): Processor => ExternalFactory::make());', [$contract => []]],
            'never executed' => ['$this->app->singleton(function (): Processor { file_put_contents("/not-to-be-created", "bad"); return new Local; });', [$contract => []]],
            'nested return ignored' => ['$this->app->bind(function (): Processor { $factory = fn () => new Local; return ExternalFactory::make(); });', [$contract => []]],
        ];
    }

    public function test_conflicting_factory_registrations_do_not_keep_the_first_implementation_as_certain(): void
    {
        $files = new Filesystem;
        foreach ([
            '$this->app->singleton(fn (): Contract => new First); $this->app->singleton(fn (): Contract => new Second);',
            '$this->app->singleton(fn (): Contract => new First); $this->app->singleton(fn (): Contract => ExternalFactory::make());',
            '$this->app->bind(Contract::class, First::class); $this->app->singleton(fn (): Contract => new Second);',
            '$this->app->singleton(fn (): Contract => new First); $this->app->bind(Contract::class, Second::class);',
        ] as $registrations) {
            $files->ensureDirectoryExists($this->tempPath.'/app/Providers');
            $files->put($this->tempPath.'/app/Providers/AppServiceProvider.php', '<?php namespace App\Providers; final class AppServiceProvider { public function register(): void { '.$registrations.' } }');
            $context = (new FrameworkContextBuilder($this->sources($files), new RouteMap(context: [
                'status' => 'known', 'providers' => ['App\\Providers\\AppServiceProvider'],
                'middleware' => [], 'middlewareGroups' => [], 'middlewareAliases' => [], 'packageVersions' => [],
            ])))->build();
            $this->assertSame(FrameworkContext::UNAVAILABLE, $context->status);
            $this->assertTrue($context->bindingCandidates['App\\Providers\\Contract']->isAmbiguous());
        }
    }

    public function test_empty_known_and_unavailable_contexts_are_distinct(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app/Providers');
        $files->put($this->tempPath.'/app/Providers/AppServiceProvider.php', <<<'PHP'
<?php namespace App\Providers;
final class AppServiceProvider {
    public function boot(): void { \Inertia\Inertia::share('version', fn () => 'one'); }
}
PHP);
        $sources = $this->sources($files);

        $this->assertSame(FrameworkContext::EMPTY, (new FrameworkContextBuilder($sources))->build()->status);
        $this->assertSame(FrameworkContext::UNAVAILABLE, (new FrameworkContextBuilder($sources, new RouteMap))->build()->status);
        $this->assertSame(FrameworkContext::UNAVAILABLE, (new FrameworkContextBuilder($sources, new RouteMap(context: ['providers' => []])))->build()->status);

        $known = (new FrameworkContextBuilder($sources, new RouteMap(context: [
            'status' => 'known',
            'providers' => ['App\\Providers\\AppServiceProvider'],
            'middleware' => [],
            'middlewareGroups' => [],
            'middlewareAliases' => [],
            'packageVersions' => [],
        ])))->build();

        $this->assertSame(FrameworkContext::KNOWN, $known->status);
        $this->assertCount(1, $known->inertiaShares);
        $this->assertSame(['app/Providers/AppServiceProvider.php'], $known->origins);
    }

    public function test_provider_and_middleware_source_budget_failures_are_unavailable(): void
    {
        $files = new Filesystem;
        $padding = str_repeat('// source budget padding'.PHP_EOL, 5_000);
        $files->ensureDirectoryExists($this->tempPath.'/app/Providers');
        $files->put($this->tempPath.'/app/Providers/LargeProvider.php', "<?php namespace App\\Providers; final class LargeProvider { public function boot(): void {} }\n".$padding);
        $files->ensureDirectoryExists($this->tempPath.'/app/Http/Middleware');
        $files->put($this->tempPath.'/app/Http/Middleware/LargeMiddleware.php', "<?php namespace App\\Http\\Middleware; final class LargeMiddleware { public function share(): array { return []; } }\n".$padding);
        $baseContext = [
            'status' => 'known',
            'providers' => [],
            'middleware' => [],
            'middlewareGroups' => [],
            'middlewareAliases' => ['large' => 'App\\Http\\Middleware\\LargeMiddleware'],
            'packageVersions' => [],
        ];
        $route = new RouteEntry(['GET', 'HEAD'], 'large', class: 'App\\Http\\Controllers\\LargeController', method: 'show', middleware: ['large']);

        $provider = (new FrameworkContextBuilder(
            $this->sources($files),
            new RouteMap(context: [...$baseContext, 'providers' => ['App\\Providers\\LargeProvider']]),
        ))->build();
        $middleware = (new FrameworkContextBuilder(
            $this->sources($files),
            new RouteMap(entries: [$route], context: $baseContext),
            $route,
        ))->build();

        $this->assertSame(FrameworkContext::UNAVAILABLE, $provider->status);
        $this->assertStringContainsString('Source or memory budget exceeded', (string) $provider->unavailable);
        $this->assertSame(FrameworkContext::UNAVAILABLE, $middleware->status);
        $this->assertStringContainsString('Source or memory budget exceeded', (string) $middleware->unavailable);
    }

    public function test_laravel_mcp_auth_middleware_is_known_without_hiding_unknown_vendor_middleware(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $sources = $this->sources($files);
        $context = [
            'status' => 'known',
            'providers' => [],
            'middleware' => ['Laravel\\Mcp\\Server\\Middleware\\AddWwwAuthenticateHeader'],
            'middlewareGroups' => [],
            'middlewareAliases' => [],
            'packageVersions' => [],
        ];

        $knownRoute = new RouteEntry(['GET', 'HEAD'], 'known', class: 'App\\Http\\Controllers\\KnownController', method: 'show');
        $known = (new FrameworkContextBuilder(
            $sources,
            new RouteMap(entries: [$knownRoute], context: $context),
            $knownRoute,
        ))->build();

        $this->assertSame(FrameworkContext::EMPTY, $known->status);
        $this->assertNull($known->unavailable);

        $unknownRoute = new RouteEntry(['GET', 'HEAD'], 'unknown', class: 'App\\Http\\Controllers\\UnknownController', method: 'show', middleware: ['Vendor\\Package\\Middleware\\Unknown']);
        $unknown = (new FrameworkContextBuilder(
            $sources,
            new RouteMap(entries: [$unknownRoute], context: $context),
            $unknownRoute,
        ))->build();

        $this->assertSame(FrameworkContext::UNAVAILABLE, $unknown->status);
        $this->assertStringContainsString('Route middleware source is unavailable for Vendor\\Package\\Middleware\\Unknown.', (string) $unknown->unavailable);
    }

    private function sources(Filesystem $files): SourceIndex
    {
        $graph = (new ProjectGraphLoader($files, $this->tempPath))->load();

        return new SourceIndex($files, $this->tempPath, $graph);
    }
}
