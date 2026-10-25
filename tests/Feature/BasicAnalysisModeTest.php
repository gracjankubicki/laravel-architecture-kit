<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\BuiltInRules;
use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use GracjanKubicki\ArchitectureKit\Context\GraphSource;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\ArchitecturePath;
use GracjanKubicki\ArchitectureKit\Reach\ArchitectureReach;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class BasicAnalysisModeTest extends TestCase
{
    public function test_default_maps_direct_and_indirect_method_users_with_sources(): void
    {
        $this->fixture();
        $this->writeSource('app/helper.php', '<?php namespace App; function helper(DiscountService $s) { $s->apply(); }');
        $source = (new GraphSource(new Filesystem, $this->tempPath))->load();
        $query = (new GraphQuery($source['index']))->query('App\\DiscountService::apply', 'impact');
        $names = array_column(array_column($query['records'], 'element'), 'name');
        $this->assertContains('App\\OrderService::place', $names);
        $this->assertContains('App\\helper', $names);
        $this->assertContains('App\\OrderController::store', $names);
        $this->assertNotContains('App\\UnusedService', $names);
        $this->assertSame(AnalysisMode::Basic, $source['index']->mode);
        foreach ($query['records'] as $row) {
            $this->assertFalse($row['execution_proven']);
            $this->assertNotEmpty($row['explanation']);
            $this->assertArrayHasKey('path', $row['explanation'][0]);
        }
        $report = (new ArchitectureImpact(new Filesystem, $this->tempPath))->inspect('App\\DiscountService::apply');
        $this->assertSame('basic', $report['analysis']['mode']);
        $this->assertContains('App\\OrderController::store', array_column($report['dependents'], 'symbol'));
        $this->assertSame('not_run', $report['data']['status']);
        $reach = (new ArchitectureReach(new Filesystem, $this->tempPath))->inspect('App\\DiscountService');
        $this->assertSame('basic', $reach['analysis']['mode']);
        $this->assertNull($reach['reach']['counts']['execution_entries']);
        $this->assertNull($reach['reach']['counts']['data_operations']);
        $path = (new ArchitecturePath(new Filesystem, $this->tempPath))->inspect('OrderService::place', 'DiscountService::apply');
        $this->assertTrue($path['ok']);
        $this->assertSame('found', $path['source_path']['status']);
        $this->assertContains('App\\helper', array_column($report['dependents'], 'symbol'));
    }

    public function test_modules_are_discovered_from_composer_and_vendor_php_is_never_parsed(): void
    {
        $this->fixture();
        $this->writeSource('Modules/Sales/Caller.php', '<?php namespace Modules\\Sales; class Caller { public function call(\\App\\DiscountService $service) { $service->apply(); } }');
        $this->writeSource('vendor/ThirdParty/Caller.php', '<?php namespace Vendor; class Caller { public function run(\\App\\DiscountService $service) { $service->apply(); } }');
        $composer = json_decode(file_get_contents($this->tempPath.'/composer.json'), true);
        $composer['autoload']['psr-4']['Modules\\Sales\\'] = 'Modules/Sales';
        file_put_contents($this->tempPath.'/composer.json', json_encode($composer));
        $parsed = [];
        $source = (new GraphSource(new Filesystem, $this->tempPath, function ($path) use (&$parsed): void {
            $parsed[] = $path;
        }))->load();
        $names = array_column($source['index']->elements, 'name');
        $this->assertContains('Modules\\Sales\\Caller', $names);
        $this->assertNotContains('Vendor\\Caller', $names);
        $this->assertContains('Modules/Sales/Caller.php', $parsed);
        $this->assertSame([], array_values(array_filter($parsed, fn ($path) => str_starts_with($path, 'vendor/'))));
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false);
        $this->assertContains('Modules/Sales', $result->graphScope);
        $this->assertNotContains('Modules/Sales/Caller.php', $result->auditedPaths);
    }

    public function test_test_candidates_explain_source_evidence_without_execution_or_coverage_claim(): void
    {
        $this->fixture();
        $this->writeSource('tests/DiscountTest.php', '<?php class DiscountTest { public function test_discount(\\App\\DiscountService $service) { $service->apply(); } }');
        $report = (new ArchitectureImpact(new Filesystem, $this->tempPath))->inspect('App\\DiscountService');
        $this->assertNotEmpty($report['tests']);
        $this->assertFalse($report['tests'][0]['proves_coverage']);
        $this->assertNotEmpty($report['tests'][0]['basis']);
        $none = (new ArchitectureImpact(new Filesystem, $this->tempPath))->inspect('App\\UnusedService');
        $this->assertSame([], $none['tests']);
        $audit = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false, missingTestLevel: MissingTestLevel::Warn);
        $this->assertContains('missing-test', array_column($audit->findings, 'rule'));
    }

    public function test_basic_never_enters_deep_stages_and_explicit_deep_is_positive_control(): void
    {
        $this->fixture();
        $this->writeSource('app/Factory.php', '<?php namespace App; function factory() { return new DiscountService; } function entry() { factory()->apply(); $callback = fn (DiscountService $s) => $s->apply(); }');
        $stages = [];
        $session = AnalysisRuntime::session();
        AnalysisRuntime::activate($session->withStageObserver(function ($stage) use (&$stages): void {
            $stages[] = $stage;
        }));
        $basic = (new GraphSource(new Filesystem, $this->tempPath))->load();
        (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false);
        $this->assertSame([], $stages);
        $deep = (new GraphSource(new Filesystem, $this->tempPath, mode: AnalysisMode::Deep))->load();
        $this->assertContains('deep.catalog.extractors', $stages);
        $this->assertContains('deep.catalog.resolvers', $stages);
        $this->assertContains('deep.calls.extract', $stages);
        $target = $deep['index']->names[strtolower('App\\DiscountService::apply')][0];
        $entry = $deep['index']->names[strtolower('App\\entry')][0];
        $this->assertNotEmpty(array_filter($deep['index']->relations, fn ($r) => $r['from'] === $entry && $r['to'] === $target && $r['kind'] === 'calls'));
        $this->assertEmpty(array_filter($basic['index']->relations, fn ($r) => $r['from'] === $entry && $r['to'] === $target && $r['kind'] === 'calls'));
    }

    public function test_cache_modes_and_equal_stat_edits_do_not_reuse_stale_bindings(): void
    {
        $this->fixture();
        $this->writeSource('app/Factory.php', '<?php namespace App; function factory() { return new DiscountService; } function entry() { factory()->apply(); }');
        $basic = (new GraphSource(new Filesystem, $this->tempPath))->load();
        $deep = (new GraphSource(new Filesystem, $this->tempPath, mode: AnalysisMode::Deep))->load();
        $again = (new GraphSource(new Filesystem, $this->tempPath))->load();
        $this->assertSame($basic['index']->relations, $again['index']->relations);
        $this->assertNotSame($basic['snapshot'], $deep['snapshot']);
        $this->assertSame($deep['index']->relations, (new GraphSource(new Filesystem, $this->tempPath, mode: AnalysisMode::Deep))->load()['index']->relations);
        $this->writeSource('config/architectures.php', '<?php return ["audit" => ["cache" => false]];');
        $uncached = (new GraphSource(new Filesystem, $this->tempPath))->load();
        $appRelations = static fn ($source) => array_values(array_filter($source['index']->relations, static fn ($r) => str_starts_with($r['path'], 'app/')));
        $this->assertSame($appRelations($basic), $appRelations($uncached));
        $this->writeSource('config/architectures.php', '<?php return ["audit" => ["cache" => true]];');
        $beforeEdit = (new GraphSource(new Filesystem, $this->tempPath))->load();
        $this->assertSame($beforeEdit['snapshot'], (new GraphSource(new Filesystem, $this->tempPath))->load()['snapshot']);
        $path = $this->tempPath.'/app/DiscountService.php';
        $mtime = filemtime($path);
        $size = filesize($path);
        file_put_contents($path, str_replace('apply', 'other', file_get_contents($path)));
        touch($path, $mtime);
        $this->assertSame($size, filesize($path));
        $changed = (new GraphSource(new Filesystem, $this->tempPath))->load();
        $this->assertNotSame($beforeEdit['snapshot'], $changed['snapshot']);
        $this->assertEmpty(array_filter($changed['index']->relations, fn ($r) => ($r['metadata']['method'] ?? null) === 'apply' && isset($changed['index']->elements[$r['to']])));
    }

    public function test_dynamic_calls_and_parse_failures_have_different_completeness(): void
    {
        $this->fixture();
        $this->writeSource('app/Dynamic.php', '<?php namespace App; class Dynamic { public function run($receiver, $method) { $receiver->$method(); } }');
        $source = (new GraphSource(new Filesystem, $this->tempPath))->load();
        $unknown = array_values(array_filter($source['index']->diagnostics, fn ($d) => $d['code'] === 'unresolved_call'));
        $this->assertNotEmpty($unknown);
        $this->assertSame('app/Dynamic.php', $unknown[0]['path']);
        $audit = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false);
        $this->assertTrue($audit->checksComplete);
        $this->writeSource('app/Broken.php', '<?php class { invalid');
        $audit = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false);
        $this->assertFalse($audit->checksComplete);
        $this->assertTrue($audit->incomplete());
        $path = (new ArchitecturePath(new Filesystem, $this->tempPath))->inspect('OrderService::place', 'DiscountService::apply');
        $this->assertFalse($path['analysis']['checks_complete']);
    }

    public function test_all_local_rules_still_exist_in_basic(): void
    {
        $this->assertCount(22, BuiltInRules::all(new Filesystem, $this->tempPath, []));
        $this->fixture();
        $this->writeSource('app/Many.php', '<?php namespace App; class Many { public function call(DiscountService $s) { '.str_repeat('$s->apply(); ', 20).' } }');
        $index = (new GraphSource(new Filesystem, $this->tempPath))->load()['index'];
        $limited = (new GraphQuery($index, maxEdges: 1))->query('App\\DiscountService::apply', 'impact');
        $full = (new GraphQuery($index))->query('App\\DiscountService::apply', 'impact');
        $this->assertFalse($limited['traversal_complete']);
        $this->assertFalse($limited['analysis']['checks_complete']);
        $this->assertTrue($full['traversal_complete']);
        $this->assertTrue($full['analysis']['checks_complete']);
    }

    public function test_deep_audit_interpreter_remains_explicit_and_basic_has_no_endpoint_effects(): void
    {
        $this->writeSource('app/Http/Controllers/Example.php', '<?php namespace App\\Http\\Controllers; class Example { public function show() { \\Illuminate\\Support\\Facades\\DB::table("orders")->delete(); } }');
        $stages = [];
        AnalysisRuntime::activate(AnalysisRuntime::session()->withStageObserver(function ($stage) use (&$stages): void {
            $stages[] = $stage;
        }));
        $routes = new RouteMap(['app\\http\\controllers\\example::show' => ['GET']]);
        $enabled = [Architecture::ThinControllers, Architecture::Actions];
        $audit = new ApplicationAudit(new Filesystem, $this->tempPath);
        $basic = $audit->run($enabled, false, routes: $routes);
        $this->assertSame([], $stages);
        $this->assertSame([], $basic->suggestions);
        $deep = $audit->run($enabled, false, routes: $routes, mode: AnalysisMode::Deep);
        $this->assertContains('deep.controller-read', $stages);
        $this->assertContains('deep.method-analyzer', $stages);
        $this->assertContains('S_MOVE_WRITE_TO_ACTION', array_column($deep->suggestions, 'code'));
    }

    public function test_source_budget_is_reported_as_unchecked_with_a_location(): void
    {
        $this->fixture();
        $this->writeSource('app/Large.php', '<?php namespace App; class Large {} '.str_repeat(' ', 100001));
        $audit = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false);
        $this->assertFalse($audit->analysis()['checks_complete']);
        $notices = array_values(array_filter($audit->notices, fn ($n) => $n->code === 'A_SOURCE_CHECK_INCOMPLETE'));
        $this->assertNotEmpty($notices);
        $this->assertSame('app/Large.php', $notices[0]->path);
    }

    private function fixture(): void
    {
        $this->writeSource('app/DiscountService.php', '<?php namespace App; class DiscountService { public function apply() {} }');
        $this->writeSource('app/OrderService.php', '<?php namespace App; class OrderService { public function place(DiscountService $discount) { $discount->apply(); } }');
        $this->writeSource('app/OrderController.php', '<?php namespace App; class OrderController { public function store(OrderService $orders) { $orders->place(new DiscountService); } }');
        $this->writeSource('app/UnusedService.php', '<?php namespace App; class UnusedService {}');
    }

    private function writeSource(string $path, string $source): void
    {
        (new Filesystem)->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        file_put_contents($this->tempPath.'/'.$path, $source);
    }
}
