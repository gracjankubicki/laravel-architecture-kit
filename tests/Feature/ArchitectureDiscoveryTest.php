<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Discovery\ArchitectureDiscovery;
use GracjanKubicki\ArchitectureKit\Discovery\SearchSchema;
use GracjanKubicki\ArchitectureKit\Impact\ArchitecturePath;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Search;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class ArchitectureDiscoveryTest extends TestCase
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
        $this->write('app/Invoice.php', 'namespace App; class Invoice { public function sendInvoice() {} }');
        $this->write('app/Jobs/Decoy.php', 'namespace App\\Jobs; class Decoy { public function handle() {} }');
        $this->write('app/Worker.php', 'namespace App; class Worker implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { public function handle() {} }');
        $this->write('app/Type.php', 'namespace App; interface Port {} trait T {} enum State { case Open; }');
        $this->write('routes/web.php', '\\Illuminate\\Support\\Facades\\Route::get("/invoices", [\\App\\Invoice::class, "sendInvoice"])->name("invoice.list"); \\Illuminate\\Support\\Facades\\Route::get("/closure-invoice", fn () => 1);');
        $this->write('routes/console.php', '\\Illuminate\\Support\\Facades\\Artisan::command("invoice:send", function () { return 1; });');
    }

    private function search(string $query = '', ?string $kind = null, int $limit = 20): array
    {
        return (new ArchitectureDiscovery(new Filesystem, $this->tempPath))->search($query, $kind, $limit);
    }

    public function test_literal_case_insensitive_search_exact_first_and_supported_selector(): void
    {
        $this->fixture();
        $r = $this->search('app\\invoice');
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('App\\Invoice', $r['candidates'][0]['name']);
        $this->assertTrue($r['candidates'][0]['exact']);
        $this->assertTrue($r['ambiguous']);
        $this->assertSame($r['candidates'], $this->search('app\\invoice')['candidates']);
        $route = $this->search('/INVOICES', 'route')['candidates'][0];
        $this->assertSame('App\\Invoice::sendInvoice', $route['selector']);
        $this->assertTrue((new ArchitecturePath(new Filesystem, $this->tempPath))->inspect($route['selector'], 'Invoice')['ok']);
        $this->assertNotEmpty($this->search('invoice.list', 'route')['candidates']);
        $this->assertNotEmpty($this->search('invoice:send', 'command')['candidates']);
        $this->assertSame('file', $this->search('invoice:send', 'command')['candidates'][0]['selector_scope']);
        $this->assertSame('routes/console.php', $this->search('invoice:send', 'command')['candidates'][0]['selector']);
        $this->assertNotEmpty($this->search('app/invoice.php', 'file')['candidates']);
    }

    public function test_kinds_without_query_and_job_contract_not_directory(): void
    {
        $this->fixture();
        $this->assertSame(['App\\Worker'], array_column($this->search('', 'job')['candidates'], 'name'));
        foreach (['interface' => 'App\\Port', 'trait' => 'App\\T', 'enum' => 'App\\State'] as $kind => $name) {
            $this->assertSame([$name], array_column($this->search('', $kind)['candidates'], 'name'));
        }
        $this->assertNotEmpty($this->search('Decoy', 'class')['candidates'][0]['notes']);
    }

    public function test_duplicate_declarations_remain_separate_and_no_fuzzy_selection(): void
    {
        $this->fixture();
        $this->write('app/InvoiceCopy.php', 'namespace App; class Invoice {}');
        $r = $this->search('App\\Invoice', 'class');
        $this->assertCount(2, $r['candidates']);
        $this->assertNotSame($r['candidates'][0]['id'], $r['candidates'][1]['id']);
        $this->assertSame([], $this->search('Invocie')['candidates']);
        $this->assertSame([], $this->search('.*Invoice')['candidates']);
    }

    public function test_conditional_duplicate_declarations_on_one_line_remain_separate(): void
    {
        $this->fixture();
        $this->write('app/Conditional.php', 'namespace App; if ($condition) { class ConditionalInvoice { public function first() {} } } else { class ConditionalInvoice { public function second() {} } }');
        $r = $this->search('ConditionalInvoice', 'class');
        $this->assertCount(2, $r['candidates']);
        $this->assertNotSame($r['candidates'][0]['id'], $r['candidates'][1]['id']);
        foreach ($r['candidates'] as $candidate) {
            $this->assertSame('file', $candidate['selector_scope']);
            $this->assertSame('app/Conditional.php', $candidate['selector']);
            $this->assertNotEmpty($candidate['notes']);
        }
        $methods = $this->search('ConditionalInvoice', 'method')['candidates'];
        $this->assertCount(2, $methods);
        $this->assertSame(['App\\ConditionalInvoice::first', 'App\\ConditionalInvoice::second'], array_column($methods, 'name'));
        $this->assertSame(['file', 'file'], array_column($methods, 'selector_scope'));
    }

    public function test_empty_invalid_and_display_limits_do_not_limit_total(): void
    {
        $this->fixture();
        $this->assertFalse($this->search()['ok']);
        $this->assertFalse($this->search('a', 'invented')['ok']);
        $this->assertFalse($this->search('a', null, -1)['ok']);
        $this->assertFalse($this->search(str_repeat('x', 501))['ok']);
        $r = $this->search('invoice', null, 0);
        $this->assertSame([], $r['candidates']);
        $this->assertGreaterThan(0, $r['total']);
        $this->assertTrue($r['truncated']);
        $this->assertFalse($r['total_is_lower_bound']);
    }

    public function test_unknown_sources_and_edits_are_visible_and_cache_refreshes(): void
    {
        $this->fixture();
        $this->write('app/Broken.php', 'this is not php');
        $r = $this->search('invoice');
        $this->assertNotEmpty($r['candidates']);
        $this->assertSame('incomplete', $r['status']);
        $this->assertGreaterThan(0, $r['notice_total']);
        $this->write('app/NewInvoice.php', 'namespace App; class NewInvoice {}');
        $this->assertContains('App\\NewInvoice', array_column($this->search('invoice', 'class')['candidates'], 'name'));
        unlink($this->tempPath.'/app/NewInvoice.php');
        $this->assertNotContains('App\\NewInvoice', array_column($this->search('invoice', 'class')['candidates'], 'name'));
    }

    public function test_configuration_code_vendor_env_and_symlinks_are_not_executed_or_read(): void
    {
        $this->fixture();
        $this->write('config/architectures.php', 'file_put_contents(__DIR__."/executed", "bad"); return ["enabled" => []];');
        $this->assertFalse($this->search('Invoice')['ok']);
        $this->assertFileDoesNotExist($this->tempPath.'/config/executed');
        $this->write('config/architectures.php', 'return ["audit" => ["paths" => ["vendor"]]];');
        $this->assertFalse($this->search('Invoice')['ok']);
        $this->write('config/architectures.php', 'return ["enabled" => []];');
        $this->write('vendor/Secret.php', 'namespace Secret; class InvoiceSecret {}');
        $this->write('.env', 'namespace Secret; class InvoiceEnv {}');
        symlink($this->tempPath.'/vendor/Secret.php', $this->tempPath.'/app/Secret.php');
        $r = $this->search('Secret', 'class');
        $this->assertSame([], $r['candidates']);
    }

    public function test_dispatch_witnesses_and_custom_dispatch_do_not_confuse_jobs(): void
    {
        $this->fixture();
        $this->write('app/Plain.php', 'namespace App; class Plain { public function handle() {} }');
        $this->write('app/Decoy.php', 'namespace App; class Decoy { public static function dispatch() {} public function handle() {} }');
        $this->write('app/Caller.php', 'namespace App; class Caller { public function run() { dispatch(new Plain); Decoy::dispatch(); } }');
        $names = array_column($this->search('', 'job')['candidates'], 'name');
        $this->assertContains('App\\Plain', $names);
        $this->assertNotContains('App\\Decoy', $names);
    }

    public function test_analysis_byte_limit_is_a_lower_bound_not_no_matches(): void
    {
        $this->fixture();
        $this->write('app/Oversize.php', '/*'.str_repeat('x', 10000001).'*/');
        $r = $this->search('invoice');
        $this->assertTrue($r['limited']);
        $this->assertTrue($r['total_is_lower_bound']);
        $this->assertSame('limit', $r['status']);
    }

    public function test_source_mutation_during_reads_is_stale(): void
    {
        $this->fixture();
        $files = new class($this->tempPath) extends Filesystem
        {
            private bool $changed = false;

            public function __construct(private string $root) {}

            public function get($path, $lock = false)
            {
                $value = parent::get($path, $lock);
                if (! $this->changed && str_ends_with($path, '/app/Invoice.php')) {
                    $this->changed = true;
                    parent::put($path, '<?php namespace App; class Invoice { public function veryDifferentMethod() {} }');
                    clearstatcache();
                }

                return $value;
            }
        };
        $r = (new ArchitectureDiscovery($files, $this->tempPath))->search('invoice');
        $this->assertFalse($r['fresh']);
        $this->assertSame('stale', $r['status']);
    }

    public function test_static_configuration_paths_excludes_and_cache_switch_are_respected(): void
    {
        $this->fixture();
        $this->write('config/architectures.php', 'return ["enabled" => [Unknown::Pattern], "audit" => ["paths" => ["modules"], "exclude" => ["app/Invoice.php"], "cache" => false]];');
        $this->write('modules/Invoice.php', 'namespace Modules; class Invoice {}');
        $r = $this->search('Invoice', 'class');
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(['Modules\\Invoice'], array_column($r['candidates'], 'name'));
        $this->write('config/architectures.php', 'return ["audit" => ["paths" => env("SCAN_PATHS")]];');
        $this->assertFalse($this->search('invoice')['ok']);
    }

    public function test_cli_mcp_schema_and_input_errors_agree(): void
    {
        $this->fixture();
        $this->assertSame(0, Artisan::call('architecture-kit:search', ['query' => 'invoice', '--kind' => 'route', '--agent' => true]));
        $r = json_decode(trim(Artisan::output()), true);
        ArchitectureKitServer::tool(Search::class, ['query' => 'invoice', 'kind' => 'route'])->assertOk()->assertStructuredContent(fn ($json) => $json->where('candidates', $r['candidates'])->where('total', $r['total'])->etc());
        ArchitectureKitServer::tool(Search::class, ['query' => []])->assertSee('E_INVALID_TOOL_INPUT');
        $this->assertSame(1, Artisan::call('architecture-kit:search', ['query' => 'invoice', '--limit' => '-1', '--agent' => true]));
        $this->assertSame(0, Artisan::call('architecture-kit:search', ['--schema' => true]));
        $this->assertSame(SearchSchema::get(), json_decode(trim(Artisan::output()), true));
    }
}
