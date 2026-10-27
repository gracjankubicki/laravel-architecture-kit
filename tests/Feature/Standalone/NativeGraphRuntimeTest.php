<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature\Standalone;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphEpoch;
use GracjanKubicki\ArchitectureKit\Context\ArchitectureContext;
use GracjanKubicki\ArchitectureKit\Discovery\ArchitectureDiscovery;
use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimeInstallation;
use GracjanKubicki\ArchitectureKit\Standalone\GraphRuntime;
use GracjanKubicki\ArchitectureKit\Standalone\Host;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeAdmission;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use GracjanKubicki\ArchitectureKit\Tests\Support\EngineFixture;
use GracjanKubicki\ArchitectureKit\Tests\Support\GraphRuntimeCleanup;
use GracjanKubicki\ArchitectureKit\Tests\Support\WorktreeMcpClient;
use GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\Watch\RuntimeFixture;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Own Composer fixture only. Does not create Git repositories or worktrees. */
final class NativeGraphRuntimeTest extends TestCase
{
    private string $root;

    private string $home;

    private string $artifacts;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(6));
        $this->artifacts = (getenv('ARCHITECTURE_KIT_NATIVE_FEATURE_ARTIFACTS') ?: dirname(__DIR__, 3).'/build/graph-performance-rust/R04/feature-runs').'/'.$id;
        mkdir($this->artifacts, 0700, true);
        $this->root = realpath(sys_get_temp_dir()).'/kit-r04-source-'.$id;
        $this->home = realpath(sys_get_temp_dir()).'/kit-r04-home-'.$id;
        foreach (['app', 'routes', 'bootstrap', 'config', 'database', 'resources/views', 'tests', '.architecture-kit'] as $dir) {
            mkdir($this->root.'/'.$dir, 0700, true);
        }
        file_put_contents($this->root.'/composer.json', '{"name":"fixture/native","autoload":{"psr-4":{"App\\\\":"app/"}}}');
        file_put_contents($this->root.'/.architecture-kit/settings.json', json_encode(['enabled' => ['services'], 'analysis' => ['engine' => EngineFixture::selected(), 'php_version' => '8.3'], 'audit' => ['cache' => true],
            'graph_runtime' => ['representation' => 'rust', 'resident_budget_bytes' => 268435456, 'transition_budget_bytes' => 536870912]], JSON_THROW_ON_ERROR));
        file_put_contents($this->root.'/app/A.php', '<?php namespace App; class A { function alpha() {} }');
        if (EngineFixture::selected() === 'mago') {
            EngineFixture::install($this->home);
        }
        $body = file_get_contents(RuntimeFixture::native());
        $manifest = RuntimeFixture::manifest($body);
        (new RuntimeInstallation($this->home, static fn (string $url): string => str_ends_with($url, 'manifest.json') ? json_encode($manifest, JSON_THROW_ON_ERROR) : $body))->install();
    }

    protected function tearDown(): void
    {
        if (isset($this->home)) {
            foreach (glob($this->home.'/repositories/*/runtime/*/*.{json,stderr,stdout}', GLOB_BRACE) ?: [] as $path) {
                $target = $this->artifacts.'/'.basename(dirname($path)).'-'.basename($path);
                copy($path, $target);
                chmod($target, 0600);
            }
            foreach (['native-test-events.json', 'graph-resources-v2.json'] as $name) {
                if (is_file($this->home.'/'.$name)) {
                    copy($this->home.'/'.$name, $this->artifacts.'/'.$name);
                    chmod($this->artifacts.'/'.$name, 0600);
                }
            }
            GraphRuntimeCleanup::stop($this->home);
            (new Filesystem)->deleteDirectory($this->home);
            (new Filesystem)->deleteDirectory($this->root);
        }
        RepositoryContext::activate(null);
    }

    /** @return array<string, mixed> */
    private function cli(string ...$args): array
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', dirname(__DIR__, 2).'/Support/native-runtime-entrypoint.php', '--project', $this->root, '--tool-home', $this->home, ...$args, ...(in_array($args[0], ['search', 'context', 'path', 'impact', 'reach'], true) ? ['--agent'] : [])], timeout: 30);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function oracleContext(): RepositoryContext
    {
        $settings = json_decode(file_get_contents($this->root.'/.architecture-kit/settings.json'), true, flags: JSON_THROW_ON_ERROR);
        $settings['graph_runtime']['representation'] = 'php';
        $directory = $this->home.'/profiles/'.hash('sha256', $this->root);
        (new StateStore($directory, $this->home))->update('oracle', static fn () => $settings);

        return new RepositoryContext($this->root, $this->home, 'oracle');
    }

    public function test_ram_patch_retirement_preserves_live_owner_before_heartbeat(): void
    {
        $path = $this->root.'/.architecture-kit/settings.json';
        $settings = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $settings['audit']['cache'] = false;
        file_put_contents($path, json_encode($settings, JSON_THROW_ON_ERROR));
        $cold = $this->cli('search', 'alpha');
        $old = $cold['runtime']['job_id'];
        $state = new StateStore($this->home);
        $state->update('native-test-control', static fn () => ['barrier' => 'native.generation.retired', 'job_id' => $old]);
        try {
            file_put_contents($this->root.'/app/A.php', '<?php namespace App; class A { function bravo() {} }');
            $retiredEvent = $this->waitEvent('native.generation.retired', $old);
            $registry = $state->read('graph-resources-v2');
            $owner = $registry['residents'][$cold['runtime']['instance_token']] ?? null;
            self::assertNotNull($owner, 'G1 retired, coordinator held before heartbeat: G2 owner must remain registered.');
            self::assertSame($cold['runtime']['pid'], $owner['pid']);
            $context = new RepositoryContext($this->root, $this->home);
            $grant = new NativeAdmission($context, 'r2-owner-proof', true, 1, 0);
            try {
                $pids = array_column($grant->report['sample']['processes'], 'pid');
                self::assertContains($owner['pid'], $pids);
                self::assertContains($cold['runtime']['measurements']['native']['process_usage']['pid'], $pids);
                file_put_contents($this->artifacts.'/owner-before-heartbeat.json', json_encode(['phase' => 'coordinator_held_after_retirement_before_heartbeat', 'retirement_event' => $retiredEvent, 'registry' => $registry, 'admission' => $grant->report, 'native_child_pid' => $cold['runtime']['measurements']['native']['process_usage']['pid']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } finally {
                $grant->release();
            }
        } finally {
            $state->update('native-test-control', static fn () => []);
        }
        $new = $this->cli('search', 'bravo');
        self::assertNotEmpty($new['candidates']);
        self::assertSame($cold['runtime']['pid'], $new['runtime']['pid']);
        self::assertNotSame($old, $new['runtime']['job_id']);
    }

    private function excludedProductFixture(): array
    {
        $settingsPath = $this->root.'/.architecture-kit/settings.json';
        $settings = json_decode(file_get_contents($settingsPath), true, flags: JSON_THROW_ON_ERROR);
        $settings['graph']['exclude'] = ['app/GraphHidden.php'];
        $exclude = ['app/Ignored.php', 'app/Broken.php', 'tests/*'];
        $settings['audit']['exclude'] = $exclude;
        file_put_contents($settingsPath, json_encode($settings, JSON_THROW_ON_ERROR));
        file_put_contents($this->root.'/app/GraphHidden.php', '<?php namespace App\\One; class Target { public function alpha(int $value): int { return $value; } public function go(\\App\\Two\\Ignored $x) {} public function ambiguous(\\App\\One\\Dup $x) { $x->run(); } }');
        file_put_contents($this->root.'/app/Ignored.php', '<?php namespace App\\Two; class Ignored { public function call(\\App\\One\\Target $x) { return $x->alpha(1); } } namespace App\\One; class Dup { public function run() {} }');
        file_put_contents($this->root.'/app/Dup.php', '<?php namespace App\\One; class Dup { public function run() {} }');
        file_put_contents($this->root.'/app/Caller.php', '<?php namespace App; class Caller { public function call(\\App\\One\\Target $x) { return $x->alpha(1); } }');
        file_put_contents($this->root.'/app/Broken.php', '<?php class Broken {');
        file_put_contents($this->root.'/tests/TargetTest.php', '<?php namespace Tests; class TargetTest { public function testDirect(\\App\\One\\Target $x) {} }');
        file_put_contents($this->root.'/tests/IndirectTest.php', '<?php namespace Tests; class IndirectTest { public function testIndirect(\\App\\Two\\Ignored $x) {} }');

        return $exclude;
    }

    public function test_product_exclusions_filter_rules_assessment_and_diagnostics_cold_and_restore(): void
    {
        $exclude = $this->excludedProductFixture();
        $native = [];
        foreach (['cold', 'restore'] as $phase) {
            if ($phase === 'restore') {
                GraphRuntimeCleanup::stop($this->home);
            }
            $context = $this->cli('context', 'App\\One\\Target');
            GraphRuntimeCleanup::stop($this->home);
            $impact = $this->cli('impact', 'App\\One\\Target::alpha', '--change=delete');
            self::assertTrue($context['ok'], json_encode($context));
            self::assertTrue($impact['ok'], json_encode($impact));
            self::assertNotContains('W_NAMESPACE_CYCLE', array_column($context['violations'], 'code'));
            self::assertTrue($context['analysis']['checks_complete'], json_encode($context['analysis']));
            self::assertNotContains('app/Broken.php', array_column($context['analysis']['notices'], 'path'));
            self::assertNotContains('app/Ignored.php', array_column($impact['dependents'], 'path'));
            self::assertContains('app/Caller.php', array_column($impact['dependents'], 'path'));
            if ($phase === 'restore') {
                self::assertSame('restore', $context['analysis']['runtime']['execution_mode']);
                self::assertSame('restore', $impact['runtime']['execution_mode']);
            }
            $native[] = [$context, $impact];
            GraphRuntimeCleanup::stop($this->home);
        }
        RepositoryContext::activate($this->oracleContext());
        AnalysisRuntime::activate(null);
        Host::create($this->home);
        GraphRuntime::enable(false);
        $oracleContext = (new ArchitectureContext(new Filesystem, $this->root))->inspect('App\\One\\Target', [], $exclude);
        $oracleImpact = (new ArchitectureImpact(new Filesystem, $this->root))->inspect('App\\One\\Target::alpha', $exclude, change: 'delete');
        foreach ($native as [$context, $impact]) {
            foreach (['dependencies', 'dependents', 'violations', 'tests', 'next'] as $field) {
                self::assertSame($oracleContext->$field, $context[$field], 'context '.$field);
            }
            self::assertSame($oracleContext->analysis['notices'], $context['analysis']['notices'], 'per-file diagnostics, without composed helper diagnostics');
            foreach (['subject', 'dependents', 'dependencies', 'possible', 'references', 'tests', 'delete'] as $field) {
                self::assertSame($oracleImpact[$field] ?? null, $impact[$field] ?? null, 'impact '.$field);
            }
        }
    }

    public function test_included_syntax_diagnostics_remain_incomplete_and_match_php_after_restore(): void
    {
        file_put_contents($this->root.'/app/Broken.php', '<?php class Broken {');
        $native = [];
        foreach (['cold', 'restore'] as $phase) {
            if ($phase === 'restore') {
                GraphRuntimeCleanup::stop($this->home);
            }
            $result = $this->cli('context', 'App\\A');
            self::assertFalse($result['analysis']['checks_complete']);
            self::assertContains('app/Broken.php', array_column($result['analysis']['notices'], 'path'));
            if ($phase === 'restore') {
                self::assertSame('restore', $result['analysis']['runtime']['execution_mode']);
            }
            $native[] = $result;
        }
        RepositoryContext::activate($this->oracleContext());
        AnalysisRuntime::activate(null);
        Host::create($this->home);
        GraphRuntime::enable(false);
        $oracle = (new ArchitectureContext(new Filesystem, $this->root))->inspect('App\\A', []);
        foreach ($native as $result) {
            self::assertSame($oracle->analysis['notices'], $result['analysis']['notices']);
            self::assertSame($oracle->analysis['checks_complete'], $result['analysis']['checks_complete']);
        }
    }

    public function test_context_test_lookup_resolves_all_supported_selectors_before_lookup(): void
    {
        $exclude = $this->excludedProductFixture();
        $selectors = ['App\\One\\Target', '\\App\\One\\Target', 'app/GraphHidden.php', $this->root.'/app/GraphHidden.php'];
        $native = [];
        foreach (['cold', 'restore'] as $phase) {
            if ($phase === 'restore') {
                GraphRuntimeCleanup::stop($this->home);
            }
            foreach ($selectors as $selector) {
                $result = $this->cli('context', $selector);
                if ($phase === 'restore' && $selector === $selectors[0]) {
                    self::assertSame('restore', $result['analysis']['runtime']['execution_mode']);
                }
                $native[] = $result;
            }
        }
        RepositoryContext::activate($this->oracleContext());
        AnalysisRuntime::activate(null);
        Host::create($this->home);
        GraphRuntime::enable(false);
        $oracle = (new ArchitectureContext(new Filesystem, $this->root))->inspect('App\\One\\Target', [], $exclude);
        self::assertCount(2, $oracle->tests);
        foreach ($native as $context) {
            self::assertSame('App\\One\\Target', $context['subject']['name']);
            self::assertSame($oracle->tests, $context['tests']);
            self::assertSame($oracle->next, $context['next']);
        }
    }

    public function test_cache_clear_removes_disk_while_pinned_query_finishes_from_ram(): void
    {
        $cold = $this->cli('search', 'alpha');
        $query = $this->heldSearch('alpha', $cold['runtime']['job_id']);
        (new StateStore($this->home))->update('native-test-control', static fn () => ['barriers' => ['native.query.pinned' => $cold['runtime']['job_id'], 'build.before' => null]]);
        try {
            $cleared = $this->cli('cache-clear', '--agent');
            self::assertGreaterThanOrEqual(2, $cleared['removed']);
            self::assertSame([], glob($this->home.'/repositories/*/runtime/*/native-*/current.json'));
            self::assertSame([], glob($this->home.'/repositories/*/runtime/*/native-*/snapshot-*'));
            $old = $this->releaseSearch($query);
            self::assertSame($cold['runtime']['snapshot_id'], $old['runtime']['snapshot_id']);
            self::assertNotEmpty($old['candidates']);
            self::assertSame('unverified', $old['runtime']['freshness']);
            $fresh = $this->cli('search', 'alpha');
            self::assertNotEmpty($fresh['candidates']);
            self::assertNotSame($cold['runtime']['job_id'], $fresh['runtime']['job_id']);
        } finally {
            if ($query->isRunning()) {
                $this->releaseSearch($query);
            }
        }
    }

    public function test_explicit_cache_clear_removes_native_payload_and_old_writer_cannot_recreate_it(): void
    {
        $state = new StateStore($this->home);
        $state->update('native-test-control', static fn () => ['barrier' => 'native.cache.before']);
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', dirname(__DIR__, 2).'/Support/native-runtime-entrypoint.php', '--project', $this->root, '--tool-home', $this->home, 'search', 'alpha', '--agent'], timeout: 30);
        $process->start();
        try {
            $old = $this->waitEvent('native.cache.before');
            $cleared = $this->cli('cache-clear', '--agent');
            self::assertTrue($cleared['ok']);
            $state->update('native-test-control', static fn () => []);
            $process->wait();
            self::assertFalse(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['ok']);
            self::assertSame([], glob($this->home.'/repositories/*/runtime/*/native-*/snapshot-*'));
            self::assertSame([], glob($this->home.'/repositories/*/runtime/*/native-*/current.json'));
            $fresh = $this->cli('search', 'alpha');
            self::assertNotEmpty($fresh['candidates']);
            self::assertNotSame($old['job_id'], $fresh['runtime']['job_id']);
            self::assertCount(1, glob($this->home.'/repositories/*/runtime/*/native-*/snapshot-*'));
            self::assertCount(1, glob($this->home.'/repositories/*/runtime/*/native-*/current.json'));
            $state->update('native-test-control', static fn () => ['barrier' => 'build.before']);
            $cleared = $this->cli('cache-clear', '--agent');
            self::assertGreaterThanOrEqual(2, $cleared['removed']);
            self::assertSame([], glob($this->home.'/repositories/*/runtime/*/native-*/snapshot-*'));
            self::assertSame([], glob($this->home.'/repositories/*/runtime/*/native-*/current.json'));
        } finally {
            $state->update('native-test-control', static fn () => []);
            if ($process->isRunning()) {
                $process->wait();
            }
        }
    }

    public function test_context_audit_sees_a_cycle_beyond_neighbors_and_matches_legacy_classification(): void
    {
        foreach (['One' => 'Two', 'Two' => 'Three', 'Three' => 'Four', 'Four' => 'One'] as $from => $to) {
            mkdir($this->root.'/app/'.$from, 0700);
            file_put_contents($this->root.'/app/'.$from.'/Node.php', '<?php namespace App\\'.$from.'; class Node { function run(\\App\\'.$to.'\\Node $next) {} }');
        }
        $native = $this->cli('context', 'App\\One\\Node');
        self::assertTrue($native['ok'], json_encode($native));
        self::assertContains('W_NAMESPACE_CYCLE', array_column($native['violations'], 'code'));
        $context = $this->oracleContext();
        RepositoryContext::activate($context);
        AnalysisRuntime::activate(null);
        Host::create($this->home);
        GraphRuntime::enable(false);
        $oracle = (new ArchitectureContext(new Filesystem, $this->root))->inspect('App\\One\\Node', []);
        foreach (['dependencies', 'dependents', 'violations', 'classification', 'tests'] as $field) {
            self::assertSame($oracle->$field, $native[$field], $field);
        }
    }

    public function test_cache_disabled_keeps_a_live_base_for_selective_patch(): void
    {
        $path = $this->root.'/.architecture-kit/settings.json';
        $settings = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $settings['audit']['cache'] = false;
        file_put_contents($path, json_encode($settings, JSON_THROW_ON_ERROR));
        $cold = $this->cli('search', 'alpha');
        self::assertSame('skip', $cold['runtime']['cache']['write']['status']);
        $pid = $cold['runtime']['pid'];
        file_put_contents($this->root.'/app/A.php', '<?php namespace App; class A { function bravo() {} }');
        $until = microtime(true) + 10;
        do {
            usleep(100000);
            $next = $this->cli('search', 'bravo');
        } while (($next['candidates'] ?? []) === [] && microtime(true) < $until);
        self::assertNotEmpty($next['candidates'], json_encode($next));
        self::assertSame($pid, $next['runtime']['pid']);
        self::assertSame('selective_patch', $next['runtime']['execution_mode']);
        self::assertSame('skip', $next['runtime']['cache']['write']['status']);
        self::assertSame([], glob($this->home.'/repositories/*/runtime/*/native-*/snapshot-*'));
    }

    public function test_legacy_impact_signature_delete_move_reach_and_pages_survive_native_restore(): void
    {
        file_put_contents($this->root.'/app/A.php', '<?php namespace App; class A { public function alpha(int $x): int { return $x; } }');
        file_put_contents($this->root.'/app/B.php', '<?php namespace App; class B { function run(A $a) { return $a->alpha(1); } }');
        $baseline = [];
        foreach ([[], ['--change=delete'], ['--signature=public function alpha(int $x, string $other): int']] as $options) {
            $result = $this->cli('impact', 'App\\A::alpha', ...$options);
            self::assertTrue($result['ok'], json_encode($result));
            self::assertNotEmpty($result['dependents'], json_encode($result));
            $baseline[] = $result;
        }
        $move = $this->cli('impact', 'App\\A', '--change=move', '--target-class=App\\RenamedA');
        self::assertTrue($move['ok'], json_encode($move));
        self::assertArrayHasKey('move', $move);
        $reach = $this->cli('reach', 'App\\A::alpha', '--limit=1');
        self::assertTrue($reach['ok'], json_encode($reach));
        self::assertArrayHasKey('pagination', $reach['reach']);
        $paged = $this->cli('impact', 'App\\A::alpha', '--change=delete', '--page=1', '--limit=1');
        self::assertTrue($paged['ok'], json_encode($paged));
        self::assertArrayHasKey('pagination', $paged);
        GraphRuntimeCleanup::stop($this->home);
        $restored = $this->cli('impact', 'App\\A::alpha', '--change=delete');
        self::assertSame('restore', $restored['runtime']['execution_mode'], json_encode($restored));
        $phases = $restored['runtime']['measurements']['native']['phase_measurements']['phases'];
        self::assertArrayHasKey('restore', $phases);
        foreach (['foundation', 'compose', 'resolve', 'dependency', 'search'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $phases, 'Restore must not rebuild '.$forbidden);
        }
        foreach (['subject', 'dependents', 'dependencies', 'possible', 'references', 'class_context', 'tests', 'delete', 'classification'] as $field) {
            self::assertSame($baseline[1][$field] ?? null, $restored[$field] ?? null, $field);
        }
        $context = $this->oracleContext();
        RepositoryContext::activate($context);
        AnalysisRuntime::activate(null);
        Host::create($this->home);
        GraphRuntime::enable(false);
        $settings = DiscoverySettings::load(new Filesystem, $this->root);
        $oracle = (new ArchitectureImpact(new Filesystem, $this->root, $settings->scope, $settings->cache, $settings->fingerprint))->inspect('App\\A::alpha', $settings->exclude, change: 'delete');
        foreach (['subject', 'dependents', 'dependencies', 'possible', 'references', 'class_context', 'tests', 'delete', 'classification'] as $field) {
            self::assertSame($oracle[$field] ?? null, $restored[$field] ?? null, 'PHP parity '.$field);
        }
    }

    public function test_real_mcp_pages_keep_true_count_and_reject_an_old_cursor_after_patch(): void
    {
        for ($i = 0; $i < 50; $i++) {
            file_put_contents($this->root.'/app/Needle'.$i.'.php', '<?php namespace App; class Needle'.$i.' { function call() {} }');
        }
        $client = new WorktreeMcpClient(['--project', $this->root, '--tool-home', $this->home, 'mcp'], binary: dirname(__DIR__, 2).'/Support/native-runtime-entrypoint.php');
        try {
            $client->initialize();
            $first = $client->tool('architecture-search', ['query' => 'Needle', 'limit' => 3])['result']['structuredContent'];
            self::assertTrue($first['ok'], json_encode($first));
            self::assertGreaterThan(50, $first['result']['match_count']);
            self::assertCount(3, $first['result']['candidates']);
            self::assertLessThanOrEqual(65536, strlen(json_encode($first, JSON_THROW_ON_ERROR)));
            $nextArgs = $first['next']['arguments'];
            $second = $client->tool('architecture-search', $nextArgs)['result']['structuredContent'];
            self::assertSame($first['result']['match_count'], $second['result']['match_count']);
            self::assertNotSame($first['result']['candidates'][0]['id'], $second['result']['candidates'][0]['id']);
            file_put_contents($this->root.'/app/A.php', '<?php namespace App; class A { function changedMarker() {} }');
            $until = microtime(true) + 10;
            do {
                usleep(100000);
                $ready = $client->tool('architecture-search', ['query' => 'changedMarker'])['result']['structuredContent'];
            } while (($ready['result']['candidates'] ?? []) === [] && microtime(true) < $until);
            self::assertNotEmpty($ready['result']['candidates'], json_encode($ready));
            $stale = $client->tool('architecture-search', $nextArgs)['result']['structuredContent'];
            self::assertSame('stale_snapshot', $stale['status']);
            self::assertFalse($stale['ok']);
        } finally {
            $client->close();
        }
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        return (new StateStore($this->home))->read('native-test-events');
    }

    /** @return array<string, mixed> */
    private function waitEvent(string $stage, ?string $job = null): array
    {
        $until = microtime(true) + 10;
        do {
            foreach (array_reverse($this->events()) as $event) {
                if ($event['stage'] === $stage && ($job === null || $event['job_id'] === $job)) {
                    return $event;
                }
            }
            usleep(10000);
        } while (microtime(true) < $until);
        self::fail('Missing event '.$stage.' '.json_encode($this->events()));
    }

    private function heldSearch(string $query, string $job): Process
    {
        (new StateStore($this->home))->update('native-test-events', static fn () => []);
        (new StateStore($this->home))->update('native-test-control', static fn () => ['barrier' => 'native.query.pinned', 'job_id' => $job]);
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', dirname(__DIR__, 2).'/Support/native-runtime-entrypoint.php', '--project', $this->root, '--tool-home', $this->home, 'search', $query, '--agent'], timeout: 30);
        $process->start();
        $this->waitEvent('native.query.pinned', $job);

        return $process;
    }

    private function releaseSearch(Process $process): array
    {
        (new StateStore($this->home))->update('native-test-control', static fn () => []);
        $process->wait();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function enqueueNativeUpdate(): array
    {
        $context = new RepositoryContext($this->root, $this->home);
        RepositoryContext::activate($context);
        AnalysisRuntime::activate(null);
        Host::create($this->home);
        GraphRuntime::startProcess();
        $result = (new GraphRuntime($context))->request(['action' => 'prepare', 'job_id' => null]);
        self::assertArrayHasKey('job_id', $result, json_encode($result));

        return $result;
    }

    public function test_snapshot_worker_publishes_g2_while_real_g1_query_is_pinned(): void
    {
        $cold = $this->cli('search', 'alpha');
        $job = $cold['runtime']['job_id'];
        $query = $this->heldSearch('alpha', $job);
        try {
            $write = hrtime(true);
            file_put_contents($this->root.'/app/A.php', '<?php namespace App; class A { function bravo() {} }');
            $this->waitEvent('watch.event');
            $until = microtime(true) + 10;
            do {
                $next = $this->enqueueNativeUpdate();
                usleep(20000);
            } while (($next['job_id'] ?? $job) === $job && microtime(true) < $until);
            self::assertNotSame($job, $next['job_id'], json_encode($next));
            $published = $this->waitEvent('generation.publish', $next['job_id']);
            self::assertTrue($query->isRunning(), 'G1 query must still be held while G2 is published.');
            $old = $this->releaseSearch($query);
            self::assertSame($cold['runtime']['snapshot_id'], $old['runtime']['snapshot_id']);
            self::assertNotEmpty($old['candidates']);
            $new = $this->cli('search', 'bravo');
            self::assertNotEmpty($new['candidates'], json_encode($new));
            self::assertNotSame($cold['runtime']['pid'], $new['runtime']['pid']);
            self::assertSame($published['snapshot_id'], $new['runtime']['snapshot_id']);
            self::assertLessThan($this->waitEvent('query.after', $job)['wall_observed_at'], $published['wall_observed_at']);
            file_put_contents($this->artifacts.'/write-to-response.json', json_encode(['elapsed_ns' => hrtime(true) - $write, 'basis' => 'harness_t_write_to_full_new_response_including_wait', 'ac03' => 'NOT_EVALUATED'], JSON_THROW_ON_ERROR));
        } finally {
            if ($query->isRunning()) {
                $this->releaseSearch($query);
            }
        }
    }

    public function test_cacheoff_serializes_patch_behind_pinned_query_and_drains_response_before_ping(): void
    {
        $path = $this->root.'/.architecture-kit/settings.json';
        $settings = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $settings['audit']['cache'] = false;
        file_put_contents($path, json_encode($settings, JSON_THROW_ON_ERROR));
        $cold = $this->cli('search', 'alpha');
        $job = $cold['runtime']['job_id'];
        $query = $this->heldSearch('alpha', $job);
        try {
            $write = hrtime(true);
            file_put_contents($this->root.'/app/A.php', '<?php namespace App; class A { function bravo() {} }');
            $this->waitEvent('watch.event');
            $until = microtime(true) + 10;
            do {
                $next = $this->enqueueNativeUpdate();
                usleep(20000);
            } while (($next['job_id'] ?? $job) === $job && microtime(true) < $until);
            self::assertNotSame($job, $next['job_id'], json_encode($next));
            self::assertSame('building', $this->cli('map', 'status', $next['job_id'])['availability']);
            self::assertTrue($query->isRunning());
            $old = $this->releaseSearch($query);
            self::assertSame($cold['runtime']['snapshot_id'], $old['runtime']['snapshot_id']);
            self::assertNotEmpty($old['candidates']);
            $this->waitEvent('generation.publish', $next['job_id']);
            $new = $this->cli('search', 'bravo');
            self::assertNotEmpty($new['candidates'], json_encode($new));
            self::assertSame($cold['runtime']['pid'], $new['runtime']['pid']);
            self::assertSame('selective_patch', $new['runtime']['execution_mode']);
            self::assertSame([], array_values(array_filter($this->events(), static fn ($e) => $e['stage'] === 'native.collect.rejected')));
            file_put_contents($this->artifacts.'/write-to-response.json', json_encode(['elapsed_ns' => hrtime(true) - $write, 'basis' => 'serialized_harness_t_write_to_full_new_response_including_query_wait', 'ac03' => 'NOT_EVALUATED'], JSON_THROW_ON_ERROR));
        } finally {
            if ($query->isRunning()) {
                $this->releaseSearch($query);
            }
        }
    }

    public function test_clear_after_native_build_prevents_old_epoch_publication(): void
    {
        $state = new StateStore($this->home);
        $state->update('native-test-control', static fn () => ['barrier' => 'build.after']);
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', dirname(__DIR__, 2).'/Support/native-runtime-entrypoint.php', '--project', $this->root, '--tool-home', $this->home, 'search', 'alpha', '--agent'], timeout: 30);
        $process->start();
        try {
            $built = $this->waitEvent('build.after');
            $context = new RepositoryContext($this->root, $this->home);
            $epoch = new GraphEpoch($context);
            $epoch->clear(static fn () => 0);
            $state->update('native-test-control', static fn () => []);
            $process->wait();
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertFalse($result['ok'], json_encode($result));
            self::assertSame([], array_values(array_filter($this->events(), static fn ($e) => $e['stage'] === 'generation.publish' && $e['job_id'] === $built['job_id'])));
            $fresh = $this->cli('search', 'alpha');
            self::assertNotEmpty($fresh['candidates'], json_encode($fresh));
            self::assertNotSame($built['job_id'], $fresh['runtime']['job_id']);
            self::assertSame($epoch->current(), $fresh['runtime']['epoch'] ?? $epoch->current());
        } finally {
            $state->update('native-test-control', static fn () => []);
            if ($process->isRunning()) {
                $process->wait();
            }
        }
    }

    public function test_native_child_crash_is_explicit_and_next_request_restores_in_a_new_owner(): void
    {
        $cold = $this->cli('search', 'alpha');
        $pid = $cold['runtime']['measurements']['native']['process_usage']['pid'];
        $rows = $cold['runtime']['measurements']['process_tree']['processes'];
        $child = array_values(array_filter($rows, static fn ($row) => $row['pid'] === $pid));
        self::assertCount(1, $child, 'Only the own fixture runtime child may be signaled.');
        self::assertSame($cold['runtime']['pid'], $child[0]['ppid']);
        self::assertTrue(posix_kill($pid, 9));
        usleep(50000);
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', dirname(__DIR__, 2).'/Support/native-runtime-entrypoint.php', '--project', $this->root, '--tool-home', $this->home, 'search', 'alpha', '--agent'], timeout: 30);
        $process->run();
        $failed = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if ($failed['ok']) {
            self::assertNotSame($cold['runtime']['pid'], $failed['runtime']['pid']);
            self::assertSame('restore', $failed['runtime']['execution_mode']);
        } else {
            self::assertNotEmpty($failed['reason'] ?? $failed['msg'] ?? $failed['status'] ?? null, json_encode($failed));
        }
        $fresh = $this->cli('search', 'alpha');
        self::assertNotEmpty($fresh['candidates'], json_encode($fresh));
        self::assertSame('restore', $fresh['runtime']['execution_mode']);
        self::assertNotSame($cold['runtime']['pid'], $fresh['runtime']['pid']);
        self::assertNotSame($pid, $fresh['runtime']['measurements']['native']['process_usage']['pid']);
    }

    public function test_native_cache_write_refusal_keeps_ready_ram_and_preserves_target_bytes(): void
    {
        $state = new StateStore($this->home);
        $state->update('native-test-control', static fn () => ['barrier' => 'native.cache.before']);
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', dirname(__DIR__, 2).'/Support/native-runtime-entrypoint.php', '--project', $this->root, '--tool-home', $this->home, 'search', 'alpha', '--agent'], timeout: 30);
        $process->start();
        try {
            $event = $this->waitEvent('native.cache.before');
            $directories = glob($this->home.'/repositories/*/runtime/*/native-*');
            self::assertCount(1, $directories);
            mkdir($this->home.'/protected', 0700);
            file_put_contents($this->home.'/protected/sentinel', 'unchanged');
            symlink($this->home.'/protected', $directories[0].'/snapshot-'.$event['snapshot_id']);
            $state->update('native-test-control', static fn () => []);
            $process->wait();
            self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertNotEmpty($result['candidates'], json_encode($result));
            self::assertSame('ready', $result['runtime']['availability']);
            self::assertSame('error', $result['runtime']['cache']['write']['status']);
            self::assertStringContainsString('symlink', $result['runtime']['cache']['write']['reason']);
            self::assertSame('unchanged', file_get_contents($this->home.'/protected/sentinel'));
            self::assertCount(1, glob($this->home.'/protected/*'));
            self::assertFileDoesNotExist($directories[0].'/current.json');
            self::assertNotEmpty($this->cli('search', 'alpha')['candidates']);
        } finally {
            $state->update('native-test-control', static fn () => []);
            if ($process->isRunning()) {
                $process->wait();
            }
        }
    }

    public function test_foreign_native_cache_identity_is_rejected_before_validated_restore(): void
    {
        $cold = $this->cli('search', 'alpha');
        GraphRuntimeCleanup::stop($this->home);
        $paths = glob($this->home.'/repositories/*/runtime/*/native-*/current.json');
        self::assertCount(1, $paths);
        $value = json_decode(file_get_contents($paths[0]), true, flags: JSON_THROW_ON_ERROR);
        $value['identity']['repository'] = 'foreign-repository';
        file_put_contents($paths[0], json_encode($value, JSON_THROW_ON_ERROR));
        $fresh = $this->cli('search', 'alpha');
        self::assertNotEmpty($fresh['candidates'], json_encode($fresh));
        self::assertSame('full_recompose', $fresh['runtime']['execution_mode']);
        self::assertSame('error', $fresh['runtime']['cache']['read']['status']);
        self::assertStringContainsString('native_cache_identity:repository', $fresh['runtime']['cache']['read']['reason']);
        self::assertNotSame($cold['runtime']['pid'], $fresh['runtime']['pid']);
    }

    public function test_discovery_filters_ranking_zero_limit_and_fields_match_php(): void
    {
        file_put_contents($this->root.'/app/Alpha.php', '<?php namespace App; class Alpha { function alpha() {} function ALPHABET() {} }');
        $cases = [['alpha', null, 20], ['App\\Alpha', null, 20], ['app/', 'file', 20], ['', 'method', 20], ['missing', null, 20], ['alpha', null, 0], ['alpha', null, 1]];
        $native = [];
        foreach ($cases as [$query, $kind, $limit]) {
            $native[] = $this->cli('search', $query, '--limit='.$limit, ...($kind === null ? [] : ['--kind='.$kind]));
        }
        RepositoryContext::activate($this->oracleContext());
        AnalysisRuntime::activate(null);
        Host::create($this->home);
        GraphRuntime::enable(false);
        foreach ($cases as $i => [$query, $kind, $limit]) {
            $oracle = (new ArchitectureDiscovery(new Filesystem, $this->root))->search($query, $kind, $limit);
            foreach (['candidates', 'total', 'total_is_lower_bound', 'ambiguous', 'truncated', 'limited', 'status', 'query', 'kind'] as $field) {
                self::assertSame($oracle[$field] ?? null, $native[$i][$field] ?? null, json_encode([$query, $kind, $limit, $field]));
            }
        }
    }

    public function test_public_cli_native_cold_search_and_ram_patch_without_php_index(): void
    {
        $cold = $this->cli('search', 'alpha');
        self::assertTrue($cold['ok'], json_encode($cold));
        self::assertNotEmpty($cold['candidates']);
        $first = $cold['runtime']['snapshot_id'];
        file_put_contents($this->root.'/app/A.php', '<?php namespace App; class A { function bravo() {} }');
        $until = microtime(true) + 10;
        do {
            usleep(100000);
            $next = $this->cli('search', 'bravo');
        } while (($next['candidates'] ?? []) === [] && microtime(true) < $until);
        self::assertNotEmpty($next['candidates'], json_encode($next));
        self::assertNotSame($first, $next['runtime']['snapshot_id']);
        self::assertSame('selective_patch', $next['runtime']['execution_mode']);
        self::assertSame([], $this->cli('search', 'alpha')['candidates']);
    }
}
