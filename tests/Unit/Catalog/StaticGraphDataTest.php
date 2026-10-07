<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use PHPUnit\Framework\TestCase;

final class StaticGraphDataTest extends TestCase
{
    public function test_model_tables_inherit_properties_and_getters_without_executing_sources(): void
    {
        $facts = $this->facts([
            'app/Base.php' => 'namespace App; trait TableName { protected $table = "tenant_users"; } class Base extends \\Illuminate\\Database\\Eloquent\\Model { use TableName; protected $connection = "tenant"; protected $password = "credential-secret"; }',
            'app/User.php' => 'namespace App; class User extends Base {} class AuditLog extends \\Illuminate\\Database\\Eloquent\\Model {} class Override extends Base { public function getTable() { return "archives"; } public function getConnectionName() { return "archive"; } } class Decoy { protected $table = "decoy"; } throw new \\Exception("execution-sentinel");',
        ]);
        $serialized = json_encode(array_map(fn ($row) => $row->toArray(), $facts));
        $this->assertStringNotContainsString('credential-secret', $serialized);
        $index = new CatalogIndex($facts);
        $maps = $this->edges($index, 'maps-table');
        $this->assertCount(4, $maps);
        $tables = [];
        foreach ($maps as $map) {
            $tables[$index->elements[$map['from']]['name']] = $index->elements[$map['to']]['name'];
            $this->assertFalse($map['metadata']['execution_proven']);
        }
        $this->assertSame(['App\\Base' => 'tenant_users', 'App\\User' => 'tenant_users', 'App\\AuditLog' => 'audit_logs', 'App\\Override' => 'archives'], $tables);
        $child = array_values(array_filter($maps, fn ($map) => $index->elements[$map['from']]['name'] === 'App\\User'))[0];
        $this->assertContains('App\\TableName::$table', array_column($child['metadata']['selector_sources'], 'symbol'));
        $this->assertContains('App\\Base::$connection', array_column($child['metadata']['selector_sources'], 'symbol'));
        $this->assertCount(4, $this->edges($index, 'uses-database-connection'));
        $this->assertSame([], $this->edges($index, 'writes'));
    }

    public function test_dynamic_getters_and_connections_do_not_fall_back_to_conventions(): void
    {
        $index = new CatalogIndex($this->facts(['app/Models.php' => 'namespace App; class Dynamic extends \\Illuminate\\Database\\Eloquent\\Model { public function getTable() { return env("TABLE"); } } class DynamicConnection extends \\Illuminate\\Database\\Eloquent\\Model { protected $connection = unknown(); } class Invalid extends \\Illuminate\\Database\\Eloquent\\Model { protected $table = "https://host?credential=secret"; }']));
        $this->assertSame([], $this->edges($index, 'maps-table'));
        $this->assertCount(3, array_filter($index->diagnostics, fn ($row) => $row['code'] === 'data_analysis'));
    }

    public function test_reused_child_facts_resolve_again_after_parent_change_or_deletion(): void
    {
        $child = $this->facts(['app/Child.php' => 'namespace App; class Child extends Base {}'])[0];
        foreach (['old_rows', 'new_rows'] as $table) {
            $parent = $this->facts(['app/Base.php' => 'namespace App; class Base extends \\Illuminate\\Database\\Eloquent\\Model { protected $table = "'.$table.'"; }'])[0];
            $index = new CatalogIndex([$parent, CatalogFacts::fromArray($child->path, $child->toArray())]);
            $maps = $this->edges($index, 'maps-table');
            $this->assertCount(2, $maps);
            $this->assertSame($table, $index->elements[$maps[1]['to']]['name']);
        }
        $this->assertSame([], $this->edges(new CatalogIndex([$child]), 'maps-table'));
    }

    public function test_same_table_on_distinct_connections_has_distinct_identity(): void
    {
        $index = new CatalogIndex($this->facts(['app/Models.php' => 'namespace App; class A extends \\Illuminate\\Database\\Eloquent\\Model { protected $table = "rows"; protected $connection = "a"; } class B extends \\Illuminate\\Database\\Eloquent\\Model { protected $table = "rows"; protected $connection = "b"; }']));
        $maps = $this->edges($index, 'maps-table');
        $this->assertCount(2, $maps);
        $this->assertNotSame($maps[0]['to'], $maps[1]['to']);
        $this->assertCount(2, $index->names['rows']);
    }

    public function test_source_model_shadow_does_not_prove_framework_mapping(): void
    {
        $index = new CatalogIndex($this->facts(['app/Model.php' => 'namespace Illuminate\\Database\\Eloquent; class Model {}', 'app/User.php' => 'namespace App; class User extends \\Illuminate\\Database\\Eloquent\\Model {}']));
        $this->assertSame([], $this->edges($index, 'maps-table'));
        $this->assertNotEmpty($index->diagnostics);
    }

