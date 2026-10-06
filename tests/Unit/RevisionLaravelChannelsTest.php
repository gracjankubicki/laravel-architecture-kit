<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\Revision\RevisionChanges;
use GracjanKubicki\ArchitectureKit\Revision\RevisionConfiguration;
use GracjanKubicki\ArchitectureKit\Revision\RevisionFacts;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use PHPUnit\Framework\TestCase;

final class RevisionLaravelChannelsTest extends TestCase
{
    public function test_all_laravel_channels_ignore_comment_line_and_offset_shifts(): void
    {
        $files = $this->fixture();
        $before = $this->facts($files);
        $shifted = array_map(static fn (string $source): string => str_replace('<?php', "<?php\n// shifted\n", $source), $files);
        $after = $this->facts($shifted);
        $changes = RevisionChanges::compare($before, $after);
        foreach (['symbols', 'structure', 'execution', 'http', 'data', 'transitions'] as $channel) {
            $this->assertSame([], $changes[$channel], $channel);
        }
        $this->assertNotEmpty($before->http['routes']);
        $kinds = array_column(array_merge([], ...array_values($before->data->links->out)), 'kind');
        $this->assertContains('job-handler', $kinds);
        $this->assertContains('event-listener', $kinds);
        $this->assertContains('artisan-command', $kinds);
        $this->assertContains('schedule-task', $kinds);
        $this->assertContains('orders', array_column($before->data->extractor->effects, 'table'));
    }

    public function test_real_http_execution_and_data_changes_keep_conditions_and_source_witnesses(): void
    {
        $files = $this->fixture();
        $before = $this->facts($files);
        $files['routes/web.php'] = str_replace('old', 'new', $files['routes/web.php']);
        $files['app/Work.php'] = str_replace('Job::dispatch()', 'Job::dispatchSync()', $files['app/Work.php']);
        $files['app/Work.php'] = str_replace('table("orders")', 'table("customers")', $files['app/Work.php']);
        $changes = RevisionChanges::compare($before, $this->facts($files));
        $this->assertNotEmpty($changes['http']);
        $this->assertNotEmpty($changes['execution']);
        $this->assertNotEmpty($changes['data']);
        foreach ($changes['data'] as $row) {
            $effect = $row['before'] ?? $row['after'];
            $this->assertArrayHasKey('conditions', $effect);
            $this->assertArrayHasKey('certainty', $effect);
            $this->assertArrayHasKey('path', $effect);
            $this->assertArrayHasKey('line', $effect);
        }
    }

    public function test_move_and_rename_preserve_laravel_relations_and_data_helper_paths(): void
    {
        $files = $this->fixture();
        $before = $this->facts($files);
        $changed = array_map(static fn (string $source): string => str_replace('Work', 'RenamedWork', $source), $files);
        $changed['app/RenamedWork.php'] = $changed['app/Work.php'];
        unset($changed['app/Work.php']);
        $changes = RevisionChanges::compare($before, $this->facts($changed));
        $this->assertSame('app\\renamedwork', $changes['pairs']['app\\work']);
        foreach (['structure', 'execution', 'http', 'data'] as $channel) {
            $this->assertSame([], $changes[$channel], $channel);
        }
    }

    public function test_excluded_sources_are_scope_changes_in_all_laravel_channels(): void
    {
        $files = $this->fixture();
        $before = $this->facts($files);
        $files['config/architectures.php'] = '<?php return ["audit" => ["exclude" => ["app/Work.php", "routes/web.php", "routes/console.php"]]];';
        $changes = RevisionChanges::compare($before, $this->facts($files));
        foreach (['symbols', 'structure', 'http', 'data'] as $channel) {
            $scoped = array_filter($changes[$channel], static fn (array $row): bool => in_array(($row['before'] ?? $row['after'])['path'] ?? ($row['before'] ?? $row['after'])['source']['path'] ?? '', ['app/Work.php', 'routes/web.php', 'routes/console.php'], true));
            $this->assertNotEmpty($scoped, $channel);
            $this->assertSame(['scope_left'], array_values(array_unique(array_column($scoped, 'change'))), $channel);
        }
    }

    public function test_split_class_file_preserves_relations_in_structure_execution_and_data(): void
    {
        $a = 'class A { public static function run() { B::run(); \Illuminate\Support\Facades\DB::table("orders")->get(); } }';
        $b = 'class B { public static function run() {} }';
        $files = ['app/Many.php' => '<?php namespace App; '.$a.' '.$b];
        $before = $this->facts($files);
        $after = $this->facts(['app/Many.php' => '<?php namespace App; '.$b, 'app/A.php' => '<?php namespace App; '.$a]);
        $changes = RevisionChanges::compare($before, $after);
        $this->assertContains('moved_or_renamed', array_column($changes['symbols'], 'change'));
        foreach (['structure', 'execution', 'data'] as $channel) {
            $this->assertSame([], $changes[$channel], $channel);
        }
        $this->assertNotEmpty($before->data->extractor->effects);
        $this->assertNotEmpty($before->data->links->out);
    }

