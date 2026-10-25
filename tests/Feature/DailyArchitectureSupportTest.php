<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CacheStatus;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Context\ArchitectureContext;
use GracjanKubicki\ArchitectureKit\Context\GraphPage;
use GracjanKubicki\ArchitectureKit\Discovery\ArchitectureDiscovery;
use GracjanKubicki\ArchitectureKit\Impact\ArchitecturePath;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ArchitectureGraph;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ArchitectureSearch;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Guard;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\RevisionDiff;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Target;
use GracjanKubicki\ArchitectureKit\Revision\RevisionDiffSchema;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Target\TargetSchema;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class DailyArchitectureSupportTest extends TestCase
{
    public function test_context_map_scope_cache_and_same_stat_freshness_include_own_extra_roots(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Discount.php', '<?php namespace App; class Discount {}');
        $cache = new ProjectGraphCache($files, $this->tempPath);
        $query = new ArchitectureContext($files, $this->tempPath, cache: $cache);
        $this->assertSame([], $query->inspect('App\\Discount', [])->dependents);
        $this->assertSame(CacheStatus::Fresh, $query->inspect('App\\Discount', [])->cacheStatus);
        $files->ensureDirectoryExists($this->tempPath.'/Modules');
        $files->put($this->tempPath.'/Modules/User.php', '<?php namespace Modules; class User { public function use(\\App\\Discount $d) {} }');
        $module = $query->inspect('App\\Discount', []);
        $this->assertContains('Modules\\User', array_column($module->dependents, 'symbol'));
        $this->assertContains('Modules', $module->analysis['map_paths']);
        $composer = json_decode($files->get($this->tempPath.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $files->ensureDirectoryExists($this->tempPath.'/extras');
        $files->put($this->tempPath.'/extras/ExtraUser.php', '<?php namespace Extra; class ExtraUser { public function use(\\App\\Discount $d) {} }');
        $composer['autoload']['psr-4'] = ['Extra\\' => 'extras/'];
        $files->put($this->tempPath.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        $extra = $query->inspect('App\\Discount', []);
        $this->assertContains('Extra\\ExtraUser', array_column($extra->dependents, 'symbol'));
        $this->assertContains('extras', $extra->analysis['map_paths']);
        $target = $this->tempPath.'/extras/ExtraUser.php';
        $before = $files->get($target);
        $mtime = filemtime($target);
        $after = str_replace('ExtraUser', 'OtherUser', $before);
        $files->put($target, $after);
        touch($target, $mtime);
        $this->assertSame(strlen($before), strlen($after));
        $changed = $query->inspect('App\\Discount', []);
        $this->assertContains('Extra\\OtherUser', array_column($changed->dependents, 'symbol'));
        $this->assertNotContains('Extra\\ExtraUser', array_column($changed->dependents, 'symbol'));
        $this->assertTrue($changed->analysis['checks_complete']);

        $mutating = new class($target, $before, $mtime) extends Filesystem
        {
            public function __construct(private string $target, private string $replacement, private int $mtime) {}

            public function get($path, $lock = false)
            {
                $contents = parent::get($path, $lock);
                if ($path === $this->target) {
                    file_put_contents($path, $this->replacement);
                    touch($path, $this->mtime);
                }

                return $contents;
            }
        };
        $stale = (new ArchitectureContext($mutating, $this->tempPath))->inspect('App\\Discount', []);
        $this->assertFalse($stale->analysis['checks_complete']);
        $this->assertContains('changed_inputs', array_column($stale->analysis['notices'], 'code'));
        $this->assertSame($mtime, filemtime($target));
        $this->assertSame(strlen($before), filesize($target));
    }

    public function test_target_and_revision_entry_points_deny_deep_work_and_file_rules_only_read_declarations(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app/Actions');
        $files->ensureDirectoryExists($this->tempPath.'/.architecture-kit');
        $files->put($this->tempPath.'/app/Actions/One.php', '<?php namespace App\\Actions; class One { public function handle() {} }');
        $files->put($this->tempPath.'/config/architectures.php', '<?php return ["enabled"=>["actions"]];');
        $targetPath = $this->tempPath.'/.architecture-kit/target.json';
        $declaration = ['id' => 'daily', 'version' => '1', 'paths' => ['app']];
        $files->put($targetPath, json_encode($declaration, JSON_THROW_ON_ERROR));
        $stages = [];
        AnalysisRuntime::activate(AnalysisRuntime::session()->withStageObserver(function ($stage) use (&$stages): void {
            $stages[] = $stage;
        }));
        $home = $this->tempPath.'-consent';
        $previous = getenv('ARCHITECTURE_KIT_HOME');
        putenv('ARCHITECTURE_KIT_HOME='.$home);
        try {
            foreach (['target', 'revision-diff'] as $command) {
                foreach ([[], ['--mode' => 'deep', '--task' => 'daily']] as $options) {
                    self::assertSame(1, Artisan::call('architecture-kit:'.$command, [...$options, '--agent' => true]));
                    $denied = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
                    self::assertFalse($denied['ok']);
                    $this->assertAssessmentSchema($denied, $command === 'target' ? TargetSchema::get() : RevisionDiffSchema::get());
                    self::assertSame('not_run', $denied['analysis']['status']);
                    self::assertFalse($denied['analysis']['checks_complete']);
                    self::assertStringContainsString('consent', $denied['msg']);
                }
            }
            foreach ([Target::class, RevisionDiff::class] as $tool) {
                foreach ([[], ['analysis_mode' => 'deep', 'task' => 'daily']] as $options) {
                    ArchitectureKitServer::tool($tool, ['from' => 'HEAD', ...$options])->assertOk()
                        ->assertStructuredContent(fn ($json) => $json->where('ok', false)->where('analysis.status', 'not_run')->where('analysis.deep_run', false)->etc());
                }
            }
            self::assertSame(0, Artisan::call('architecture-kit:guard', ['--target' => true, '--agent' => true]));
            $guard = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            self::assertFalse($guard['ok']);
            self::assertSame('not_run', $guard['analysis']['status']);
            self::assertFalse($guard['control']['blocking']);
            ArchitectureKitServer::tool(Guard::class, ['target' => true])->assertOk()
                ->assertStructuredContent(fn ($json) => $json->where('ok', false)->where('analysis.status', 'not_run')->etc());
            foreach ([json_encode($declaration, JSON_THROW_ON_ERROR), '{invalid', json_encode([...$declaration, 'reference' => '../outside.json'], JSON_THROW_ON_ERROR)] as $i => $source) {
                $files->put($targetPath, $source);
                $exit = Artisan::call('architecture-kit:file-rules', ['path' => 'app/Actions/Future.php', '--agent' => true]);
                $output = Artisan::output();
                self::assertSame(0, $exit, $output);
                $rules = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
                self::assertNotEmpty($rules['rules']);
                self::assertSame('not_run', $rules['target']['analysis']['status']);
                self::assertFalse($rules['target']['analysis']['deep_run']);
                self::assertArrayNotHasKey('gate', $rules['target']);
                self::assertArrayNotHasKey('reference_candidate', $rules['target']);
                if ($i === 0) {
                    self::assertSame('daily', $rules['target']['declarations']['id']);
                } else {
                    self::assertSame('E_TARGET_CONFIGURATION', $rules['target']['m']);
                    self::assertNotEmpty($rules['target']['msg']);
                }
            }
            self::assertSame(0, Artisan::call('architecture-kit:search', ['query' => 'One', '--agent' => true]));
            self::assertNotEmpty(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['candidates']);
            self::assertSame([], array_values(array_filter($stages, static fn ($stage) => str_starts_with($stage, 'deep.'))));
            $files->put($targetPath, json_encode($declaration, JSON_THROW_ON_ERROR));
            self::assertSame(0, Artisan::call('architecture-kit:analysis-consent', ['task' => 'daily', '--confirm' => true]));
            self::assertSame(0, Artisan::call('architecture-kit:target', ['--mode' => 'deep', '--task' => 'daily', '--agent' => true]));
            $deep = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($deep['ok'], json_encode($deep));
            $this->assertAssessmentSchema($deep, TargetSchema::get());
            self::assertSame('deep', $deep['analysis']['mode']);
            self::assertTrue($deep['analysis']['deep_run']);
            self::assertContains('deep.revision.facts', $stages);
            ArchitectureKitServer::tool(Target::class, ['analysis_mode' => 'deep', 'task' => 'daily'])->assertOk()
                ->assertStructuredContent(fn ($json) => $json->where('ok', true)->where('analysis.mode', 'deep')->etc());
            foreach (['target', 'revision-diff'] as $command) {
                self::assertSame(1, Artisan::call('architecture-kit:'.$command, ['--mode' => 'deep', '--task' => 'daily', '--limit' => '-1', '--agent' => true]));
                $invalid = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($command === 'target' ? 'E_TARGET_INPUT' : 'E_REVISION_INPUT', $invalid['m']);
                self::assertArrayNotHasKey('analysis', $invalid);
                $this->assertAssessmentSchema($invalid, $command === 'target' ? TargetSchema::get() : RevisionDiffSchema::get());
            }
        } finally {
            putenv($previous === false ? 'ARCHITECTURE_KIT_HOME' : 'ARCHITECTURE_KIT_HOME='.$previous);
            $files->deleteDirectory($home);
        }
    }

    /** @param array<string, mixed> $payload
     * @param array<string, mixed> $schema */
    private function assertAssessmentSchema(array $payload, array $schema): void
    {
        $variants = array_values(array_filter($schema['oneOf'], static fn ($variant) => $variant['properties']['ok']['const'] === $payload['ok']
            && (! isset($variant['properties']['configured']) || $variant['properties']['configured']['const'] === ($payload['configured'] ?? null))));
        self::assertCount(1, $variants);
        $variant = $variants[0];
        self::assertFalse($variant['additionalProperties']);
        self::assertSame([], array_values(array_diff($variant['required'], array_keys($payload))));
        self::assertSame([], array_values(array_diff(array_keys($payload), array_keys($variant['properties']))));
        foreach ($variant['properties'] as $key => $property) {
            if (array_key_exists('const', $property)) {
                self::assertSame($property['const'], $payload[$key]);
            }
        }
        if (! $payload['ok'] && isset($payload['analysis'])) {
            $analysis = $variant['properties']['analysis'];
            self::assertFalse($analysis['additionalProperties']);
            self::assertSame([], array_values(array_diff($analysis['required'], array_keys($payload['analysis']))));
            self::assertSame([], array_values(array_diff(array_keys($payload['analysis']), array_keys($analysis['properties']))));
            foreach ($analysis['properties'] as $key => $property) {
                self::assertSame($property['const'], $payload['analysis'][$key]);
            }
        }
    }

    public function test_graph_analysis_metadata_counts_toward_the_complete_page_byte_budget(): void
    {
        $page = static function (int $length, ?array $analysis = null): array {
            return GraphPage::make('architecture-search', ['limit' => 1], 'snapshot', ['app'], [],
                ['status' => 'found', 'candidates' => [['id' => 'one', 'name' => str_repeat('x', $length)], ['id' => 'two', 'name' => 'next']],
                    ...($analysis === null ? [] : ['analysis' => $analysis])], 'candidates');
        };
        $low = 0;
        $high = GraphPage::MAX_BYTES;
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if (count($page($mid)['result']['candidates']) === 1) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }
        $before = $page($low);
        $analysis = ['mode' => 'basic', 'checks_complete' => true, 'deep_run' => false];
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        self::assertGreaterThan(GraphPage::MAX_BYTES, strlen(json_encode([...$before, 'analysis' => $analysis], $flags)));
        $bounded = $page($low, $analysis);
        self::assertLessThanOrEqual(GraphPage::MAX_BYTES, strlen(json_encode($bounded, $flags)));
        self::assertSame($analysis, $bounded['analysis']);
        self::assertTrue($bounded['truncated']);
        self::assertSame([], $bounded['result']['candidates']);
        self::assertTrue($bounded['analysis']['checks_complete']); // Display truncation is not an analysis failure.
    }

    public function test_local_artisan_and_mcp_use_scoped_consent_without_standalone_context(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/One.php', '<?php namespace App; class One {}');
        $files->put($this->tempPath.'/config/architectures.php', '<?php return ["enabled"=>["actions"]];');
        $home = $this->tempPath.'-consent';
        $previous = getenv('ARCHITECTURE_KIT_HOME');
        putenv('ARCHITECTURE_KIT_HOME='.$home);
        try {
            self::assertNull(RepositoryContext::current());
            self::assertSame(0, Artisan::call('architecture-kit:audit', ['--agent' => true, '--mode' => 'deep', '--task' => 'local']));
            $denied = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            self::assertFalse($denied['ok']);
            self::assertStringContainsString('consent', $denied['msg']);
            ArchitectureKitServer::tool(ArchitectureSearch::class, ['query' => 'One', 'analysis_mode' => 'deep', 'task' => 'local'])
                ->assertOk()->assertStructuredContent(fn ($json) => $json->where('ok', false)->etc());
            self::assertSame(0, Artisan::call('architecture-kit:analysis-consent', ['task' => 'local', '--confirm' => true]));
            self::assertTrue(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['ok']);
            self::assertSame(0, Artisan::call('architecture-kit:audit', ['--agent' => true, '--mode' => 'deep', '--task' => 'local']));
            $deep = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('deep', $deep['analysis']['mode']);
            self::assertTrue($deep['analysis']['deep_run']);
            self::assertSame(0, Artisan::call('architecture-kit:audit', ['--agent' => true, '--mode' => 'deep', '--task' => 'other']));
            self::assertFalse(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['ok']);
            self::assertSame(0, Artisan::call('architecture-kit:search', ['query' => 'One', '--agent' => true]));
            self::assertSame('basic', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['analysis']['mode']);
        } finally {
            putenv($previous === false ? 'ARCHITECTURE_KIT_HOME' : 'ARCHITECTURE_KIT_HOME='.$previous);
            $files->deleteDirectory($home);
        }
    }

    public function test_basic_aliases_keep_source_map_and_report_real_gaps_without_deep_work(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/DiscountService.php', '<?php namespace App; class DiscountService { public function apply() {} }');
        $files->put($this->tempPath.'/app/OrderService.php', '<?php namespace App; class OrderService { public function send(DiscountService $d) { $d->apply(); } }');
        $files->put($this->tempPath.'/config/architectures.php', '<?php return ["enabled"=>["actions"]];');
        $stages = [];
        AnalysisRuntime::activate(AnalysisRuntime::session()->withStageObserver(function ($stage) use (&$stages): void {
            $stages[] = $stage;
        }));
        $search = (new ArchitectureDiscovery($files, $this->tempPath))->search('DiscountService');
        self::assertTrue($search['analysis']['checks_complete']);
        self::assertSame('basic', $search['analysis']['mode']);
        self::assertNotEmpty($search['candidates']);
        $context = (new ArchitectureContext($files, $this->tempPath))->inspect('App\\DiscountService', []);
        self::assertTrue($context->analysis['checks_complete']);
        self::assertNotEmpty($context->dependents);
        $path = (new ArchitecturePath($files, $this->tempPath))->inspect('App\\OrderService::send', 'App\\DiscountService::apply');
        self::assertNotEmpty($path['source_path']['records']);
        ArchitectureKitServer::tool(ArchitectureSearch::class, ['query' => 'DiscountService'])->assertOk()->assertStructuredContent(fn ($json) => $json->where('analysis.mode', 'basic')->etc());
        ArchitectureKitServer::tool(ArchitectureGraph::class, ['subject' => 'App\\DiscountService::apply', 'mode' => 'impact'])->assertOk()->assertStructuredContent(fn ($json) => $json->where('analysis.deep_run', false)->etc());
        self::assertSame([], array_values(array_filter($stages, static fn ($stage) => str_starts_with($stage, 'deep.'))));

        $files->put($this->tempPath.'/app/Broken.php', '<?php class Broken { invalid');
        $context = (new ArchitectureContext($files, $this->tempPath))->inspect('App\\DiscountService', [], limit: 0);
        self::assertFalse($context->analysis['checks_complete']);
        self::assertGreaterThan(0, $context->analysis['total']);
        self::assertSame([], $context->analysis['notices']);
        self::assertTrue($context->analysis['truncated']);
        $exit = Artisan::call('architecture-kit:context', ['subject' => 'App\\DiscountService', '--agent' => true]);
        $raw = Artisan::output();
        self::assertSame(0, $exit, $raw);
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['analysis']['checks_complete']);
        self::assertContains('app/Broken.php', array_column($payload['analysis']['notices'], 'path'));
        $path = (new ArchitecturePath($files, $this->tempPath))->inspect('App\\OrderService::send', 'App\\DiscountService::apply');
        self::assertFalse($path['analysis']['checks_complete']);
        self::assertContains('app/Broken.php', array_column($path['diagnostics']['items'], 'path'));
    }
}
