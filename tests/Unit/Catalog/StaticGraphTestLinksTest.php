<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use PHPUnit\Framework\TestCase;

final class StaticGraphTestLinksTest extends TestCase
{
    public function test_http_request_test_reaches_source_route_and_impact_recommends_it_without_pass(): void
    {
        $index = new CatalogIndex($this->facts([
            'routes/web.php' => '\Illuminate\Support\Facades\Route::get("invoices/{id}", [App\Controller::class, "show"])->name("invoices.show");',
            'app/Controller.php' => 'namespace App; class Controller { public function show() { Service::load(); } } class Service { public static function load() {} } class Invoice extends \Illuminate\Database\Eloquent\Model {}',
            'tests/Feature/InvoiceTest.php' => <<<'SOURCE'
namespace Tests;
class InvoiceTest extends \PHPUnit\Framework\TestCase {
 public function test_show() { $this->getJson(route('invoices.show', ['id' => 42]))->assertOk(); }
 public function test_setup_only() { \App\Invoice::factory()->make(['payload' => 'payload-secret']); }
}
throw new \RuntimeException('execution-sentinel');
SOURCE,
        ]));
        $test = $index->names[strtolower('Tests\InvoiceTest::test_show')][0];
        $target = $index->names[strtolower('App\Service::load')][0];
        $query = new GraphQuery($index);
        $this->assertSame('found', $query->query($test, 'path', $target, 8)['status']);
        $impact = $query->query($target, 'impact', null, 8);
        $candidates = array_values(array_filter($impact['records'], fn ($row) => $row['element']['id'] === $test));
        $this->assertCount(1, $candidates);
        $this->assertTrue($candidates[0]['test_candidate']);
        $this->assertFalse($candidates[0]['test_executed']);
        $this->assertFalse($candidates[0]['execution_proven']);
        $setup = $index->names[strtolower('Tests\InvoiceTest::test_setup_only')][0];
        $this->assertSame('no_path_in_analyzed_graph', $query->query($setup, 'path', 'App\Invoice', 8)['status']);
        $this->assertCount(1, $this->edges($index, 'references-factory-setup'));
        $this->assertStringNotContainsString('payload-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
    }

    public function test_pest_body_and_before_each_are_candidates_and_dataset_values_are_not_stored(): void
    {
        $index = new CatalogIndex($this->facts([
            'routes/web.php' => '\Illuminate\Support\Facades\Route::get("ready", fn () => App\Service::load());',
            'app/Service.php' => 'namespace App; class Service { public static function load() {} public static function prepare() {} }',
            'tests/Feature/ReadyTest.php' => 'beforeEach(fn () => App\Service::prepare()); dataset("cases", ["payload-secret"]); test("ready", fn () => $this->get("/ready?token=query-secret"))->with("cases");',
        ]));
        $test = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'pest-test'))[0];
        $query = new GraphQuery($index);
        foreach (['load', 'prepare'] as $method) {
            $this->assertSame('found', $query->query($test['id'], 'path', $index->names[strtolower('App\Service::'.$method)][0], 8)['status']);
        }
        $this->assertCount(1, array_filter($index->elements, fn ($row) => $row['kind'] === 'dataset'));
        $this->assertCount(1, $this->edges($index, 'references-test-dataset'));
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
    }

    public function test_phpunit_source_lifecycle_hooks_follow_inheritance_and_do_not_claim_execution(): void
    {
        $index = new CatalogIndex($this->facts([
            'app/Service.php' => 'namespace App; class Service { public static function prepare() {} public static function cleanup() {} }',
            'tests/BaseTest.php' => 'namespace Tests; abstract class BaseTest extends \PHPUnit\Framework\TestCase { protected function setUp(): void { \App\Service::prepare(); } public static function tearDownAfterClass(): void { \App\Service::cleanup(); } }',
            'tests/Feature/ReadyTest.php' => 'namespace Tests; class ReadyTest extends BaseTest { public function test_ready() {} } class Decoy { public function test_ready() {} protected function setUp() {} }',
        ]));
        $setup = $this->edges($index, 'test-setup');
        $teardown = $this->edges($index, 'test-teardown');
        $this->assertCount(1, $setup);
        $this->assertCount(1, $teardown);
        $this->assertSame('Tests\BaseTest::setUp', $index->elements[$setup[0]['to']]['name']);
        $this->assertSame('Tests\BaseTest::tearDownAfterClass', $index->elements[$teardown[0]['to']]['name']);
        foreach ([...$setup, ...$teardown] as $edge) {
            $this->assertFalse($edge['metadata']['test_executed']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
    }

    public function test_cached_test_hooks_recompose_after_parent_edit_and_reject_invalid_declarations(): void
    {
        $fixed = $this->facts(['tests/Feature/ReadyTest.php' => 'namespace Tests; class ReadyTest extends BaseTest { public function test_ready() {} }']);
        foreach ([
            ['protected function setUp(): void {}', 1],
            ['private function setUp(): void {}', 0],
            ['protected static function setUp(): void {}', 0],
            ['protected function setUp($required): void {}', 0],
        ] as [$hook, $expected]) {
            $parent = $this->facts(['tests/BaseTest.php' => 'namespace Tests; abstract class BaseTest extends \PHPUnit\Framework\TestCase { '.$hook.' }']);
            $index = new CatalogIndex([...$fixed, ...$parent]);
            $this->assertCount($expected, $this->edges($index, 'test-setup'), $hook);
        }
        $this->assertSame([], $this->edges(new CatalogIndex($fixed), 'test-setup'));
    }

    public function test_phpunit_attribute_hooks_follow_effective_source_methods_and_priorities(): void
    {
        $base = <<<'PHP'
namespace Tests;
use PUNITATTRBefore as Prepare;
abstract class BaseTest extends PUNITBASE {
 #[Prepare(10)] protected function prepare() {}
 #[PUNITATTRAfter(priority: -2)] protected function cleanup() {}
 #[PUNITATTRBeforeClass] public static function prepareClass() {}
 #[PUNITATTRPreCondition] protected function checkBefore() {}
 #[PUNITATTRPostCondition] protected function checkAfter() {}
 #[PUNITATTRBefore] protected function overridden() {}
}
PHP;
        $base = str_replace(['PUNITATTR', 'PUNITBASE'], ['\PHPUnit\Framework\Attributes\\', '\PHPUnit\Framework\TestCase'], $base);
        $index = new CatalogIndex($this->facts([
            'tests/BaseTest.php' => $base,
            'tests/Feature/ReadyTest.php' => 'namespace Tests; class ReadyTest extends BaseTest { public function test_ready() {} protected function overridden() {} }',
        ]));
        $setup = $this->edges($index, 'test-setup');
        $this->assertCount(2, $setup);
        $this->assertSame([10, 0], array_column(array_column($setup, 'metadata'), 'priority'));
        $teardown = $this->edges($index, 'test-teardown');
        $this->assertCount(1, $teardown);
        $this->assertSame(-2, $teardown[0]['metadata']['priority']);
        $this->assertCount(1, $this->edges($index, 'test-precondition'));
        $this->assertCount(1, $this->edges($index, 'test-postcondition'));
        foreach ([...$setup, ...$teardown] as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertFalse($edge['metadata']['test_executed']);
        }
    }

    public function test_phpunit_attribute_lookalikes_source_shadows_and_invalid_hooks_do_not_link(): void
    {
        foreach ([
            '#[PUNITATTRBefore] private function prepare() {}',
            '#[PUNITATTRBeforeClass] protected function prepare() {}',
            '#[PUNITATTRBefore] protected function prepare($required) {}',
            '#[PUNITATTRBefore(payload: "body-secret")] protected function prepare() {}',
            '#[OtherBefore] protected function prepare() {}',
        ] as $hook) {
            $hook = str_replace('PUNITATTR', '\PHPUnit\Framework\Attributes\\', $hook);
            $index = new CatalogIndex($this->facts(['tests/Feature/ReadyTest.php' => 'class ReadyTest extends \PHPUnit\Framework\TestCase { '.$hook.' public function test_ready() {} }']));
            $this->assertSame([], $this->edges($index, 'test-setup'), $hook);
            $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
        }
        $index = new CatalogIndex($this->facts([
            'tests/Feature/ReadyTest.php' => 'class ReadyTest extends \PHPUnit\Framework\TestCase { #[\PHPUnit\Framework\Attributes\Before] protected function prepare() {} public function test_ready() {} }',
            'tests/Shadow.php' => 'namespace PHPUnit\Framework\Attributes; class Before {}',
        ]));
        $this->assertSame([], $this->edges($index, 'test-setup'));
    }

    public function test_phpunit_data_providers_select_inherited_and_external_source_methods_without_values(): void
    {
        $sources = [
            'tests/BaseTest.php' => 'namespace Tests; abstract class BaseTest extends PUNITBASE { public static function cases() { return [["payload-secret"]]; } }',
            'tests/Feature/ReadyTest.php' => <<<'PHP'
namespace Tests;
use PUNITATTRDataProvider as Cases;
use PUNITATTRDataProviderExternal as ExternalCases;
use App_PROVIDER as SharedCases;
class ReadyTest extends BaseTest {
 #[Cases(methodName: 'cases', validateArgumentCount: false)] public function test_local($value) {}
 #[ExternalCases(SharedCases::class, 'rows')] public function test_external($value) {}
}
PHP,
            'app/Provider.php' => 'namespace App; throw new PUNIT_EXCEPTION("never execute"); class Provider { public static function rows() { return [["other-secret"]]; } }',
        ];
        foreach ($sources as &$source) {
            $source = str_replace(['PUNITBASE', 'PUNITATTR', 'App_PROVIDER', 'PUNIT_EXCEPTION'], ['\PHPUnit\Framework\TestCase', '\PHPUnit\Framework\Attributes\\', 'App\Provider', '\RuntimeException'], $source);
        }
        unset($source);
        $facts = array_map(fn ($facts) => CatalogFacts::fromArray($facts->path, $facts->toArray()), $this->facts($sources));
        $index = new CatalogIndex($facts);
        $providers = $this->edges($index, 'test-dataset-provider');
        $this->assertCount(2, $providers);
        $this->assertSame(['Tests\BaseTest::cases', 'App\Provider::rows'], array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $providers));
        $this->assertFalse($providers[0]['metadata']['validate_argument_count']);
        foreach ($providers as $edge) {
            $this->assertFalse($edge['metadata']['test_executed']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertFalse($edge['metadata']['dataset_values_retained']);
        }
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
    }

    public function test_phpunit_provider_invalid_shapes_and_methods_remain_unresolved(): void
    {
        foreach ([
            ['PUNITATTRDataProvider("cases")', 'protected static function cases() {}'],
            ['PUNITATTRDataProvider("cases")', 'public function cases() {}'],
            ['PUNITATTRDataProvider("cases")', 'public static function cases($optional = null) {}'],
            ['PUNITATTRDataProvider("missing")', 'public static function cases() {}'],
            ['PUNITATTRDataProvider(payload: "payload-secret")', 'public static function cases() {}'],
            ['PUNITATTRDataProvider($dynamic)', 'public static function cases() {}'],
        ] as [$attribute, $provider]) {
            $attribute = str_replace('PUNITATTR', '\PHPUnit\Framework\Attributes\\', $attribute);
            $index = new CatalogIndex($this->facts(['tests/Feature/ReadyTest.php' => 'class ReadyTest extends \PHPUnit\Framework\TestCase { #['.$attribute.'] public function test_ready($value) {} '.$provider.' }']));
            $this->assertSame([], $this->edges($index, 'test-dataset-provider'));
            $this->assertContains('test_analysis', array_column($index->diagnostics, 'code'));
            $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
        }
    }

    public function test_pest_configuration_hooks_select_only_declared_source_paths_and_latest_callbacks(): void
    {
        $index = new CatalogIndex(array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $this->facts([
            'tests/Pest.php' => 'pest()->beforeEach(fn () => null)->afterEach(fn () => null)->in("Feature"); pest()->beforeAll(fn () => null)->in("Unit/*.php"); pest()->beforeEach(fn () => oldHook())->beforeEach(fn () => newHook())->in("Feature/Nested");',
            'tests/Feature/ReadyTest.php' => 'test("ready", fn () => null);',
            'tests/Feature/Nested/ReadyTest.php' => 'test("nested", fn () => null);',
            'tests/Unit/ReadyTest.php' => 'test("unit", fn () => null);',
            'tests/OutsideTest.php' => 'test("outside", fn () => null);',
        ])));
        $setup = $this->edges($index, 'test-setup');
        $teardown = $this->edges($index, 'test-teardown');
        $this->assertCount(4, $setup);
        $this->assertCount(2, $teardown);
        foreach ([...$setup, ...$teardown] as $edge) {
            $this->assertNotSame('outside', $index->elements[$edge['from']]['name']);
            $this->assertSame('tests/Pest.php', $index->elements[$edge['to']]['path']);
            $this->assertFalse($edge['metadata']['test_executed']);
        }
        $hooks = array_values(array_filter($index->elements, fn ($element) => $element['kind'] === 'test-hook'));
        $this->assertCount(4, $hooks);
    }

    public function test_pest_configuration_dynamic_paths_source_shadows_and_decoys_do_not_apply(): void
    {
        foreach ([
            'pest()->beforeEach(fn () => null)->in($dynamic);',
            'pest()->beforeEach(fn () => null)->in("../outside");',
            'pest()->beforeEach(fn () => null)->in("Feature")->unknown();',
            'pest()->extend(123)->beforeEach(fn () => null)->in("Feature");',
            'pest()->group($groups)->beforeEach(fn () => null)->in("Feature");',
            'pest()->beforeEach(fn () => null)->uses("Tests\\\\TestCase")->in("Feature");',
            'uses(Tests\\TestCase::UNKNOWN)->beforeEach(fn () => null)->in("Feature");',
            'function pest() {} pest()->beforeEach(fn () => null)->in("Feature");',
            '(new Other)->beforeEach(fn () => null)->in("Feature");',
        ] as $configuration) {
            $index = new CatalogIndex($this->facts([
                'tests/Pest.php' => $configuration,
                'tests/Feature/ReadyTest.php' => 'test("ready", fn () => null);',
            ]));
            $this->assertSame([], $this->edges($index, 'test-setup'));
        }
    }

    public function test_cached_pest_global_hook_registration_rechecks_source_api_shadow(): void
    {
        $fixed = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $this->facts([
            'tests/Pest.php' => 'pest()->beforeEach(fn () => null)->in("Feature");',
            'tests/Feature/ReadyTest.php' => 'test("ready", fn () => null);',
        ]));
        $this->assertCount(1, $this->edges(new CatalogIndex($fixed), 'test-setup'));
        $shadow = $this->facts(['tests/Shadow.php' => 'function pest() {}']);
        $this->assertSame([], $this->edges(new CatalogIndex([...$fixed, ...$shadow]), 'test-setup'));
    }

    public function test_pest_and_uses_defaults_and_replacement_targets_follow_source_api_contracts(): void
    {
        $index = new CatalogIndex($this->facts([
            'tests/Pest.php' => 'uses()->beforeEach(fn () => null); pest()->extends(Tests\\TestCase::class)->beforeEach(fn () => null)->in("Feature")->in("Unit"); pest()->uses(Tests\\TestCase::class)->afterEach(fn () => null)->in("Feature");',
            'tests/Feature/ReadyTest.php' => 'uses()->beforeAll(fn () => null); test("feature", fn () => null);',
            'tests/Unit/ReadyTest.php' => 'pest()->afterAll(fn () => null); test("unit", fn () => null);',
            'tests/Other/ReadyTest.php' => 'test("other", fn () => null);',
        ]));
        $setup = $this->edges($index, 'test-setup');
        $teardown = $this->edges($index, 'test-teardown');
        $this->assertCount(2, $setup);
        $this->assertCount(2, $teardown);
        $this->assertSame(['feature', 'unit'], array_values(array_unique(array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $setup))));
        foreach ($setup as $edge) {
            $source = $index->elements[$edge['to']]['path'];
            $this->assertSame($index->elements[$edge['from']]['name'] === 'unit' ? 'tests/Pest.php' : 'tests/Feature/ReadyTest.php', $source);
        }
        foreach ($teardown as $edge) {
            $this->assertSame($index->elements[$edge['from']]['name'] === 'feature' ? 'tests/Pest.php' : 'tests/Unit/ReadyTest.php', $index->elements[$edge['to']]['path']);
        }
    }

    public function test_generator_test_hook_does_not_create_a_path_to_its_deferred_body(): void
    {
        $sources = [
            'app/Service.php' => 'namespace App; class Service { public static function cleanup() {} }',
            'tests/Feature/ReadyTest.php' => 'afterEach(function () { App_SERVICE::cleanup(); yield null; }); test("ready", fn () => null);',
        ];
        $sources['tests/Feature/ReadyTest.php'] = str_replace('App_SERVICE', 'App\Service', $sources['tests/Feature/ReadyTest.php']);
        $index = new CatalogIndex($this->facts($sources));
        $test = array_values(array_filter($index->elements, fn ($element) => $element['kind'] === 'pest-test'))[0];
        $service = $index->names[strtolower('App\Service::cleanup')][0];
        $this->assertSame('no_path_in_analyzed_graph', (new GraphQuery($index))->query($test['id'], 'path', $service, 8)['status']);
        $this->assertContains('test_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_pest_after_hooks_are_teardown_and_shadowed_datasets_are_not_selected(): void
    {
        $index = new CatalogIndex($this->facts([
            'app/Service.php' => 'namespace App; class Service { public static function cleanup() {} }',
            'tests/Feature/ReadyTest.php' => 'function dataset($name, $values) {} dataset("cases", ["payload-secret"]); afterEach(fn () => \App\Service::cleanup()); afterAll(fn () => \App\Service::cleanup()); test("ready", fn () => null)->with("cases");',
        ]));
        $this->assertCount(2, $this->edges($index, 'test-teardown'));
        $this->assertSame([], $this->edges($index, 'references-test-dataset'));
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
    }

    public function test_multiple_source_routes_remain_candidates_and_dynamic_or_decoy_tests_do_not_dispatch(): void
    {
        $index = new CatalogIndex($this->facts([
            'routes/web.php' => '\Illuminate\Support\Facades\Route::get("ready", fn () => App\A::run()); \Illuminate\Support\Facades\Route::get("ready", fn () => App\B::run());',
            'app/Service.php' => 'namespace App; class A { public static function run() {} } class B { public static function run() {} }',
            'tests/Feature/ReadyTest.php' => 'class ReadyTest extends \PHPUnit\Framework\TestCase { public function test_ready() { $this->get("ready"); } public function test_dynamic() { $this->get($url); } } class Decoy { public function test_ready() { $this->get("ready"); } }',
        ]));
        $edges = $this->edges($index, 'test-http-candidate');
        $this->assertCount(2, $edges);
        $this->assertSame([2, 2], array_column(array_column($edges, 'metadata'), 'candidate_count'));
        $this->assertSame([$index->names['readytest::test_ready'][0], $index->names['readytest::test_ready'][0]], array_column($edges, 'from'));
        $this->assertContains('test_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_cached_test_invocations_follow_route_edits_and_source_test_base_shadow(): void
    {
        $fixed = $this->facts(['tests/Feature/ReadyTest.php' => 'class ReadyTest extends \PHPUnit\Framework\TestCase { public function test_ready() { $this->get("ready"); } }']);
        $fixed = array_map(fn ($row) => CatalogFacts::fromArray($row->path, $row->toArray()), $fixed);
        $route = $this->facts(['routes/web.php' => '\Illuminate\Support\Facades\Route::get("ready", fn () => 1);']);
        $changed = $this->facts(['routes/web.php' => '\Illuminate\Support\Facades\Route::get("changed", fn () => 1);']);
        $shadow = $this->facts(['tests/Shadow.php' => 'namespace PHPUnit\Framework; class TestCase {}']);
        $this->assertCount(1, $this->edges(new CatalogIndex([...$fixed, ...$route]), 'test-http-candidate'));
        $this->assertSame([], $this->edges(new CatalogIndex([...$fixed, ...$changed]), 'test-http-candidate'));
        $this->assertSame([], $this->edges(new CatalogIndex([...$fixed, ...$route, ...$shadow]), 'test-http-candidate'));
    }

    public function test_source_registration_shadow_and_mock_references_do_not_create_test_paths(): void
    {
        $index = new CatalogIndex($this->facts([
            'app/Service.php' => 'namespace App; class Service { public static function run() {} }',
            'tests/Feature/ShadowTest.php' => 'function test($name, $callback) {} test("decoy", fn () => App\Service::run()); class RealTest extends \PHPUnit\Framework\TestCase { public function test_mock() { \Mockery::mock(App\Service::class); \Illuminate\Support\Facades\Event::fake([App\Service::class]); } }',
        ]));
        $query = new GraphQuery($index);
        $target = $index->names[strtolower('App\Service::run')][0];
        $test = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'pest-test'))[0];
        $this->assertSame('no_path_in_analyzed_graph', $query->query($test['id'], 'path', $target, 8)['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($index->names['realtest::test_mock'][0], 'path', $target, 8)['status']);
        $this->assertCount(1, $this->edges($index, 'references-test-body'));
        $impact = $query->query($target, 'impact', null, 8);
        $records = array_values(array_filter($impact['records'], fn ($row) => $row['element']['id'] === $test['id']));
        $this->assertCount(1, $records);
        $this->assertFalse($records[0]['test_candidate']);
    }

    public function test_source_group_prefix_name_and_constraints_filter_http_candidates(): void
    {
        $index = new CatalogIndex($this->facts([
            'routes/web.php' => '\Illuminate\Support\Facades\Route::prefix("billing")->name("billing.")->group(function () { \Illuminate\Support\Facades\Route::get("orders/{id}", fn () => 1)->where("id", "[0-9]+")->name("orders"); });',
            'tests/Feature/OrdersTest.php' => 'class OrdersTest extends \PHPUnit\Framework\TestCase { public function test_number() { $this->get(route("billing.orders", ["id" => 42])); } public function test_text() { $this->get("/billing/orders/text"); } }',
        ]));
        $edges = $this->edges($index, 'test-http-candidate');
        $this->assertCount(1, $edges);
        $this->assertSame($index->names['orderstest::test_number'][0], $edges[0]['from']);
        $this->assertSame('/billing/orders/{id}', $index->elements[$edges[0]['to']]['metadata']['uri']);
        $this->assertSame(['id' => '[0-9]+'], $index->elements[$edges[0]['to']]['metadata']['constraints']);
    }

    /** @param array<string, string> $sources
     * @return list<CatalogFacts>
     */
    private function facts(array $sources): array
    {
        return (new ProjectGraphBuilder(catalog: true))->build(array_map(fn ($path, $source) => new FileContext($path, '<?php '.$source), array_keys($sources), $sources))->catalogFacts;
    }

    /** @return list<array<string, mixed>> */
    private function edges(CatalogIndex $index, string $kind): array
    {
        return array_values(array_filter($index->relations, fn ($row) => $row['kind'] === $kind));
    }
}
