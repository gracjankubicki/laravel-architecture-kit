<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Reach;
use GracjanKubicki\ArchitectureKit\Reach\ArchitectureReach;
use GracjanKubicki\ArchitectureKit\Reach\ReachSchema;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class ArchitectureReachTest extends TestCase
{
    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, '<?php '.$source);
        clearstatcache();
    }

    private function fixture(): void
    {
        $this->write('config/architectures.php', 'return ["enabled" => ["actions"]];');
        $this->write('app/A.php', 'namespace App; class A { public static function go() { B::go(); C::go(); } }');
        $this->write('app/B.php', 'namespace App; class B { public static function go() { D::go(); } }');
        $this->write('app/C.php', 'namespace App; class C { public static function go() { D::go(); } }');
        $this->write('app/D.php', 'namespace App; class D { public static function go() { A::go(); } }');
        $this->write('routes/web.php', '\\Illuminate\\Support\\Facades\\Route::get("/a", [\\App\\A::class, "go"]);');
    }

    private function reach(string $subject = 'App\\A::go', int $limit = 20, int $depth = 4, ?string $id = null, int $page = 1): array
    {
        return (new ArchitectureReach(new Filesystem, $this->tempPath))->inspect($subject, $limit, $depth, $id, $page);
    }

    public function test_cycles_and_duplicate_paths_count_unique_direct_and_exclusively_indirect(): void
    {
        $this->fixture();
        $r = $this->reach(limit: 1);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(['direct' => 2, 'exclusively_indirect' => 1, 'total' => 3], $r['reach']['counts']['code']['dependencies']['resolved']);
        $this->assertSame(['direct' => 1, 'exclusively_indirect' => 2, 'total' => 3], $r['reach']['counts']['code']['dependents']['resolved']);
        $this->assertCount(1, $r['dependencies']);
        $this->assertFalse($r['reach']['total_is_lower_bound']);
        $this->assertTrue($r['reach']['pagination']['truncated']);
        $zero = $this->reach(limit: 0);
        $this->assertSame($r['reach']['counts'], $zero['reach']['counts']);
        $this->assertSame([], $zero['dependencies']);
    }

    public function test_pages_have_identical_report_snapshot_and_counts_without_reanalysis(): void
    {
        $this->fixture();
        $first = $this->reach(limit: 1);
        $id = $first['reach']['pagination']['report_id'];
        $seen = [$first['dependencies'][0]['symbol']];
        for ($page = 2; $page <= 3; $page++) {
            $next = $this->reach('', 1, 4, $id, $page);
            $this->assertTrue($next['ok'], json_encode($next));
            $this->assertSame($first['snapshot'], $next['snapshot']);
            $this->assertSame($first['reach']['counts'], $next['reach']['counts']);
            $this->assertSame($id, $next['reach']['pagination']['report_id']);
            $seen[] = $next['dependencies'][0]['symbol'];
        }
        $this->assertCount(3, array_unique($seen));
        $this->assertFalse($this->reach('', 1, 4, $id, 10000)['ok']);
        $metadataOnly = new class extends Filesystem
        {
            public function get($path, $lock = false)
            {
                if (str_ends_with($path, '.php')) {
                    throw new \RuntimeException('Continuation must not read PHP bodies.');
                }

                return parent::get($path, $lock);
            }
        };
        $this->assertTrue((new ArchitectureReach($metadataOnly, $this->tempPath))->inspect('', 1, 4, $id, 2)['ok']);
    }

    public function test_added_removed_edited_and_config_sources_invalidate_pages(): void
    {
        $this->fixture();
        foreach (['edit', 'add', 'remove', 'config'] as $operation) {
            $first = $this->reach(limit: 1);
            $this->assertTrue($first['ok'], json_encode($first));
            if ($operation === 'edit') {
                $this->write('app/B.php', 'namespace App; class B { public static function go() { D::go(); D::go(); } }');
            } elseif ($operation === 'add') {
                $this->write('app/New.php', 'namespace App; class NewEntry {}');
            } elseif ($operation === 'remove') {
                unlink($this->tempPath.'/app/New.php');
            } else {
                $this->write('config/architectures.php', 'return ["enabled" => [], "audit" => ["exclude" => ["app/C.php"]]];');
            }
            $next = $this->reach('', 1, 4, $first['reach']['pagination']['report_id'], 2);
            $this->assertFalse($next['ok']);
            $this->assertStringContainsString('changed', $next['msg']);
        }
    }

    public function test_invalid_missing_and_symlink_reports_and_input_are_explicit(): void
    {
        $this->fixture();
        foreach (['../x', str_repeat('a', 32)] as $id) {
            $this->assertFalse($this->reach('', 1, 4, $id)['ok']);
        }
        $r = $this->reach();
        $id = $r['reach']['pagination']['report_id'];
        $path = $this->tempPath.'/storage/framework/cache/architecture-kit/reach/'.$id.'.json';
        unlink($path);
        symlink($this->tempPath.'/app/A.php', $path);
        $this->assertFalse($this->reach('', 1, 4, $id)['ok']);
        $this->assertFalse($this->reach(limit: 501)['ok']);
        $this->assertFalse($this->reach(page: 2)['ok']);
        $this->assertFalse($this->reach('A', 1, 4, $id)['ok']);
    }

    public function test_depth_boundaries_are_lower_bounds_and_references_are_leaves(): void
    {
        $this->fixture();
        $r = $this->reach(depth: 1);
        $this->assertTrue($r['reach']['total_is_lower_bound']);
        $this->assertSame('limit', $r['reach']['status']);
        $this->write('app/Ref.php', 'namespace App; class Ref { public static function go() { $callable = A::go(...); } }');
        $r = $this->reach();
        $this->assertSame(1, $r['reach']['counts']['code']['dependents']['references']['total']);
        $this->assertNotContains('App\\Ref::go', array_column($r['dependents'], 'symbol'));
    }

    public function test_file_includes_multiple_declarations_and_class_query_stays_class_channel(): void
    {
        $this->fixture();
        $this->write('app/Pair.php', 'namespace App; class First { public static function go() { B::go(); } } class Second { public static function go() { C::go(); } }');
        $r = $this->reach('app/Pair.php');
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('file', $r['subject']['kind']);
        $this->assertContains('App\\B::go', array_column($r['dependencies'], 'symbol'));
        $this->assertContains('App\\C::go', array_column($r['dependencies'], 'symbol'));
        $class = $this->reach('App\\A');
        $this->assertTrue($class['ok']);
        $this->assertNotContains('App\\B::go', array_column($class['dependencies'], 'symbol'));
    }

    public function test_execution_data_and_crossings_have_distinct_units_and_evidence(): void
    {
        $this->fixture();
        $this->write('app/Actions/Save.php', 'namespace App\\Actions; class Save { public static function go() { \\App\\Infrastructure\\Store::go(); } }');
        $this->write('app/Infrastructure/Store.php', 'namespace App\\Infrastructure; class Store { public static function go() { \\Illuminate\\Support\\Facades\\DB::table("invoices")->insert(["id"=>1]); } }');
        $r = $this->reach('App\\Actions\\Save::go');
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertNotEmpty($r['reach']['layer_crossings']);
        $this->assertFalse($r['reach']['layer_crossings'][0]['is_violation']);
        $this->assertNotEmpty($r['reach']['layer_crossings'][0]['path']);
        $this->assertGreaterThan(0, $r['reach']['counts']['data_operations']['outgoing']);
        $this->assertArrayHasKey('execution_entries', $r['reach']['units']);
        $a = $this->reach();
        $this->assertSame(1, $a['reach']['counts']['execution_entries']['possible']);
        $this->assertSame(0, $a['reach']['counts']['execution_entries']['declared']);
    }

    public function test_static_config_never_executes_and_excludes_apply(): void
    {
        $this->fixture();
        $this->write('config/architectures.php', 'file_put_contents(__DIR__."/executed", "bad"); return [];');
        $this->assertFalse($this->reach()['ok']);
        $this->assertFileDoesNotExist($this->tempPath.'/config/executed');
        $this->write('config/architectures.php', 'return ["audit"=>["exclude"=>["app/C.php"]]];');
        $r = $this->reach();
        $this->assertTrue($r['ok']);
        $this->assertSame(['app/C.php'], $r['scope']['exclude']);
        $this->assertSame(['app'], $r['scope']['paths']);
    }

    public function test_possible_contract_targets_and_unknown_roles_are_explicit(): void
    {
        $this->fixture();
        $this->write('app/Port.php', 'namespace App; interface Port { public function run(); }');
        $this->write('app/Impl.php', 'namespace App; class Impl implements Port { public function run() { B::go(); } }');
        $this->write('app/UsePort.php', 'namespace App; class UsePort { public function go(Port $port) { $port->run(); } }');
        $r = $this->reach('App\\UsePort::go');
        $this->assertTrue($r['ok']);
        $this->assertGreaterThan(0, $r['reach']['counts']['code']['dependencies']['possible']['total']);
        $this->assertSame('unknown', $r['subject']['role']);
        $this->assertNotEmpty($r['possible']['dependencies']);
    }

    public function test_source_limits_are_not_exhaustive_zero_and_corrupt_reports_fail(): void
    {
        $this->fixture();
        $this->write('app/Oversize.php', '/*'.str_repeat('x', 10000001).'*/');
        $r = $this->reach();
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue($r['reach']['total_is_lower_bound']);
        $this->assertSame('limit', $r['reach']['status']);
        $id = $r['reach']['pagination']['report_id'];
        file_put_contents($this->tempPath.'/storage/framework/cache/architecture-kit/reach/'.$id.'.json', '{}');
        $this->assertFalse($this->reach('', 1, 4, $id)['ok']);
    }

    public function test_analysis_result_budget_is_independent_of_display_size(): void
    {
        $this->fixture();
        $declarations = 'namespace App; ';
        $calls = '';
        for ($i = 0; $i < 1005; $i++) {
            $declarations .= 'class Wide'.$i.' { public static function go() {} } ';
            $calls .= 'Wide'.$i.'::go();';
        }
        $this->write('app/Wide.php', $declarations.'class WideRoot { public static function go() { '.$calls.' } }');
        $r = $this->reach('App\\WideRoot::go', 1, 2);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertTrue($r['reach']['total_is_lower_bound']);
        $this->assertSame('limit', $r['reach']['status']);
        $this->assertLessThanOrEqual(1000, $r['reach']['counts']['code']['dependencies']['resolved']['total']);
        $this->assertCount(1, $r['dependencies']);
        $this->assertNotEmpty($r['analysis']['notices']);
    }

    public function test_mutation_during_analysis_cannot_be_continued(): void
    {
        $this->fixture();
        $files = new class($this->tempPath) extends Filesystem
        {
            private bool $changed = false;

            public function __construct(private string $root) {}

            public function get($path, $lock = false)
            {
                $value = parent::get($path, $lock);
                if (! $this->changed && str_ends_with($path, '/app/A.php')) {
                    $this->changed = true;
                    parent::put($path, '<?php namespace App; class A { public static function changedMethod() {} }');
                    clearstatcache();
                }

                return $value;
            }
        };
        $r = (new ArchitectureReach($files, $this->tempPath))->inspect('App\\A::go', 1);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertFalse($r['reach']['fresh']);
        $this->assertTrue($r['reach']['total_is_lower_bound']);
        $this->assertFalse($this->reach('', 1, 4, $r['reach']['pagination']['report_id'], 2)['ok']);
    }

    public function test_retained_excluded_tests_invalidate_continuation_on_edit_add_remove(): void
    {
        $this->fixture();
        $this->write('config/architectures.php', 'return ["audit"=>["missing_test"=>"warn", "exclude"=>["tests/*"]]];');
        $this->write('tests/EntryTest.php', 'namespace Tests; class EntryTest { public function testRun() { \\App\\A::go(); } }');
        foreach (['edit', 'add', 'remove'] as $operation) {
            $first = $this->reach(limit: 1);
            $this->assertTrue($first['ok'], json_encode($first));
            $this->assertContains('Tests\\EntryTest::testRun', array_column($this->reach()['dependents'], 'symbol'));
            if ($operation === 'edit') {
                $this->write('tests/EntryTest.php', 'namespace Tests; class EntryTest { public function testRun() { \\App\\A::go(); \\App\\B::go(); } }');
            } elseif ($operation === 'add') {
                $this->write('tests/OtherTest.php', 'namespace Tests; class OtherTest { public function testRun() { \\App\\A::go(); } }');
            } else {
                unlink($this->tempPath.'/tests/OtherTest.php');
            }
            $r = $this->reach('', 1, 4, $first['reach']['pagination']['report_id'], 2);
            $this->assertFalse($r['ok']);
            $this->assertStringContainsString('changed', $r['msg']);
        }
    }

    public function test_crossings_keep_diamond_edges_even_when_symbol_was_already_counted(): void
    {
        $this->fixture();
        $this->write('app/Actions/Root.php', 'namespace App\\Actions; class Root { public static function go() { Middle::go(); \\App\\Infrastructure\\Bridge::go(); } }');
        $this->write('app/Actions/Middle.php', 'namespace App\\Actions; class Middle { public static function go() { Leaf::go(); } }');
        $this->write('app/Actions/Leaf.php', 'namespace App\\Actions; class Leaf { public static function go() {} }');
        $this->write('app/Infrastructure/Bridge.php', 'namespace App\\Infrastructure; class Bridge { public static function go() { \\App\\Actions\\Leaf::go(); } }');
        $r = $this->reach('App\\Actions\\Root::go');
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(['direct' => 2, 'exclusively_indirect' => 1, 'total' => 3], $r['reach']['counts']['code']['dependencies']['resolved']);
        $pairs = array_map(static fn ($edge) => $edge['from'].'>'.$edge['to'], $r['reach']['layer_crossings']);
        $this->assertContains('App\\Actions\\Root::go>App\\Infrastructure\\Bridge::go', $pairs);
        $this->assertContains('App\\Infrastructure\\Bridge::go>App\\Actions\\Leaf::go', $pairs);
        $this->assertFalse($r['reach']['layer_crossings_limited']);
    }

    public function test_method_class_and_file_preserve_selected_and_reached_method_uncertainty(): void
    {
        $this->fixture();
        $this->write('app/Unknown.php', 'namespace App; class Unknown { public static function go() { \\Vendor\\Client::send(); } public function dynamic($unknown) { $unknown->other(); } }');
        $this->write('app/ParentCaller.php', 'namespace App; class ParentCaller { public static function go() { Unknown::go(); } }');
        foreach (['App\\Unknown::go', 'App\\Unknown', 'app/Unknown.php', 'App\\ParentCaller::go', 'App\\ParentCaller', 'app/ParentCaller.php'] as $subject) {
            $r = $this->reach($subject);
            $this->assertTrue($r['ok'], json_encode($r));
            $this->assertSame('incomplete', $r['reach']['status']);
            $this->assertContains('Vendor\\Client', array_column($r['analysis']['notices'], 'receiver'), $subject);
        }
        $legacy = (new ArchitectureImpact(new Filesystem, $this->tempPath))->inspect('App\\Unknown');
        $this->assertArrayNotHasKey('reach', $legacy);
    }

    public function test_cli_mcp_and_schema_and_legacy_contract(): void
    {
        $this->fixture();
        Artisan::call('architecture-kit:reach', ['subject' => 'App\\A::go', '--agent' => true, '--limit' => 1]);
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($cli['ok'], json_encode($cli));
        $this->assertSame('reach', $cli['cmd']);
        ArchitectureKitServer::tool(Reach::class, ['subject' => 'App\\A::go', 'limit' => 1])->assertOk()->assertStructuredContent(fn ($json) => $json->where('reach.counts', $cli['reach']['counts'])->where('dependencies', $cli['dependencies'])->etc());
        ArchitectureKitServer::tool(Reach::class, ['subject' => []])->assertSee('E_INVALID_TOOL_INPUT');
        $this->assertContains('reach', ReachSchema::get()['oneOf'][0]['required']);
        $this->assertSame(1000, ReachSchema::get()['oneOf'][0]['properties']['analysis']['properties']['limit']['maximum']);
        $this->assertContains(Reach::class, (new \ReflectionClass(ArchitectureKitServer::class))->getDefaultProperties()['tools']);
        $legacy = (new ArchitectureImpact(new Filesystem, $this->tempPath))->inspect('App\\A::go', limit: 1);
        $this->assertTrue($legacy['ok']);
        $this->assertSame('impact', $legacy['cmd']);
        $this->assertArrayNotHasKey('reach', $legacy);
        $this->assertCount(1, $legacy['dependencies']);
    }
}
