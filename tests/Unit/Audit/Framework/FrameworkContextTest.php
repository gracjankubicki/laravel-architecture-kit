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

final class FrameworkContextTest extends TestCase
{
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

    private function sources(Filesystem $files): SourceIndex
    {
        $graph = (new ProjectGraphLoader($files, $this->tempPath))->load();

        return new SourceIndex($files, $this->tempPath, $graph);
    }
}
