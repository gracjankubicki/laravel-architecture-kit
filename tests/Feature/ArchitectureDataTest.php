<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\DataSql;
use GracjanKubicki\ArchitectureKit\Impact\ExecutionSources;
use GracjanKubicki\ArchitectureKit\Impact\ImpactSchema;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Impact;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class ArchitectureDataTest extends TestCase
{
    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, '<?php '.$source);
        clearstatcache();
    }

    private function fixture(string $body): void
    {
        $this->write('config/architectures.php', 'return ["enabled" => ["actions"]];');
        $this->write('app/Order.php', 'namespace App; class Order extends \Illuminate\Database\Eloquent\Model { public function products() { return $this->belongsToMany(Product::class); } public function lines() { return $this->hasMany(Line::class); } public function scopeActive(\Illuminate\Database\Eloquent\Builder $query) { return $query->join("customers", "orders.customer_id", "=", "customers.id"); } }');
        $this->write('app/Product.php', 'namespace App; class Product extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Line.php', 'namespace App; class Line extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Action.php', 'namespace App; use Illuminate\Support\Facades\DB; class Action { public function run(Order $order) { '.$body.' } public function unused() {} }');
    }

    private function inspectImpact(string $subject = 'Action::run', int $limit = 100, int $depth = 16, array $exclude = [], ?ProjectGraphCache $cache = null): array
    {
        $result = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope, $cache))->inspect($subject, $exclude, $limit, $depth);
        $this->assertTrue($result['ok'], json_encode($result));

        return $result;
    }

    private function has(array $rows, string $table, string $kind): bool
    {
        return array_filter($rows, fn ($r) => $r['table'] === $table && $r['kind'] === $kind) !== [];
    }

    public function test_model_reads_writes_quiet_mass_and_both_branches(): void
    {
        $this->fixture('Order::where("id", 1)->update(["x" => 2]); Order::createQuietly([]); Order::firstOrCreate([]); $order->delete(); $order->forceDeleteQuietly();');
        $data = $this->inspectImpact()['data'];
        $this->assertTrue($this->has($data['outgoing'], 'orders', 'write'));
        $this->assertTrue($this->has($data['outgoing'], 'orders', 'read'));
        $this->assertContains('createquietly', array_column($data['outgoing'], 'operation'));
        $this->assertContains('update', array_column($data['outgoing'], 'operation'));
        $this->assertContains('forcedeletequietly', array_column($data['outgoing'], 'operation'));
        $this->assertSame([], $this->inspectImpact('Action::unused')['data']['outgoing']);
    }

    public function test_preparing_queries_relations_and_callbacks_has_no_effect(): void
    {
        $this->fixture('Order::where("id", 1)->with("lines"); $order->products(); $query = DB::table("orders"); $callback = fn () => Order::create([]); new Order;');
        $this->assertSame([], $this->inspectImpact()['data']['outgoing']);
    }

    public function test_eager_lazy_and_pivot_writes(): void
    {
        $this->fixture('Order::with("lines")->get(); $items = $order->products; $order->products()->sync([1]);');
        $data = $this->inspectImpact()['data'];
        foreach ([['orders', 'read'], ['lines', 'read'], ['products', 'read'], ['order_product', 'read'], ['order_product', 'write']] as [$table, $kind]) {
            $this->assertTrue($this->has($data['outgoing'], $table, $kind), json_encode($data));
        }
        $lazy = array_values(array_filter($data['outgoing'], fn ($r) => $r['operation'] === 'lazy-load'));
        $this->assertStringContainsString('not already loaded', implode(' ', $lazy[0]['conditions']));
        $pivot = array_values(array_filter($data['outgoing'], fn ($r) => $r['operation'] === 'sync'));
        $this->assertSame(['order_product'], array_values(array_unique(array_column($pivot, 'table'))));
    }

    public function test_scope_and_custom_builder_keep_model_context_and_source(): void
    {
        $this->fixture('Order::active()->get(); Order::query()->recent()->get();');
        $this->write('app/Order.php', 'namespace App; class Order extends \Illuminate\Database\Eloquent\Model { public function scopeActive(\Illuminate\Database\Eloquent\Builder $q) { return $q->join("customers", "x", "=", "y"); } public function newEloquentBuilder($query) { return new OrderBuilder($query); } }');
        $this->write('app/OrderBuilder.php', 'namespace App; class OrderBuilder extends \Illuminate\Database\Eloquent\Builder { public function recent() { return $this->join("payments", "x", "=", "y"); } }');
        $data = $this->inspectImpact()['data'];
        foreach (['orders', 'customers', 'payments'] as $table) {
            $this->assertTrue($this->has($data['outgoing'], $table, 'read'), json_encode($data));
        }
    }

    public function test_custom_method_names_do_not_imply_framework_semantics(): void
    {
        $this->fixture('Order::get(); Order::create([]);');
        $this->write('app/Order.php', 'namespace App; class Order extends \Illuminate\Database\Eloquent\Model { public static function get() { return []; } public static function create($data) { return new self; } }');
        $this->assertSame([], $this->inspectImpact()['data']['outgoing']);
    }

    public function test_join_subquery_aliases_and_connection_identities(): void
    {
        $this->fixture('DB::connection("crm")->table("orders as o")->leftJoin("customers as c", "o.id", "=", "c.id")->whereIn("id", DB::table("payments")->select("id"))->get(); DB::connection("archive")->table("orders")->delete(); DB::table("public.users")->get();');
        $rows = $this->inspectImpact()['data']['outgoing'];
        foreach (['orders', 'customers', 'payments', 'public.users'] as $table) {
            $this->assertContains($table, array_column($rows, 'table'));
        }
        $this->assertContains(['kind' => 'named', 'name' => 'crm'], array_column($rows, 'connection'));
        $this->assertContains(['kind' => 'named', 'name' => 'archive'], array_column($rows, 'connection'));
        $this->assertContains(['kind' => 'default', 'name' => null], array_column($rows, 'connection'));
    }

    public function test_closure_subqueries_execute_only_with_outer_terminal(): void
    {
        $this->fixture('DB::table("orders")->whereExists(function ($q) { $q->select("id")->from("payments"); })->get();');
        $data = $this->inspectImpact()['data'];
        $this->assertTrue($this->has($data['outgoing'], 'payments', 'read'), json_encode($data));
    }

    public function test_raw_sql_literals_comments_strings_ctes_and_unknown(): void
    {
        $this->fixture('DB::select("WITH recent AS (SELECT * FROM orders) SELECT * FROM recent JOIN customers c ON c.id = recent.id"); DB::statement("UPDATE orders SET state = ?"); DB::select($sql); DB::select("CALL unknown_proc()");');
        $data = $this->inspectImpact()['data'];
        $this->assertTrue($this->has($data['outgoing'], 'orders', 'read'));
        $this->assertTrue($this->has($data['outgoing'], 'customers', 'read'));
        $this->assertTrue($this->has($data['outgoing'], 'orders', 'write'));
        $this->assertNotContains('recent', array_column($data['outgoing'], 'table'));
        $this->assertSame('incomplete', $data['status']);
        $sql = (new DataSql)->inspect("SELECT 'FROM secrets' FROM orders /* JOIN private */");
        $this->assertSame([['table' => 'orders', 'kind' => 'read']], $sql['effects']);
    }

    public function test_partial_known_tables_and_dynamic_connection_remain_visible(): void
    {
        $this->fixture('DB::connection($connection)->table("orders")->join($table, "x", "=", "y")->get();');
        $data = $this->inspectImpact()['data'];
        $this->assertTrue($this->has($data['outgoing'], 'orders', 'read'));
        $this->assertSame(['kind' => 'dynamic', 'name' => null], $data['outgoing'][0]['connection']);
        $this->assertNotEmpty($data['unresolved']);
        $this->assertSame('incomplete', $data['status']);
    }

    public function test_both_directions_without_entrypoint_and_no_shared_table_edges(): void
    {
        $this->fixture('Calculator::price(); Order::create([]);');
        $this->write('app/Calculator.php', 'namespace App; class Calculator { public static function price() { return 1; } }');
        $this->write('app/Unrelated.php', 'namespace App; class Unrelated { public function run() { Order::get(); } }');
        $data = $this->inspectImpact('Calculator::price')['data'];
        $this->assertSame([], $data['outgoing']);
        $this->assertTrue($this->has($data['consumers'], 'orders', 'write'));
        $this->assertSame('App\Action::run', $data['consumers'][0]['root']);
        $this->assertSame('App\Calculator::price', $data['consumers'][0]['subject_via'][0]['to']);
        $this->assertNotContains('App\Unrelated::run', array_column($data['consumers'], 'root'));
        foreach (['Action', 'app/Action.php'] as $subject) {
            $this->assertTrue($this->has($this->inspectImpact($subject)['data']['outgoing'], 'orders', 'write'));
        }
    }

    public function test_anonymous_migration_file_up_down_connections_and_no_execution(): void
    {
        $this->fixture('');
        $path = 'database/migrations/2026_create_orders.php';
        $this->write($path, 'use Illuminate\Support\Facades\Schema; return new class extends \Illuminate\Database\Migrations\Migration { public function up() { Schema::connection("crm")->create("orders", function ($table) { $table->id(); }); } public function down() { Schema::connection("crm")->dropIfExists("orders"); } }; throw new \RuntimeException("must not execute");');
        $rows = $this->inspectImpact($path)['data']['outgoing'];
        $this->assertTrue($this->has($rows, 'orders', 'schema'), json_encode($rows));
        $this->assertSame(['up', 'down'], array_column($rows, 'migration'));
        $this->assertSame(['kind' => 'named', 'name' => 'crm'], $rows[0]['connection']);
        $this->assertSame([], $this->inspectImpact('Action')['data']['outgoing']);
    }

    public function test_limit_zero_retains_totals_and_depth_limit_is_honest(): void
    {
        $this->fixture('Order::create([]);');
        $data = $this->inspectImpact(limit: 0)['data'];
        $this->assertSame([], $data['outgoing']);
        $this->assertGreaterThan(0, $data['totals']['outgoing']);
        $this->assertSame('limit', $data['status']);
        $this->write('app/Root.php', 'namespace App; class Root { public function run() { (new Middle)->run(); } }');
        $this->write('app/Middle.php', 'namespace App; class Middle { public function run() { (new Action)->run(new Order); } }');
        $this->assertSame('limit', $this->inspectImpact('Root::run', depth: 1)['data']['status']);
    }

    public function test_model_and_migration_changes_refresh_warm_graph_snapshot(): void
    {
        $this->fixture('Order::create([]);');
        $cache = new ProjectGraphCache(new Filesystem, $this->tempPath.'/cache');
        $before = $this->inspectImpact(cache: $cache);
        $this->write('app/Order.php', 'namespace App; class Order extends \Illuminate\Database\Eloquent\Model { protected $table = "sales_orders"; protected $connection = "crm"; }');
        $after = $this->inspectImpact(cache: $cache);
        $this->assertTrue($this->has($after['data']['outgoing'], 'sales_orders', 'write'));
        $this->assertNotSame($before['snapshot'], $after['snapshot']);
        $this->write('database/migrations/new.php', 'use Illuminate\Support\Facades\Schema; return new class extends \Illuminate\Database\Migrations\Migration { public function up() { Schema::create("new", fn ($b) => $b->id()); } };');
        $last = $this->inspectImpact(cache: $cache);
        $this->assertNotSame($after['snapshot'], $last['snapshot']);
        $this->assertTrue($last['data']['fresh']);
    }

    public function test_exclusions_vendor_and_symlinks_are_not_analyzed(): void
    {
        $this->fixture('Order::create([]);');
        $this->write('vendor/Evil.php', 'throw new \RuntimeException("no");');
        $this->write('app/Excluded.php', 'namespace App; class Excluded { public function run() { \Illuminate\Support\Facades\DB::table("secret")->get(); } }');
        $data = $this->inspectImpact(exclude: ['app/Excluded.php'])['data'];
        $this->assertNotContains('secret', array_column($data['outgoing'], 'table'));
        symlink($this->tempPath.'/vendor', $this->tempPath.'/database');
        $result = $this->inspectImpact();
        $this->assertNotContains('secret', array_column($result['data']['outgoing'], 'table'));
        $this->assertSame('E_IMPACT_OUT_OF_SCOPE', (new ArchitectureImpact(new Filesystem, $this->tempPath))->inspect('vendor/Evil.php')['m']);
    }

    public function test_cli_mcp_schema_and_change_modes_share_data_contract(): void
    {
        $this->fixture('Order::create([]);');
        $this->assertSame(0, Artisan::call('architecture-kit:impact', ['subject' => 'Action::run', '--agent' => true, '--limit' => 100, '--depth' => 16]));
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($this->has($cli['data']['outgoing'], 'orders', 'write'));
        ArchitectureKitServer::tool(Impact::class, ['subject' => 'Action::run', 'limit' => 100, 'depth' => 16])->assertOk()->assertSee('"data"')->assertSee('orders');
        $this->assertArrayHasKey('data', ImpactSchema::get()['oneOf'][0]['properties']);
        foreach (['signature', 'delete'] as $mode) {
            $result = (new ArchitectureImpact(new Filesystem, $this->tempPath))->inspect('Action::run', change: $mode);
            $this->assertTrue($result['ok']);
            $this->assertArrayHasKey('data', $result);
            $this->assertFalse($result[$mode]['safe_to_change']);
        }
    }

    public function test_model_selector_and_scope_selector_show_consumers(): void
    {
        $this->fixture('Order::active()->get(); Order::create([]);');
        $this->assertTrue($this->has($this->inspectImpact('Order')['data']['consumers'], 'orders', 'write'));
        $scope = $this->inspectImpact('Order::scopeActive')['data'];
        $this->assertTrue($this->has($scope['consumers'], 'customers', 'read'), json_encode($scope));
    }

    public function test_attribute_scope_and_dynamic_model_getters_are_honest(): void
    {
        $this->fixture('Order::active()->get();');
        $this->write('app/Order.php', 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { #[\\Illuminate\\Database\\Eloquent\\Attributes\\Scope] protected function active(\\Illuminate\\Database\\Eloquent\\Builder $q) { return $q->join("customers", "x", "=", "y"); } public function getTable() { return getenv("TABLE"); } public function getConnectionName() { return getenv("CONNECTION"); } }');
        $data = $this->inspectImpact()['data'];
        $this->assertNotContains('orders', array_column($data['outgoing'], 'table'));
        $this->assertTrue($this->has($data['outgoing'], 'customers', 'read'));
        $this->assertNotEmpty($data['unresolved']);
    }

    public function test_schema_rename_named_migration_and_declared_connection(): void
    {
        $this->fixture('');
        $path = 'database/migrations/rename.php';
        $this->write($path, 'use Illuminate\\Support\\Facades\\Schema; class RenameOrders extends \\Illuminate\\Database\\Migrations\\Migration { protected $connection = "archive"; public function up() { Schema::rename("orders", "old_orders"); } public function down() { Schema::rename("old_orders", "orders"); } }');
        $data = $this->inspectImpact($path)['data'];
        $this->assertCount(4, $data['outgoing']);
        $this->assertSame(['up', 'up', 'down', 'down'], array_column($data['outgoing'], 'migration'));
        $this->assertSame(['kind' => 'named', 'name' => 'archive'], $data['outgoing'][0]['connection']);
    }

    public function test_ordinary_results_and_collection_methods_are_not_builders(): void
    {
        $this->fixture('$all = Order::get(); $all->delete(); $saved = $order->save(); $saved->delete();');
        $rows = $this->inspectImpact()['data']['outgoing'];
        $this->assertSame(['get', 'save'], array_column($rows, 'operation'));
    }

    public function test_callbacks_have_effects_only_on_recognized_paths(): void
    {
        $this->fixture('Order::withoutEvents(function () { Order::create([]); }); $unused = fn () => \\Illuminate\\Support\\Facades\\DB::table("secrets")->get();');
        $data = $this->inspectImpact()['data'];
        $this->assertTrue($this->has($data['outgoing'], 'orders', 'write'), json_encode($data));
        $this->assertNotContains('secrets', array_column($data['outgoing'], 'table'));
    }

    public function test_model_default_eager_relations_and_constraints(): void
    {
        $this->fixture('Order::get(); Order::with(["lines" => fn ($q) => $q->join("taxes", "x", "=", "y")])->get();');
        $this->write('app/Order.php', 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { protected $with = ["lines"]; public function lines() { return $this->hasMany(Line::class); } }');
        $rows = $this->inspectImpact()['data']['outgoing'];
        $this->assertTrue($this->has($rows, 'lines', 'read'));
        $this->assertTrue($this->has($rows, 'taxes', 'read'), json_encode($rows));
    }

    public function test_sql_subquery_insert_select_and_multiple_statements_are_partial(): void
    {
        $parser = new DataSql;
        $sql = $parser->inspect('INSERT INTO archive.orders SELECT * FROM orders o WHERE EXISTS (SELECT 1 FROM payments p WHERE p.id = o.id)');
        $this->assertContains(['table' => 'archive.orders', 'kind' => 'write'], $sql['effects']);
        $this->assertContains(['table' => 'orders', 'kind' => 'read'], $sql['effects']);
        $this->assertContains(['table' => 'payments', 'kind' => 'read'], $sql['effects']);
        $this->assertTrue($parser->inspect('SELECT * FROM orders; CALL custom()')['unknown']);
    }

    public function test_mutable_builder_aliases_and_model_setters_keep_identity(): void
    {
        $this->fixture('$q = DB::table("orders"); $alias = $q; $q->from("sales"); $alias->get(); $order->setConnection("archive"); $order->setTable("old_orders"); $order->save();');
        $rows = $this->inspectImpact()['data']['outgoing'];
        $this->assertTrue($this->has($rows, 'sales', 'read'));
        $this->assertNotContains('orders', array_column($rows, 'table'));
        $write = array_values(array_filter($rows, fn ($r) => $r['kind'] === 'write'));
        $this->assertSame('old_orders', $write[0]['table']);
        $this->assertSame(['kind' => 'named', 'name' => 'archive'], $write[0]['connection']);
    }

    public function test_read_guard_rejects_changed_and_symlinked_inputs(): void
    {
        $this->fixture('Order::get();');
        $sources = new ExecutionSources(new Filesystem, $this->tempPath);
        $sources->discover(new ProjectGraphSnapshot([], []), []);
        $this->assertNotNull($sources->read('app/Order.php'));
        $this->write('app/Order.php', 'namespace App; class Changed {}');
        $this->assertNull($sources->read('app/Order.php'));
        $this->assertFalse($sources->fresh());
        unlink($this->tempPath.'/app/Order.php');
        symlink($this->tempPath.'/composer.json', $this->tempPath.'/app/Order.php');
        $this->assertNull($sources->read('app/Order.php'));
    }

    public function test_relation_update_reads_pivot_but_writes_related_table(): void
    {
        $this->fixture('$order->products()->update(["name" => "new"]);');
        $rows = $this->inspectImpact()['data']['outgoing'];
        $this->assertTrue($this->has($rows, 'products', 'write'));
        $this->assertTrue($this->has($rows, 'order_product', 'read'));
        $this->assertFalse($this->has($rows, 'order_product', 'write'));
    }

    public function test_cte_column_aliases_and_nested_select_keep_physical_tables_only(): void
    {
        $sql = (new DataSql)->inspect('WITH recent(id) AS (SELECT id FROM orders), other AS (SELECT id FROM customers) SELECT * FROM recent JOIN other ON recent.id = other.id');
        $this->assertSame(['orders', 'customers'], array_column($sql['effects'], 'table'));
        $this->fixture('DB::connection("crm")->table("orders")->select(["total" => DB::connection("archive")->table("payments")->select("amount")])->get();');
        $rows = $this->inspectImpact()['data']['outgoing'];
        $payments = array_values(array_filter($rows, fn ($r) => $r['table'] === 'payments'));
        $this->assertSame(['kind' => 'named', 'name' => 'archive'], $payments[0]['connection']);
    }

    public function test_builder_attribute_and_query_factory_return_descriptor(): void
    {
        $this->fixture('Order::recent()->get(); (new QueryFactory)->orders()->get();');
        $this->write('app/Order.php', 'namespace App; #[\\Illuminate\\Database\\Eloquent\\Attributes\\UseEloquentBuilder(OrderBuilder::class)] class Order extends \\Illuminate\\Database\\Eloquent\\Model {}');
        $this->write('app/OrderBuilder.php', 'namespace App; class OrderBuilder extends \\Illuminate\\Database\\Eloquent\\Builder { public function recent() { return $this->join("customers", "x", "=", "y"); } }');
        $this->write('app/QueryFactory.php', 'namespace App; class QueryFactory { public function orders() { return \\Illuminate\\Support\\Facades\\DB::table("factory_orders"); } }');
        $rows = $this->inspectImpact()['data']['outgoing'];
        $this->assertTrue($this->has($rows, 'customers', 'read'));
        $this->assertTrue($this->has($rows, 'factory_orders', 'read'));
    }

    public function test_dynamic_pivot_override_does_not_guess_conventional_name(): void
    {
        $this->fixture('$order->products()->get();');
        $this->write('app/Order.php', 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { public function products() { return $this->belongsToMany(Product::class); } public function joiningTable($related, $instance = null) { return getenv("PIVOT"); } }');
        $data = $this->inspectImpact()['data'];
        $this->assertTrue($this->has($data['outgoing'], 'products', 'read'));
        $this->assertNotContains('order_product', array_column($data['outgoing'], 'table'));
        $this->assertNotEmpty($data['unresolved']);
    }

    public function test_explicit_default_and_dynamic_connections_are_distinct(): void
    {
        $this->fixture('DB::connection()->table("a")->get(); DB::connection(null)->table("b")->get(); DB::connection($unknown)->table("c")->get();');
        $rows = $this->inspectImpact()['data']['outgoing'];
        $this->assertSame(['default', 'default', 'dynamic'], array_column(array_column($rows, 'connection'), 'kind'));
    }

    public function test_composer_and_include_never_read_env_or_non_php_sources(): void
    {
        $this->fixture('include base_path(".env");');
        $this->write('.env', 'SECRET=never-read');
        $this->write('private.txt', 'not PHP');
        $this->write('composer.json', '');
        (new Filesystem)->put($this->tempPath.'/composer.json', json_encode(['autoload' => ['files' => ['.env', 'private.txt']]]));
        $files = new class extends Filesystem
        {
            public array $reads = [];

            public function get($path, $lock = false)
            {
                $this->reads[] = $path;

                return parent::get($path, $lock);
            }
        };
        $result = (new ArchitectureImpact($files, $this->tempPath))->inspect('Action::run');
        $this->assertTrue($result['ok']);
        $this->assertNotContains($this->tempPath.'/.env', $files->reads);
        $this->assertNotContains($this->tempPath.'/private.txt', $files->reads);
        $this->assertContains($this->tempPath.'/composer.json', $files->reads);
        $this->assertNotEmpty($result['data']['unresolved']);
    }

    public function test_void_legacy_and_attribute_scopes_preserve_query_mutations(): void
    {
        $this->fixture('Order::active()->get(); Order::recent()->get();');
        $this->write('app/Order.php', 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { public function scopeActive(\\Illuminate\\Database\\Eloquent\\Builder $q): void { $q->join("customers", "x", "=", "y"); } #[\\Illuminate\\Database\\Eloquent\\Attributes\\Scope] protected function recent(\\Illuminate\\Database\\Eloquent\\Builder $q) { $q->join("payments", "x", "=", "y"); return null; } }');
        $rows = $this->inspectImpact()['data']['outgoing'];
        $this->assertTrue($this->has($rows, 'customers', 'read'), json_encode($rows));
        $this->assertTrue($this->has($rows, 'payments', 'read'));
        $this->assertTrue($this->has($this->inspectImpact('Order::scopeActive')['data']['consumers'], 'customers', 'read'));
        $this->assertTrue($this->has($this->inspectImpact('Order::recent')['data']['consumers'], 'payments', 'read'));
    }

    public function test_associate_and_dissociate_save_the_declaring_model(): void
    {
        $this->fixture('$order->customer()->associate(new Customer)->save(); $order->customer()->dissociate()->save();');
        $this->write('app/Customer.php', 'namespace App; class Customer extends \\Illuminate\\Database\\Eloquent\\Model {}');
        $this->write('app/Order.php', 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { public function customer() { return $this->belongsTo(Customer::class); } }');
        $rows = $this->inspectImpact()['data']['outgoing'];
        $this->assertTrue($this->has($rows, 'orders', 'write'));
        $this->assertFalse($this->has($rows, 'customers', 'write'));
    }

    public function test_load_accepts_arrays_names_variadic_constraints_and_dynamic_unknown(): void
    {
        foreach (['$order->load(["lines"]);', '$order->loadMissing(["lines"]);', '$order->loadCount(["lines"]);', '$order->load(relations: ["lines"]);', '$order->load("lines", "products");', '$order->load(["lines" => fn ($q) => $q->join("taxes", "x", "=", "y")]);'] as $body) {
            $this->fixture($body);
            $rows = $this->inspectImpact()['data']['outgoing'];
            $this->assertTrue($this->has($rows, 'lines', 'read'), $body.' '.json_encode($rows));
        }
        $this->assertTrue($this->has($rows, 'taxes', 'read'));
        $this->fixture('$order->load($unknown);');
        $data = $this->inspectImpact()['data'];
        $this->assertNotEmpty($data['unresolved']);
        $this->assertSame('incomplete', $data['status']);
    }

    public function test_aggregate_does_not_hydrate_eager_relations_but_reads_relation_subqueries(): void
    {
        foreach (['Order::with("lines")->count();', 'Order::with("lines")->exists();', 'Order::with("lines")->sum("total");'] as $body) {
            $this->fixture($body);
            $this->assertFalse($this->has($this->inspectImpact()['data']['outgoing'], 'lines', 'read'), $body);
        }
        $this->fixture('Order::exists();');
        $this->write('app/Order.php', 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { protected $with = ["lines"]; public function lines() { return $this->hasMany(Line::class); } }');
        $this->assertFalse($this->has($this->inspectImpact()['data']['outgoing'], 'lines', 'read'));
        foreach (['Order::withCount("lines")->get();', 'Order::whereHas("lines")->count();', 'Order::whereHas("lines", fn ($q) => $q->join("taxes", "x", "=", "y"))->count();'] as $body) {
            $this->fixture($body);
            $this->assertTrue($this->has($this->inspectImpact()['data']['outgoing'], 'lines', 'read'), $body);
        }
    }

    public function test_sql_expression_from_and_unsupported_tail_are_not_guessed(): void
    {
        $parser = new DataSql;
        $sql = $parser->inspect('SELECT EXTRACT(YEAR FROM created_at) FROM orders');
        $this->assertSame([['table' => 'orders', 'kind' => 'read']], $sql['effects']);
        $sql = $parser->inspect('SELECT * FROM orders WHERE id = $1');
        $this->assertSame([['table' => 'orders', 'kind' => 'read']], $sql['effects']);
        $this->assertTrue($sql['unknown']);
    }

    public function test_nested_custom_calls_keep_continuous_source_paths(): void
    {
        $this->fixture('Order::active()->get(); Order::sourceRead()->get();');
        $this->write('app/Order.php', 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { public function scopeActive($q) { return Helper::query(); } public function scopeSourceRead($q) { return Helper::read(); } }');
        $this->write('app/Helper.php', 'namespace App; class Helper { public static function query() { return \\Illuminate\\Support\\Facades\\DB::table("customers"); } public static function read() { \\Illuminate\\Support\\Facades\\DB::table("audit")->get(); return \\Illuminate\\Support\\Facades\\DB::table("payments"); } }');
        $rows = $this->inspectImpact()['data']['outgoing'];
        $this->assertTrue($this->has($rows, 'customers', 'read'));
        $this->assertTrue($this->has($rows, 'audit', 'read'));
        foreach ($rows as $row) {
            foreach ([$row['via'], $row['preparation_via'], ...$row['preparation_paths']] as $path) {
                for ($i = 1; $i < count($path); $i++) {
                    $this->assertSame($path[$i - 1]['to'], $path[$i]['from'], json_encode($path));
                }
            }
        }
        $customer = array_values(array_filter($rows, fn ($r) => $r['table'] === 'customers'))[0];
        $this->assertSame(['App\Action::run', 'App\Order::scopeActive'], array_column($customer['preparation_via'], 'from'));
        $this->assertSame(['app/Action.php', 'app/Order.php'], array_column($customer['preparation_via'], 'path'));
    }

    public function test_inline_scopes_do_not_invoke_unused_callbacks_and_callback_paths_are_continuous(): void
    {
        $this->fixture('Order::active()->get();');
        $this->write('app/Order.php', 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { public function scopeActive($q) { $unused = fn () => \\Illuminate\\Support\\Facades\\DB::table("secrets")->get(); return $q->whereExists(function ($sub) { $sub->from("payments"); }); } }');
        $rows = $this->inspectImpact()['data']['outgoing'];
        $this->assertNotContains('secrets', array_column($rows, 'table'));
        $this->assertTrue($this->has($rows, 'payments', 'read'));
        foreach ($rows as $row) {
            foreach ([$row['via'], $row['preparation_via'], ...$row['preparation_paths']] as $path) {
                for ($i = 1; $i < count($path); $i++) {
                    $this->assertSame($path[$i - 1]['to'], $path[$i]['from']);
                }
            }
        }
    }

    public function test_update_from_reads_source_table_without_expression_from_false_table(): void
    {
        $sql = (new DataSql)->inspect('UPDATE orders SET total = customers.total, year = EXTRACT(YEAR FROM orders.created_at) FROM customers WHERE orders.customer_id = customers.id');
        $this->assertContains(['table' => 'orders', 'kind' => 'write'], $sql['effects']);
        $this->assertContains(['table' => 'customers', 'kind' => 'read'], $sql['effects']);
        $this->assertSame(['orders', 'customers'], array_column($sql['effects'], 'table'));
        $this->assertFalse($sql['unknown']);
    }

    public function test_callback_passed_between_files_keeps_declaration_context_and_call_site(): void
    {
        $this->fixture('Factory::orders(function ($q) { DB::table("audit")->get(); })->get();');
        $this->write('app/Factory.php', 'namespace App; class Factory { public static function orders($constraint) { return \\Illuminate\\Support\\Facades\\DB::table("orders")->where($constraint); } }');
        $rows = $this->inspectImpact()['data']['outgoing'];
        $audit = array_values(array_filter($rows, fn ($r) => $r['table'] === 'audit' && $r['root'] === 'App\Action::run'))[0];
        $this->assertSame('app/Action.php', $audit['path']);
        $this->assertSame('App\Action::run', $audit['from']);
        $this->assertSame('App\Factory::orders', $audit['via'][0]['to']);
        $callback = $audit['via'][1];
        $this->assertSame('App\Factory::orders', $callback['from']);
        $this->assertStringStartsWith('(callback) app/Action.php:', $callback['to']);
        $this->assertSame('app/Factory.php', $callback['path']);
        $this->assertSame($audit['via'][0]['to'], $callback['from']);
    }
}
