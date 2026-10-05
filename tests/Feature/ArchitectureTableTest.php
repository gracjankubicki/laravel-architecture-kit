<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\ImpactSchema;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Impact;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class ArchitectureTableTest extends TestCase
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
        $this->write('app/Order.php', 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model {}');
        $this->write('app/Action.php', 'namespace App; use Illuminate\\Support\\Facades\\DB; class Action { public static function run() { '.$body.' } }');
    }

    private function query(string $table = 'orders', ?string $match = null, ?string $connection = null, ?string $operation = null, int $limit = 100, int $depth = 16, array $exclude = [], ?ProjectGraphCache $cache = null): array
    {
        $result = (new ArchitectureImpact(new Filesystem, $this->tempPath, new AuditScope, $cache))->inspect('', $exclude, $limit, $depth, table: $table, tableMatch: $match, connection: $connection, operation: $operation);
        $this->assertTrue($result['ok'], json_encode($result));

        return $result;
    }

    private function paths(array $report): array
    {
        return array_merge([], ...array_column($report['usages'], 'paths'));
    }

    public function test_exact_contains_qualified_and_literal_names_are_separate(): void
    {
        $this->fixture('DB::table("orders")->get(); DB::table("archived_orders")->get(); DB::table("public.orders")->get(); DB::table("Orders")->get();');
        $this->assertSame(['orders'], array_column($this->query()['table_report']['tables'], 'table'));
        $this->assertSame(['public.orders'], array_column($this->query('public.orders')['table_report']['tables'], 'table'));
        $this->assertCount(3, $this->query('orders', 'contains')['table_report']['tables']);
        $this->assertSame([], $this->query('%', 'contains')['table_report']['tables']);
        $this->assertSame([], $this->query('missing')['table_report']['usages']);
        $this->assertSame('none', $this->query('missing')['table_report']['status']);
    }

    public function test_connections_and_effect_kind_filters_do_not_guess_database_identity(): void
    {
        $this->fixture('DB::table("orders")->get(); DB::connection("crm")->table("orders")->insert([]); DB::connection("default")->table("orders")->get(); DB::connection($name)->table("orders")->get();');
        $this->assertCount(4, $this->query()['table_report']['tables']);
        foreach (['default' => 'default', 'dynamic' => 'dynamic', 'named:crm' => 'named', 'named:default' => 'named'] as $filter => $kind) {
            $rows = $this->query(connection: $filter)['table_report']['usages'];
            $this->assertCount(1, $rows);
            $this->assertSame($kind, $rows[0]['connection']['kind']);
        }
        $writes = $this->query(connection: 'named:crm', operation: 'write')['table_report']['usages'];
        $this->assertCount(1, $writes);
        $this->assertSame('insert', $writes[0]['operation']);
        $this->assertSame([], $this->query(connection: 'named:missing')['table_report']['usages']);
    }

    public function test_preparation_without_terminal_has_no_usage_and_local_method_needs_no_entry(): void
    {
        $this->fixture('Order::where("id", 1); DB::table("orders");');
        $this->assertSame([], $this->query()['table_report']['usages']);
        $this->fixture('Order::createQuietly([]);');
        $row = $this->query()['table_report']['usages'][0];
        $this->assertSame('createquietly', $row['operation']);
        $this->assertSame('local', $row['paths'][0]['entry']['kind']);
        $this->assertSame('App\\Action::run', $row['paths'][0]['entry']['symbol']);
    }

    public function test_migration_up_down_and_schema_read_remain_source_declarations(): void
    {
        $this->fixture('');
        $this->write('database/migrations/create.php', 'use Illuminate\\Support\\Facades\\Schema; return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() { Schema::connection("archive")->create("orders", fn ($t) => $t->id()); } public function down() { Schema::connection("archive")->dropIfExists("orders"); } };');
        $this->write('database/migrations/check.php', 'use Illuminate\\Support\\Facades\\Schema; class Check extends \\Illuminate\\Database\\Migrations\\Migration { public function up() { Schema::hasTable("orders"); } }');
        $report = $this->query(operation: 'schema')['table_report'];
        $this->assertSame(['up', 'down'], array_column($report['usages'], 'migration'));
        $this->assertSame('archive', $report['usages'][0]['connection']['name']);
        $this->assertCount(1, $this->query(operation: 'schema-read')['table_report']['usages']);
        $this->assertSame(['app'], $this->query()['scope']['paths']);
    }

    public function test_callers_and_http_paths_are_continuous_and_do_not_connect_same_table_users(): void
    {
        $this->fixture('DB::table("orders")->insert([]);');
        $this->write('app/Controller.php', 'namespace App; class Controller { public function store() { Action::run(); } }');
        $this->write('app/Other.php', 'namespace App; class Other { public function run() { \\Illuminate\\Support\\Facades\\DB::table("orders")->get(); } }');
        $this->write('routes/web.php', '\\Illuminate\\Support\\Facades\\Route::post("/orders", [App\\Controller::class, "store"])->name("orders.store");');
        $report = $this->query()['table_report'];
        $this->assertCount(2, $report['usages']);
        $action = array_values(array_filter($report['usages'], fn ($r) => $r['from'] === 'App\\Action::run'))[0];
        $http = array_values(array_filter($action['paths'], fn ($p) => $p['entry']['kind'] === 'http'));
        $this->assertCount(1, $http);
        $this->assertSame('orders.store', $http[0]['entry']['route']['name']);
        $this->assertSame(['App\\Controller::store', 'App\\Action::run'], array_column($http[0]['via'], 'to'));
        foreach ($action['paths'] as $path) {
            $this->assertNotContains('App\\Other::run', array_column($path['via'], 'from'));
            $this->assertContinuous($path['via']);
        }
    }

    public function test_route_and_console_callbacks_keep_declaration_sources(): void
    {
        $this->fixture('');
        $this->write('routes/web.php', '\\Illuminate\\Support\\Facades\\Route::get("/orders", fn () => \\Illuminate\\Support\\Facades\\DB::table("orders")->get());');
        $this->write('routes/console.php', '\\Illuminate\\Support\\Facades\\Artisan::command("orders:show", function () { \\Illuminate\\Support\\Facades\\DB::table("orders")->get(); });');
        $report = $this->query()['table_report'];
        $kinds = array_column(array_column($this->paths($report), 'entry'), 'kind');
        $this->assertContains('http', $kinds, json_encode($report));
        $this->assertContains('console', $kinds, json_encode($report));
        foreach ($report['usages'] as $usage) {
            $this->assertContains($usage['path'], ['routes/web.php', 'routes/console.php']);
        }
    }

    public function test_scheduler_artisan_queue_and_events_keep_modes_and_conditions(): void
    {
        $this->fixture('Job::dispatch();');
        $this->write('app/Job.php', 'namespace App; class Job implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Foundation\\Bus\\Dispatchable; public function handle() { \\Illuminate\\Support\\Facades\\DB::table("orders")->insert([]); \\Illuminate\\Support\\Facades\\Event::dispatch(new Done); } }');
        $this->write('app/Done.php', 'namespace App; class Done {}');
        $this->write('app/Listener.php', 'namespace App; class Listener { public function handle(Done $event) { \\Illuminate\\Support\\Facades\\DB::table("orders")->get(); } }');
        $this->write('app/Providers/EventServiceProvider.php', 'namespace App\\Providers; class EventServiceProvider extends \\Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider { protected $listen = [\\App\\Done::class => [\\App\\Listener::class]]; }');
        $this->write('app/Console/Commands/Run.php', 'namespace App\\Console\\Commands; class Run extends \\Illuminate\\Console\\Command { protected $signature = "orders:run"; public function handle() { \\App\\Action::run(); } }');
        $this->write('bootstrap/app.php', 'return \\Illuminate\\Foundation\\Application::configure()->withCommands([App\\Console\\Commands\\Run::class]);');
        $this->write('routes/console.php', '\\Illuminate\\Support\\Facades\\Schedule::command("orders:run")->daily();');
        $report = $this->query()['table_report'];
        $paths = $this->paths($report);
        $this->assertContains('schedule', array_column(array_column($paths, 'entry'), 'kind'), json_encode($report));
        $edges = array_merge([], ...array_column($paths, 'via'));
        $this->assertContains('queue-requested', array_column($edges, 'mode'));
        $this->assertContains('artisan-command', array_column($edges, 'kind'));
        $this->assertContains('event-listener', array_column($edges, 'kind'));
        foreach ($paths as $path) {
            $this->assertContinuous($path['via']);
        }
    }

    public function test_known_effects_coexist_with_global_unknown_without_guessing_matches(): void
    {
        $this->fixture('DB::table("orders")->join($table, "x", "=", "y")->get(); DB::select($sql);');
        $report = $this->query()['table_report'];
        $this->assertSame(['orders'], array_column($report['tables'], 'table'));
        $this->assertNotEmpty($report['unresolved']);
        $this->assertSame('incomplete', $report['status']);
        $absent = $this->query('missing')['table_report'];
        $this->assertSame([], $absent['usages']);
        $this->assertSame('incomplete', $absent['status']);
    }

    public function test_quiet_path_suppresses_model_event_route_but_keeps_local_listener_use(): void
    {
        $this->fixture('Order::createQuietly([]);');
        $this->write('app/Order.php', 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { protected $dispatchesEvents = ["created" => Done::class]; }');
        $this->write('app/Done.php', 'namespace App; class Done {}');
        $this->write('app/Listener.php', 'namespace App; class Listener { public function handle(Done $event) { \\Illuminate\\Support\\Facades\\DB::table("audit")->insert([]); } }');
        $this->write('app/Providers/EventServiceProvider.php', 'namespace App\\Providers; class EventServiceProvider extends \\Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider { protected $listen = [\\App\\Done::class => [\\App\\Listener::class]]; }');
        $this->write('routes/web.php', '\\Illuminate\\Support\\Facades\\Route::post("/orders", [App\\Action::class, "run"]);');
        $quiet = $this->query('audit')['table_report'];
        $this->assertNotEmpty($quiet['usages']);
        $this->assertNotContains('http', array_column(array_column($this->paths($quiet), 'entry'), 'kind'));
        $this->write('app/Action.php', 'namespace App; class Action { public static function run() { Order::create([]); } }');
        $normal = $this->query('audit')['table_report'];
        $this->assertContains('http', array_column(array_column($this->paths($normal), 'entry'), 'kind'), json_encode($normal));
    }

    public function test_custom_query_preparation_and_callback_paths_remain_separate(): void
    {
        $this->fixture('Order::query()->active()->get();');
        $this->write('app/Order.php', 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { public function scopeActive($q) { return Helper::query(); } }');
        $this->write('app/Helper.php', 'namespace App; class Helper { public static function query() { return \\Illuminate\\Support\\Facades\\DB::table("orders")->where(function ($q) { \\Illuminate\\Support\\Facades\\DB::table("audit")->get(); }); } }');
        $orders = $this->query()['table_report']['usages'];
        $this->assertNotEmpty($orders);
        $this->assertNotEmpty($orders[0]['preparation_via']);
        $audit = $this->query('audit')['table_report'];
        $this->assertNotEmpty($audit['usages']);
        foreach ($this->paths($audit) as $path) {
            $this->assertContinuous($path['via']);
        }
        $this->assertSame(['app/Helper.php'], array_values(array_unique(array_column($audit['usages'], 'path'))));
    }

    public function test_table_search_reuses_relations_pivots_join_subquery_and_raw_sql_semantics(): void
    {
        $this->fixture('Order::with("lines")->get(); $order = new Order; $order->products()->sync([1]); DB::table("orders")->join("customers", "x", "=", "y")->whereExists(fn ($q) => $q->from("payments"))->get(); DB::select("SELECT * FROM audit");');
        $this->write('app/Order.php', 'namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { public function lines() { return $this->hasMany(Line::class); } public function products() { return $this->belongsToMany(Product::class); } }');
        $this->write('app/Line.php', 'namespace App; class Line extends \\Illuminate\\Database\\Eloquent\\Model {}');
        $this->write('app/Product.php', 'namespace App; class Product extends \\Illuminate\\Database\\Eloquent\\Model {}');
        foreach (['lines', 'customers', 'payments', 'audit'] as $table) {
            $rows = $this->query($table, operation: 'read')['table_report']['usages'];
            $this->assertNotEmpty($rows, $table);
            $this->assertSame([$table], array_values(array_unique(array_column($rows, 'table'))));
        }
        $this->assertNotEmpty($this->query('order_product', operation: 'write')['table_report']['usages']);
    }

    public function test_composer_env_include_is_never_read_and_unknown_call_remains_global(): void
    {
        $this->fixture('DB::table("orders")->get(); $unknown->send(); include ".env";');
        $files = new class extends Filesystem
        {
            public array $reads = [];

            public function get($path, $lock = false)
            {
                $this->reads[] = $path;

                return parent::get($path, $lock);
            }
        };
        symlink($this->tempPath.'/.env', $this->tempPath.'/app/Secret.php');
        $files->ensureDirectoryExists($this->tempPath.'/vendor');
        $files->put($this->tempPath.'/vendor/Secret.php', '<?php throw new \\RuntimeException;');
        $files->put($this->tempPath.'/.env', 'SECRET_DO_NOT_READ=anything');
        $files->put($this->tempPath.'/composer.json', '{"autoload":{"files":[".env"]}}');
        $files->ensureDirectoryExists($this->tempPath.'/routes');
        symlink($this->tempPath.'/.env', $this->tempPath.'/routes/Secret.php');
        $this->write('routes/web.php', 'include ".env"; \\Illuminate\\Support\\Facades\\Route::get("/orders", [App\\Action::class, "run"]);');
        $report = (new ArchitectureImpact($files, $this->tempPath, new AuditScope(['app', 'vendor'])))->inspect('', table: 'orders')['table_report'];
        $this->assertNotContains($this->tempPath.'/.env', $files->reads);
        $this->assertNotContains($this->tempPath.'/app/Secret.php', $files->reads);
        $this->assertNotContains($this->tempPath.'/routes/Secret.php', $files->reads);
        $this->assertNotContains($this->tempPath.'/vendor/Secret.php', $files->reads);
        $this->assertNotEmpty($report['usages']);
        $this->assertStringContainsString('send', json_encode($report['unresolved']));
        $this->assertStringContainsString('HTTP source omitted', json_encode($report['unresolved']));
        $this->assertContains('http', array_column(array_column($this->paths($report), 'entry'), 'kind'));
        $this->assertSame('incomplete', $report['status']);
    }

    public function test_zero_limit_depth_cycle_and_multiple_paths_preserve_totals(): void
    {
        $this->fixture('DB::table("orders")->get();');
        $this->write('app/A.php', 'namespace App; class A { public static function run() { B::run(); Action::run(); } }');
        $this->write('app/B.php', 'namespace App; class B { public static function run() { A::run(); Action::run(); } }');
        $report = $this->query()['table_report'];
        $this->assertGreaterThan(2, $report['totals']['paths']);
        $zero = $this->query(limit: 0)['table_report'];
        $this->assertSame([], $zero['usages']);
        $this->assertSame($report['totals']['usages'], $zero['totals']['usages']);
        $this->assertSame('limit', $zero['status']);
        $this->assertSame('limit', $this->query(depth: 1)['table_report']['status']);
    }

    public function test_source_only_excludes_vendor_env_symlinks_and_explicit_excludes(): void
    {
        $this->fixture('DB::table("orders")->get(); throw new \\RuntimeException("must not execute");');
        $this->write('vendor/Fake.php', '\\Illuminate\\Support\\Facades\\DB::table("orders")->delete();');
        $this->write('outside.php', '\\Illuminate\\Support\\Facades\\DB::table("orders")->delete();');
        symlink($this->tempPath.'/outside.php', $this->tempPath.'/app/Link.php');
        $this->write('.env', 'this is not PHP');
        $report = $this->query()['table_report'];
        $this->assertSame(['app/Action.php'], array_values(array_unique(array_column($report['usages'], 'path'))));
        $this->assertSame([], $this->query(exclude: ['app/Action.php'])['table_report']['usages']);
    }

    public function test_new_data_inputs_change_snapshot_without_expanding_audit_scope(): void
    {
        $this->fixture('DB::table("orders")->get();');
        $cache = new ProjectGraphCache(new Filesystem, $this->tempPath);
        $first = $this->query(cache: $cache);
        $second = $this->query(cache: $cache);
        $this->assertSame($first['snapshot'], $second['snapshot']);
        $this->write('database/migrations/new.php', 'return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up() { \\Illuminate\\Support\\Facades\\Schema::create("orders", fn ($t) => $t->id()); } };');
        $third = $this->query(cache: $cache);
        $this->assertNotSame($first['snapshot'], $third['snapshot']);
        $this->assertTrue($third['table_report']['fresh']);
        $this->assertSame(['app'], $third['scope']['paths']);
    }

    public function test_invalid_and_conflicting_inputs_are_errors_before_analysis(): void
    {
        $impact = new ArchitectureImpact(new Filesystem, $this->tempPath);
        foreach ([['table' => ''], ['table' => "orders\0"], ['table' => 'orders', 'change' => 'delete'], ['table' => 'orders', 'tableMatch' => 'glob'], ['table' => 'orders', 'connection' => 'crm'], ['table' => 'orders', 'operation' => 'select'], ['tableMatch' => 'contains'], ['table' => 'orders', 'limit' => 501]] as $options) {
            $this->assertFalse($impact->inspect('', ...$options)['ok'], json_encode($options));
        }
        $this->assertSame('E_IMPACT_TABLE_INVALID', $impact->inspect('Order', table: 'orders')['m']);
    }

    public function test_cli_mcp_and_schema_share_table_contract_and_preserve_legacy(): void
    {
        $this->fixture('DB::connection("crm")->table("orders")->get();');
        $this->assertSame(0, Artisan::call('architecture-kit:impact', ['--table' => 'orders', '--connection' => 'named:crm', '--operation' => 'read', '--agent' => true, '--limit' => 100, '--depth' => 16]));
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($this->query(connection: 'named:crm', operation: 'read')['table_report'], $cli['table_report']);
        ArchitectureKitServer::tool(Impact::class, ['table' => 'orders', 'connection' => 'named:crm', 'operation' => 'read', 'limit' => 100, 'depth' => 16])->assertOk()->assertSee('"table_report"')->assertSee('orders');
        ArchitectureKitServer::tool(Impact::class, ['table' => ['orders']])->assertSee('E_INVALID_TOOL_INPUT');
        $this->assertSame(0, Artisan::call('architecture-kit:impact', ['--table' => 'orders']));
        $this->assertStringContainsString('app/Action.php', Artisan::output());
        $this->assertSame(1, Artisan::call('architecture-kit:impact', ['subject' => 'Order', '--table' => 'orders', '--agent' => true]));
        $schema = ImpactSchema::get()['oneOf'][2];
        $this->assertSame([], array_diff(array_keys($cli), array_keys($schema['properties'])));
        $this->assertSame([], array_diff($schema['required'], array_keys($cli)));
        $reportSchema = $schema['properties']['table_report'];
        $this->assertSame([], array_diff(array_keys($cli['table_report']), array_keys($reportSchema['properties'])));
        $this->assertSame([], array_diff($reportSchema['required'], array_keys($cli['table_report'])));
        $this->assertArrayHasKey('data', (new ArchitectureImpact(new Filesystem, $this->tempPath))->inspect('Action::run'));
    }

    private function assertContinuous(array $via): void
    {
        for ($i = 1; $i < count($via); $i++) {
            $this->assertSame($via[$i - 1]['to'], $via[$i]['from'], json_encode($via));
        }
    }
}