    public function test_ambiguous_parent_is_unresolved_and_trait_precedence_selects_the_table(): void
    {
        $index = new CatalogIndex($this->facts([
            'app/Base1.php' => 'namespace App; class Base extends \\Illuminate\\Database\\Eloquent\\Model { protected $table = "first"; }',
            'app/Base2.php' => 'namespace App; class Base extends \\Illuminate\\Database\\Eloquent\\Model { protected $table = "second"; }',
            'app/Child.php' => 'namespace App; class Child extends Base {} trait A { public function getTable() { return "a"; } } trait B { public function getTable() { return "b"; } } class Adapted extends \\Illuminate\\Database\\Eloquent\\Model { use A, B { A::getTable insteadof B; } }',
        ]));
        $maps = $this->edges($index, 'maps-table');
        $this->assertCount(1, $maps);
        $this->assertSame('App\\Adapted', $index->elements[$maps[0]['from']]['name']);
        $this->assertSame('a', $index->elements[$maps[0]['to']]['name']);
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'ambiguous_type'));
    }

    public function test_selector_trait_precedence_and_aliases_use_selected_source_evidence(): void
    {
        $index = new CatalogIndex($this->facts(['app/SelectorTraits.php' => <<<'SOURCE'
namespace App;
trait A { public function getTable() { return 'first_rows'; } public function getConnectionName() { return 'first_db'; } }
trait B { public function getTable() { return 'second_rows'; } public function getConnectionName() { return 'second_db'; } }
class Order extends \Illuminate\Database\Eloquent\Model { use A, B { B::getTable insteadof A; A::getConnectionName insteadof B; } }
trait Connection { public function getConnectionName() { return 'alias_rows'; } }
class Aliased extends \Illuminate\Database\Eloquent\Model { use Connection { getConnectionName as getTable; } }
class Conflict extends \Illuminate\Database\Eloquent\Model { use A, B; }
class InvalidVisibility extends \Illuminate\Database\Eloquent\Model { use A { getTable as protected; } }
class Action { public function run() { Order::count(); Aliased::count(); Conflict::count(); InvalidVisibility::count(); } }
SOURCE]));
        $maps = $this->edges($index, 'maps-table');
        $this->assertCount(2, $maps);
        $this->assertSame(['second_rows', 'alias_rows'], array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $maps));
        $this->assertSame(['first_db', 'alias_rows'], array_map(fn ($edge) => $edge['metadata']['connection']['name'], $maps));
        $selectors = array_values(array_filter($maps[0]['metadata']['selector_sources'], fn ($source) => isset($source['selector'])));
        $this->assertSame(['App\B::getTable', 'App\A::getConnectionName'], array_column($selectors, 'symbol'));
        $this->assertCount(2, $this->edges($index, 'reads'));
    }

    public function test_cached_selector_trait_precedence_changes_recompose_unchanged_queries(): void
    {
        $fixed = $this->facts([
            'app/Action.php' => 'namespace App; class Action { public function run() { Order::count(); } }',
            'app/Selectors.php' => 'namespace App; trait A { public function getTable() { return "first_rows"; } } trait B { public function getTable() { return "second_rows"; } }',
        ]);
        $fixed = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $fixed);
        foreach (['A' => 'first_rows', 'B' => 'second_rows'] as $chosen => $table) {
            $other = $chosen === 'A' ? 'B' : 'A';
            $model = $this->facts(['app/Order.php' => 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { use A, B { '.$chosen.'::getTable insteadof '.$other.'; } }'])[0];
            $index = new CatalogIndex([...$fixed, CatalogFacts::fromArray($model->path, $model->toArray())]);
            $reads = $this->edges($index, 'reads');
            $this->assertCount(1, $reads);
            $this->assertSame($table, $index->elements[$reads[0]['to']]['name']);
        }
        $this->assertSame([], $this->edges(new CatalogIndex([$fixed[0]]), 'reads'));
    }

    public function test_cached_arbitrary_getter_and_cast_aliases_decode_only_selected_source_returns(): void
    {
        $sources = [
            'app/Names.php' => <<<'SOURCE'
namespace App;
trait Names {
    public function tableName() { return 'Custom_' . 'Orders'; }
    public function connectionName() { return null; }
    protected function castMap() { return ['amount' => 'decimal:2', 'settings' => 'array']; }
    public function unused() { return 'credential-payload-secret'; }
}
throw new \RuntimeException('execution-sentinel');
SOURCE,
            'app/Order.php' => 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { use Names { tableName as getTable; connectionName as getConnectionName; castMap as protected casts; } } class Action { public function run() { Order::count(); } }',
        ];
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $this->facts($sources));
        $this->assertStringNotContainsString('credential-payload-secret', json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR));
        $reads = [];
        $index = new CatalogIndex($facts, function ($path) use ($sources, &$reads) {
            $reads[] = $path;

            return isset($sources[$path]) ? '<?php '.$sources[$path] : null;
        });
        $model = $index->elements[$index->names['app\\order'][0]];
        $this->assertTrue($model['metadata']['casts_resolved']);
        $this->assertSame(['decimal', 'array'], array_column($model['metadata']['declared_casts'], 'cast'));
        $maps = $this->edges($index, 'maps-table');
        $this->assertCount(1, $maps);
        $this->assertSame('Custom_Orders', $index->elements[$maps[0]['to']]['name']);
        $this->assertSame('default', $maps[0]['metadata']['connection']['kind']);
        $this->assertCount(1, $this->edges($index, 'reads'));
        $this->assertSame(['app/Names.php'], array_values(array_unique($reads)));
        $this->assertStringNotContainsString('credential-payload-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
        $changed = new CatalogIndex($facts, fn ($path) => isset($sources[$path]) ? '<?php '.str_replace('Custom_', 'Other_', $sources[$path]) : null);
        $this->assertSame([], $this->edges($changed, 'reads'));
        $this->assertContains('changed_inputs', array_column($changed->diagnostics, 'code'));
    }

    public function test_deferred_alias_string_escapes_preserve_php_values_and_reject_interpolation(): void
    {
        $source = <<<'SOURCE'
namespace App;
trait Names {
    public function name() { return "Order\x73\137\u{41}"; }
    protected function map() { return ['key' => "App\\KeyCast"]; }
}
class Order extends \Illuminate\Database\Eloquent\Model { use Names { name as getTable; map as protected casts; } }
trait DynamicNames { public function name() { return "Orders{$tenant}"; } }
class DynamicOrder extends \Illuminate\Database\Eloquent\Model { use DynamicNames { name as getTable; } }
SOURCE;
        $facts = $this->facts(['app/Names.php' => $source]);
        $index = new CatalogIndex($facts, fn ($path) => '<?php '.$source);
        $maps = $this->edges($index, 'maps-table');
        $this->assertCount(1, $maps);
        $this->assertSame('Orders_A', $index->elements[$maps[0]['to']]['name']);
        $this->assertSame('App\\KeyCast', $index->elements[$index->names['app\\order'][0]]['metadata']['declared_casts'][0]['cast']);
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'data_analysis'));
    }

    public function test_deferred_alias_reader_failures_and_oversized_sources_keep_selectors_unknown(): void
    {
        $facts = $this->facts(['app/Names.php' => 'namespace App; trait Names { public function name() { return "orders"; } } class Order extends \\Illuminate\\Database\\Eloquent\\Model { use Names { name as getTable; } }']);
        foreach ([fn ($path) => null, fn ($path) => str_repeat('a', 2 * 1024 * 1024 + 1), fn ($path) => throw new \RuntimeException('unavailable')] as $reader) {
            $index = new CatalogIndex($facts, $reader);
            $this->assertSame([], $this->edges($index, 'maps-table'));
            $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'data_analysis'));
        }
    }

    public function test_deferred_source_return_cache_rejects_raw_literal_payload(): void
    {
        $fact = $this->facts(['app/Names.php' => 'namespace App; trait Names { public function name() { return "orders"; } }'])[0];
        $raw = $fact->toArray();
        foreach ($raw['elements'] as &$element) {
            if (isset($element['metadata']['source_return'])) {
                $element['metadata']['source_return']['value']['literal'] = 'credential-secret';
            }
        }
        unset($element);
        $this->expectException(\InvalidArgumentException::class);
        CatalogFacts::fromArray($fact->path, $raw);
    }

    public function test_deferred_source_return_cache_rejects_spans_from_another_method(): void
    {
        $fact = $this->facts(['app/Names.php' => 'namespace App; trait Names { public function name() { return "orders"; } public function unused() { return "credential-secret"; } }'])[0];
        $raw = $fact->toArray();
        $descriptors = array_values(array_filter($raw['elements'], fn ($element) => isset($element['metadata']['source_return'])));
        $this->assertCount(2, $descriptors);
        foreach ($raw['elements'] as &$element) {
            if ($element['id'] === $descriptors[0]['id']) {
                $element['metadata']['source_return']['value'] = $descriptors[1]['metadata']['source_return']['value'];
            }
        }
        unset($element);
        $this->expectException(\InvalidArgumentException::class);
        CatalogFacts::fromArray($fact->path, $raw);
    }

    public function test_deferred_source_return_structural_limits_are_explicit_and_valid_in_cache_format(): void
    {
        $entries = implode(',', array_fill(0, 257, '"key" => "array"'));
        $fact = $this->facts(['app/Names.php' => 'namespace App; trait Names { protected function map() { return ['.$entries.']; } }'])[0];
        $limited = array_values(array_filter($fact->diagnostics, fn ($row) => $row->code === 'catalog_limit'));
        $this->assertNotEmpty($limited);
        $this->assertSame('structure', $limited[0]->limitReason);
        $this->assertEquals($fact, CatalogFacts::fromArray($fact->path, $fact->toArray()));
    }

    public function test_cache_rejects_selector_payload_outside_the_whitelist(): void
    {
        $facts = $this->facts(['app/User.php' => 'namespace App; class User extends \\Illuminate\\Database\\Eloquent\\Model {}'])[0];
        $row = $facts->toArray();
        foreach ($row['elements'] as &$element) {
            if ($element['kind'] === 'class') {
                $element['metadata']['data_declaration']['properties']['password'] = 'secret';
            }
        }
        unset($element);
        $this->expectException(\InvalidArgumentException::class);
        CatalogFacts::fromArray($facts->path, $row);
    }

    public function test_db_terminal_effects_joins_connections_and_preparation_are_distinct(): void
    {
        $index = new CatalogIndex($this->facts(['app/Queries.php' => <<<'SOURCE'
namespace App;
use Illuminate\Support\Facades\DB as Database;
class Queries {
 public function run() {
  Database::table('orders')->where('secret', 'payload-secret');
  Database::connection('tenant')->table('orders as o')->join('users as u', 'u.id', '=', 'o.user_id')->update(['secret' => 'payload-secret']);
  Database::table('ord'.'ers')->get();
  Database::table('orders')->firstOrCreate(['secret' => 'payload-secret']);
  Database::table($dynamic)->get();
  Database::table('orders')->customMacro()->delete();
 }
}
SOURCE]));
        $reads = $this->edges($index, 'reads');
        $writes = $this->edges($index, 'writes');
        $this->assertCount(3, $reads);
        $this->assertCount(2, $writes);
        $this->assertEqualsCanonicalizing(['users', 'orders', 'orders'], array_map(fn ($row) => $index->elements[$row['to']]['name'], $reads));
        $this->assertSame('tenant', $writes[0]['metadata']['connection']['name']);
        $this->assertNotEmpty($index->diagnostics);
        $this->assertStringNotContainsString('payload-secret', json_encode($index->elements));
        foreach ([...$reads, ...$writes] as $row) {
            $this->assertFalse($row['metadata']['execution_proven']);
            $this->assertSame('App\\Queries::run', $index->elements[$row['from']]['name']);
            $this->assertNotEmpty($row['metadata']['operation_source']);
        }
    }

    public function test_eloquent_operations_resolve_model_selector_from_other_cached_file(): void
    {
        $action = $this->facts(['app/Action.php' => 'namespace App; class Action { public function run() { User::where("active", true)->get(); User::create(["value" => "payload-secret"]); Decoy::get(); } }'])[0];
        foreach (['old_users', 'new_users'] as $table) {
            $model = $this->facts(['app/User.php' => 'namespace App; class User extends \\Illuminate\\Database\\Eloquent\\Model { protected $table = "'.$table.'"; } class Decoy { public static function get() {} }'])[0];
            $index = new CatalogIndex([$model, CatalogFacts::fromArray($action->path, $action->toArray())]);
            $reads = $this->edges($index, 'reads');
            $writes = $this->edges($index, 'writes');
            $this->assertCount(1, $reads);
            $this->assertCount(1, $writes);
            $this->assertSame($table, $index->elements[$reads[0]['to']]['name']);
            $this->assertSame('app/User.php', $reads[0]['metadata']['selector_sources'][0]['path']);
        }
        $this->assertSame([], $this->edges(new CatalogIndex([$action]), 'reads'));
    }

    public function test_schema_and_sql_keep_effect_kind_without_serializing_query_payload(): void
    {
        $facts = $this->facts(['database/migrations/schema.php' => <<<'SOURCE'
use Illuminate\Support\Facades\Schema as S;
use Illuminate\Support\Facades\DB as D;
S::create('orders', function ($table) { $table->string('private'); });
S::rename('orders', 'archived_orders');
D::select("SELECT * FROM orders WHERE secret = 'query-secret'");
D::statement("UPDATE orders SET secret = 'query-secret'");
D::select($dynamic);
SOURCE]);
        $this->assertStringNotContainsString('query-secret', json_encode(array_map(fn ($row) => $row->toArray(), $facts)));
        $index = new CatalogIndex($facts);
        $this->assertCount(3, $this->edges($index, 'changes-schema'));
        $this->assertCount(1, $this->edges($index, 'reads'));
        $this->assertCount(1, $this->edges($index, 'writes'));
        $this->assertNotEmpty($index->diagnostics);
    }

    public function test_source_facade_shadow_and_model_override_do_not_infer_effects(): void
    {
        $index = new CatalogIndex($this->facts([
            'app/DB.php' => 'namespace Illuminate\\Support\\Facades; class DB { public static function table($name) {} }',
            'app/Run.php' => 'namespace App; class User extends \\Illuminate\\Database\\Eloquent\\Model { public static function get() {} } class Run { public function run() { \\Illuminate\\Support\\Facades\\DB::table("orders")->get(); User::get(); } }',
        ]));
        $this->assertSame([], $this->edges($index, 'reads'));
        $this->assertNotEmpty($index->diagnostics);
    }

    public function test_http_action_table_path_uses_terminal_effect_and_not_model_structure(): void
    {
        $index = new CatalogIndex($this->facts([
            'routes/web.php' => '\\Illuminate\\Support\\Facades\\Route::get("orders", [App\\Controller::class, "index"]);',
            'app/Controller.php' => 'namespace App; class Controller { public function index() { return (new Action)->run(); } } class Action { public function run() { return Order::get(); } }',
            'app/Order.php' => 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model {}',
        ]));
        $route = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'route'))[0];
        $table = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'table'))[0];
        $query = new GraphQuery($index);
        $path = $query->query($route['id'], 'path', $table['id'], 8);
        $this->assertSame('found', $path['status']);
        $this->assertContains('reads', array_column($path['records'][0]['relations'], 'kind'));
        $this->assertNotContains('maps-table', array_column($path['records'][0]['relations'], 'kind'));
        $this->assertFalse($path['records'][0]['execution_proven']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query('App\\Order', 'path', $table['id'])['status']);
    }

    public function test_unpacked_query_arguments_and_oversized_sql_identifiers_are_explicit(): void
    {
        $index = new CatalogIndex($this->facts(['app/Run.php' => '\\Illuminate\\Support\\Facades\\DB::table("orders")->get(...$args); \\illuminate\\support\\facades\\db::table("orders")->count(); \\Illuminate\\Support\\Facades\\DB::select("SELECT * FROM '.str_repeat('a', 257).'");']));
        $reads = $this->edges($index, 'reads');
        $this->assertCount(1, $reads);
        $this->assertSame('count', $reads[0]['metadata']['operation']);
        $this->assertNotEmpty($index->diagnostics);
    }

    public function test_cast_properties_and_method_merge_with_inherited_source_evidence(): void
    {
        $index = new CatalogIndex($this->facts([
            'app/Base.php' => <<<'SOURCE'
namespace App;
class Currency implements \Illuminate\Contracts\Database\Eloquent\CastsAttributes { public function get($model, $key, $value, $attributes) {} public function set($model, $key, $value, $attributes) {} }
class Other implements \Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes { public function set($model, $key, $value, $attributes) {} }
enum Status: string { case Open = 'open'; }
trait CommonCasts { protected $casts = ['amount' => Currency::class, 'enabled' => 'boolean', 'price' => 'decimal:2', 'state' => Status::class]; }
class Base extends \Illuminate\Database\Eloquent\Model { use CommonCasts; }
SOURCE,
            'app/Child.php' => 'namespace App; class Child extends Base { protected function casts(): array { return ["amount" => Other::class.":credential-secret", "name" => "string"]; } } class Decoy { protected $casts = ["amount" => Other::class]; }',
        ]));
        $child = $index->elements[$index->namedTypes('App\\Child')[0]];
        $casts = array_column($child['metadata']['declared_casts'], null, 'attribute');
        $this->assertSame('App\\Other', $casts['amount']['cast']);
        $this->assertSame('boolean', $casts['enabled']['cast']);
        $this->assertSame('decimal', $casts['price']['cast']);
        $this->assertSame('App\\Status', $casts['state']['cast']);
        $this->assertSame('app/Child.php', $casts['amount']['source']['path']);
        $this->assertSame('App\\CommonCasts::$casts', $casts['enabled']['source']['symbol']);
        $this->assertTrue($child['metadata']['casts_resolved']);
        $this->assertFalse($child['metadata']['runtime_cast_mutations_known']);
        $edges = $this->edges($index, 'uses-cast');
        $this->assertCount(4, $edges);
        foreach ($edges as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertTrue($edge['metadata']['contract_verified']);
        }
        $this->assertStringNotContainsString('credential-secret', json_encode($index->elements));
    }

    public function test_cast_trait_precedence_and_class_override_select_the_effective_map(): void
    {
        $index = new CatalogIndex($this->facts(['app/CastTraits.php' => <<<'SOURCE'
namespace App;
trait A { protected function casts() { return ['amount' => 'int']; } }
trait B { protected function casts() { return ['amount' => 'string']; } }
class Order extends \Illuminate\Database\Eloquent\Model { use A, B { B::casts insteadof A; } protected $casts = ['settings' => 'array']; }
class Override extends Order { protected function casts() { return ['amount' => 'float']; } }
class Conflict extends \Illuminate\Database\Eloquent\Model { use A, B; }
class PrivateCast extends \Illuminate\Database\Eloquent\Model { use A { casts as private; } }
SOURCE]));
        foreach (['Order' => 'string', 'Override' => 'float'] as $name => $cast) {
            $model = $index->elements[$index->names[strtolower('App\\'.$name)][0]];
            $this->assertTrue($model['metadata']['casts_resolved']);
            $entries = array_column($model['metadata']['declared_casts'], null, 'attribute');
            $this->assertSame('array', $entries['settings']['cast']);
            $this->assertSame($cast, $entries['amount']['cast']);
            $this->assertSame($name === 'Order' ? 'App\\B::casts' : 'App\\Override::casts', $entries['amount']['source']['symbol']);
        }
        foreach (['Conflict', 'PrivateCast'] as $name) {
            $model = $index->elements[$index->names[strtolower('App\\'.$name)][0]];
            $this->assertFalse($model['metadata']['casts_resolved']);
        }
        $this->assertCount(4, $this->edges($index, 'maps-table'));
    }

    public function test_cached_cast_trait_precedence_changes_refresh_selected_map(): void
    {
        $traits = $this->facts(['app/Traits.php' => 'namespace App; trait A { protected function casts() { return ["amount" => "int"]; } } trait B { protected function casts() { return ["amount" => "string"]; } }'])[0];
        $traits = CatalogFacts::fromArray($traits->path, $traits->toArray());
        foreach (['A' => 'int', 'B' => 'string'] as $chosen => $cast) {
            $other = $chosen === 'A' ? 'B' : 'A';
            $model = $this->facts(['app/Order.php' => 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { use A, B { '.$chosen.'::casts insteadof '.$other.'; } }'])[0];
            $index = new CatalogIndex([$traits, CatalogFacts::fromArray($model->path, $model->toArray())]);
            $declaration = $index->elements[$index->names['app\\order'][0]];
            $this->assertTrue($declaration['metadata']['casts_resolved']);
            $this->assertSame($cast, $declaration['metadata']['declared_casts'][0]['cast']);
        }
    }

    public function test_dynamic_cast_map_is_not_replaced_by_the_property_and_decoys_do_not_get_casts(): void
    {
        $index = new CatalogIndex($this->facts(['app/Models.php' => 'namespace App; class Cast implements \\Illuminate\\Contracts\\Database\\Eloquent\\Castable { public static function castUsing(array $arguments) {} } class User extends \\Illuminate\\Database\\Eloquent\\Model { protected $casts = ["name" => Cast::class]; protected function casts() { return unknown(); } } class Decoy { protected $casts = ["name" => Cast::class]; }']));
        $this->assertSame([], $this->edges($index, 'uses-cast'));
        $user = $index->elements[$index->namedTypes('App\\User')[0]];
        $this->assertFalse($user['metadata']['casts_resolved']);
        $this->assertNotEmpty($index->diagnostics);
        $decoy = $index->elements[$index->namedTypes('App\\Decoy')[0]];
        $this->assertArrayNotHasKey('declared_casts', $decoy['metadata']);
    }

    public function test_cast_contract_changes_refresh_when_model_file_is_reused(): void
    {
        $model = $this->facts(['app/User.php' => 'namespace App; class User extends \\Illuminate\\Database\\Eloquent\\Model { protected $casts = ["name" => Value::class]; }'])[0];
        $good = $this->facts(['app/Value.php' => 'namespace App; class Value implements \\Illuminate\\Contracts\\Database\\Eloquent\\Castable { public static function castUsing(array $arguments) {} }'])[0];
        $bad = $this->facts(['app/Value.php' => 'namespace App; class Value {}'])[0];
        $this->assertCount(1, $this->edges(new CatalogIndex([$good, CatalogFacts::fromArray($model->path, $model->toArray())]), 'uses-cast'));
        $index = new CatalogIndex([$bad, $model]);
        $this->assertSame([], $this->edges($index, 'uses-cast'));
        $this->assertCount(1, $this->edges($index, 'references-cast'));
        $this->assertNotEmpty($index->diagnostics);
    }

    public function test_cast_cache_rejects_constructor_argument_payload(): void
    {
        $facts = $this->facts(['app/User.php' => 'namespace App; class User extends \\Illuminate\\Database\\Eloquent\\Model { protected $casts = ["name" => "string"]; }'])[0];
        $row = $facts->toArray();
        foreach ($row['elements'] as &$element) {
            if ($element['kind'] === 'class') {
                $element['metadata']['data_casts']['property']['entries'][0]['cast'] = 'string:credential-secret';
            }
        }
        unset($element);
        $this->expectException(\InvalidArgumentException::class);
        CatalogFacts::fromArray($facts->path, $row);
    }

    public function test_source_cast_contract_shadow_does_not_prove_a_laravel_cast(): void
    {
        $index = new CatalogIndex($this->facts([
            'app/Shadow.php' => 'namespace Illuminate\\Contracts\\Database\\Eloquent; interface Castable {}',
            'app/Model.php' => 'namespace App; class Cast implements \\Illuminate\\Contracts\\Database\\Eloquent\\Castable {} class User extends \\Illuminate\\Database\\Eloquent\\Model { protected $casts = ["name" => Cast::class]; }',
        ]));
        $this->assertSame([], $this->edges($index, 'uses-cast'));
        $this->assertCount(1, $this->edges($index, 'references-cast'));
        $this->assertNotEmpty($index->diagnostics);
    }

    public function test_relation_declarations_reference_models_through_and_pivot_without_query_execution(): void
    {
        $index = new CatalogIndex($this->facts([
            'app/Models.php' => <<<'SOURCE'
namespace App;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
trait Relations { public function customer() { return $this->belongsTo(Customer::class); } }
class Order extends Model {
 use Relations;
 protected $connection = 'tenant';
 public function products() { return $this->belongsToMany(Product::class); }
 public function explicitProducts() { return $this->belongsToMany(related: Product::class, table: 'custom_links')->using(OrderProduct::class)->withPivot('payload-secret'); }
 public function deliveries() { return $this->hasManyThrough(Delivery::class, Warehouse::class); }
 public function tags() { return $this->morphToMany(Tag::class, 'taggable'); }
}
class Customer extends Model {} class Product extends Model {} class Delivery extends Model {} class Warehouse extends Model {} class Tag extends Model {}
class OrderProduct extends Pivot {}
class Decoy { public function customer() { return $this->belongsTo(Customer::class); } }
SOURCE,
        ]));
        $this->assertCount(5, $this->edges($index, 'references-related-model'));
        foreach ($this->edges($index, 'references-relation-table') as $edge) {
            $this->assertSame(['kind' => 'named', 'name' => 'tenant'], $index->elements[$edge['to']]['metadata']['connection']);
        }
        $this->assertCount(1, $this->edges($index, 'references-through-model'));
        $this->assertCount(1, $this->edges($index, 'references-pivot-model'));
        $pivotEdges = array_values(array_filter($this->edges($index, 'references-relation-table'), fn ($row) => ($row['metadata']['table_role'] ?? null) === 'pivot'));
        $this->assertSame(['order_product', 'custom_links', 'taggables'], array_map(fn ($row) => $index->elements[$row['to']]['name'], $pivotEdges));
        foreach ($pivotEdges as $edge) {
            $this->assertSame(['kind' => 'named', 'name' => 'tenant'], $index->elements[$edge['to']]['metadata']['connection']);
            $this->assertFalse($edge['metadata']['query_executed']);
        }
        $method = $index->names[strtolower('App\Order::products')][0];
        $this->assertSame('no_path_in_analyzed_graph', (new GraphQuery($index))->query($method, 'path', $pivotEdges[0]['to'], 8)['status']);
        $this->assertSame([], $this->edges($index, 'reads'));
        $this->assertSame([], $this->edges($index, 'writes'));
        $this->assertStringNotContainsString('payload-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
    }

    public function test_relation_overrides_dynamic_targets_and_pivot_conventions_are_explicit(): void
    {
        $index = new CatalogIndex($this->facts(['app/Models.php' => <<<'SOURCE'
namespace App;
class Product extends \Illuminate\Database\Eloquent\Model {}
class Override extends \Illuminate\Database\Eloquent\Model { public function belongsTo($class) {} public function product() { return $this->belongsTo(Product::class); } }
class Dynamic extends \Illuminate\Database\Eloquent\Model { public function product() { return $this->belongsTo($this->class); } public function morph() { return $this->morphTo(Product::class); } public function products() { return $this->belongsToMany(Product::class, $this->tableName); } }
class Custom extends \Illuminate\Database\Eloquent\Model { public function joiningTable($class) { return config('pivot.table'); } public function products() { return $this->belongsToMany(Product::class); } }
SOURCE]));
        $this->assertCount(2, $this->edges($index, 'references-related-model'));
        $pivots = array_filter($this->edges($index, 'references-relation-table'), fn ($row) => ($row['metadata']['table_role'] ?? null) === 'pivot');
        $this->assertSame([], array_values($pivots));
        $this->assertContains('eloquent_relation_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_cached_relation_declarations_recompose_after_related_table_changes_and_source_shadow(): void
    {
        $fixed = $this->facts(['app/Order.php' => 'namespace App; class Order extends \Illuminate\Database\Eloquent\Model { public function customer() { return $this->belongsTo(Customer::class); } }']);
        $fixed = array_map(fn ($row) => CatalogFacts::fromArray($row->path, $row->toArray()), $fixed);
        $before = $this->facts(['app/Customer.php' => 'namespace App; class Customer extends \Illuminate\Database\Eloquent\Model { protected $table = "customers"; }']);
        $after = $this->facts(['app/Customer.php' => 'namespace App; class Customer extends \Illuminate\Database\Eloquent\Model { protected $table = "archived_customers"; }']);
        foreach ([[$before, 'customers'], [$after, 'archived_customers']] as [$facts, $table]) {
            $index = new CatalogIndex([...$fixed, ...$facts]);
            $edges = $this->edges($index, 'references-relation-table');
            $this->assertCount(1, $edges);
            $this->assertSame($table, $index->elements[$edges[0]['to']]['name']);
        }
        $this->assertSame([], $this->edges(new CatalogIndex($fixed), 'references-related-model'));
        $shadow = $this->facts(['app/Shadow.php' => 'namespace Illuminate\Database\Eloquent; class Model {}']);
        $this->assertSame([], $this->edges(new CatalogIndex([...$fixed, ...$before, ...$shadow]), 'references-related-model'));
    }

    public function test_legacy_and_attribute_accessors_have_structural_callback_links_and_verified_roles(): void
    {
        $index = new CatalogIndex($this->facts(['app/Attributes.php' => <<<'SOURCE'
namespace App;
use Illuminate\Database\Eloquent\Casts\Attribute as Field;
trait Names {
 protected function fullName(): Field { return Field::make(get: fn ($value) => Service::read(), set: fn ($value) => Service::write())->shouldCache(); }
 public function getLegacyNameAttribute($value) { return Service::read(); }
 public function setLegacyNameAttribute($value) { Service::write(); }
}
class User extends \Illuminate\Database\Eloquent\Model {
 use Names;
 protected function code(): Field { return new Field(get: fn () => Service::read()); }
 protected function normalized(): Field { return Field::set(fn ($value) => Service::write()); }
 protected function readOnly(): Field { return Field::get(fn () => Service::read())->withoutObjectCaching(); }
}
class Service { public static function read() {} public static function write() {} }
class Decoy { protected function code(): Field { return Field::get(fn () => Service::read()); } }
SOURCE]));
        $this->assertCount(4, $this->edges($index, 'declares-accessor'));
        $this->assertCount(3, $this->edges($index, 'declares-mutator'));
        $this->assertCount(3, $this->edges($index, 'registers-accessor-callback'));
        $this->assertCount(2, $this->edges($index, 'registers-mutator-callback'));
        $fullName = $index->names[strtolower('App\Names::fullName')][0];
        $this->assertContains('accessor', $index->elements[$fullName]['roles']);
        $this->assertContains('mutator', $index->elements[$fullName]['roles']);
        $this->assertSame('full_name', $index->elements[$fullName]['metadata']['eloquent_attribute']['attribute']);
        $query = new GraphQuery($index);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($fullName, 'path', $index->names[strtolower('App\Service::read')][0], 8)['status']);
        $impact = $query->query($index->names[strtolower('App\Service::read')][0], 'impact', null, 8);
        $this->assertContains($fullName, array_column(array_column($impact['records'], 'element'), 'id'));
    }

    public function test_property_operations_invoke_selected_attribute_callbacks_without_implying_database_writes(): void
    {
        $index = new CatalogIndex($this->facts(['app/PropertyOperations.php' => <<<'SOURCE'
namespace App;
use Illuminate\Database\Eloquent\Casts\Attribute as Field;
trait Names {
    public function getLegacyNameAttribute($value) { return $value; }
    public function setLegacyNameAttribute($value) {}
    protected function fullName(): Field { return Field::make(get: fn ($value) => Helper::read(), set: fn ($value) => Helper::write()); }
}
class User extends \Illuminate\Database\Eloquent\Model { use Names; }
class Child extends User {}
class Action {
    public function read(User $user) { return $user->legacy_name; }
    public function write(User $user) { $user->legacy_name = 'credential-secret'; }
    public function callback(Child $user) { $user->full_name; $user->full_name = 'credential-secret'; }
    public function alias() { $user = new User; $alias = $user; $alias->legacy_name; }
    public function nullsafe(?User $user) { return $user?->legacy_name; }
    public function compound(User $user) { $user->legacy_name .= 'suffix'; }
}
class Helper { public static function read() {} public static function write() {} }
SOURCE]));
        $this->assertCount(5, $this->edges($index, 'invokes-accessor'));
        $this->assertCount(3, $this->edges($index, 'invokes-mutator'));
        $this->assertCount(2, $this->edges($index, 'invokes-attribute-definition'));
        $this->assertSame([], $this->edges($index, 'writes'));
        foreach ([...$this->edges($index, 'invokes-accessor'), ...$this->edges($index, 'invokes-mutator')] as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertFalse($edge['metadata']['database_write_proven']);
            $this->assertSame('conditional', $edge['resolution']);
            $this->assertSame('app/PropertyOperations.php', $edge['path']);
        }
        $this->assertStringNotContainsString('credential-secret', json_encode($index->elements, JSON_THROW_ON_ERROR));
    }

    public function test_property_attribute_inference_rejects_decoys_php_properties_dispatch_overrides_and_changed_bindings(): void
    {
        $index = new CatalogIndex($this->facts(['app/PropertyNegatives.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model { public function getNameAttribute($value) { return $value; } }
class Decoy { public function getNameAttribute($value) { return $value; } }
class Declared extends User { public string $name; }
class Overridden extends User { public function __get($key) { return null; } }
class Box { private User $user; }
class Action {
    public function decoy(Decoy $user) { $user->name; }
    public function declared(Declared $user) { $user->name; }
    public function overridden(Overridden $user) { $user->name; }
    public function dynamic(User $user, $name) { $user->$name; }
    public function reassigned(User $user) { $user = new Decoy; $user->name; }
    public function branch(User $user) { if (unknown()) { $user = new Decoy; } $user->name; }
    public function unrelated() { $user->name; }
    public function callback(User $user) { $callback = static function () { $user->name; }; }
    public function inspection(User $user) { isset($user->name); unset($user->name); }
    public function privateProperty(Box $box) { $box->user->name; }
    public function references(User $user) { $alias =& $user; $alias = new Decoy; $user->name; }
    public function escaped(User $user) { unknown($user); $user->name; }
}
SOURCE]));
        $this->assertSame([], $this->edges($index, 'invokes-accessor'));
        $this->assertSame([], $this->edges($index, 'invokes-mutator'));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'eloquent_attribute_analysis'));
    }

    public function test_cached_property_accesses_recompose_selected_attributes_and_typed_properties_after_edits(): void
    {
        $consumer = $this->facts(['app/Consumer.php' => 'namespace App; class Action { public function __construct(public User $user) {} public function read() { return $this->user->name; } }'])[0];
        $consumer = CatalogFacts::fromArray($consumer->path, $consumer->toArray());
        $model = $this->facts(['app/User.php' => 'namespace App; class User extends \Illuminate\Database\Eloquent\Model { public function getNameAttribute($value) { return $value; } }'])[0];
        $this->assertCount(1, $this->edges(new CatalogIndex([$consumer, $model]), 'invokes-accessor'));
        $edited = $this->facts(['app/User.php' => 'namespace App; class User extends \Illuminate\Database\Eloquent\Model { public function getOtherAttribute($value) { return $value; } }'])[0];
        $this->assertSame([], $this->edges(new CatalogIndex([$consumer, $edited]), 'invokes-accessor'));
        $this->assertSame([], $this->edges(new CatalogIndex([$consumer]), 'invokes-accessor'));
    }

    public function test_explicit_attribute_methods_use_framework_dispatch_and_never_store_key_or_value_payloads(): void
    {
        $facts = $this->facts(['app/ExplicitAttributes.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model {
    public string $name;
    public function getNameAttribute($value) { return $value; }
    public function setNameAttribute($value) {}
}
class Magic extends User { public function __get($key) { return null; } public function __set($key, $value) {} }
class Overridden extends User { public function getAttribute($key) { return null; } public function setAttribute($key, $value) {} }
class Decoy { public function getNameAttribute($value) {} public function getAttribute($key) {} }
class Action {
    public function read(User $user) { $user->getAttribute('name'); $user->getAttributeValue(key: 'name'); }
    public function write(User $user) { $user->setAttribute(value: 'credential-secret', key: 'name'); }
    public function magic(Magic $user) { $user->getAttribute('name'); $user->setAttribute('name', 'credential-secret'); }
    public function overridden(Overridden $user) { $user->getAttribute('name'); $user->setAttribute('name', 'credential-secret'); }
    public function bypass(Overridden $user) { $user->getAttributeValue('name'); }
    public function dynamic(User $user, $key, $args) { $user->getAttribute($key); $user->setAttribute(...$args); $user->getAttribute('credential-key-secret'); }
    public function callable(User $user) { $reference = $user->getAttribute(...); }
    public function decoy(Decoy $user) { $user->getAttribute('name'); }
}
SOURCE]);
        $this->assertStringNotContainsString('credential-secret', json_encode($facts[0]->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('credential-key-secret', json_encode($facts[0]->toArray(), JSON_THROW_ON_ERROR));
        $index = new CatalogIndex([CatalogFacts::fromArray($facts[0]->path, $facts[0]->toArray())]);
        $readOwners = array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $this->edges($index, 'invokes-accessor'));
        $writeOwners = array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $this->edges($index, 'invokes-mutator'));
        $this->assertSame(['App\Action::read', 'App\Action::read', 'App\Action::magic', 'App\Action::bypass'], $readOwners);
        $this->assertSame(['App\Action::write', 'App\Action::magic'], $writeOwners);
        $this->assertSame([], $this->edges($index, 'writes'));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'eloquent_attribute_analysis'));
    }

    public function test_attribute_receiver_unions_and_value_captures_keep_callback_ownership_and_parameter_shadowing(): void
    {
        $index = new CatalogIndex($this->facts(['app/CapturedAttributes.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model { public function getNameAttribute($value) { return $value; } }
class Other extends \Illuminate\Database\Eloquent\Model { public function getNameAttribute($value) { return $value; } }
class Decoy {}
class Action {
    public function union(User|Other|Decoy $user) { return $user->name; }
    public function closure(User $user) { $callback = static function () use ($user) { return $user->name; }; }
    public function arrow(User $user) { $callback = static fn () => $user->getAttribute('name'); }
    public function shadow(User $user) { $callback = fn (Decoy $user) => $user->name; }
    public function reference(User $user) { $callback = function () use (&$user) { $user = new Decoy; }; $callback(); $user->name; }
}
class StaticModel extends User { public static function callback() { $callback = function () { return $this->name; }; } }
SOURCE]));
        $edges = $this->edges($index, 'invokes-accessor');
        $this->assertCount(4, $edges);
        $methods = array_filter($edges, fn ($edge) => $index->elements[$edge['from']]['kind'] === 'method');
        $closures = array_filter($edges, fn ($edge) => $index->elements[$edge['from']]['kind'] === 'closure');
        $this->assertCount(2, $methods);
        $this->assertCount(2, $closures);
        foreach ($methods as $edge) {
            $this->assertSame('App\Action::union', $index->elements[$edge['from']]['name']);
        }
        foreach ($closures as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
    }

    public function test_attribute_receiver_descriptor_budget_is_explicit_and_not_cacheable(): void
    {
        $types = array_map(fn ($i) => 'Type'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), range(1, 120));
        $fact = $this->facts(['app/UnionLimit.php' => 'namespace App; function inspect('.implode('|', $types).' $model) { return $model->name; }'])[0];
        $this->assertFalse($fact->cacheable());
        $this->assertContains('catalog_limit', array_map(fn ($row) => $row->code, $fact->diagnostics));
        $this->assertSame([], $this->edges(new CatalogIndex([$fact]), 'invokes-accessor'));
    }

    public function test_cached_attribute_access_descriptor_rejects_arbitrary_payload_fields(): void
    {
        $fact = $this->facts(['app/Access.php' => 'namespace App; function inspect(User $user) { return $user->getAttribute("name"); }'])[0];
        $raw = $fact->toArray();
        foreach ($raw['elements'] as &$element) {
            if ($element['kind'] === 'attribute-access') {
                $element['metadata']['payload'] = 'secret';
            }
        }
        unset($element);
        $this->expectException(\InvalidArgumentException::class);
        CatalogFacts::fromArray($fact->path, $raw);
    }

    public function test_model_serialization_links_visible_accessors_and_appends_with_loaded_data_conditions(): void
    {
        $index = new CatalogIndex($this->facts(['app/Serialization.php' => <<<'SOURCE'
namespace App;
use Illuminate\Database\Eloquent\Casts\Attribute as Field;
class User extends \Illuminate\Database\Eloquent\Model {
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
    protected $hidden = ['secret'];
    protected $appends = ['full_name'];
    public function getNameAttribute($value) { return $value; }
    public function getSecretAttribute($value) { return $value; }
    protected function fullName(): Field { return Field::get(fn ($value) => Helper::read()); }
}
class Visible extends User { protected $visible = ['full_name']; }
class Action {
    public function array(User $user) { return $user->toArray(); }
    public function attributes(User $user) { return $user->attributesToArray(); }
    public function json(User $user) { return $user->toJson(options: 0); }
    public function serialize(User $user) { return $user->jsonSerialize(); }
    public function visible(Visible $user) { return $user->toArray(); }
}
class Helper { public static function read() {} }
SOURCE]));
        $edges = $this->edges($index, 'serializes-through-accessor');
        $this->assertCount(9, $edges);
        $this->assertCount(5, $this->edges($index, 'invokes-attribute-definition'));
        foreach ($edges as $edge) {
            $this->assertNotSame('secret', $edge['metadata']['attribute']);
            $this->assertSame($edge['metadata']['attribute'] === 'full_name', $edge['metadata']['appended']);
            $this->assertSame($edge['metadata']['attribute'] === 'name', $edge['metadata']['loaded_attribute_required']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertSame('conditional', $edge['resolution']);
            $this->assertNotEmpty($edge['metadata']['selector_sources']);
        }
        $this->assertSame([], $this->edges($index, 'writes'));
    }

    public function test_serialization_dispatch_and_dynamic_state_do_not_invent_accessor_paths(): void
    {
        $index = new CatalogIndex($this->facts(['app/SerializationNegative.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model { public function getNameAttribute($value) { return $value; } }
class Override extends User { public function toArray() { return []; } }
class Dynamic extends User { protected $hidden = UNKNOWN; }
#[\Illuminate\Database\Eloquent\Attributes\Hidden(['name'])]
class Attributed extends User {}
class DynamicCast extends User { protected function casts(): array { return unknown(); } }
class CustomCast extends User { protected $casts = ['name' => Cast::class]; }
class Cast implements \Illuminate\Contracts\Database\Eloquent\CastsAttributes { public function get($model, $key, $value, $attributes) {} public function set($model, $key, $value, $attributes) {} }
class CamelCase extends User { public static $snakeAttributes = UNKNOWN; }
class Decoy { public function getNameAttribute($value) {} public function toArray() {} }
class Action {
    public function override(Override $user) { $user->toArray(); $user->toJson(); }
    public function dynamic(Dynamic $user) { $user->toArray(); }
    public function attributed(Attributed $user) { $user->toArray(); }
    public function casts(DynamicCast $user) { $user->toArray(); }
    public function customCast(CustomCast $user) { $user->toArray(); }
    public function camelCase(CamelCase $user) { $user->toArray(); }
    public function decoy(Decoy $user) { $user->toArray(); }
    public function reference(User $user) { $callback = $user->toArray(...); }
    public function mutated(User $user) { $alias = $user; $user->setHidden(['name']); $alias->toArray(); }
    public function bypass(Override $user) { $user->attributesToArray(); }
}
SOURCE]));
        $edges = $this->edges($index, 'serializes-through-accessor');
        $this->assertCount(1, $edges);
        $this->assertSame('App\Action::bypass', $index->elements[$edges[0]['from']]['name']);
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'eloquent_serialization_analysis'));
    }

    public function test_cached_serialization_recomposes_hidden_fields_without_retaining_selector_payloads(): void
    {
        $consumer = $this->facts(['app/Consumer.php' => 'namespace App; class Action { public function run(User $user) { return $user->toArray(); } }'])[0];
        $consumer = CatalogFacts::fromArray($consumer->path, $consumer->toArray());
        foreach ([false, true] as $hidden) {
            $model = $this->facts(['app/User.php' => 'namespace App; class User extends \Illuminate\Database\Eloquent\Model { protected $hidden = ['.($hidden ? '"name", ' : '').'"credential-key-secret"]; public function getNameAttribute($value) { return $value; } }'])[0];
            $this->assertStringNotContainsString('credential-key-secret', json_encode($model->toArray(), JSON_THROW_ON_ERROR));
            $model = CatalogFacts::fromArray($model->path, $model->toArray());
            $this->assertCount($hidden ? 0 : 1, $this->edges(new CatalogIndex([$consumer, $model]), 'serializes-through-accessor'));
        }
        $this->assertSame([], $this->edges(new CatalogIndex([$consumer]), 'serializes-through-accessor'));
    }

    public function test_serialization_selector_budget_is_explicit_and_keeps_partial_facts_noncacheable(): void
    {
        $selectors = implode(',', array_fill(0, 129, '"name"'));
        $fact = $this->facts(['app/SelectorBudget.php' => 'namespace App; class User extends \Illuminate\Database\Eloquent\Model { protected $appends = ['.$selectors.']; public function getNameAttribute($value) {} } class Action { public function run(User $user) { $user->toArray(); } }'])[0];
        $this->assertFalse($fact->cacheable());
        $this->assertContains('catalog_limit', array_map(fn ($row) => $row->code, $fact->diagnostics));
        $this->assertSame([], $this->edges(new CatalogIndex([$fact]), 'serializes-through-accessor'));
    }

    public function test_serialization_candidate_expansion_has_an_explicit_composition_budget(): void
    {
        $methods = implode(' ', array_map(fn ($i) => 'public function getField'.$i.'Attribute($value) { return $value; }', range(1, 70)));
        $calls = str_repeat('$user->toArray(); ', 60);
        $index = new CatalogIndex($this->facts(['app/SerializationBudget.php' => 'namespace App; class User extends \Illuminate\Database\Eloquent\Model { '.$methods.' } class Action { public function run(User $user) { '.$calls.' } }']));
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
        $edges = $this->edges($index, 'serializes-through-accessor');
        $this->assertNotEmpty($edges);
        $this->assertLessThan(4200, count($edges));
    }

    public function test_serialization_class_attributes_follow_framework_version_and_nearest_class_inheritance(): void
    {
        $facts = $this->facts(['app/ClassSelectors.php' => <<<'SOURCE'
namespace App;
use Illuminate\Database\Eloquent\Attributes\Hidden as Conceal;
use Illuminate\Database\Eloquent\Attributes\Visible as Show;
use Illuminate\Database\Eloquent\Attributes\Appends as Add;
#[Conceal('name')]
#[Add(['name', 'credential-key-secret'])]
class User extends \Illuminate\Database\Eloquent\Model { protected $hidden = ['other']; public function getNameAttribute($value) { return $value; } }
class Child extends User {}
#[Conceal([])]
#[Show('name')]
class Override extends User {}
#[Conceal('name')]
trait AnnotatedTrait {}
class TraitUser extends \Illuminate\Database\Eloquent\Model { use AnnotatedTrait; public function getNameAttribute($value) { return $value; } }
class Action {
    public function inherited(Child $user) { $user->toArray(); }
    public function nearest(Override $user) { $user->toArray(); }
    public function trait(TraitUser $user) { $user->toArray(); }
}
SOURCE]);
        $this->assertStringNotContainsString('credential-key-secret', json_encode($facts[0]->toArray(), JSON_THROW_ON_ERROR));
        $facts = [CatalogFacts::fromArray($facts[0]->path, $facts[0]->toArray())];
        foreach (['v12.41.1', 'v13.0.0', 'v13.2.0', 'v13.3.0', 'v13.18.0'] as $version) {
            $composer = (new ComposerCatalogExtractor)->extract(new FileContext('composer.lock', json_encode(['packages' => [['name' => 'laravel/framework', 'version' => $version]]], JSON_THROW_ON_ERROR)));
            $index = new CatalogIndex([...$facts, $composer]);
            $owners = array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $this->edges($index, 'serializes-through-accessor'));
            $this->assertSame(in_array($version, ['v13.3.0', 'v13.18.0'], true) ? ['App\Action::nearest', 'App\Action::trait'] : ['App\Action::inherited', 'App\Action::nearest', 'App\Action::trait'], $owners, $version);
            foreach ($this->edges($index, 'serializes-through-accessor') as $edge) {
                if ($index->elements[$edge['from']]['name'] === 'App\Action::nearest') {
                    $this->assertSame($version !== 'v12.41.1', $edge['metadata']['appended']);
                }
            }
        }
    }

    public function test_class_serialization_attributes_keep_dynamic_shapes_shadows_and_version_conflicts_explicit(): void
    {
        $facts = $this->facts(['app/UnknownClassSelectors.php' => <<<'SOURCE'
namespace App;
use Illuminate\Database\Eloquent\Attributes\Hidden as Conceal;
class User extends \Illuminate\Database\Eloquent\Model { public function getNameAttribute($value) { return $value; } }
#[Conceal(unknown())] class Dynamic extends User {}
#[Conceal(columns: ['name'])] class Named extends User {}
#[Conceal('name'), Conceal('other')] class Repeated extends User {}
class Hidden {}
#[Hidden('name')] class Lookalike extends User {}
#[Conceal('other')] class Attributed extends User {}
#[Conceal('other')] class CustomMerge extends User { public function mergeHidden(array $hidden) { return $this; } }
#[Conceal('other')] class CustomResolve extends User { protected static function resolveClassAttribute($class, $property = null, $type = null) { return ['name']; } }
class Action {
    public function dynamic(Dynamic $user) { $user->toArray(); }
    public function named(Named $user) { $user->toArray(); }
    public function repeated(Repeated $user) { $user->toArray(); }
    public function lookalike(Lookalike $user) { $user->toArray(); }
    public function attributed(Attributed $user) { $user->toArray(); }
    public function customMerge(CustomMerge $user) { $user->toArray(); }
    public function customResolve(CustomResolve $user) { $user->toArray(); }
}
SOURCE]);
        $package = fn ($path, $version) => (new ComposerCatalogExtractor)->extract(new FileContext($path, json_encode(['packages' => [['name' => 'laravel/framework', 'version' => $version]]], JSON_THROW_ON_ERROR)));
        $locked = $package('composer.lock', 'v13.18.0');
        $index = new CatalogIndex([...$facts, $locked]);
        $owners = array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $this->edges($index, 'serializes-through-accessor'));
        $this->assertSame(['App\Action::lookalike', 'App\Action::attributed'], $owners);
        $shadow = $this->facts(['app/Shadow.php' => 'namespace Illuminate\Database\Eloquent\Attributes; class Hidden {}']);
        $index = new CatalogIndex([...$facts, ...$shadow, $locked]);
        $this->assertCount(1, $this->edges($index, 'serializes-through-accessor'));
        foreach ([[], [$package('vendor/composer/installed.json', 'v13.0.0')]] as $other) {
            $index = new CatalogIndex([...$facts, ...($other === [] ? [] : [$locked]), ...$other]);
            $this->assertCount(1, $this->edges($index, 'serializes-through-accessor'));
            $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'eloquent_serialization_analysis'));
        }
    }

    public function test_serialization_naming_uses_exact_mutator_spelling_when_snake_attributes_are_disabled(): void
    {
        $index = new CatalogIndex($this->facts(['app/SerializationNames.php' => <<<'SOURCE'
namespace App;
use Illuminate\Database\Eloquent\Casts\Attribute as Field;
class User extends \Illuminate\Database\Eloquent\Model {
    public static $snakeAttributes = false;
    protected $visible = ['fullName', 'nickName'];
    protected $appends = ['nickName'];
    public function getFullNameAttribute($value) { return $value; }
    protected function nickName(): Field { return Field::get(fn ($value) => $value); }
}
class Child extends User {}
class Hidden extends User { protected $hidden = ['fullName']; }
class Snake extends User { public static $snakeAttributes = true; protected $visible = []; protected $appends = []; }
trait Naming { public static $snakeAttributes = false; }
class Exact extends \Illuminate\Database\Eloquent\Model {
    use Naming;
    public function getURLCodeAttribute($value) { return $value; }
    public function getFoo_BarAttribute($value) { return $value; }
}
class Action {
    public function inherited(Child $user) { return $user->toArray(); }
    public function hidden(Hidden $user) { return $user->toArray(); }
    public function snake(Snake $user) { return $user->toArray(); }
    public function exact(Exact $user) { return $user->toArray(); }
}
SOURCE]));
        $names = [];
        foreach ($this->edges($index, 'serializes-through-accessor') as $edge) {
            $names[$index->elements[$edge['from']]['name']][] = $edge['metadata']['attribute'];
            $this->assertSame($edge['metadata']['attribute'] === 'nickName', $edge['metadata']['appended']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertNotEmpty($edge['metadata']['selector_sources']);
        }
        $this->assertSame([
            'App\Action::inherited' => ['fullName', 'nickName'],
            'App\Action::hidden' => ['nickName'],
            'App\Action::snake' => ['full_name', 'nick_name'],
            'App\Action::exact' => ['uRLCode', 'foo_Bar'],
        ], $names);
    }

    public function test_serialization_appends_resolve_legacy_aliases_independently_of_mutator_registration(): void
    {
        $index = new CatalogIndex($this->facts(['app/AppendAliases.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model {
    public static $snakeAttributes = false;
    protected $appends = ['full_name', 'FullName', 'odd_name'];
    public function getFullNameAttribute($value) { return $value; }
    public function GETOddNameATTRIBUTE($value) { return $value; }
    public function GETUnusedNameATTRIBUTE($value) { return $value; }
}
class Action { public function run(User $user) { return $user->toArray(); } }
SOURCE]));
        $attributes = [];
        foreach ($this->edges($index, 'serializes-through-accessor') as $edge) {
            $attributes[$edge['metadata']['attribute']] = $edge['metadata']['appended'];
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertSame($edge['metadata']['appended'], ! $edge['metadata']['loaded_attribute_required']);
        }
        ksort($attributes);
        $this->assertSame(['FullName' => true, 'fullName' => false, 'full_name' => true, 'odd_name' => true], $attributes);
    }

    public function test_serialization_changes_follow_instance_aliases_order_and_fluent_calls(): void
    {
        $facts = $this->facts(['app/InstanceSelectors.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model {
    protected $hidden = ['email'];
    protected $visible = ['name'];
    public function getNameAttribute($value) { return $value; }
    public function getEmailAttribute($value) { return $value; }
}
class Override extends User { public function makeVisible($attributes) { return $this; } }
class Action {
    public function aliases(User $user) { $alias = $user; $alias->makeVisible('email'); $user->toArray(); }
    public function separate(User $first, User $second) { $first->setHidden(['name']); $second->toArray(); }
    public function fluent(User $user) { $user->setVisible([])->makeVisible('email')->append('email')->makeHidden('name')->toArray(); }
    public function ordered(User $user) { $user->makeHidden('name'); $user->setHidden([]); $user->setVisible(['name']); $user->toArray(); }
    public function reassigned(User $user) { $alias = $user; $user->makeHidden('name'); $alias = new User; $alias->toArray(); }
    public function noOp(User $user) { $user->makeHiddenIf(false, 'name'); $user->toArray(); }
    public function condition(User $user, $flag) { $user->makeVisibleIf($flag, 'email'); $user->toArray(); }
    public function dynamic(User $user, $fields) { $user->setHidden($fields); $user->toArray(); }
    public function overridden(Override $user) { $user->makeVisible('email'); $user->toArray(); }
    public function privacy(User $user) { $user->makeHidden('private-selector-secret'); $user->toArray(); }
}
SOURCE]);
        $this->assertStringNotContainsString('private-selector-secret', json_encode($facts[0]->toArray(), JSON_THROW_ON_ERROR));
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex($facts);
        $names = [];
        foreach ($this->edges($index, 'serializes-through-accessor') as $edge) {
            $names[$index->elements[$edge['from']]['name']][] = $edge['metadata']['attribute'];
            $this->assertFalse($edge['metadata']['execution_proven']);
            if ($index->elements[$edge['from']]['name'] === 'App\Action::fluent') {
                $this->assertTrue($edge['metadata']['appended']);
                $this->assertContains('append', array_column($edge['metadata']['selector_sources'], 'symbol'));
            }
        }
        $this->assertSame([
            'App\Action::aliases' => ['name', 'email'],
            'App\Action::separate' => ['name'],
            'App\Action::fluent' => ['email'],
            'App\Action::ordered' => ['name'],
            'App\Action::reassigned' => ['name'],
            'App\Action::noOp' => ['name'],
            'App\Action::privacy' => ['name'],
        ], $names);
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'eloquent_serialization_analysis'));
    }

    public function test_serialization_changes_preserve_callback_and_escape_uncertainty(): void
    {
        $index = new CatalogIndex($this->facts(['app/SelectorCallbacks.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model { public function getNameAttribute($value) { return $value; } }
class Action {
    public User $user;
    public function property() { $this->user->makeHidden('name'); $this->user->toArray(); }
    public function resetProperty() { $this->user->makeHidden('name'); $this->user = new User; $this->user->toArray(); }
    public function dynamicProperty() { $this->user->makeHidden('name'); $this->user = unknown(); $this->user->toArray(); }
    public function callbackProperty() { $callback = function () { $this->user->makeHidden('name'); }; $this->user->toArray(); }
    public function callbackAssignment() { $callback = function () { $this->user = new User; }; $this->user->toArray(); }
    public function closure(User $user) { $callback = function () use ($user) { $user->makeHidden('name'); }; $user->toArray(); }
    public function shadowed(User $user) { $callback = function (User $user) { $user->makeHidden('name'); }; $user->toArray(); }
    public function escape(User $user) { $alias = $user; unknown($user); $alias->toArray(); }
    public function invalid(User $user) { $user->setHidden(hidden: ['name'], extra: []); $user->toArray(); }
    public function nullable(User $user) { $user?->makeHidden('name'); $user->toArray(); }
    public function localCallback(User $user) { $callback = function () use ($user) { $user->makeHidden('name'); $user->setHidden([]); $user->toArray(); }; }
    public function named(User $user) { $user->makeHiddenIf(attributes: ['name'], condition: false); $user->toArray(); }
}
SOURCE]));
        $names = [];
        foreach ($this->edges($index, 'serializes-through-accessor') as $edge) {
            $names[] = $index->elements[$edge['from']]['name'];
        }
        $this->assertCount(4, $names);
        $this->assertContains('App\Action::resetProperty', $names);
        $this->assertContains('App\Action::shadowed', $names);
        $this->assertContains('App\Action::named', $names);
        $this->assertCount(1, array_filter($names, fn ($name) => str_contains($name, 'closure')));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'attribute_binding_analysis'));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'eloquent_serialization_analysis'));
    }

    public function test_cached_serialization_changes_recompose_after_model_and_consumer_edits(): void
    {
        foreach (['name', 'other'] as $field) {
            $consumer = $this->facts(['app/Action.php' => 'namespace App; class Action { public function run(User $user) { $user->makeHidden("'.$field.'"); $user->toArray(); } }'])[0];
            $consumer = CatalogFacts::fromArray($consumer->path, $consumer->toArray());
            foreach (['name', 'email'] as $getter) {
                $model = $this->facts(['app/User.php' => 'namespace App; class User extends \Illuminate\Database\Eloquent\Model { public function get'.ucfirst($getter).'Attribute($value) { return $value; } }'])[0];
                $model = CatalogFacts::fromArray($model->path, $model->toArray());
                $this->assertCount($field === $getter ? 0 : 1, $this->edges(new CatalogIndex([$consumer, $model]), 'serializes-through-accessor'));
            }
        }
    }

    public function test_json_encode_serializes_source_models_with_aliases_state_and_function_shadow_checks(): void
    {
        $facts = $this->facts(['app/Json.php' => <<<'SOURCE'
namespace App;
use function json_encode as encode;
class User extends \Illuminate\Database\Eloquent\Model { public function getNameAttribute($value) { return $value; } }
class Override extends User { public function jsonSerialize(): mixed { return []; } }
class Decoy { public function getNameAttribute($value) {} }
class Action {
    public function global(User $user) { return \json_encode($user); }
    public function fallback(User $user) { return json_encode($user); }
    public function imported(User $user) { return encode(value: $user, flags: 0); }
    public function hidden(User $user) { $user->makeHidden('name'); return encode($user); }
    public function fluent(User $user) { return encode($user->setHidden([])); }
    public function nested(User $user) { return encode(['payload-secret' => [$user], 'second' => new User]); }
    public function override(Override $user) { return encode($user); }
    public function decoy(Decoy $user) { return encode($user); }
    public function reference(User $user) { return encode(...); }
    public function malformed(User $user) { return encode($user, value: $user); }
}
SOURCE]);
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $this->assertStringNotContainsString('payload-secret', json_encode($facts[0]->toArray(), JSON_THROW_ON_ERROR));
        $index = new CatalogIndex($facts);
        $edges = $this->edges($index, 'serializes-through-accessor');
        $this->assertSame(['App\Action::global', 'App\Action::fallback', 'App\Action::imported', 'App\Action::fluent', 'App\Action::nested', 'App\Action::nested'],
            array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $edges));
        foreach ($edges as $edge) {
            $this->assertSame('json_encode', $edge['metadata']['form']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $shadow = $this->facts(['app/Shadow.php' => 'namespace App; function json_encode($value) { return []; }']);
        $index = new CatalogIndex([...$facts, ...$shadow]);
        $this->assertSame(['App\Action::global', 'App\Action::imported', 'App\Action::fluent', 'App\Action::nested', 'App\Action::nested'],
            array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $this->edges($index, 'serializes-through-accessor')));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => str_contains($row['message'], 'shadowed')));
    }

    public function test_json_model_serialization_exposes_array_expansion_limits_and_reference_uncertainty(): void
    {
        $source = 'namespace App; class User extends \\Illuminate\\Database\\Eloquent\\Model { public function getNameAttribute($value) { return $value; } } '
            .'class Action { public function budget(User $user) { return \\json_encode(['.implode(', ', array_fill(0, 129, '$user')).']); } '
            .'public function referenced(User $user) { return \\json_encode([&$user]); } }';
        $index = new CatalogIndex($this->facts(['app/JsonLimit.php' => $source]));
        $this->assertSame([], $this->edges($index, 'serializes-through-accessor'));
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
        $reference = new CatalogIndex($this->facts(['app/JsonRef.php' => 'namespace App; class User extends \\Illuminate\\Database\\Eloquent\\Model { public function getNameAttribute($value) {} } class Action { public function run(User $user) { return \\json_encode([&$user]); } }']));
        $this->assertSame([], $this->edges($reference, 'serializes-through-accessor'));
        $this->assertContains('attribute_binding_analysis', array_column($reference->diagnostics, 'code'));
    }

    public function test_http_response_serialization_selects_direct_jsonable_and_nested_jsonserializable_paths(): void
    {
        $facts = $this->facts(['app/HttpJson.php' => <<<'SOURCE'
namespace App;
use Illuminate\Support\Facades\Response as Reply;
use Illuminate\Http\JsonResponse as JsonReply;
use function response as respond;
class User extends \Illuminate\Database\Eloquent\Model { public function getNameAttribute($value) { return $value; } }
class Override extends User { public function toJson($options = 0) { return '{}'; } }
class Decoy { public function json($value) {} }
class Action {
    public function helper(User $user) { return response()->json(data: $user); }
    public function plain(User $user) { return respond(content: $user); }
    public function facade(User $user) { return Reply::json($user); }
    public function make(User $user) { return Reply::make($user); }
    public function constructed(User $user) { return new JsonReply(data: $user, json: false); }
    public function nested(User $user) { return response()->json(['user' => $user]); }
    public function hidden(User $user) { $user->makeHidden('name'); return response()->json($user); }
    public function overridden(Override $user) { return Reply::json($user); }
    public function nestedOverride(Override $user) { return Reply::json([$user]); }
    public function encoded(User $user) { return new JsonReply($user, json: true); }
    public function decoy(User $user, Decoy $reply) { return $reply->json($user); }
    public function malformed(User $user) { return response()->json($user, data: $user); }
}
SOURCE]);
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex($facts);
        $names = [];
        foreach ($this->edges($index, 'serializes-through-accessor') as $edge) {
            $name = $index->elements[$edge['from']]['name'];
            $names[] = $name;
            $this->assertSame('http_json', $edge['metadata']['form']);
            $this->assertSame(str_contains($name, 'nested') ? 'jsonserialize' : 'tojson', $edge['metadata']['serialization_dispatch']);
            $this->assertTrue($edge['metadata']['runtime_standard_transport_required']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $this->assertSame(['App\Action::helper', 'App\Action::plain', 'App\Action::facade', 'App\Action::make', 'App\Action::constructed', 'App\Action::nested', 'App\Action::nestedOverride'], $names);
        $shadow = $this->facts(['app/ShadowHelper.php' => 'namespace App; function response($content = null) { return new Decoy; }']);
        $index = new CatalogIndex([...$facts, ...$shadow]);
        $this->assertSame(['App\Action::plain', 'App\Action::facade', 'App\Action::make', 'App\Action::constructed', 'App\Action::nestedOverride'],
            array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $this->edges($index, 'serializes-through-accessor')));
        $shadow = $this->facts(['app/ShadowResponse.php' => 'namespace Illuminate\Http; class JsonResponse {}']);
        $index = new CatalogIndex([...$facts, ...$shadow]);
        $this->assertSame(['App\Action::plain', 'App\Action::make'],
            array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $this->edges($index, 'serializes-through-accessor')));
        $binding = $this->facts(['app/ResponseProvider.php' => 'namespace App; class CustomFactory {} class ResponseProvider extends \\Illuminate\\Support\\ServiceProvider { public function register() { $this->app->bind(\\Illuminate\\Contracts\\Routing\\ResponseFactory::class, CustomFactory::class); } }']);
        $index = new CatalogIndex([...$facts, ...$binding]);
        $this->assertSame(['App\Action::constructed'],
            array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $this->edges($index, 'serializes-through-accessor')));
    }

    public function test_source_http_handlers_serialize_model_returns_without_turning_service_returns_into_http(): void
    {
        $sources = [
            'bootstrap/app.php' => '\\Illuminate\\Foundation\\Application::configure()->withRouting(web: __DIR__."/../routes/web.php");',
            'routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; Route::get("/user", [App\\Controller::class, "show"]); Route::get("/string", [App\\Controller::class, "string"]); Route::get("/responsable", [App\\Controller::class, "responsable"]); Route::get("/nested", [App\\Controller::class, "nested"]); Route::get("/closure", fn (App\\User $user) => $user);',
            'app/ReturnModels.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model { public function getNameAttribute($value) { return $value; } }
class StringUser extends User { public function __toString(): string { return 'custom'; } }
class ResponseUser extends User implements \Illuminate\Contracts\Support\Responsable { public function toResponse($request) { return []; } }
class Controller {
    public function show(User $user) { return $user; }
    public function string(StringUser $user) { return $user; }
    public function responsable(ResponseUser $user) { return $user; }
    public function nested(ResponseUser $user) { return ['user' => $user]; }
    public function unused(User $user) { return $user; }
}
class Service { public function run(User $user): User { return $user; } }
SOURCE,
        ];
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $this->facts($sources));
        $index = new CatalogIndex($facts);
        $names = [];
        foreach ($this->edges($index, 'serializes-through-accessor') as $edge) {
            $name = $index->elements[$edge['from']]['name'];
            $names[] = $name;
            $this->assertSame('http_return', $edge['metadata']['form']);
            $this->assertTrue($edge['metadata']['http_response_pipeline_required']);
            $this->assertSame($name === 'App\Controller::string', $edge['metadata']['recently_created_required']);
            $this->assertNotEmpty($edge['metadata']['http_entry_sources']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            foreach ($edge['metadata']['http_entry_sources'] as $source) {
                $this->assertSame('route', $index->elements[$source['route']]['kind']);
            }
        }
        $this->assertCount(4, $names);
        $this->assertContains('App\Controller::show', $names);
        $this->assertContains('App\Controller::string', $names);
        $this->assertContains('App\Controller::nested', $names);
        $this->assertNotContains('App\Controller::unused', $names);
        $this->assertNotContains('App\Service::run', $names);
        $this->assertSame(['App\StringUser::__toString', 'App\ResponseUser::toResponse'],
            array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $this->edges($index, 'invokes-response-conversion')));
        $changedRoutes = $this->facts(['routes/web.php' => 'use Illuminate\\Support\\Facades\\Route; Route::get("/unused", [App\\Controller::class, "unused"]);'])[0];
        $changedRoutes = CatalogFacts::fromArray($changedRoutes->path, $changedRoutes->toArray());
        $index = new CatalogIndex([...array_filter($facts, fn ($fact) => $fact->path !== 'routes/web.php'), $changedRoutes]);
        $this->assertSame(['App\Controller::unused'], array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $this->edges($index, 'serializes-through-accessor')));
    }

    public function test_typed_http_factories_and_response_setters_require_framework_contracts_and_source_hooks(): void
    {
        $sources = ['app/TypedReplies.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model { public function getNameAttribute($value) { return $value; } }
class Reply extends \Illuminate\Http\JsonResponse {}
class CustomReply extends Reply { public function setData($data = []) { return $this; } }
class Html extends \Illuminate\Http\Response {}
class CustomHtml extends Html { protected function morphToJson($content) { return '{}'; } }
class Factory extends \Illuminate\Routing\ResponseFactory {}
class CustomFactory extends Factory { public function json($data = [], $status = 200, array $headers = [], $options = 0) { return []; } }
class Decoy { public function json($data) {} public function setData($data) {} }
class Action {
    public \Illuminate\Http\JsonResponse $reply;
    public function factory(\Illuminate\Contracts\Routing\ResponseFactory $factory, User $user) { $factory->json($user); }
    public function concrete(Factory $factory, User $user) { $factory->make($user); }
    public function json(Reply $reply, User $user) { $reply->setData(data: $user); }
    public function content(Html $reply, User $user) { $reply->setContent($user); }
    public function property(User $user) { $this->reply->setData($user); }
    public function variable(User $user) { $factory = response(); $factory->json($user); }
    public function nested(Reply $reply, User $user) { $reply->setData([$user]); }
    public function override(CustomReply $reply, User $user) { $reply->setData($user); }
    public function overrideHtml(CustomHtml $reply, User $user) { $reply->setContent($user); }
    public function overrideFactory(CustomFactory $factory, User $user) { $factory->json($user); }
    public function decoy(Decoy $reply, User $user) { $reply->setData($user); $reply->json($user); }
    public function malformed(Reply $reply, User $user) { $reply->setData(wrong: $user); }
}
SOURCE];
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $this->facts($sources));
        $index = new CatalogIndex($facts);
        $edges = $this->edges($index, 'serializes-through-accessor');
        $this->assertSame(['App\Action::factory', 'App\Action::concrete', 'App\Action::json', 'App\Action::content', 'App\Action::property', 'App\Action::variable', 'App\Action::nested'],
            array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $edges));
        foreach ($edges as $edge) {
            $this->assertSame('http_instance', $edge['metadata']['form']);
            $this->assertTrue($edge['metadata']['runtime_standard_transport_required']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $shadow = $this->facts(['app/ResponseHelper.php' => 'namespace App; function response() { return new Decoy; }']);
        $index = new CatalogIndex([...$facts, ...$shadow]);
        $this->assertNotContains('App\Action::variable', array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $this->edges($index, 'serializes-through-accessor')));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'eloquent_serialization_analysis'));
    }

    public function test_cached_serialization_naming_recomposes_after_static_property_edits(): void
    {
        $consumer = $this->facts(['app/Consumer.php' => 'namespace App; class Action { public function run(User $user) { return $user->toArray(); } }'])[0];
        $consumer = CatalogFacts::fromArray($consumer->path, $consumer->toArray());
        foreach ([true, false] as $snake) {
            $model = $this->facts(['app/User.php' => 'namespace App; class User extends \Illuminate\Database\Eloquent\Model { public static $snakeAttributes = '.($snake ? 'true' : 'false').'; public function getFullNameAttribute($value) { return $value; } }'])[0];
            $model = CatalogFacts::fromArray($model->path, $model->toArray());
            $edge = $this->edges(new CatalogIndex([$consumer, $model]), 'serializes-through-accessor')[0];
            $this->assertSame($snake ? 'full_name' : 'fullName', $edge['metadata']['attribute']);
            $this->assertSame($snake, $edge['metadata']['snake_attributes']);
        }
    }

    public function test_attribute_source_shadows_private_methods_and_dynamic_callbacks_do_not_invent_bodies(): void
    {
        $index = new CatalogIndex($this->facts(['app/User.php' => <<<'SOURCE'
namespace App;
use Illuminate\Database\Eloquent\Casts\Attribute;
class User extends \Illuminate\Database\Eloquent\Model {
 private function hidden(): Attribute { return Attribute::get(fn () => Service::read()); }
 protected function dynamic(): Attribute { return Attribute::make(get: $this->callback); }
 protected function unknown(): Attribute { return $this->field; }
 protected function untyped() { return Attribute::get(fn () => Service::read()); }
 public static function getStaticAttribute() { return Service::read(); }
}
class Service { public static function read() {} }
SOURCE]));
        $this->assertSame([], $this->edges($index, 'registers-accessor-callback'));
        $this->assertCount(2, $this->edges($index, 'declares-model-attribute'));
        $this->assertContains('eloquent_attribute_analysis', array_column($index->diagnostics, 'code'));
        $shadow = $this->facts(['app/Shadow.php' => 'namespace Illuminate\Database\Eloquent\Casts; class Attribute {}']);
        $fixed = $this->facts(['app/User.php' => 'class User extends \Illuminate\Database\Eloquent\Model { protected function name(): \Illuminate\Database\Eloquent\Casts\Attribute { return \Illuminate\Database\Eloquent\Casts\Attribute::get(fn () => 1); } }']);
        $fixed = array_map(fn ($row) => CatalogFacts::fromArray($row->path, $row->toArray()), $fixed);
        $this->assertCount(1, $this->edges(new CatalogIndex($fixed), 'registers-accessor-callback'));
        $this->assertSame([], $this->edges(new CatalogIndex([...$fixed, ...$shadow]), 'registers-accessor-callback'));
    }

    public function test_cached_attribute_trait_selection_tracks_current_model_override(): void
    {
        $fixed = $this->facts(['app/Names.php' => 'namespace App; trait Names { protected function name(): \Illuminate\Database\Eloquent\Casts\Attribute { return \Illuminate\Database\Eloquent\Casts\Attribute::get(fn () => 1); } }']);
        $fixed = array_map(fn ($row) => CatalogFacts::fromArray($row->path, $row->toArray()), $fixed);
        $before = $this->facts(['app/User.php' => 'namespace App; class User extends \Illuminate\Database\Eloquent\Model { use Names; }']);
        $after = $this->facts(['app/User.php' => 'namespace App; class User extends \Illuminate\Database\Eloquent\Model { use Names; protected function name() { return "payload-secret"; } }']);
        $this->assertCount(1, $this->edges(new CatalogIndex([...$fixed, ...$before]), 'registers-accessor-callback'));
        $this->assertSame([], $this->edges(new CatalogIndex([...$fixed, ...$after]), 'registers-accessor-callback'));
        $this->assertSame([], $this->edges(new CatalogIndex($fixed), 'registers-accessor-callback'));
    }

    public function test_factory_model_selectors_and_create_write_candidate_without_make_write(): void
    {
        $index = new CatalogIndex($this->facts([
            'app/Types.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model { protected $table = 'users'; }
class Other extends \Illuminate\Database\Eloquent\Model {}
class Controller { public function create() { UserFactory::new()->create(['password' => 'payload-secret']); } public function make() { UserFactory::new()->make(['password' => 'payload-secret']); } }
class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; public function definition() { return ['password' => 'payload-secret']; } }
#[\Illuminate\Database\Eloquent\Factories\Attributes\UseModel(User::class)]
class AttributedFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = Other::class; }
class MethodFactory extends \Illuminate\Database\Eloquent\Factories\Factory { public function modelName() { return User::class; } }
class Decoy { protected $model = User::class; }
SOURCE,
            'routes/web.php' => '\Illuminate\Support\Facades\Route::post("users", [App\Controller::class, "create"]);',
        ]));
        $links = $this->edges($index, 'references-factory-model');
        $this->assertCount(3, $links);
        $this->assertSame(['App\User', 'App\User', 'App\User'], array_map(fn ($row) => $index->elements[$row['to']]['name'], $links));
        $writes = array_values(array_filter($this->edges($index, 'writes'), fn ($row) => ($row['metadata']['operation'] ?? null) === 'factory-create'));
        $this->assertCount(1, $writes);
        $this->assertSame('users', $index->elements[$writes[0]['to']]['name']);
        $query = new GraphQuery($index);
        $this->assertSame('found', $query->query($index->names[strtolower('App\Controller::create')][0], 'path', $writes[0]['to'], 8)['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($index->names[strtolower('App\Controller::make')][0], 'path', $writes[0]['to'], 8)['status']);
        $this->assertStringNotContainsString('payload-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
    }

    public function test_seeders_and_anonymous_migration_directions_are_separate_conditional_entrypoints(): void
    {
        $index = new CatalogIndex($this->facts([
            'app/User.php' => 'namespace App; class User extends \Illuminate\Database\Eloquent\Model {}',
            'database/seeders/UserSeeder.php' => 'namespace Database\Seeders; class UserSeeder extends \Illuminate\Database\Seeder { public function run() { \App\User::create(["password" => "payload-secret"]); } } class Decoy { public function run() {} }',
            'database/migrations/create_users.php' => 'return new class extends \Illuminate\Database\Migrations\Migration { public function up() { \Illuminate\Support\Facades\Schema::create("users", fn ($table) => $table->string("name")); } public function down() { \Illuminate\Support\Facades\Schema::dropIfExists("users"); } }; throw new \Exception("execution-sentinel");',
        ]));
        $seeders = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'database-seeder'));
        $migrations = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'database-migration'));
        $this->assertCount(1, $seeders);
        $this->assertCount(2, $migrations);
        $table = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'table' && $row['name'] === 'users'))[0];
        $query = new GraphQuery($index);
        foreach ([...$seeders, ...$migrations] as $entry) {
            $path = $query->query($entry['id'], 'path', $table['id'], 8);
            $this->assertSame('found', $path['status']);
            $this->assertFalse($path['records'][0]['execution_proven']);
        }
        $impact = $query->query($table['id'], 'impact', null, 8);
        $this->assertCount(3, array_filter($impact['records'], fn ($row) => $row['entrypoint'] && in_array($row['element']['kind'], ['database-seeder', 'database-migration'], true)));
    }

    public function test_dynamic_factory_model_and_source_lifecycle_overrides_do_not_guess_writes(): void
    {
        $index = new CatalogIndex($this->facts(['app/Factory.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model {}
class DynamicFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = SOME_MODEL; }
class OverrideFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; public function create($attributes = []) {} }
class Action { public function run() { DynamicFactory::new()->create(); OverrideFactory::new()->create(); } }
SOURCE]));
        $this->assertSame([], $this->edges($index, 'writes'));
        $this->assertContains('database_lifecycle_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_factory_attributes_validate_named_arguments_and_do_not_fall_back_from_invalid_selectors(): void
    {
        $index = new CatalogIndex($this->facts(['app/Factories.php' => <<<'SOURCE'
namespace App;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel as FactoryModel;
class User extends \Illuminate\Database\Eloquent\Model {}
#[FactoryModel(class: User::class)]
class NamedFactory extends \Illuminate\Database\Eloquent\Factories\Factory {}
#[FactoryModel(wrong: User::class)]
class WrongFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; }
#[FactoryModel(...MODEL_ARGUMENTS)]
class UnpackedFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; }
#[FactoryModel(User::class), FactoryModel(User::class)]
class RepeatedFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; }
#[FactoryModel(User::class, User::class)]
class ExtraFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; }
SOURCE]));
        $links = $this->edges($index, 'references-factory-model');
        $this->assertCount(1, $links);
        $this->assertSame('App\NamedFactory', $index->elements[$links[0]['from']]['name']);
        $this->assertCount(4, array_filter($index->diagnostics, fn ($row) => $row['code'] === 'database_lifecycle_analysis'));
    }

    public function test_database_lifecycle_allows_public_static_methods_and_rejects_inaccessible_entries(): void
    {
        $index = new CatalogIndex($this->facts(['database/lifecycle.php' => <<<'SOURCE'
class StaticSeeder extends \Illuminate\Database\Seeder { public static function run() {} }
class PrivateSeeder extends \Illuminate\Database\Seeder { private function run() {} }
class StaticMigration extends \Illuminate\Database\Migrations\Migration { public static function up() {} protected function down() {} }
SOURCE]));
        $this->assertCount(1, $this->edges($index, 'seeder-entry'));
        $this->assertCount(1, $this->edges($index, 'migration-entry'));
        $this->assertCount(2, array_filter($index->diagnostics, fn ($row) => $row['code'] === 'database_lifecycle_analysis'));
    }

    public function test_model_factory_selectors_resolve_properties_attributes_and_source_methods(): void
    {
        $index = new CatalogIndex($this->facts(['app/FactoryModels.php' => <<<'SOURCE'
namespace App;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory as FactoryFor;
class User extends \Illuminate\Database\Eloquent\Model { use HasFactory; protected static $factory = UserFactory::class; }
#[FactoryFor(factoryClass: UserFactory::class)]
class Attributed extends \Illuminate\Database\Eloquent\Model { use HasFactory; }
class Selected extends \Illuminate\Database\Eloquent\Model { use HasFactory; protected static function newFactory() { return UserFactory::new(); } }
class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; }
class Action { public function create() { User::factory()->create(); Attributed::factory()->create(); Selected::factory()->create(); } public function make() { User::factory()->make(); } }
class Decoy extends \Illuminate\Database\Eloquent\Model { protected static $factory = UserFactory::class; }
class Overridden extends User { public static function factory() { return unknown(); } }
class Dynamic extends User { protected static function newFactory() { return unknown(); } }
SOURCE]));
        $links = $this->edges($index, 'references-model-factory');
        $this->assertCount(3, $links);
        $this->assertSame(['App\User', 'App\Attributed', 'App\Selected'], array_map(fn ($row) => $index->elements[$row['from']]['name'], $links));
        $writes = array_values(array_filter($this->edges($index, 'writes'), fn ($row) => ($row['metadata']['operation'] ?? null) === 'factory-create'));
        $this->assertCount(3, $writes);
        foreach ($writes as $write) {
            $this->assertSame('users', $index->elements[$write['to']]['name']);
            $this->assertFalse($write['metadata']['execution_proven']);
        }
        $this->assertSame('no_path_in_analyzed_graph', (new GraphQuery($index))->query($index->names[strtolower('App\Action::make')][0], 'path', $writes[0]['to'], 8)['status']);
    }

    public function test_factory_conventions_use_composer_namespace_and_recompose_cached_model_after_factory_changes(): void
    {
        $composer = (new ComposerCatalogExtractor)->extract(new FileContext('composer.json', json_encode(['autoload' => ['psr-4' => ['Domain\\' => 'app/']]], JSON_THROW_ON_ERROR)));
        $model = $this->facts(['app/User.php' => 'namespace Domain\Models; class User extends \Illuminate\Database\Eloquent\Model { use \Illuminate\Database\Eloquent\Factories\HasFactory; }'])[0];
        $model = CatalogFacts::fromArray($model->path, $model->toArray());
        foreach (['old_users', 'new_users'] as $table) {
            $factory = $this->facts(['database/factories/UserFactory.php' => 'namespace Database\Factories; class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory {}'])[0];
            $override = $this->facts(['app/User.php' => 'namespace Domain\Models; class User extends \Illuminate\Database\Eloquent\Model { use \Illuminate\Database\Eloquent\Factories\HasFactory; protected $table = "'.$table.'"; }'])[0];
            $index = new CatalogIndex([$composer, $override, $factory]);
            $links = $this->edges($index, 'references-model-factory');
            $this->assertCount(1, $links);
            $this->assertSame('Database\Factories\UserFactory', $index->elements[$links[0]['to']]['name']);
            $this->assertCount(1, $this->edges($index, 'references-factory-model'));
        }
        $this->assertSame([], $this->edges(new CatalogIndex([$composer, $model]), 'references-model-factory'));
        $factory = $this->facts(['database/factories/UserFactory.php' => 'namespace Database\Factories; class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory {}'])[0];
        $dynamicNaming = $this->facts(['app/Provider.php' => '\Illuminate\Database\Eloquent\Factories\Factory::guessFactoryNamesUsing(fn ($model) => unknown());'])[0];
        $this->assertSame([], $this->edges(new CatalogIndex([$composer, $model, $factory, $dynamicNaming]), 'references-model-factory'));
        $shadow = $this->facts(['app/HasFactory.php' => 'namespace Illuminate\Database\Eloquent\Factories; trait HasFactory {}'])[0];
        $this->assertSame([], $this->edges(new CatalogIndex([$composer, $model, $factory, $shadow]), 'references-model-factory'));
        $changed = $this->facts(['database/factories/UserFactory.php' => 'namespace Database\Factories; class UserFactory {}'])[0];
        $this->assertSame([], $this->edges(new CatalogIndex([$composer, $model, $changed]), 'references-model-factory'));
    }

    public function test_use_factory_model_override_applies_only_to_attribute_instantiation_context(): void
    {
        $index = new CatalogIndex($this->facts(['app/FactoryContext.php' => <<<'SOURCE'
namespace App;
#[\Illuminate\Database\Eloquent\Attributes\UseFactory(SharedFactory::class)]
class User extends \Illuminate\Database\Eloquent\Model { use \Illuminate\Database\Eloquent\Factories\HasFactory; }
class SharedFactory extends \Illuminate\Database\Eloquent\Factories\Factory {}
class Action { public function attributed() { User::factory()->create(); } public function direct() { SharedFactory::new()->create(); } }
SOURCE]));
        $writes = array_values(array_filter($this->edges($index, 'writes'), fn ($row) => ($row['metadata']['operation'] ?? null) === 'factory-create'));
        $this->assertCount(1, $writes);
        $this->assertSame('users', $index->elements[$writes[0]['to']]['name']);
        $this->assertSame('App\Action::attributed', $index->elements[$writes[0]['from']]['name']);
    }

    public function test_nested_seeder_calls_have_conditional_paths_without_parameter_payloads(): void
    {
        $facts = $this->facts(['database/seeders/Seeds.php' => <<<'SOURCE'
namespace Database\Seeders;
use Illuminate\Database\Seeder;
class DatabaseSeeder extends Seeder { public function run() { $this->call([UserSeeder::class, 'Database\\Seeders\\AuditSeeder']); $this->callWith(class: UserSeeder::class, parameters: ['password' => 'payload-secret']); $this->callSilent(UserSeeder::class); $this->callOnce(UserSeeder::class); } }
class UserSeeder extends Seeder { public function run() { \Illuminate\Support\Facades\DB::table('users')->insert(['password' => 'payload-secret']); } }
class AuditSeeder extends Seeder { public function run() { \Illuminate\Support\Facades\DB::table('audits')->insert([]); } }
throw new \Exception('execution-sentinel');
SOURCE]);
        $this->assertStringNotContainsString('payload-secret', json_encode(array_map(fn ($row) => $row->toArray(), $facts), JSON_THROW_ON_ERROR));
        $cached = array_map(fn ($row) => CatalogFacts::fromArray($row->path, $row->toArray()), $facts);
        $index = new CatalogIndex($cached);
        $calls = $this->edges($index, 'calls-seeder');
        $this->assertCount(5, $calls);
        $once = array_values(array_filter($calls, fn ($row) => $row['metadata']['operation'] === 'callonce'))[0];
        $this->assertCount(2, $once['metadata']['conditions']);
        $this->assertFalse($once['metadata']['execution_proven']);
        $table = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'table' && $row['name'] === 'users'))[0];
        $entry = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'database-seeder' && $row['name'] === 'Database\Seeders\DatabaseSeeder::run'))[0];
        $this->assertSame('found', (new GraphQuery($index))->query($entry['id'], 'path', $table['id'], 8)['status']);
    }

    public function test_seeder_lookalikes_dynamic_targets_and_source_overrides_do_not_infer_calls(): void
    {
        $index = new CatalogIndex($this->facts(['database/seeders/Negative.php' => <<<'SOURCE'
namespace Database\Seeders;
class Target extends \Illuminate\Database\Seeder { public function run() {} }
class Decoy { public function run() { $this->call(Target::class); } }
class Dynamic extends \Illuminate\Database\Seeder { public function run() { $this->call(unknown()); $this->call(...unknown()); $this->call(wrong: Target::class); $this->call(['key' => Target::class]); } }
class Override extends \Illuminate\Database\Seeder { public function call($class) {} public function run() { $this->call(Target::class); } }
class Resolver extends \Illuminate\Database\Seeder { protected function resolve($class) {} public function run() { $this->callSilent(Target::class); } }
class Invoked extends \Illuminate\Database\Seeder { public function __invoke() {} public function run() {} }
class StaticScope extends \Illuminate\Database\Seeder { public static function run() { $this->call(Target::class); } }
class ParentSeeder extends \Illuminate\Database\Seeder { public function run() { $this->call(Invoked::class); (static function () { $this->call(Target::class); })(); } }
SOURCE]));
        $this->assertSame([], $this->edges($index, 'calls-seeder'));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'database_lifecycle_analysis'));
    }

    public function test_cached_seeder_calls_recompose_targets_and_enforce_selector_limits(): void
    {
        $parent = $this->facts(['database/seeders/Parent.php' => 'namespace Database\Seeders; class ParentSeeder extends \Illuminate\Database\Seeder { public function run() { $this->call(Target::class); } }'])[0];
        $parent = CatalogFacts::fromArray($parent->path, $parent->toArray());
        $target = $this->facts(['database/seeders/Target.php' => 'namespace Database\Seeders; class Target extends \Illuminate\Database\Seeder { public function run() {} }'])[0];
        $this->assertCount(1, $this->edges(new CatalogIndex([$parent, $target]), 'calls-seeder'));
        $changed = $this->facts(['database/seeders/Target.php' => 'namespace Database\Seeders; class Target extends \Illuminate\Database\Seeder { protected function run() {} }'])[0];
        $this->assertSame([], $this->edges(new CatalogIndex([$parent, $changed]), 'calls-seeder'));
        $this->assertSame([], $this->edges(new CatalogIndex([$parent]), 'calls-seeder'));
        $shadow = $this->facts(['app/Seeder.php' => 'namespace Illuminate\Database; class Seeder {}'])[0];
        $this->assertSame([], $this->edges(new CatalogIndex([$parent, $target, $shadow]), 'calls-seeder'));
        $large = $this->facts(['database/seeders/Large.php' => 'class Large extends \Illuminate\Database\Seeder { public function run() { $this->call(['.implode(',', array_fill(0, 129, '\Database\Seeders\Target::class')).']); } }'])[0];
        $this->assertFalse($large->cacheable());
        $this->assertContains('catalog_limit', array_column(array_map(fn ($row) => $row->toArray(), $large->diagnostics), 'code'));
        $this->assertSame([], $this->edges(new CatalogIndex([$large, $target]), 'calls-seeder'));
    }

    public function test_anonymous_seeder_this_calls_keep_exact_closure_ownership(): void
    {
        $index = new CatalogIndex($this->facts(['database/seeders/Anonymous.php' => 'class Target extends \Illuminate\Database\Seeder { public function run() {} } return new class extends \Illuminate\Database\Seeder { public function run() { (function () { $this->call(Target::class); })(); } };']));
        $calls = $this->edges($index, 'calls-seeder');
        $this->assertCount(1, $calls);
        $this->assertSame('closure', $index->elements[$calls[0]['from']]['kind']);
        $this->assertSame('Target::run', $index->elements[$calls[0]['to']]['name']);
    }

    public function test_factory_make_and_create_invoke_only_their_source_lifecycle_stages(): void
    {
        $facts = $this->facts(['app/FactoryLifecycle.php' => <<<'SOURCE'
namespace App;
use Illuminate\Support\Facades\DB;
class User extends \Illuminate\Database\Eloquent\Model { use \Illuminate\Database\Eloquent\Factories\HasFactory; protected static $factory = UserFactory::class; }
class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; public function definition() { DB::table('definitions')->get(); return ['password' => 'payload-secret']; } }
class Action {
    public function make() { User::factory()->state(fn () => DB::table('states')->get())->afterMaking(callback: fn () => DB::table('made')->insert([]))->afterCreating(fn () => DB::table('created')->insert([]))->make(); }
    public function create() { UserFactory::new()->afterMaking(fn () => DB::table('made')->insert([]))->afterCreating(fn () => DB::table('created')->insert([]))->createQuietly(['password' => 'payload-secret']); }
    public function clear() { UserFactory::new()->afterMaking(fn () => DB::table('cleared')->insert([]))->withoutAfterMaking()->afterCreating(fn () => DB::table('cleared')->insert([]))->withoutAfterCreating()->createOne(); }
    public function empty() { User::factory(count: 0)->afterCreating(fn () => DB::table('empty')->insert([]))->create(); }
    public function resetCount() { UserFactory::new()->count(0)->count(null)->afterMaking(fn () => DB::table('reset')->insert([]))->makeOne(); }
}
throw new \Exception('execution-sentinel');
SOURCE]);
        $this->assertStringNotContainsString('payload-secret', json_encode(array_map(fn ($row) => $row->toArray(), $facts), JSON_THROW_ON_ERROR));
        $index = new CatalogIndex(array_map(fn ($row) => CatalogFacts::fromArray($row->path, $row->toArray()), $facts));
        $this->assertCount(5, $this->edges($index, 'invokes-factory-callback'));
        $this->assertCount(4, $this->edges($index, 'invokes-factory-definition'));
        $factoryWrites = array_values(array_filter($this->edges($index, 'writes'), fn ($row) => ($row['metadata']['operation'] ?? null) === 'factory-create'));
        $this->assertCount(2, $factoryWrites);
        $query = new GraphQuery($index);
        $tableId = fn ($name) => array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'table' && $row['name'] === $name))[0]['id'];
        $methodId = fn ($name) => $index->names[strtolower('App\Action::'.$name)][0];
        foreach (['states', 'made', 'definitions'] as $name) {
            $this->assertSame('found', $query->query($methodId('make'), 'path', $tableId($name), 8)['status']);
        }
        $this->assertSame('no_path_in_analyzed_graph', $query->query($methodId('make'), 'path', $tableId('created'), 8)['status']);
        $this->assertSame('found', $query->query($methodId('create'), 'path', $tableId('created'), 8)['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($methodId('clear'), 'path', $tableId('cleared'), 8)['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($methodId('empty'), 'path', $tableId('empty'), 8)['status']);
    }

    public function test_factory_callback_lookalikes_invalid_arguments_and_overrides_do_not_execute_callbacks(): void
    {
        $index = new CatalogIndex($this->facts(['app/FactoryNegative.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model {}
class Decoy { public static function new() {} }
class SourceFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; public function afterCreating($callback) {} }
class NormalFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; }
class Action { public function run() { Decoy::new()->afterCreating(fn () => unknown())->create(); SourceFactory::new()->afterCreating(fn () => unknown())->create(); NormalFactory::new()->afterCreating(wrong: fn () => unknown())->create(); NormalFactory::new()->afterCreating(...unknown())->create(); NormalFactory::new()->state(unknown())->create(); NormalFactory::new()->custom()->afterCreating(fn () => unknown())->create(); } }
SOURCE]));
        $this->assertSame([], $this->edges($index, 'invokes-factory-callback'));
        $this->assertSame([], $this->edges($index, 'writes'));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'database_lifecycle_analysis'));
    }

    public function test_factory_state_callback_sources_and_has_factory_argument_precedence(): void
    {
        $index = new CatalogIndex($this->facts(['app/FactoryStates.php' => <<<'SOURCE'
namespace App;
use Illuminate\Support\Facades\DB;
class User extends \Illuminate\Database\Eloquent\Model { use \Illuminate\Database\Eloquent\Factories\HasFactory; protected static $factory = UserFactory::class; }
class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; }
class Action {
    public function root() { UserFactory::new(attributes: fn () => DB::table('root_states')->get())->make(); }
    public function factory() { User::factory(count: fn () => DB::table('selected_states')->get(), state: fn () => DB::table('ignored_states')->get())->make(); }
    public function attributes() { UserFactory::new()->create(attributes: fn () => DB::table('attribute_states')->get()); }
    public function invalidBinding() { UserFactory::new()->state(static fn () => DB::table('static_states')->get())->create(); }
}
SOURCE]));
        $this->assertCount(3, $this->edges($index, 'invokes-factory-callback'));
        $query = new GraphQuery($index);
        $tableId = fn ($name) => array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'table' && $row['name'] === $name))[0]['id'];
        foreach (['root' => 'root_states', 'factory' => 'selected_states', 'attributes' => 'attribute_states'] as $method => $table) {
            $this->assertSame('found', $query->query($index->names[strtolower('App\Action::'.$method)][0], 'path', $tableId($table), 8)['status']);
        }
        $this->assertSame('no_path_in_analyzed_graph', $query->query($index->names[strtolower('App\Action::factory')][0], 'path', $tableId('ignored_states'), 8)['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($index->names[strtolower('App\Action::invalidBinding')][0], 'path', $tableId('static_states'), 8)['status']);
    }

    public function test_factory_configure_callbacks_compose_through_traits_and_caller_clearing(): void
    {
        $facts = $this->facts([
            'app/FactoryConfiguration.php' => <<<'SOURCE'
namespace App;
trait Lifecycle { public function configure() { return $this->state(fn () => \Illuminate\Support\Facades\DB::table('configured_states')->get())->afterMaking(fn () => \Illuminate\Support\Facades\DB::table('configured_made')->insert([]))->afterCreating(fn () => \Illuminate\Support\Facades\DB::table('configured_created')->insert([])); } }
class User extends \Illuminate\Database\Eloquent\Model { use \Illuminate\Database\Eloquent\Factories\HasFactory; protected static $factory = UserFactory::class; }
class BaseFactory extends \Illuminate\Database\Eloquent\Factories\Factory { use Lifecycle; protected $model = User::class; }
class UserFactory extends BaseFactory {}
SOURCE,
            'app/Action.php' => <<<'SOURCE'
namespace App;
class Action { public function create() { User::factory()->create(); } public function make() { UserFactory::new()->make(); } public function clear() { UserFactory::new()->withoutAfterMaking()->withoutAfterCreating()->create(); } }
SOURCE,
        ]);
        $index = new CatalogIndex(array_map(fn ($row) => CatalogFacts::fromArray($row->path, $row->toArray()), $facts));
        $this->assertCount(3, $this->edges($index, 'invokes-factory-configuration'));
        $this->assertCount(6, $this->edges($index, 'invokes-factory-callback'));
        foreach ($this->edges($index, 'invokes-factory-callback') as $edge) {
            $this->assertSame('app/FactoryConfiguration.php', $edge['path']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $query = new GraphQuery($index);
        $tableId = fn ($name) => array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'table' && $row['name'] === $name))[0]['id'];
        $methodId = fn ($name) => $index->names[strtolower('App\Action::'.$name)][0];
        $this->assertSame('found', $query->query($methodId('create'), 'path', $tableId('configured_created'), 8)['status']);
        $this->assertSame('found', $query->query($methodId('make'), 'path', $tableId('configured_made'), 8)['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($methodId('make'), 'path', $tableId('configured_created'), 8)['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($methodId('clear'), 'path', $tableId('configured_created'), 8)['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($methodId('clear'), 'path', $tableId('configured_made'), 8)['status']);
        $this->assertSame('found', $query->query($methodId('clear'), 'path', $tableId('configured_states'), 8)['status']);
    }

    public function test_factory_configure_count_and_model_factory_count_reset_are_separate(): void
    {
        $index = new CatalogIndex($this->facts(['app/FactoryCount.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model { use \Illuminate\Database\Eloquent\Factories\HasFactory; protected static $factory = UserFactory::class; }
class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; public function configure() { return $this->count(0)->afterCreating(fn () => unknown()); } }
class Action { public function empty() { UserFactory::new()->create(); } public function reset() { User::factory()->create(); } }
SOURCE]));
        $calls = $this->edges($index, 'invokes-factory-callback');
        $this->assertCount(1, $calls);
        $this->assertSame('App\Action::reset', $index->elements[$calls[0]['from']]['name']);
        $writes = array_values(array_filter($this->edges($index, 'writes'), fn ($row) => ($row['metadata']['operation'] ?? null) === 'factory-create'));
        $this->assertCount(1, $writes);
        $this->assertSame('App\Action::reset', $index->elements[$writes[0]['from']]['name']);
    }

    public function test_model_factory_numeric_counts_do_not_invent_models_or_callbacks(): void
    {
        $index = new CatalogIndex($this->facts(['app/NumericFactory.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model { use \Illuminate\Database\Eloquent\Factories\HasFactory; protected static $factory = UserFactory::class; }
class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; public function configure() { return $this->afterCreating(fn () => unknown()); } }
class Action {
    public function zero() { User::factory('0')->create(); }
    public function negative() { User::factory('-2')->create(); }
    public function fraction() { User::factory(0.5)->create(); }
    public function fractionString() { User::factory('0.9')->create(); }
    public function negativeFloat() { User::factory(-0.5)->create(); }
    public function positiveFloat() { User::factory(+0.5)->create(); }
    public function positive() { User::factory('2e0')->create(); }
    public function reset() { User::factory('0')->count(null)->create(); }
    public function one() { User::factory('0')->createOne(); }
    public function defaultCount() { User::factory(count: null)->create(); }
    public function overflow() { User::factory('1e999')->create(); }
}
SOURCE]));
        $expected = ['App\Action::defaultCount', 'App\Action::one', 'App\Action::positive', 'App\Action::reset'];
        foreach (['writes', 'invokes-factory-callback'] as $kind) {
            $edges = $this->edges($index, $kind);
            if ($kind === 'writes') {
                $edges = array_filter($edges, fn ($row) => ($row['metadata']['operation'] ?? null) === 'factory-create');
            }
            $callers = array_map(fn ($row) => $index->elements[$row['from']]['name'], array_values($edges));
            sort($callers);
            $this->assertSame($expected, $callers);
        }
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'database_lifecycle_analysis'));
    }

    public function test_factory_connections_compose_configuration_states_and_caller_resets(): void
    {
        $index = new CatalogIndex($this->facts(['app/ConnectionFactory.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model { protected $connection = 'primary'; }
trait Archive { public function archived() { return $this->connection('archive'); } }
class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory { use Archive; protected $model = User::class; public function configure() { return $this->connection('configured'); } }
class Action {
    public function configured() { UserFactory::new()->create(); }
    public function archived() { UserFactory::new()->archived()->create(); }
    public function explicit() { UserFactory::new()->archived()->connection(connection: 'manual')->createQuietly(); }
    public function reset() { UserFactory::new()->archived()->connection(null)->create(); }
    public function dynamic() { UserFactory::new()->connection(unknown())->create(); }
    public function malformed() { UserFactory::new()->connection('https://user:secret@host')->create(); }
    public function make() { UserFactory::new()->connection('archive')->make(); }
}
SOURCE]));
        $writes = array_values(array_filter($this->edges($index, 'writes'), fn ($row) => ($row['metadata']['operation'] ?? null) === 'factory-create'));
        $actual = [];
        foreach ($writes as $write) {
            $table = $index->elements[$write['to']];
            $this->assertSame('users', $table['name']);
            $this->assertSame($write['metadata']['connection'], $table['metadata']['connection']);
            $this->assertFalse($write['metadata']['execution_proven']);
            $actual[$index->elements[$write['from']]['name']] = $table['metadata']['connection']['name'];
        }
        ksort($actual);
        $this->assertSame(['App\Action::archived' => 'archive', 'App\Action::configured' => 'configured', 'App\Action::explicit' => 'manual', 'App\Action::reset' => 'primary'], $actual);
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'database_lifecycle_analysis'));
        $this->assertStringNotContainsString('secret', json_encode($index->elements, JSON_THROW_ON_ERROR));
    }

    public function test_cached_factory_connection_selectors_track_trait_edit_delete_and_preserve_evidence(): void
    {
        $consumer = $this->facts(['app/Consumer.php' => 'namespace App; class User extends \Illuminate\Database\Eloquent\Model {} class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory { use Setup; protected $model = User::class; } class Action { public function run() { UserFactory::new()->archived()->create(); } }'])[0];
        $consumer = CatalogFacts::fromArray($consumer->path, $consumer->toArray());
        foreach (['old', 'new'] as $connection) {
            $setup = $this->facts(['app/Setup.php' => 'namespace App; trait Setup { public function archived() { return $this->connection("'.$connection.'"); } }'])[0];
            $setup = CatalogFacts::fromArray($setup->path, $setup->toArray());
            $index = new CatalogIndex([$consumer, $setup]);
            $writes = array_values(array_filter($this->edges($index, 'writes'), fn ($row) => ($row['metadata']['operation'] ?? null) === 'factory-create'));
            $this->assertCount(1, $writes);
            $this->assertSame($connection, $index->elements[$writes[0]['to']]['metadata']['connection']['name']);
            $this->assertSame('app/Setup.php', $writes[0]['metadata']['connection_sources'][0]['path']);
            $connections = $this->edges($index, 'uses-database-connection');
            $calls = array_values(array_filter($connections, fn ($row) => $index->elements[$row['from']]['name'] === 'App\Action::run'));
            $this->assertCount(1, $calls);
            $this->assertSame('app/Setup.php', $calls[0]['path']);
        }
        $this->assertSame([], array_values(array_filter($this->edges(new CatalogIndex([$consumer]), 'writes'), fn ($row) => ($row['metadata']['operation'] ?? null) === 'factory-create')));
    }

    public function test_cached_factory_connection_selector_rejects_unsanitized_names(): void
    {
        $fact = $this->facts(['app/Example.php' => 'namespace App; class Action { public function run() { UserFactory::new()->connection("archive")->create(); } }'])[0];
        $raw = $fact->toArray();
        foreach ($raw['elements'] as &$element) {
            if ($element['kind'] === 'factory-operation') {
                $element['metadata']['steps'][1]['connection']['name'] = 'https://user:secret@host';
            }
        }
        unset($element);
        $this->expectException(\InvalidArgumentException::class);
        CatalogFacts::fromArray($fact->path, $raw);
    }

    public function test_source_factory_connection_overrides_do_not_prove_standard_persistence(): void
    {
        $index = new CatalogIndex($this->facts(['app/ConnectionOverride.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model { public function setConnection($name) { return $this; } }
class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; }
class OtherFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; public function connection($name) { return $this; } }
class ConstructedFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; public function __construct() {} }
class Action { public function run() { UserFactory::new()->connection('archive')->create(); OtherFactory::new()->connection('archive')->create(); ConstructedFactory::new()->create(); } }
SOURCE]));
        $writes = array_filter($this->edges($index, 'writes'), fn ($row) => ($row['metadata']['operation'] ?? null) === 'factory-create');
        // A source preparation can be replayed, but the model's setter is an unresolved persistence boundary.
        $this->assertSame([], array_values($writes));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'database_lifecycle_analysis'));
    }

    public function test_related_factories_distinguish_children_parents_make_and_pivot_writes(): void
    {
        $index = new CatalogIndex($this->facts(['app/RelatedFactories.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model {
    public function posts() { return $this->hasMany(Post::class); }
    public function roles() { return $this->belongsToMany(Role::class, 'role_user'); }
}
class Post extends \Illuminate\Database\Eloquent\Model { public function user() { return $this->belongsTo(User::class); } }
class Role extends \Illuminate\Database\Eloquent\Model {}
class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; }
class PostFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = Post::class; }
class RoleFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = Role::class; }
class Action {
    public function children() { UserFactory::new()->has(PostFactory::new()->connection('archive'))->create(); }
    public function parent() { PostFactory::new()->for(UserFactory::new())->make(); }
    public function attachments() { UserFactory::new()->hasAttached(RoleFactory::new(), [], 'roles')->create(); }
    public function make() { UserFactory::new()->has(PostFactory::new())->make(); }
    public function empty() { UserFactory::new()->count(0)->has(PostFactory::new())->create(); }
    public function childEmpty() { UserFactory::new()->has(PostFactory::new()->count(0))->create(); }
    public function missing() { UserFactory::new()->has(PostFactory::new(), 'missing')->create(); }
    public function wrongModel() { UserFactory::new()->has(RoleFactory::new(), 'posts')->create(); }
    public function dynamic() { UserFactory::new()->has(unknown(), 'posts')->create(); }
}
SOURCE]));
        $query = new GraphQuery($index);
        $method = fn ($name) => $index->names[strtolower('App\Action::'.$name)][0];
        $table = fn ($name, $connection = '(default)') => CatalogElement::resourceIdentity('table', $connection.'::'.$name);
        $this->assertSame('found', $query->query($method('children'), 'path', $table('posts', 'archive'), 8)['status']);
        $this->assertSame('found', $query->query($method('parent'), 'path', $table('users'), 8)['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($method('parent'), 'path', $table('posts'), 8)['status']);
        $this->assertSame('found', $query->query($method('attachments'), 'path', $table('role_user'), 8)['status']);
        $this->assertSame('found', $query->query($method('attachments'), 'path', $table('roles'), 8)['status']);
        foreach (['make', 'empty', 'childEmpty', 'missing', 'wrongModel', 'dynamic'] as $name) {
            $this->assertSame('no_path_in_analyzed_graph', $query->query($method($name), 'path', $table('posts'), 8)['status']);
        }
        $this->assertNotEmpty($this->edges($index, 'invokes-factory-relation'));
        $this->assertNotEmpty($this->edges($index, 'creates-related-factory'));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'database_lifecycle_analysis'));
    }

    public function test_model_factory_related_sources_recompose_after_trait_and_model_edits(): void
    {
        $consumer = $this->facts(['app/Consumer.php' => 'namespace App; class Action { public function run() { User::factory()->withPosts()->create(); } }'])[0];
        $consumer = CatalogFacts::fromArray($consumer->path, $consumer->toArray());
        $models = $this->facts(['app/Models.php' => 'namespace App; class User extends \Illuminate\Database\Eloquent\Model { use \Illuminate\Database\Eloquent\Factories\HasFactory; protected static $factory = UserFactory::class; public function posts() { return $this->hasMany(Post::class); } } class Post extends \Illuminate\Database\Eloquent\Model { use \Illuminate\Database\Eloquent\Factories\HasFactory; protected static $factory = PostFactory::class; } class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory { use Related; protected $model = User::class; } class PostFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = Post::class; }'])[0];
        $models = CatalogFacts::fromArray($models->path, $models->toArray());
        foreach (['archive', 'current'] as $connection) {
            $related = $this->facts(['app/Related.php' => 'namespace App; trait Related { public function withPosts() { return $this->has(Post::factory()->connection("'.$connection.'")); } }'])[0];
            $related = CatalogFacts::fromArray($related->path, $related->toArray());
            $index = new CatalogIndex([$consumer, $models, $related]);
            $target = CatalogElement::resourceIdentity('table', $connection.'::posts');
            $this->assertSame('found', (new GraphQuery($index))->query($index->names[strtolower('App\Action::run')][0], 'path', $target, 8)['status']);
            $relations = $this->edges($index, 'creates-related-factory');
            $this->assertCount(1, $relations);
            $this->assertSame('app/Related.php', $relations[0]['path']);
        }
        $deleted = new CatalogIndex([$consumer, $models]);
        $this->assertSame([], $this->edges($deleted, 'creates-related-factory'));
    }

    public function test_related_factory_cycles_and_inaccessible_relations_are_explicit(): void
    {
        $index = new CatalogIndex($this->facts(['app/CyclicFactories.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model { public function children() { return $this->hasMany(User::class); } protected function hidden() { return $this->hasMany(User::class); } }
class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; public function configure() { return $this->has(UserFactory::new(), 'children'); } }
class PlainFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; }
class Action { public function cycle() { UserFactory::new()->create(); } public function hidden() { PlainFactory::new()->has(PlainFactory::new(), 'hidden')->create(); } }
SOURCE]));
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
        $this->assertLessThan(10, count($this->edges($index, 'creates-related-factory')));
        $hidden = $index->names[strtolower('App\Action::hidden')][0];
        $this->assertSame([], array_values(array_filter($this->edges($index, 'creates-related-factory'), fn ($row) => $row['from'] === $hidden)));
        $messages = implode(' ', array_column($index->diagnostics, 'message'));
        $this->assertStringContainsString('cyclic', $messages);
    }

    public function test_factory_related_descriptor_depth_and_cache_payload_are_bounded(): void
    {
        $chain = 'UserFactory::new()';
        for ($i = 0; $i < 34; $i++) {
            $chain = 'UserFactory::new()->has('.$chain.', "children")';
        }
        $deep = $this->facts(['app/Deep.php' => 'namespace App; class Action { public function run() { '.$chain.'->create(); } }'])[0];
        $this->assertFalse($deep->cacheable());
        $this->assertContains('catalog_limit', array_map(fn ($row) => $row->code, $deep->diagnostics));
        $fact = $this->facts(['app/Attach.php' => 'namespace App; class Action { public function run() { UserFactory::new()->hasAttached(RoleFactory::new(), ["token" => "credential-secret"], "roles")->create(); } }'])[0];
        $this->assertStringNotContainsString('credential-secret', json_encode($fact->toArray(), JSON_THROW_ON_ERROR));
        $raw = $fact->toArray();
        foreach ($raw['elements'] as &$element) {
            if ($element['kind'] === 'factory-operation') {
                $element['metadata']['steps'][1]['relationship']['factory'] = 'invalid-target';
            }
        }
        unset($element);
        $this->expectException(\InvalidArgumentException::class);
        CatalogFacts::fromArray($fact->path, $raw);
    }

    public function test_eloquent_local_scopes_keep_query_preparation_and_terminal_effects_separate(): void
    {
        $index = new CatalogIndex($this->facts(['app/Scopes.php' => <<<'SOURCE'
namespace App;
use Illuminate\Database\Eloquent\Attributes\Scope as LocalScope;
trait Paid { public function scopePaid($query) { return $query->where('paid', true); } }
class Order extends \Illuminate\Database\Eloquent\Model {
    use Paid, \Illuminate\Database\Eloquent\Factories\HasFactory;
    #[LocalScope] protected function recent($query) { $query->whereNotNull('paid_at')->orderBy('id'); }
}
class Action {
    public function read() { Order::paid()->recent()->get(); }
    public function write() { Order::query()->recent()->delete(); }
    public function prepare() { Order::paid(); }
}
class Decoy { public function scopePaid($query) { return $query->where('paid', true); } }
class Wrong { public function run() { Decoy::paid()->get(); } }
SOURCE]));
        $scopes = $this->edges($index, 'invokes-query-scope');
        $this->assertCount(3, $scopes);
        $this->assertSame(['App\Paid::scopePaid', 'App\Order::recent', 'App\Order::recent'], array_map(fn ($row) => $index->elements[$row['to']]['name'], $scopes));
        $reads = $this->edges($index, 'reads');
        $writes = $this->edges($index, 'writes');
        $this->assertCount(1, $reads);
        $this->assertCount(1, $writes);
        $this->assertSame('App\Action::read', $index->elements[$reads[0]['from']]['name']);
        $this->assertSame('App\Action::write', $index->elements[$writes[0]['from']]['name']);
        $this->assertSame('orders', $index->elements[$reads[0]['to']]['name']);
        foreach ($scopes as $scope) {
            $this->assertFalse($scope['metadata']['execution_proven']);
        }
    }

    public function test_dynamic_scopes_source_dispatch_and_builder_overrides_do_not_guess_tables(): void
    {
        $index = new CatalogIndex($this->facts(['app/ScopeNegatives.php' => <<<'SOURCE'
namespace App;
class Changed extends \Illuminate\Database\Eloquent\Model { public function scopePaid($query) { return \Illuminate\Support\Facades\DB::table('different'); } }
class PrivateScope extends \Illuminate\Database\Eloquent\Model { private function scopePaid($query) { return $query->where('paid', true); } }
class DispatchOverride extends \Illuminate\Database\Eloquent\Model { public function callNamedScope($scope, array $parameters = []) {} public function scopePaid($query) { return $query; } }
class CustomBuilder extends \Illuminate\Database\Eloquent\Model { protected static $builder = OtherBuilder::class; public function scopePaid($query) { return $query; } }
class AttributeScope extends \Illuminate\Database\Eloquent\Model { #[\Illuminate\Database\Eloquent\Attributes\Scope] public function paid($query) { return $query; } }
class Action { public function run() { Changed::paid()->get(); PrivateScope::paid()->get(); DispatchOverride::paid()->get(); CustomBuilder::paid()->get(); AttributeScope::paid()->get(); } }
SOURCE]));
        $this->assertSame([], $this->edges($index, 'reads'));
        $this->assertSame([], $this->edges($index, 'invokes-query-scope'));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'data_analysis'));
    }

    public function test_cached_scope_edit_and_delete_recompose_terminal_query_evidence(): void
    {
        $consumer = $this->facts(['app/Consumer.php' => 'namespace App; class Order extends \Illuminate\Database\Eloquent\Model { use Paid; } class Action { public function run() { Order::paid()->get(); } }'])[0];
        $consumer = CatalogFacts::fromArray($consumer->path, $consumer->toArray());
        $scope = $this->facts(['app/Paid.php' => 'namespace App; trait Paid { public function scopePaid($query) { return $query->where("paid", true); } }'])[0];
        $scope = CatalogFacts::fromArray($scope->path, $scope->toArray());
        $this->assertCount(1, $this->edges(new CatalogIndex([$consumer, $scope]), 'reads'));
        $changed = $this->facts(['app/Paid.php' => 'namespace App; trait Paid { public function scopePaid($query) { return unknown(); } }'])[0];
        $this->assertSame([], $this->edges(new CatalogIndex([$consumer, $changed]), 'reads'));
        $this->assertSame([], $this->edges(new CatalogIndex([$consumer]), 'reads'));
    }

    public function test_scope_attribute_lookalike_and_invalid_cached_scope_shape_are_not_framework_evidence(): void
    {
        $facts = $this->facts(['app/Scopes.php' => 'namespace App; class Scope {} class Order extends \Illuminate\Database\Eloquent\Model { #[Scope] protected function paid($query) { return $query->where("paid", true); } } class Action { public function run() { Order::paid()->get(); } }']);
        $this->assertSame([], $this->edges(new CatalogIndex($facts), 'reads'));
        $fact = $this->facts(['app/Legacy.php' => 'namespace App; class Order extends \Illuminate\Database\Eloquent\Model { public function scopePaid($query) { return $query; } }'])[0];
        $raw = $fact->toArray();
        foreach ($raw['elements'] as &$element) {
            if (isset($element['metadata']['eloquent_scope'])) {
                $element['metadata']['eloquent_scope']['predicate_payload'] = 'credential-secret';
            }
        }
        unset($element);
        $this->expectException(\InvalidArgumentException::class);
        CatalogFacts::fromArray($fact->path, $raw);
    }

    public function test_custom_eloquent_builder_selectors_connect_source_methods_and_terminal_data(): void
    {
        $index = new CatalogIndex($this->facts(['app/Builders.php' => <<<'SOURCE'
namespace App;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder as CustomBuilder;
trait PaidBuilder { public function paid() { return $this->whereNotNull('paid_at'); } }
class OrderBuilder extends \Illuminate\Database\Eloquent\Builder { use PaidBuilder; }
class PropertyOrder extends \Illuminate\Database\Eloquent\Model { protected static $builder = OrderBuilder::class; }
#[CustomBuilder(builderClass: OrderBuilder::class)] class AttributeOrder extends \Illuminate\Database\Eloquent\Model {}
class MethodOrder extends \Illuminate\Database\Eloquent\Model { public function newEloquentBuilder($query) { return new OrderBuilder($query); } }
class ChildOrder extends PropertyOrder { public function scopeRecent($query) { return $query->whereNotNull('created_at'); } }
class Action {
    public function property() { PropertyOrder::paid()->get(); }
    public function attribute() { AttributeOrder::query()->paid()->get(); }
    public function method() { MethodOrder::paid()->delete(); }
    public function inherited() { ChildOrder::paid()->recent()->get(); }
}
SOURCE]));
        $calls = $this->edges($index, 'invokes-query-builder');
        $this->assertCount(4, $calls);
        foreach ($calls as $call) {
            $this->assertSame('App\PaidBuilder::paid', $index->elements[$call['to']]['name']);
            $this->assertSame('App\OrderBuilder', $call['metadata']['builder_type']);
            $this->assertFalse($call['metadata']['execution_proven']);
            $this->assertSame('app/Builders.php', $call['metadata']['selector_source']['path']);
        }
        $this->assertCount(3, $this->edges($index, 'reads'));
        $this->assertCount(1, $this->edges($index, 'writes'));
        $this->assertCount(1, $this->edges($index, 'invokes-query-scope'));
    }

    public function test_custom_builder_dynamic_selector_and_terminal_override_are_explicit(): void
    {
        $index = new CatalogIndex($this->facts(['app/BuildersNegative.php' => <<<'SOURCE'
namespace App;
class WrongBuilder { public function paid() { return $this; } }
class DynamicBuilder extends \Illuminate\Database\Eloquent\Builder { public function paid() { return unknown(); } }
class TerminalBuilder extends \Illuminate\Database\Eloquent\Builder { public function get($columns = ['*']) { return unknown(); } }
class PrivateBuilder extends \Illuminate\Database\Eloquent\Builder { protected function paid() { return $this; } }
class ConstraintBuilder extends \Illuminate\Database\Eloquent\Builder { public function paid() { return $this->where('paid', true); } public function where($column, $operator = null, $value = null, $boolean = 'and') { return unknown(); } }
class A extends \Illuminate\Database\Eloquent\Model { protected static $builder = WrongBuilder::class; }
class B extends \Illuminate\Database\Eloquent\Model { protected static $builder = DynamicBuilder::class; }
class C extends \Illuminate\Database\Eloquent\Model { protected static $builder = TerminalBuilder::class; }
class D extends \Illuminate\Database\Eloquent\Model { protected static $builder = PrivateBuilder::class; }
class E extends \Illuminate\Database\Eloquent\Model { public function newEloquentBuilder($query) { if (unknown()) { return new DynamicBuilder($query); } return new TerminalBuilder($query); } }
class F extends \Illuminate\Database\Eloquent\Model { protected static $builder = ConstraintBuilder::class; }
class Action { public function run() { A::paid()->get(); B::paid()->get(); C::get(); D::paid()->get(); E::get(); F::paid()->get(); } }
SOURCE]));
        $this->assertSame([], $this->edges($index, 'reads'));
        $this->assertSame([], $this->edges($index, 'invokes-query-builder'));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'data_analysis'));
    }

    public function test_cached_custom_builder_method_and_selector_changes_recompose_reads(): void
    {
        $consumer = $this->facts(['app/Consumer.php' => 'namespace App; class Order extends \Illuminate\Database\Eloquent\Model { protected static $builder = OrderBuilder::class; } class Action { public function run() { Order::paid()->get(); } }'])[0];
        $consumer = CatalogFacts::fromArray($consumer->path, $consumer->toArray());
        $builder = $this->facts(['app/Builder.php' => 'namespace App; class OrderBuilder extends \Illuminate\Database\Eloquent\Builder { public function paid() { return $this->where("paid", true); } }'])[0];
        $builder = CatalogFacts::fromArray($builder->path, $builder->toArray());
        $this->assertCount(1, $this->edges(new CatalogIndex([$consumer, $builder]), 'reads'));
        $changed = $this->facts(['app/Builder.php' => 'namespace App; class OrderBuilder extends \Illuminate\Database\Eloquent\Builder { public function paid() { return unknown(); } }'])[0];
        $this->assertSame([], $this->edges(new CatalogIndex([$consumer, $changed]), 'reads'));
        $this->assertSame([], $this->edges(new CatalogIndex([$consumer]), 'reads'));
    }

    public function test_builder_cache_descriptor_rejects_nonselector_payload(): void
    {
        $fact = $this->facts(['app/Order.php' => 'namespace App; #[\Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder(builderClass: OrderBuilder::class)] class Order extends \Illuminate\Database\Eloquent\Model {}'])[0];
        $raw = $fact->toArray();
        foreach ($raw['elements'] as &$element) {
            if (isset($element['metadata']['query_builder']['attribute'])) {
                $element['metadata']['query_builder']['attribute']['credentials'] = 'secret';
            }
        }
        unset($element);
        $this->expectException(\InvalidArgumentException::class);
        CatalogFacts::fromArray($fact->path, $raw);
    }

    public function test_global_scopes_show_registration_application_and_selective_exclusion(): void
    {
        $index = new CatalogIndex($this->facts(['app/GlobalScopes.php' => <<<'SOURCE'
namespace App;
use Illuminate\Database\Eloquent\Attributes\ScopedBy as GlobalScopes;
class TenantScope implements \Illuminate\Database\Eloquent\Scope { public function apply($builder, $model) { $builder->where('tenant_id', 1); } }
#[GlobalScopes(classes: [TenantScope::class])] class Order extends \Illuminate\Database\Eloquent\Model {
    protected static function booted() { static::addGlobalScope('paid', fn ($query) => $query->where('paid', true)); }
}
class Action {
    public function read() { Order::get(); }
    public function write() { Order::where('id', 1)->update([]); }
    public function unscoped() { Order::withoutGlobalScopes()->get(); }
    public function selective() { Order::withoutGlobalScope(TenantScope::class)->get(); }
    public function selectedList() { Order::withoutGlobalScopes(['paid'])->delete(); }
    public function dynamic() { Order::withoutGlobalScopes(unknown())->get(); }
    public function create() { Order::create([]); }
}
SOURCE]));
        $applied = $this->edges($index, 'applies-global-scope');
        $byCaller = [];
        foreach ($applied as $edge) {
            $byCaller[$index->elements[$edge['from']]['name']][] = $index->elements[$edge['to']]['kind'];
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertSame('app/GlobalScopes.php', $edge['metadata']['registration_source']['path']);
        }
        $this->assertSame(['App\Action::read' => ['method', 'closure'], 'App\Action::write' => ['method', 'closure'], 'App\Action::selective' => ['closure'], 'App\Action::selectedList' => ['method']], $byCaller);
        $this->assertCount(2, $this->edges($index, 'registers-global-scope'));
        $this->assertCount(3, $this->edges($index, 'reads'));
        $this->assertCount(3, $this->edges($index, 'writes'));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'global_scope_analysis'));
    }

    public function test_global_scopes_apply_to_read_terminals_and_builder_counters(): void
    {
        $arguments = [
            'get' => '', 'first' => '', 'firstOrFail' => '', 'find' => '1', 'findOrFail' => '1', 'findMany' => '[1]',
            'sole' => '', 'value' => "'amount'", 'pluck' => "'amount'", 'count' => '', 'sum' => "'amount'",
            'avg' => "'amount'", 'min' => "'amount'", 'max' => "'amount'", 'exists' => '', 'doesntExist' => '',
            'paginate' => '', 'simplePaginate' => '', 'cursorPaginate' => '', 'cursor' => '', 'lazy' => '',
            'lazyById' => '', 'chunk' => '10, fn ($rows) => true', 'chunkById' => '10, fn ($rows) => true',
            'each' => 'fn ($row) => true', 'eachById' => 'fn ($row) => true',
        ];
        $methods = '';
        foreach ($arguments as $name => $args) {
            $methods .= 'public function '.$name.'() { Order::'.$name.'('.$args.'); } ';
        }
        $index = new CatalogIndex($this->facts(['app/ScopedTerminals.php' => <<<'SOURCE'
namespace App;
class Order extends \Illuminate\Database\Eloquent\Model {
    protected static function booted() { static::addGlobalScope('paid', fn ($query) => $query->where('paid', true)); }
}
SOURCE
            .'class Action { '.$methods."public function increase() { Order::where('id', 1)->increment('amount'); } public function decrease() { Order::where('id', 1)->decrement('amount'); } }"]));
        $reads = $this->edges($index, 'reads');
        $this->assertCount(count($arguments), $reads);
        $this->assertSame(array_map('strtolower', array_keys($arguments)), array_column(array_column($reads, 'metadata'), 'operation'));
        foreach ($reads as $edge) {
            if (in_array($edge['metadata']['operation'], ['cursor', 'lazy', 'lazybyid'], true)) {
                $this->assertContains('Lazy query executes only when consumed.', $edge['metadata']['conditions']);
            }
        }
        $applied = $this->edges($index, 'applies-global-scope');
        $this->assertCount(count($arguments) + 2, $applied);
        $this->assertCount(2, $this->edges($index, 'writes'));
        foreach ($applied as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertSame('app/ScopedTerminals.php', $edge['path']);
            $this->assertSame('closure', $index->elements[$edge['to']]['kind']);
        }
        $this->assertSame([], array_values(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'global_scope_analysis')));
    }

    public function test_global_scopes_distinguish_bulk_builder_application_from_unscoped_writes(): void
    {
        $index = new CatalogIndex($this->facts(['app/ScopeWrites.php' => <<<'SOURCE'
namespace App;
class Order extends \Illuminate\Database\Eloquent\Model {
    protected static function booted() { static::addGlobalScope('paid', fn ($query) => $query->where('paid', true)); }
}
class Action {
    public function insert() { Order::insert([['paid' => false]]); }
    public function ignore() { Order::insertOrIgnore([['paid' => false]]); }
    public function upsert() { Order::upsert([['id' => 1]], ['id']); }
    public function create() { Order::create([]); }
    public function quiet() { Order::createQuietly([]); }
    public function forceDelete() { Order::where('id', 1)->forceDelete(); }
    public function truncate() { Order::truncate(); }
    public function updateOrInsert() { Order::updateOrInsert(['id' => 1], []); }
}
SOURCE]));
        $this->assertSame(['App\Action::insert', 'App\Action::ignore', 'App\Action::upsert'], array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $this->edges($index, 'applies-global-scope')));
        $this->assertCount(8, $this->edges($index, 'writes'));
        $reads = $this->edges($index, 'reads');
        $this->assertCount(1, $reads);
        $this->assertSame('updateorinsert', $reads[0]['metadata']['operation']);
        $this->assertSame([], array_values(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'global_scope_analysis')));
    }

    public function test_unrelated_trait_aliases_and_selected_booted_aliases_preserve_model_queries(): void
    {
        $index = new CatalogIndex($this->facts(['app/TraitScopes.php' => <<<'SOURCE'
namespace App;
trait Utility { public function description() { return 'order'; } }
trait PaidBoot { protected static function install() { static::addGlobalScope('paid', fn ($query) => $query->where('paid', true)); } }
trait OtherBoot { protected static function booted() { static::addGlobalScope('other', fn ($query) => $query->where('other', true)); } }
trait TenantBoot { protected static function booted() { static::addGlobalScope('tenant', fn ($query) => $query->where('tenant', 1)); } }
class A extends \Illuminate\Database\Eloquent\Model {
    use Utility { description as label; }
    protected static function booted() { static::addGlobalScope('paid', fn ($query) => $query->where('paid', true)); }
}
class B extends \Illuminate\Database\Eloquent\Model { use PaidBoot { install as protected booted; } }
class C extends \Illuminate\Database\Eloquent\Model { use OtherBoot, TenantBoot { TenantBoot::booted insteadof OtherBoot; } }
class Action { public function run() { A::count(); B::count(); C::count(); } }
SOURCE]));
        $this->assertCount(3, $this->edges($index, 'reads'));
        $this->assertCount(3, $this->edges($index, 'applies-global-scope'));
        $registrations = $this->edges($index, 'registers-global-scope');
        $this->assertCount(3, $registrations);
        foreach ($registrations as $edge) {
            $this->assertStringNotContainsString('OtherBoot', $index->elements[$index->elements[$edge['to']]['parent']]['name']);
        }
    }

    public function test_cached_scope_trait_alias_visibility_changes_recompose_unchanged_calls(): void
    {
        $fixed = $this->facts([
            'app/Action.php' => 'namespace App; class Action { public function run() { Order::count(); } }',
            'app/Paid.php' => 'namespace App; trait Paid { protected static function install() { static::addGlobalScope("paid", fn ($query) => $query->where("paid", true)); } }',
        ]);
        $fixed = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $fixed);
        $model = $this->facts(['app/Order.php' => 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { use Paid { install as protected booted; } }'])[0];
        $model = CatalogFacts::fromArray($model->path, $model->toArray());
        $index = new CatalogIndex([...$fixed, $model]);
        $this->assertCount(1, $this->edges($index, 'reads'));
        $this->assertCount(1, $this->edges($index, 'applies-global-scope'));
        $changed = $this->facts(['app/Order.php' => 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { use Paid { install as private booted; } }'])[0];
        $index = new CatalogIndex([...$fixed, $changed]);
        $this->assertSame([], $this->edges($index, 'reads'));
        $this->assertSame([], $this->edges($index, 'applies-global-scope'));
        $this->assertSame([], $this->edges(new CatalogIndex([$fixed[0], $model]), 'reads'));
    }

    public function test_private_or_conflicting_booted_trait_methods_do_not_infer_scope_application(): void
    {
        $index = new CatalogIndex($this->facts(['app/TraitConflicts.php' => <<<'SOURCE'
namespace App;
trait Paid { protected static function booted() { static::addGlobalScope('paid', fn ($query) => $query->where('paid', true)); } }
trait Tenant { protected static function booted() { static::addGlobalScope('tenant', fn ($query) => $query->where('tenant', 1)); } }
class A extends \Illuminate\Database\Eloquent\Model { use Paid { booted as private; } }
class B extends \Illuminate\Database\Eloquent\Model { use Paid, Tenant; }
class C extends \Illuminate\Database\Eloquent\Model { protected static function unused() { static::addGlobalScope('unused', fn ($query) => $query->where('unused', true)); } }
class Action { public function run() { A::get(); B::get(); C::get(); } }
SOURCE]));
        $reads = $this->edges($index, 'reads');
        $this->assertCount(1, $reads);
        $maps = array_values(array_filter($this->edges($index, 'maps-table'), fn ($edge) => $edge['from'] === $index->names['app\\c'][0]));
        $this->assertSame($maps[0]['to'], $reads[0]['to']);
        $this->assertSame([], $this->edges($index, 'applies-global-scope'));
        $this->assertSame([], $this->edges($index, 'registers-global-scope'));
    }

    public function test_global_scope_registration_named_arguments_follow_the_framework_signature(): void
    {
        $index = new CatalogIndex($this->facts(['app/NamedScopes.php' => <<<'SOURCE'
namespace App;
class TenantScope implements \Illuminate\Database\Eloquent\Scope { public function apply($builder, $model) { $builder->where('tenant', 1); } }
class A extends \Illuminate\Database\Eloquent\Model { protected static function booted() { static::addGlobalScope(implementation: fn ($query) => $query->where('paid', true), scope: 'paid'); } }
class B extends \Illuminate\Database\Eloquent\Model { protected static function booted() { static::addGlobalScope(scope: TenantScope::class, implementation: null); } }
class C extends \Illuminate\Database\Eloquent\Model { protected static function booted() { static::addGlobalScope('tenant', implementation: new TenantScope()); } }
class D extends \Illuminate\Database\Eloquent\Model { protected static function booted() { static::addGlobalScope(scope: 'tenant', implementation: TenantScope::class); } }
class E extends \Illuminate\Database\Eloquent\Model { protected static function booted() { static::addGlobalScope(unknown: TenantScope::class); } }
class Action { public function run() { A::get(); B::get(); C::get(); D::get(); E::get(); } }
SOURCE]));
        $this->assertCount(3, $this->edges($index, 'reads'));
        $this->assertCount(3, $this->edges($index, 'applies-global-scope'));
        $this->assertCount(3, $this->edges($index, 'registers-global-scope'));
        $this->assertCount(2, array_filter($index->diagnostics, fn ($row) => $row['code'] === 'global_scope_analysis'));
    }

    public function test_compound_model_operations_keep_lookup_scopes_and_conditional_persistence(): void
    {
        $index = new CatalogIndex($this->facts(['app/Compound.php' => <<<'SOURCE'
namespace App;
class Order extends \Illuminate\Database\Eloquent\Model {
    protected static function booted() { static::addGlobalScope('paid', fn ($query) => $query->where('paid', true)); }
}
class Action {
    public function first() { Order::firstOrCreate(['id' => 1]); }
    public function create() { Order::createOrFirst(['id' => 1]); }
    public function update() { Order::updateOrCreate(['id' => 1]); }
    public function increment() { Order::incrementOrCreate(['id' => 1]); }
    public function excluded() { Order::withoutGlobalScopes()->firstOrCreate(['id' => 1]); }
}
SOURCE]));
        $this->assertCount(5, $this->edges($index, 'reads'));
        $this->assertCount(5, $this->edges($index, 'writes'));
        $scopes = $this->edges($index, 'applies-global-scope');
        $this->assertCount(4, $scopes);
        foreach ($scopes as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $conditions = implode(' ', $edge['metadata']['conditions']);
            $this->assertStringContainsString($index->elements[$edge['from']]['name'] === 'App\Action::create' ? 'unique constraint violation' : 'not to model persistence', $conditions);
        }
        foreach ([...$this->edges($index, 'reads'), ...$this->edges($index, 'writes')] as $edge) {
            $this->assertContains('Read/write branches depend on matching records and database constraints.', $edge['metadata']['conditions']);
        }
    }

    public function test_compound_operation_source_pipeline_overrides_do_not_infer_default_effects(): void
    {
        $index = new CatalogIndex($this->facts(['app/CompoundOverrides.php' => <<<'SOURCE'
namespace App;
class Order extends \Illuminate\Database\Eloquent\Model {
    protected static function booted() { static::addGlobalScope('paid', fn ($query) => $query->where('paid', true)); }
}
class SaveOrder extends Order { public function save(array $options = []) { return unknown(); } }
class LookupBuilder extends \Illuminate\Database\Eloquent\Builder { public function first($columns = ['*']) { return unknown(); } }
class LookupOrder extends Order { protected static $builder = LookupBuilder::class; }
class CreateBuilder extends \Illuminate\Database\Eloquent\Builder { public function create(array $attributes = []) { return unknown(); } }
class CreateOrder extends Order { protected static $builder = CreateBuilder::class; }
class Action { public function run() { SaveOrder::updateOrCreate([]); LookupOrder::firstOrCreate([]); CreateOrder::createOrFirst([]); } }
SOURCE]));
        $this->assertSame([], $this->edges($index, 'reads'));
        $this->assertSame([], $this->edges($index, 'writes'));
        $this->assertSame([], $this->edges($index, 'applies-global-scope'));
        $this->assertCount(3, array_filter($index->diagnostics, fn ($row) => $row['code'] === 'data_analysis'));
    }

    public function test_global_scope_read_terminals_preserve_exclusions_and_source_override_guards(): void
    {
        $index = new CatalogIndex($this->facts(['app/ScopedGuards.php' => <<<'SOURCE'
namespace App;
class Order extends \Illuminate\Database\Eloquent\Model {
    protected static function booted() { static::addGlobalScope('paid', fn ($query) => $query->where('paid', true)); }
}
class Override extends Order { public static function count() { return 123; } }
class CustomBuilder extends \Illuminate\Database\Eloquent\Builder { public function count($columns = '*') { return 123; } }
class CustomOrder extends Order { protected static $builder = CustomBuilder::class; }
class BaseOverrideBuilder extends \Illuminate\Database\Eloquent\Builder { public function toBase() { return unknown(); } }
class BaseOverrideOrder extends Order { protected static $builder = BaseOverrideBuilder::class; }
class ChangingScope implements \Illuminate\Database\Eloquent\Scope { public function apply($builder, $model) { $builder->from('other'); } }
#[\Illuminate\Database\Eloquent\Attributes\ScopedBy([ChangingScope::class])] class ChangedOrder extends \Illuminate\Database\Eloquent\Model {}
class Action {
    public function excluded() { Order::withoutGlobalScope('paid')->count(); }
    public function allExcluded() { Order::withoutGlobalScopes()->exists(); }
    public function dynamic() { Order::withoutGlobalScope(unknown())->count(); }
    public function overridden() { Override::count(); }
    public function builderOverride() { CustomOrder::count(); }
    public function baseOverride() { BaseOverrideOrder::count(); }
    public function changed() { ChangedOrder::count(); }
}
SOURCE]));
        $this->assertSame(['App\Action::excluded', 'App\Action::allExcluded'], array_map(fn ($edge) => $index->elements[$edge['from']]['name'], $this->edges($index, 'reads')));
        $this->assertSame([], $this->edges($index, 'applies-global-scope'));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'global_scope_analysis'));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'data_analysis'));
    }

    public function test_global_scope_lookalikes_dynamic_registration_and_selector_changes_are_explicit(): void
    {
        $index = new CatalogIndex($this->facts(['app/UnknownScopes.php' => <<<'SOURCE'
namespace App;
class WrongScope { public function apply($builder, $model) { $builder->where('x', true); } }
class ChangedScope implements \Illuminate\Database\Eloquent\Scope { public function apply($builder, $model) { $builder->from('other_table'); } }
#[\Illuminate\Database\Eloquent\Attributes\ScopedBy([WrongScope::class])] class A extends \Illuminate\Database\Eloquent\Model {}
#[\Illuminate\Database\Eloquent\Attributes\ScopedBy([ChangedScope::class])] class B extends \Illuminate\Database\Eloquent\Model {}
class C extends \Illuminate\Database\Eloquent\Model { protected static function booted() { if (unknown()) { static::addGlobalScope('x', fn ($query) => $query->where('x', true)); } } }
class Action { public function run() { A::get(); B::get(); C::get(); } }
SOURCE]));
        $this->assertSame([], $this->edges($index, 'reads'));
        $this->assertSame([], $this->edges($index, 'applies-global-scope'));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'global_scope_analysis'));
    }

    public function test_cached_global_scope_apply_edit_delete_and_inherited_registration_recompose(): void
    {
        $consumer = $this->facts(['app/Consumer.php' => 'namespace App; #[\Illuminate\Database\Eloquent\Attributes\ScopedBy(TenantScope::class)] class BaseOrder extends \Illuminate\Database\Eloquent\Model {} class Order extends BaseOrder {} class Action { public function run() { Order::get(); Order::count(); Order::firstOrCreate(["id" => 1]); } }'])[0];
        $consumer = CatalogFacts::fromArray($consumer->path, $consumer->toArray());
        $scope = $this->facts(['app/Tenant.php' => 'namespace App; class TenantScope implements \Illuminate\Database\Eloquent\Scope { public function apply($builder, $model) { $builder->where("tenant", 1); } }'])[0];
        $scope = CatalogFacts::fromArray($scope->path, $scope->toArray());
        $this->assertCount(3, $this->edges(new CatalogIndex([$consumer, $scope]), 'applies-global-scope'));
        $changed = $this->facts(['app/Tenant.php' => 'namespace App; class TenantScope implements \Illuminate\Database\Eloquent\Scope { public function apply($builder, $model) { $builder->from("other"); } }'])[0];
        $this->assertSame([], $this->edges(new CatalogIndex([$consumer, $changed]), 'reads'));
        $this->assertSame([], $this->edges(new CatalogIndex([$consumer]), 'reads'));
    }

    public function test_global_scope_source_budgets_and_cache_payload_are_checked(): void
    {
        $attribute = implode(', ', array_fill(0, 129, 'TenantScope::class'));
        $limited = $this->facts(['app/Limited.php' => 'namespace App; #[\Illuminate\Database\Eloquent\Attributes\ScopedBy(['.$attribute.'])] class Order extends \Illuminate\Database\Eloquent\Model {}'])[0];
        $this->assertFalse($limited->cacheable());
        $this->assertContains('catalog_limit', array_map(fn ($row) => $row->code, $limited->diagnostics));
        $fact = $this->facts(['app/Scope.php' => 'namespace App; class Order extends \Illuminate\Database\Eloquent\Model { protected static function booted() { static::addGlobalScope("paid", fn ($query) => $query->where("token", "credential-secret")); } }'])[0];
        $raw = $fact->toArray();
        $this->assertStringNotContainsString('credential-secret', json_encode($raw, JSON_THROW_ON_ERROR));
        foreach ($raw['elements'] as &$element) {
            if (($element['metadata']['global_scopes'] ?? []) !== []) {
                $element['metadata']['global_scopes'][0]['payload'] = 'secret';
            }
        }
        unset($element);
        $this->expectException(\InvalidArgumentException::class);
        CatalogFacts::fromArray($fact->path, $raw);
    }

    public function test_assigned_queries_replay_alias_mutations_without_changing_source_ast(): void
    {
        $index = new CatalogIndex($this->facts(['app/Queries.php' => <<<'SOURCE'
namespace App;
use Illuminate\Support\Facades\DB;
class Order extends \Illuminate\Database\Eloquent\Model { public function scopePaid($query) { return $query->where('paid', true); } }
class Action {
    public function model() { $query = Order::paid(); $query->where('id', 1); $query->get(); }
    public function alias() { $query = DB::table('orders'); $alias = $query; $query->from('archives'); $alias->get(); }
    public function reassign() { $query = DB::table('orders'); $alias = $query; $query = DB::table('different'); $alias->get(); }
    public function nested() { $query = Order::query(); $query->paid()->get(); }
    public function harmlessBranch() { $query = Order::query(); if (unknown()) { $x = 1; } $query->get(); }
}
SOURCE]));
        $reads = $this->edges($index, 'reads');
        $tables = [];
        foreach ($reads as $read) {
            $tables[$index->elements[$read['from']]['name']] = $index->elements[$read['to']]['name'];
        }
        $this->assertSame(['App\Action::model' => 'orders', 'App\Action::alias' => 'archives', 'App\Action::reassign' => 'orders', 'App\Action::nested' => 'orders', 'App\Action::harmlessBranch' => 'orders'], $tables);
        $scopeCalls = $this->edges($index, 'invokes-query-scope');
        $this->assertCount(2, $scopeCalls);
        $nested = array_values(array_filter($scopeCalls, fn ($row) => $index->elements[$row['from']]['name'] === 'App\Action::nested'));
        $this->assertCount(1, $nested);
    }

    public function test_changed_branch_reference_and_escaped_query_variables_do_not_keep_stale_tables(): void
    {
        $index = new CatalogIndex($this->facts(['app/QueryNegatives.php' => <<<'SOURCE'
namespace App;
use Illuminate\Support\Facades\DB;
class Action {
    public function branch() { $query = DB::table('orders'); if (unknown()) { $query = DB::table('archives'); } $query->get(); }
    public function clear() { $query = DB::table('orders'); unset($query); $query->get(); }
    public function overwrite() { $query = DB::table('orders'); $query = unknown(); $query->get(); }
    public function escaped() { $query = DB::table('orders'); unknown($query); $query->get(); }
    public function reference() { $query = DB::table('orders'); $alias =& $query; $query->get(); }
    public function loop() { $query = DB::table('orders'); while (unknown()) { $query->get(); $query = DB::table('archives'); } $query->get(); }
    public function propertyEscape() { $query = DB::table('orders'); $this->query = $query; unknown($this); $query->get(); }
    public function arrayEscape() { $query = DB::table('orders'); unknown(['queries' => [$query]]); $query->get(); }
    public function dynamic() { $query = DB::table('orders'); $query->$method(); $query->get(); }
    public function dynamicArgument() { $query = DB::table('orders'); $other->$method([$query]); $query->get(); }
}
SOURCE]));
        $this->assertSame([], $this->edges($index, 'reads'));
    }

    public function test_fluent_query_aliases_share_mutations_but_keep_identity_after_reassignment(): void
    {
        $index = new CatalogIndex($this->facts(['app/FluentAliases.php' => <<<'SOURCE'
namespace App;
use Illuminate\Support\Facades\DB;
class Action {
    public function mutateOriginal() { $query = DB::table('orders'); $alias = $query->where('id', 1); $query->from('archives'); $alias->get(); }
    public function mutateAlias() { $query = DB::table('orders'); $alias = $query->where('id', 1)->orderBy('id'); $alias->from('archives'); $query->get(); }
    public function reassign() { $query = DB::table('orders'); $alias = $query->where('id', 1); $query = DB::table('different'); $alias->get(); }
    public function escaped() { $query = DB::table('orders'); $alias = $query->where('id', 1); unknown($alias); $query->get(); }
    public function terminal() { $query = DB::table('orders'); $result = $query->get(); $result->get(); }
}
SOURCE]));
        $tables = [];
        foreach ($this->edges($index, 'reads') as $read) {
            $tables[$index->elements[$read['from']]['name']][] = $index->elements[$read['to']]['name'];
        }
        $this->assertSame([
            'App\Action::mutateOriginal' => ['archives'],
            'App\Action::mutateAlias' => ['archives'],
            'App\Action::reassign' => ['orders'],
            'App\Action::terminal' => ['orders'],
        ], $tables);
    }

    public function test_query_bindings_do_not_cross_method_or_callback_scopes(): void
    {
        $index = new CatalogIndex($this->facts(['app/QueryScopes.php' => <<<'SOURCE'
namespace App;
use Illuminate\Support\Facades\DB;
class Action {
    public function first() { $query = DB::table('orders'); $callback = function () { $query->get(); }; }
    public function second() { $query->get(); }
    public function callback() { $callback = function () { $query = DB::table('inside'); $query->get(); }; }
}
SOURCE]));
        $reads = $this->edges($index, 'reads');
        $this->assertCount(1, $reads);
        $this->assertSame('closure', $index->elements[$reads[0]['from']]['kind']);
        $this->assertSame('inside', $index->elements[$reads[0]['to']]['name']);
    }

    public function test_assigned_query_prefix_limits_are_explicit_and_not_cacheable(): void
    {
        $calls = str_repeat('$query->where("id", 1); ', 70);
        $fact = $this->facts(['app/DeepQueries.php' => 'namespace App; class Action { public function run() { $query = \Illuminate\Support\Facades\DB::table("orders"); '.$calls.'$query->get(); } }'])[0];
        $this->assertFalse($fact->cacheable());
        $this->assertContains('catalog_limit', array_map(fn ($row) => $row->code, $fact->diagnostics));
        $this->assertSame([], $this->edges(new CatalogIndex([$fact]), 'reads'));
    }

    public function test_cached_assigned_query_retains_only_sanitized_selectors_and_tracks_model_edit(): void
    {
        $consumer = $this->facts(['app/Consumer.php' => 'namespace App; class Action { public function run() { $query = Order::where("token", "credential-secret"); $query->get(); } }'])[0];
        $this->assertStringNotContainsString('credential-secret', json_encode($consumer->toArray(), JSON_THROW_ON_ERROR));
        $consumer = CatalogFacts::fromArray($consumer->path, $consumer->toArray());
        foreach (['orders', 'archives'] as $table) {
            $model = $this->facts(['app/Order.php' => 'namespace App; class Order extends \Illuminate\Database\Eloquent\Model { protected $table = "'.$table.'"; }'])[0];
            $index = new CatalogIndex([$consumer, $model]);
            $reads = $this->edges($index, 'reads');
            $this->assertCount(1, $reads);
            $this->assertSame($table, $index->elements[$reads[0]['to']]['name']);
        }
        $this->assertSame([], $this->edges(new CatalogIndex([$consumer]), 'reads'));
    }

    public function test_first_class_function_creation_does_not_execute_or_destroy_query_bindings(): void
    {
        $index = new CatalogIndex($this->facts(['app/CallableQuery.php' => 'namespace App; class Action { public function run() { $query = \Illuminate\Support\Facades\DB::table("orders"); $callback = unknown(...); $query->get(); } }']));
        $this->assertCount(1, $this->edges($index, 'reads'));
    }

    public function test_factory_configuration_dynamic_returns_and_required_arguments_do_not_guess_execution(): void
    {
        $index = new CatalogIndex($this->facts(['app/ConfigureNegative.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model {}
class ConditionalFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; public function configure() { if (unknown()) { return $this->afterCreating(fn () => unknown()); } return $this; } }
class RequiredFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; public function configure($required) { return $this->afterCreating(fn () => unknown()); } }
class OverriddenFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; public function configure() { return $this->afterCreating(fn () => unknown()); } public function afterCreating($callback) {} }
class DynamicFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; public function configure() { return unknown(); } }
class Action { public function run() { ConditionalFactory::new()->create(); RequiredFactory::new()->create(); OverriddenFactory::new()->create(); DynamicFactory::new()->create(); } }
SOURCE]));
        $this->assertSame([], $this->edges($index, 'invokes-factory-callback'));
        $this->assertSame([], $this->edges($index, 'writes'));
        $this->assertSame([], $this->edges($index, 'invokes-factory-configuration'));
        $this->assertNotEmpty(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'database_lifecycle_analysis'));
    }

    public function test_cached_factory_consumer_recomposes_trait_configuration_after_edit_and_delete(): void
    {
        $consumer = $this->facts(['app/Consumer.php' => 'namespace App; class User extends \Illuminate\Database\Eloquent\Model {} class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory { use Setup; protected $model = User::class; } class Action { public function run() { UserFactory::new()->create(); } }'])[0];
        $consumer = CatalogFacts::fromArray($consumer->path, $consumer->toArray());
        foreach (['old_logs', 'new_logs'] as $table) {
            $configuration = $this->facts(['app/Setup.php' => 'namespace App; trait Setup { public function configure() { return $this->afterCreating(fn () => \Illuminate\Support\Facades\DB::table("'.$table.'")->insert([])); } }'])[0];
            $index = new CatalogIndex([$consumer, $configuration]);
            $callbacks = $this->edges($index, 'invokes-factory-callback');
            $this->assertCount(1, $callbacks);
            $tableId = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'table' && $row['name'] === $table))[0]['id'];
            $this->assertSame('found', (new GraphQuery($index))->query($index->names[strtolower('App\Action::run')][0], 'path', $tableId, 8)['status']);
        }
        $this->assertSame([], $this->edges(new CatalogIndex([$consumer]), 'invokes-factory-callback'));
    }

    public function test_source_factory_states_replay_nested_registration_clearing_and_count_in_order(): void
    {
        $index = new CatalogIndex($this->facts(['app/SourceStates.php' => <<<'SOURCE'
namespace App;
use Illuminate\Support\Facades\DB;
class User extends \Illuminate\Database\Eloquent\Model {}
trait States {
    public function suspended() { return $this->state(fn () => DB::table('state_reads')->get())->afterCreating(fn () => DB::table('state_logs')->insert([])); }
    public function clearCallbacks() { return $this->withoutAfterCreating(); }
    public function emptyModels() { return $this->count(0); }
}
class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory { use States; protected $model = User::class; public function configure() { return $this->suspended(); } }
class Action {
    public function create() { UserFactory::new()->suspended()->clearCallbacks()->suspended()->create(); }
    public function clear() { UserFactory::new()->suspended()->clearCallbacks()->create(); }
    public function make() { UserFactory::new()->suspended()->make(); }
    public function empty() { UserFactory::new()->emptyModels()->create(); }
    public function reset() { UserFactory::new()->emptyModels()->count(null)->create(); }
}
SOURCE]));
        $query = new GraphQuery($index);
        $tableId = fn ($name) => array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'table' && $row['name'] === $name))[0]['id'];
        $methodId = fn ($name) => $index->names[strtolower('App\Action::'.$name)][0];
        $this->assertSame('found', $query->query($methodId('create'), 'path', $tableId('state_logs'), 8)['status']);
        $createdCallbacks = array_values(array_filter($this->edges($index, 'invokes-factory-callback'), fn ($row) => $row['from'] === $methodId('create') && $row['metadata']['execution_stage'] === 'factory-aftercreating'));
        $this->assertCount(1, $createdCallbacks);
        foreach (['clear', 'make', 'empty'] as $method) {
            $this->assertSame('no_path_in_analyzed_graph', $query->query($methodId($method), 'path', $tableId('state_logs'), 8)['status']);
        }
        $this->assertSame('found', $query->query($methodId('make'), 'path', $tableId('state_reads'), 8)['status']);
        $this->assertSame('found', $query->query($methodId('reset'), 'path', $tableId('state_logs'), 8)['status']);
        $this->assertNotEmpty($this->edges($index, 'invokes-factory-state'));
    }

    public function test_recursive_inaccessible_and_dynamic_factory_state_methods_are_explicitly_unresolved(): void
    {
        $index = new CatalogIndex($this->facts(['app/StateNegative.php' => <<<'SOURCE'
namespace App;
class User extends \Illuminate\Database\Eloquent\Model {}
class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory {
    protected $model = User::class;
    public function recursive() { return $this->recursive(); }
    protected function internal() { return $this->afterCreating(fn () => unknown()); }
    public function dynamic() { return unknown(); }
    public function parameter($required) { return $this->afterCreating(fn () => unknown()); }
}
class Action { public function run() { UserFactory::new()->recursive()->create(); UserFactory::new()->internal()->create(); UserFactory::new()->dynamic()->create(); UserFactory::new()->parameter()->create(); } }
SOURCE]));
        $this->assertSame([], $this->edges($index, 'invokes-factory-callback'));
        $this->assertSame([], $this->edges($index, 'writes'));
        $messages = array_column(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'database_lifecycle_analysis'), 'message');
        $this->assertStringContainsString('recursive method cycle', implode(' ', $messages));
    }

    public function test_factory_source_state_pipeline_depth_limit_is_explicit(): void
    {
        $methods = '';
        for ($i = 0; $i < 34; $i++) {
            $methods .= 'public function state'.$i.'() { return $this->state'.($i + 1).'(); } ';
        }
        $methods .= 'public function state34() { return $this->afterCreating(fn () => unknown()); }';
        $index = new CatalogIndex($this->facts(['app/DeepFactory.php' => 'namespace App; class User extends \Illuminate\Database\Eloquent\Model {} class UserFactory extends \Illuminate\Database\Eloquent\Factories\Factory { protected $model = User::class; '.$methods.' } class Action { public function run() { UserFactory::new()->state0()->create(); } }']));
        $this->assertSame([], $this->edges($index, 'invokes-factory-callback'));
        $this->assertSame([], $this->edges($index, 'writes'));
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
    }

    /** @param array<string, string> $sources
     * @return list<CatalogFacts>
     */
    private function facts(array $sources): array
    {
        $files = [];
        foreach ($sources as $path => $code) {
            $files[] = new FileContext($path, '<?php '.$code);
        }

        return (new ProjectGraphBuilder(catalog: true))->build($files)->catalogFacts;
    }

    /** @return list<array<string, mixed>> */
    private function edges(CatalogIndex $index, string $kind): array
    {
        return array_values(array_filter($index->relations, fn ($row) => $row['kind'] === $kind));
    }
}
