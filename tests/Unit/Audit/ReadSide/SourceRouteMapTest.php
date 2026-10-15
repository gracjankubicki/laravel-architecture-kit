<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceRouteMap;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class SourceRouteMapTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/source-routes-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/routes', 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
    }

    public function test_source_groups_resource_and_invokable_routes_keep_their_context_and_witness(): void
    {
        file_put_contents($this->root.'/routes/web.php', <<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\DownloadController;
Route::prefix('api')->middleware('auth')->group(function () {
    Route::apiResource('invoices', InvoiceController::class)->only(['show', 'store']);
    Route::get('download', DownloadController::class)->name('download');
});
PHP);
        $map = (new SourceRouteMap(new Filesystem, $this->root))->build(new ProjectGraphSnapshot([], []));
        self::assertSame([], $map->notices);
        self::assertSame(['GET', 'HEAD'], $map->verbs('App\\Http\\Controllers\\InvoiceController', 'show'));
        self::assertSame(['POST'], $map->verbs('App\\Http\\Controllers\\InvoiceController', 'store'));
        $entry = $map->entriesFor('App\\Http\\Controllers\\DownloadController', '__invoke')[0];
        self::assertSame('api/download', $entry->uri);
        self::assertSame(['auth'], $entry->middleware);
        self::assertSame('download', $entry->name);
        $witness = $map->context['source_routes'][$entry->identity()];
        self::assertSame('static', $witness['knowledge']);
        self::assertFalse($witness['execution_proven']);
        self::assertSame('routes/web.php', $witness['source']['path']);
    }

    public function test_unknown_handler_retains_known_route_and_marks_partial_analysis(): void
    {
        file_put_contents($this->root.'/routes/web.php', '<?php use Illuminate\\Support\\Facades\\Route; Route::get("/known", [App\\Http\\Controllers\\InvoiceController::class, "index"]); Route::get("/unknown", $handler);');
        $map = (new SourceRouteMap(new Filesystem, $this->root))->build(new ProjectGraphSnapshot([], []));
        self::assertSame(['GET', 'HEAD'], $map->verbs('App\\Http\\Controllers\\InvoiceController', 'index'));
        self::assertNotEmpty($map->notices);
        self::assertNull($map->unavailable);
        $restored = RouteMap::fromSnapshot($map->snapshot());
        self::assertSame($map->snapshot(), $restored->snapshot());
        self::assertNotEmpty($restored->notices);
    }

    public function test_unreadable_excluded_and_symlink_route_sources_never_look_complete(): void
    {
        file_put_contents($this->root.'/routes/web.php', '<?php use Illuminate\\Support\\Facades\\Route; Route::get("/known", [App\\Http\\Controllers\\InvoiceController::class, "index"]);');
        $excluded = (new SourceRouteMap(new Filesystem, $this->root))->build(new ProjectGraphSnapshot([], []), ['routes/web.php']);
        self::assertNotEmpty($excluded->notices);
        self::assertSame([], $excluded->entries);
        rename($this->root.'/routes/web.php', $this->root.'/original.php');
        symlink($this->root.'/original.php', $this->root.'/routes/web.php');
        $linked = (new SourceRouteMap(new Filesystem, $this->root))->build(new ProjectGraphSnapshot([], []));
        self::assertNotEmpty($linked->notices);
        self::assertSame([], $linked->entries);
    }

    public function test_source_budget_keeps_known_routes_and_does_not_certify_missing_declarations(): void
    {
        file_put_contents($this->root.'/routes/web.php', '<?php use Illuminate\\Support\\Facades\\Route; Route::get("/known", [App\\Http\\Controllers\\InvoiceController::class, "index"]);');
        file_put_contents($this->root.'/routes/zlarge.php', '<?php $large = "'.str_repeat('x', 3_000_000).'";');
        $map = (new SourceRouteMap(new Filesystem, $this->root))->build(new ProjectGraphSnapshot([], []));
        self::assertSame(['GET', 'HEAD'], $map->verbs('App\\Http\\Controllers\\InvoiceController', 'index'));
        self::assertContains('A_ROUTE_SOURCE_LIMIT', array_map(fn ($notice) => $notice->code, $map->notices));
    }

    public function test_unrelated_provider_expressions_are_not_unresolved_route_declarations(): void
    {
        mkdir($this->root.'/app/Providers', 0700, true);
        file_put_contents($this->root.'/app/Providers/Helper.php', '<?php namespace App\\Providers; class Helper { public function value($input) { $value = $input ?? "default"; return $value; } }');
        file_put_contents($this->root.'/routes/web.php', '<?php use Illuminate\\Support\\Facades\\Route; Route::get("/known", [App\\Http\\Controllers\\InvoiceController::class, "index"]);');
        $map = (new SourceRouteMap(new Filesystem, $this->root))->build(new ProjectGraphSnapshot([], []));
        self::assertSame([], $map->notices);
        file_put_contents($this->root.'/routes/web.php', '<?php use Illuminate\\Support\\Facades\\Route; $enabled && Route::get("/known", [App\\Http\\Controllers\\InvoiceController::class, "index"]);');
        $conditional = (new SourceRouteMap(new Filesystem, $this->root))->build(new ProjectGraphSnapshot([], []));
        self::assertNotEmpty($conditional->notices);
        self::assertSame(['GET', 'HEAD'], $conditional->verbs('App\\Http\\Controllers\\InvoiceController', 'index'));
    }
}