    public function test_snapshot_custom_command_directory_is_resolved_without_physical_filesystem(): void
    {
        $files = ['bootstrap/app.php' => '<?php use Illuminate\Foundation\Application; return Application::configure(basePath: dirname(__DIR__))->withCommands(["custom/Commands"]);',
            'custom/Commands/Task.php' => '<?php namespace Custom; class Task extends \Illuminate\Console\Command { protected $signature = "custom:run"; public function handle() {} }'];
        $facts = $this->facts($files);
        $this->assertSame([], $facts->data->facts['notices']);
        $this->assertArrayHasKey('custom\task', $facts->data->facts['classes']);
        $this->assertNotEmpty($facts->data->links->seeds);
    }

    public function test_unknown_execution_calls_remain_visible_with_source_and_incomplete_channel(): void
    {
        $before = $this->facts(['app/Run.php' => '<?php namespace App; class Run { public function run() {} }']);
        $after = $this->facts(['app/Run.php' => '<?php namespace App; class Run { public function run($service) { $service->unknownMethod(); } }']);
        $this->assertFalse($after->channels['execution']);
        $unknown = array_filter($after->notices, static fn (array $notice): bool => ($notice['code'] ?? null) === 'E_REVISION_EXECUTION_UNKNOWN');
        $this->assertCount(1, $unknown);
        $this->assertSame('app/Run.php', array_values($unknown)[0]['path']);
        $changes = RevisionChanges::compare($before, $after);
        $rows = array_filter($changes['execution'], static fn (array $row): bool => ($row['after']['kind'] ?? null) === 'unresolved-call');
        $this->assertCount(1, $rows);
        $row = array_values($rows)[0]['after'];
        $this->assertSame('app/Run.php', $row['path']);
        $this->assertStringContainsString('unknownMethod', $row['reason']);
        $this->assertSame('unresolved', $row['certainty']);
    }

    /** @return array<string, string> */
    private function fixture(): array
    {
        return [
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"app/"}}}',
            'bootstrap/app.php' => '<?php use Illuminate\Foundation\Application; return Application::configure(basePath: dirname(__DIR__))->withRouting(web: __DIR__."/../routes/web.php", commands: __DIR__."/../routes/console.php")->withCommands([App\Console\Commands\Task::class]);',
            'bootstrap/providers.php' => '<?php return [App\Providers\Events::class];',
            'routes/web.php' => '<?php use Illuminate\Support\Facades\Route; Route::get("old", function () { App\Work::run(); });',
            'routes/console.php' => '<?php use Illuminate\Support\Facades\Artisan; use Illuminate\Support\Facades\Schedule; Artisan::command("callback:run", function () { App\Work::run(); }); Schedule::command("task:run")->daily();',
            'app/Work.php' => '<?php namespace App; use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Artisan; class Work { public static function run() { DB::table("orders")->get(); Job::dispatch(); event(new Paid); Artisan::call("task:run"); } }',
            'app/Job.php' => '<?php namespace App; class Job implements \Illuminate\Contracts\Queue\ShouldQueue { use \Illuminate\Foundation\Bus\Dispatchable; public function handle() { Work::run(); } }',
            'app/Paid.php' => '<?php namespace App; class Paid { use \Illuminate\Foundation\Events\Dispatchable; }',
            'app/Listener.php' => '<?php namespace App; class Listener { public function handle(Paid $event) {} }',
            'app/Providers/Events.php' => '<?php namespace App\Providers; use Illuminate\Support\Facades\Event; class Events extends \Illuminate\Support\ServiceProvider { public function boot() { Event::listen(\App\Paid::class, [\App\Listener::class, "handle"]); } }',
            'app/Console/Commands/Task.php' => '<?php namespace App\Console\Commands; class Task extends \Illuminate\Console\Command { protected $signature = "task:run"; public function handle() { \App\Work::run(); } }',
        ];
    }

    /** @param array<string, string> $files */
    private function facts(array $files): RevisionFacts
    {
        $source = new SourceSnapshot('git', str_repeat('a', 40), 'hash', $files, [], array_keys($files));

        return RevisionFacts::collect($source, RevisionConfiguration::from($source));
    }
}
