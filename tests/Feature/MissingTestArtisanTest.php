<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class MissingTestArtisanTest extends TestCase
{
    private function write(string $path, string $body): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, '<?php '.$body);
        clearstatcache();
    }

    private function fixture(string $test): void
    {
        $this->write('bootstrap/app.php', 'return Illuminate\Foundation\Application::configure();');
        $this->write('app/Actions/Send.php', 'namespace App\Actions; class Send { public function handle() {} }');
        $this->write('app/Actions/Unused.php', 'namespace App\Actions; class Unused { public function handle() {} }');
        $this->write('app/Console/Commands/Send.php', 'namespace App\Console\Commands; class Send extends \Illuminate\Console\Command { protected $signature = "invoices:send {id?}"; public function handle(\App\Actions\Send $action) { $action->handle(); } public function unused() { (new \App\Actions\Unused)->handle(); } }');
        $this->write('app/Console/Commands/Other.php', 'namespace App\Console\Commands; class Other extends \Illuminate\Console\Command { protected $signature = "other"; public function handle() {} }');
        $this->write('tests/TestCase.php', 'namespace Tests; abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase {}');
        $this->write('tests/Feature/CommandsTest.php', $test);
    }

    private function audit(): ApplicationAuditResult
    {
        return (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false, missingTestLevel: MissingTestLevel::Warn);
    }

    public function test_facade_testcase_and_pest_dispatch_reach_only_the_handler(): void
    {
        foreach ([
            'use Illuminate\Support\Facades\Artisan; it("send", function () { Artisan::call("invoices:send"); });',
            'namespace Tests; class CommandsTest extends TestCase { public function test_send() { $this->artisan("invoices:send")->assertSuccessful(); } }',
            'use function Pest\Laravel\artisan as command; it("send", function () { command("invoices:send")->assertExitCode(0); });',
        ] as $test) {
            $this->fixture($test);
            $findings = $this->audit()->findings;
            $this->assertNotContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($findings, 'code'), json_encode($findings));
            $this->assertNotContains('app/Console/Commands/Send.php', array_column($findings, 'path'));
            $this->assertNotContains('app/Actions/Send.php', array_column($findings, 'path'));
            $this->assertContains('app/Console/Commands/Other.php', array_column($findings, 'path'));
            $this->assertContains('app/Actions/Unused.php', array_column($findings, 'path'));
        }
    }

    public function test_signature_attribute_and_inherited_handle_credit_the_runtime_command(): void
    {
        $this->fixture('use Illuminate\Support\Facades\Artisan; it("send", function () { Artisan::call("invoices:send"); });');
        $this->write('app/Console/BaseCommand.php', 'namespace App\Console; abstract class BaseCommand extends \Illuminate\Console\Command { public function handle(\App\Actions\Send $action) { $action->handle(); } }');
        $this->write('app/Console/Commands/Send.php', 'namespace App\Console\Commands; #[\Illuminate\Console\Attributes\Signature("invoices:send")] class Send extends \App\Console\BaseCommand {}');
        $findings = $this->audit()->findings;
        $this->assertNotContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($findings, 'code'), json_encode($findings));
        $this->assertNotContains('app/Console/Commands/Send.php', array_column($findings, 'path'));
        $this->assertNotContains('app/Actions/Send.php', array_column($findings, 'path'));
    }

    public function test_dynamic_unknown_and_conflicting_commands_remain_incomplete(): void
    {
        foreach (['$name', '"not:registered"', '"invoices:send"'] as $selector) {
            $this->fixture('use Illuminate\Support\Facades\Artisan; it("send", function () { Artisan::call('.$selector.'); });');
            if ($selector === '"invoices:send"') {
                $this->write('app/Console/Commands/Other.php', 'namespace App\Console\Commands; class Other extends \Illuminate\Console\Command { protected $signature = "invoices:send"; public function handle() {} }');
            }
            $findings = $this->audit()->findings;
            $this->assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($findings, 'code'));
            $this->assertContains('app/Console/Commands/Send.php', array_column($findings, 'path'));
            $this->assertContains('app/Actions/Send.php', array_column($findings, 'path'));
        }
    }

    public function test_project_testcase_override_is_not_treated_as_framework_dispatch(): void
    {
        $this->fixture('namespace Tests; class CommandsTest extends TestCase { public function test_send() { $this->artisan("invoices:send"); } }');
        $this->write('tests/TestCase.php', 'namespace Tests; abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase { public function artisan($name) {} }');
        $findings = $this->audit()->findings;
        $this->assertContains('app/Actions/Send.php', array_column($findings, 'path'));
        $this->assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($findings, 'code'));
    }

    public function test_command_source_edits_refresh_with_cached_test_facts_and_preserve_levels(): void
    {
        $this->fixture('use Illuminate\\Support\\Facades\\Artisan; it("send", function () { Artisan::call("invoices:send"); });');
        $files = new Filesystem;
        $audit = new ApplicationAudit($files, $this->tempPath);
        $cache = new ProjectGraphCache($files, $this->tempPath);
        $run = fn ($cache) => $audit->run([], false, missingTestLevel: MissingTestLevel::Warn, cache: $cache);
        $disabled = $run(null);
        $this->assertNotSame([], $disabled->findings);
        $this->assertEquals($disabled->findings, $run($cache)->findings);
        $this->assertEquals($disabled->findings, $run($cache)->findings);
        $this->write('app/Console/Commands/Send.php', 'namespace App\\Console\\Commands; class Send extends \\Illuminate\\Console\\Command { protected $signature = "changed"; public function handle(\\App\\Actions\\Send $action) { $action->handle(); } }');
        $changed = $run($cache);
        $this->assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($changed->findings, 'code'));
        $this->assertContains('app/Actions/Send.php', array_column($changed->findings, 'path'));
        $this->assertEquals($run(null)->findings, $changed->findings);
        $error = $audit->run([], false, missingTestLevel: MissingTestLevel::Error);
        $this->assertContains('E_MISSING_TEST', array_column($error->findings, 'code'));
        $this->assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($error->findings, 'code'));
        $this->assertSame([], $audit->run([], false, missingTestLevel: MissingTestLevel::Off)->findings);
    }

    public function test_unrelated_dynamic_and_unknown_callers_do_not_poison_literal_dispatch(): void
    {
        $this->fixture('use Illuminate\\Support\\Facades\\Artisan; it("send", function () { Artisan::call("invoices:send"); Artisan::call($unknown); Artisan::call("not:registered"); });');
        file_put_contents($this->tempPath.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']], 'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']]], JSON_THROW_ON_ERROR));
        $findings = $this->audit()->findings;
        $this->assertNotContains('app/Console/Commands/Send.php', array_column($findings, 'path'));
        $this->assertNotContains('app/Actions/Send.php', array_column($findings, 'path'));
        $this->assertContains('app/Actions/Unused.php', array_column($findings, 'path'));
        $this->assertCount(2, array_filter($findings, fn ($finding) => $finding->code === 'W_MISSING_TEST_ANALYSIS_INCOMPLETE'));
    }

    public function test_same_name_and_shared_inherited_handler_remain_conflicting_runtime_classes(): void
    {
        $this->fixture('use Illuminate\\Support\\Facades\\Artisan; it("send", function () { Artisan::call("invoices:send"); });');
        $this->write('app/Console/BaseCommand.php', 'namespace App\\Console; abstract class BaseCommand extends \\Illuminate\\Console\\Command { public function handle() { $this->runAction(); } }');
        $this->write('app/Console/Commands/Send.php', 'namespace App\\Console\\Commands; class Send extends \\App\\Console\\BaseCommand { protected $signature = "invoices:send"; public function runAction() { (new \\App\\Actions\\Send)->handle(); } }');
        $this->write('app/Console/Commands/Other.php', 'namespace App\\Console\\Commands; class Other extends \\App\\Console\\BaseCommand { protected $signature = "invoices:send"; public function runAction() { (new \\App\\Actions\\Unused)->handle(); } }');
        $findings = $this->audit()->findings;
        foreach (['app/Actions/Send.php', 'app/Actions/Unused.php', 'app/Console/Commands/Send.php', 'app/Console/Commands/Other.php'] as $path) {
            $this->assertContains($path, array_column($findings, 'path'));
        }
        $this->assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($findings, 'code'));
        $this->assertStringContainsString('conflicting handlers', implode(' ', array_column($findings, 'message')));
    }

    public function test_callback_command_with_first_class_method_stays_incomplete(): void
    {
        $this->fixture('use Illuminate\\Support\\Facades\\Artisan; it("callback", function () { Artisan::call("callback:send"); });');
        $this->write('app/Support/Runner.php', 'namespace App\\Support; class Runner { public static function run() { (new \\App\\Actions\\Send)->handle(); } }');
        $this->write('routes/console.php', 'use Illuminate\\Support\\Facades\\Artisan; Artisan::command("callback:send", \\App\\Support\\Runner::run(...));');
        $findings = $this->audit()->findings;
        $this->assertContains('app/Actions/Send.php', array_column($findings, 'path'));
        $this->assertContains('app/Support/Runner.php', array_column($findings, 'path'));
        $this->assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($findings, 'code'));
        $this->assertStringContainsString('callback command', implode(' ', array_column($findings, 'message')));
    }
}
