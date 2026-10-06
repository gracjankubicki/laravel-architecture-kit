<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\RevisionDiff;
use GracjanKubicki\ArchitectureKit\Revision\ArchitectureRevisionDiff;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

final class RevisionDiffTest extends TestCase
{
    public function test_dirty_working_sources_cli_and_mcp_preserve_git_and_do_not_execute_code(): void
    {
        $this->fixture();
        $this->write('app/Run.php', '<?php namespace App; file_put_contents(__DIR__."/executed", "bad"); class Run { public function run() { Other::changed(); } }');
        $status = $this->git('status', '--porcelain');
        $head = $this->git('rev-parse', 'HEAD');
        $index = $this->git('ls-files', '--stage');
        $report = (new ArchitectureRevisionDiff($this->tempPath))->compare('HEAD');
        $this->assertTrue($report['ok']);
        $this->assertSame('working', $report['sources']['after']['state']);
        $this->assertTrue($report['analysis']['fresh']);
        $this->assertGreaterThan(0, $report['totals']['structure']);
        $this->assertSame(0, Artisan::call('architecture-kit:revision-diff', ['from' => 'HEAD', '--agent' => true]));
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        unset($report['metrics'], $cli['metrics']);
        $this->assertSame($report, $cli);
        ArchitectureKitServer::tool(RevisionDiff::class, ['from' => 'HEAD'])->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('cmd', 'revision-diff')->where('totals.structure', $report['totals']['structure'])->etc());
        $this->assertSame($head, $this->git('rev-parse', 'HEAD'));
        $this->assertSame($index, $this->git('ls-files', '--stage'));
        $this->assertSame($status, $this->git('status', '--porcelain'));
        $this->assertFileDoesNotExist($this->tempPath.'/app/executed');
        $this->assertDirectoryDoesNotExist($this->tempPath.'/storage/framework/cache/architecture-kit');
    }

    public function test_git_to_git_ignores_dirty_files_and_limits_only_display(): void
    {
        $this->fixture();
        $before = $this->git('rev-parse', 'HEAD');
        $this->write('app/Added.php', '<?php namespace App; class Added { public function run() {} }');
        $this->commit();
        $this->write('app/Added.php', '<?php broken');
        $report = (new ArchitectureRevisionDiff($this->tempPath))->compare($before, 'HEAD', limit: 0);
        $this->assertTrue($report['ok']);
        $this->assertSame('git', $report['sources']['after']['state']);
        $this->assertGreaterThan(0, $report['totals']['symbols']);
        $this->assertSame([], $report['changes']['symbols']);
        $this->assertTrue($report['analysis']['display_truncated']);
        $this->assertGreaterThan(0, $report['metrics']['after']['reused_files']);
    }

    public function test_each_revision_has_own_scope_and_shared_configuration_is_explicit(): void
    {
        $this->fixture();
        $this->write('extra/Example.php', '<?php class Example {}');
        $this->write('config/architectures.php', '<?php return ["audit" => ["paths" => ["extra"]]];');
        $this->commit();
        $this->write('config/architectures.php', '<?php return ["audit" => ["paths" => []]];');
        $service = new ArchitectureRevisionDiff($this->tempPath);
        $own = $service->compare('HEAD');
        $this->assertContains('scope_left', array_column($own['changes']['symbols'], 'change'));
        $shared = $service->compare('HEAD', sharedConfig: 'before');
        $this->assertSame('shared_before', $shared['analysis']['configuration_mode']);
        $this->assertSame([], $shared['changes']['symbols']);
        $this->assertSame([], $shared['changes']['configuration']);
        $this->write('config/architectures.php', '<?php return env("CONFIG");');
        $unknown = $service->compare('HEAD');
        $this->assertSame('incomplete', $unknown['analysis']['status']);
        $this->assertFalse($unknown['analysis']['after_complete']);
        $this->assertNotEmpty($unknown['notices']);
        $this->assertFalse($service->compare('missing-ref')['ok']);
        $this->assertFalse($service->compare('HEAD', sharedConfig: 'current')['ok']);
        $this->assertFalse($service->compare('HEAD', manualPairs: ['Absent' => 'Other'])['ok']);
    }

    public function test_git_comparison_reports_real_laravel_changes_and_known_rows_after_parse_failure(): void
    {
        $this->fixture();
        $this->write('bootstrap/app.php', '<?php use Illuminate\Foundation\Application; return Application::configure(basePath: dirname(__DIR__))->withRouting(web: __DIR__."/../routes/web.php", commands: __DIR__."/../routes/console.php")->withCommands([App\Task::class]);');
        $this->write('routes/web.php', '<?php use Illuminate\Support\Facades\Route; Route::get("orders", function () { App\Run::run(); });');
        $this->write('routes/console.php', '<?php use Illuminate\Support\Facades\Schedule; Schedule::command("task:run")->daily();');
        $this->write('app/Task.php', '<?php namespace App; class Task extends \Illuminate\Console\Command { protected $signature = "task:run"; public function handle() { Run::run(); } }');
        $this->write('app/Job.php', '<?php namespace App; class Job implements \Illuminate\Contracts\Queue\ShouldQueue { use \Illuminate\Foundation\Bus\Dispatchable; public function handle() { Run::run(); } }');
        $this->write('app/Run.php', '<?php namespace App; use Illuminate\Support\Facades\DB; class Run { public static function run() { DB::table("orders")->get(); Job::dispatch(); } }');
        $this->commit();
        $before = $this->git('rev-parse', 'HEAD');
        $this->write('routes/web.php', '<?php use Illuminate\Support\Facades\Route; Route::get("customers", function () { App\Run::run(); });');
        $this->write('app/Run.php', '<?php namespace App; use Illuminate\Support\Facades\DB; class Run { public static function run() { DB::table("customers")->get(); Job::dispatchSync(); } }');
        $this->write('routes/console.php', '<?php use Illuminate\Support\Facades\Schedule; Schedule::command("task:run")->hourly();');
        $this->commit();
        $service = new ArchitectureRevisionDiff($this->tempPath);
        $report = $service->compare($before, 'HEAD');
        $this->assertTrue($report['ok']);
        foreach (['http', 'execution', 'data'] as $channel) {
            $this->assertGreaterThan(0, $report['totals'][$channel], $channel);
            foreach ($report['changes'][$channel] as $row) {
                $witness = $row['before'] ?? $row['after'];
                $this->assertNotEmpty($witness['path'] ?? $witness['source']['path'] ?? null);
            }
        }
        $this->write('app/Broken.php', '<?php class Broken {');
        $partial = $service->compare($before);
        $this->assertTrue($partial['ok']);
        $this->assertSame('incomplete', $partial['analysis']['status']);
        $this->assertTrue($partial['analysis']['lower_bounds']);
        $this->assertNotEmpty($partial['notices']);
        $this->assertGreaterThan(0, $partial['totals']['data']);
    }

    public function test_untracked_addition_and_tracked_deletion_are_identified_and_invalid_pairs_fail(): void
    {
        $this->fixture();
        unlink($this->tempPath.'/app/Run.php');
        $this->write('app/Untracked.php', '<?php namespace App; class Untracked {}');
        $report = (new ArchitectureRevisionDiff($this->tempPath))->compare('HEAD');
        $this->assertTrue($report['ok']);
        $classes = array_filter($report['changes']['symbols'], static fn (array $row): bool => ($row['before'] ?? $row['after'])['php_kind'] === 'class');
        $this->assertContains('added', array_column($classes, 'change'));
        $this->assertContains('removed', array_column($classes, 'change'));
        $this->assertFalse((new ArchitectureRevisionDiff($this->tempPath))->compare('HEAD', manualPairs: ['App\\Run' => 'App\\Untracked', 'App\\Other' => 'App\\Untracked'])['ok']);
        $this->assertFalse((new ArchitectureRevisionDiff($this->tempPath.'/absent'))->compare('HEAD')['ok']);
    }

    public function test_working_mutation_during_analysis_requires_rerun(): void
    {
        $this->fixture();
        for ($i = 0; $i < 30; $i++) {
            $this->write('app/Extra'.$i.'.php', '<?php namespace App; class Extra'.$i.' { public function run() { Other::work(); } }');
        }
        $ready = $this->tempPath.'/.mutation-ready';
        $script = 'file_put_contents($argv[2], "ready"); for ($i=0; $i<2000; $i++) { file_put_contents($argv[1], "<?php namespace App; class Run { public function run() { return ".$i."; } }".str_repeat(" ", $i)); usleep(5000); }';
        $writer = new Process([PHP_BINARY, '-r', $script, $this->tempPath.'/app/Run.php', $ready]);
        $writer->start();
        try {
            for ($i = 0; $i < 200 && ! file_exists($ready); $i++) {
                usleep(10000);
            }
            $this->assertFileExists($ready);
            $report = (new ArchitectureRevisionDiff($this->tempPath))->compare('HEAD');
            $this->assertTrue($report['ok']);
            $this->assertFalse($report['analysis']['fresh']);
            $this->assertTrue($report['analysis']['lower_bounds']);
            $this->assertSame(['rerun:revision-diff'], $report['next']);
            $this->assertContains('E_REVISION_STALE', array_column($report['notices'], 'code'));
        } finally {
            $writer->stop(0);
        }
    }

    public function test_unknown_execution_witness_identifies_after_side_and_source(): void
    {
        $this->fixture();
        $this->write('app/Run.php', '<?php namespace App; class Run { public function run($service) { $service->unknownMethod(); } }');
        $report = (new ArchitectureRevisionDiff($this->tempPath))->compare('HEAD');
        $this->assertTrue($report['ok']);
        $this->assertFalse($report['analysis']['channels']['execution']['after_complete']);
        $unknown = array_values(array_filter($report['notices'], static fn (array $notice): bool => ($notice['code'] ?? null) === 'E_REVISION_EXECUTION_UNKNOWN'));
        $this->assertCount(1, $unknown);
        $this->assertSame('after', $unknown[0]['side']);
        $this->assertSame('app/Run.php', $unknown[0]['path']);
        $this->assertStringContainsString('unknownMethod', $unknown[0]['reason']);
    }

    private function fixture(): void
    {
        $this->write('app/Run.php', '<?php namespace App; class Run { public function run() { Other::work(); } }');
        $this->write('app/Other.php', '<?php namespace App; class Other { public static function work() {} public static function changed() {} }');
        $this->git('init', '-q');
        $this->commit();
    }

    private function write(string $path, string $contents): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $contents);
    }

    private function commit(): void
    {
        $this->git('add', '.');
        $this->git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'fixture');
    }

    private function git(string ...$args): string
    {
        $process = new Process(['git', '-C', $this->tempPath, ...$args]);
        $this->assertSame(0, $process->run(), $process->getErrorOutput());

        return trim($process->getOutput());
    }
}
