<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use PHPUnit\Framework\TestCase;

class StaticGraphPackageInertiaTest extends TestCase
{
    /** @return list<CatalogFacts> */
    private function facts(string $source, string $path = 'app/Inertia.php'): array
    {
        return (new ProjectGraphBuilder(catalog: true))->build([new FileContext($path, '<?php '.$source)])->catalogFacts;
    }

    private function package(string $version = '3.3.4', string $path = 'composer.lock'): CatalogFacts
    {
        return (new ComposerCatalogExtractor)->extract(new FileContext($path, json_encode(['packages' => [['name' => 'inertiajs/inertia-laravel', 'version' => $version]]], JSON_THROW_ON_ERROR)));
    }

    /** @return list<array<string, mixed>> */
    private function edges(CatalogIndex $index, string $kind): array
    {
        return array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
    }

    public function test_source_pages_and_shared_callbacks_are_response_and_request_candidates(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Inertia\Inertia as Page;
use function inertia as pageResponse;
class Controller {
    public function show() {
        return Page::render(props: [
            'orders' => fn () => Service::read(),
            'details' => Page::optional(fn () => Service::details()),
            'later' => Page::defer(callback: fn () => Service::later(), group: 'orders'),
            'data' => [Service::class, 'not_a_callback'],
            'secret' => 'never-retain-payload',
        ], component: 'Orders/Index');
    }
    public function alternate() { return pageResponse('Orders/Other', ['orders' => fn () => Service::read()]); }
}
class Provider {
    public function boot() { Page::share('user', fn () => Service::user()); }
}
class Service { public static function read() {} public static function details() {} public static function later() {} public static function user() {} }
throw new \RuntimeException('source-only-sentinel');
SOURCE);
        $serialized = json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('never-retain-payload', $serialized);
        $this->assertStringNotContainsString('source-only-sentinel', $serialized);
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(2, $this->edges($index, 'renders-inertia-page'));
        $callbacks = $this->edges($index, 'inertia-prop-callback');
        $this->assertCount(4, $callbacks);
        $this->assertSame(['ordinary', 'optional', 'defer', 'ordinary'], array_column(array_column($callbacks, 'metadata'), 'mode'));
        $shared = $this->edges($index, 'inertia-shared-callback');
        $this->assertCount(2, $shared);
        $this->assertCount(1, $this->edges($index, 'registers-inertia-shared-props'));
        foreach ([...$callbacks, ...$shared] as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertTrue($edge['metadata']['response_resolution_required']);
            $this->assertTrue($edge['metadata']['request_prop_selection_required']);
            $this->assertNotEmpty($edge['metadata']['render_sources']);
            $this->assertSame('closure', $index->elements[$edge['to']]['kind']);
        }
        $this->assertNotEmpty($index->names[strtolower('Orders/Index')]);
        foreach ([[], [$this->package('99.0.0')], [$this->package(), $this->package('3.0.0', 'vendor/composer/installed.json')]] as $packages) {
            $index = new CatalogIndex([...$facts, ...$packages]);
            $this->assertSame([], $this->edges($index, 'renders-inertia-page'));
            $this->assertSame([], $this->edges($index, 'inertia-prop-callback'));
            $this->assertContains('package_inertia_analysis', array_column($index->diagnostics, 'code'));
        }
    }

    public function test_source_helpers_facades_and_dynamic_props_do_not_invent_execution(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
function inertia($page, $props) { return null; }
class Decoy { public static function render($page, $props) {} }
inertia('Fake/Page', ['data' => fn () => fake()]);
Decoy::render('Other/Page', ['data' => fn () => fake()]);
\Inertia\Inertia::render($component, ['data' => fn () => known()]);
\Inertia\Inertia::render('Real/Page', $dynamic);
\Inertia\Inertia::render('Bad/../Page', []);
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(1, $this->edges($index, 'renders-inertia-page'));
        $this->assertSame([], $this->edges($index, 'inertia-prop-callback'));
        $this->assertContains('package_inertia_analysis', array_column($index->diagnostics, 'code'));
        $shadow = $this->facts('namespace Inertia; class Inertia {}', 'app/Shadow.php');
        $index = new CatalogIndex([...$facts, ...$shadow, $this->package()]);
        $this->assertSame([], $this->edges($index, 'renders-inertia-page'));
    }

    public function test_generator_prop_and_shared_callbacks_do_not_create_paths_to_deferred_bodies(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Inertia\Inertia as Page;
Page::share('shared', function () { Service::shared(); yield null; });
function page() {
    return Page::render('Orders/Index', [
        'ordinary' => function () { Service::ordinary(); yield null; },
        'optional' => Page::optional(function () { Service::optional(); yield null; }),
        'deferred' => Page::defer(function () { Service::deferred(); yield null; }),
        'ready' => fn () => Service::ready(),
    ]);
}
class Service { public static function shared() {} public static function ordinary() {} public static function optional() {} public static function deferred() {} public static function ready() {} }
SOURCE);
        $index = new CatalogIndex([...array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts), $this->package()]);
        $this->assertCount(1, $this->edges($index, 'inertia-prop-callback'));
        $this->assertSame([], $this->edges($index, 'inertia-shared-callback'));
        $page = $index->names[strtolower('Orders/Index')][0];
        foreach (['shared', 'ordinary', 'optional', 'deferred'] as $method) {
            $target = $index->names[strtolower('App\\Service::'.$method)][0];
            $this->assertSame('no_path_in_analyzed_graph', (new GraphQuery($index))->query($page, 'path', $target, 8)['status']);
        }
        $target = $index->names[strtolower('App\\Service::ready')][0];
        $this->assertSame('found', (new GraphQuery($index))->query($page, 'path', $target, 8)['status']);
        $this->assertContains('package_inertia_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_cached_prop_callers_recompose_after_shared_source_changes_and_limits_are_explicit(): void
    {
        $render = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $this->facts("namespace App; function page() { return \\Inertia\\Inertia::render('Orders/Index', []); }", 'app/Page.php'));
        $share = $this->facts("namespace App; \\Inertia\\Inertia::share(['user' => fn () => one()]);", 'app/Share.php');
        $index = new CatalogIndex([...$render, ...$share, $this->package()]);
        $this->assertCount(1, $this->edges($index, 'inertia-shared-callback'));
        $index = new CatalogIndex([...$render, $this->package()]);
        $this->assertSame([], $this->edges($index, 'inertia-shared-callback'));
        $index = new CatalogIndex([...$render, ...$this->facts("namespace App; \\Inertia\\Inertia::share('user', fn () => two());", 'app/Share.php'), $this->package()]);
        $this->assertCount(1, $this->edges($index, 'inertia-shared-callback'));
        $limited = $this->facts("\\Inertia\\Inertia::render('Orders/Index', [".implode(',', array_fill(0, 129, 'fn () => work()')).']);');
        $index = new CatalogIndex([...$limited, $this->package()]);
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
        $this->assertLessThanOrEqual(128, count($this->edges($index, 'inertia-prop-callback')));
    }

    public function test_middleware_shared_props_require_package_contract_and_standard_handler(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Shared extends \Inertia\Middleware {
    public function share($request): array { return [...parent::share($request), 'user' => fn () => Service::user()]; }
    public function shareOnce($request): array { return ['once' => fn () => Service::once()]; }
}
class Decoy { public function share($request): array { return ['fake' => fn () => fake()]; } }
class Override extends \Inertia\Middleware {
    public function share($request): array { return ['fake' => fn () => fake()]; }
    public function handle($request, $next) { return $next($request); }
}
function page() { return inertia()->render('Account/Show', []); }
class Service { public static function user() {} public static function once() {} }
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(1, $this->edges($index, 'renders-inertia-page'));
        $this->assertCount(2, $this->edges($index, 'declares-inertia-middleware-shared-props'));
        $callbacks = $this->edges($index, 'inertia-shared-callback');
        $this->assertCount(2, $callbacks);
        $this->assertSame(['ordinary', 'once'], array_column(array_column($callbacks, 'metadata'), 'mode'));
        foreach ($callbacks as $callback) {
            $this->assertTrue($callback['metadata']['runtime_middleware_active_required']);
            $this->assertFalse($callback['metadata']['execution_proven']);
        }
        $this->assertContains('package_inertia_analysis', array_column($index->diagnostics, 'code'));
    }
}
