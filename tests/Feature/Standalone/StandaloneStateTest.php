<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature\Standalone;

use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Standalone\AgentHooks;
use GracjanKubicki\ArchitectureKit\Standalone\Host;
use GracjanKubicki\ArchitectureKit\Standalone\Projects;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\SessionBinding;
use GracjanKubicki\ArchitectureKit\Standalone\SourceFingerprint;
use GracjanKubicki\ArchitectureKit\Standalone\StandaloneAudit;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use GracjanKubicki\ArchitectureKit\Standalone\TaskControl;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class StandaloneStateTest extends TestCase
{
    private string $directory;

    private string $root;

    private string $home;

    protected function setUp(): void
    {
        mkdir($this->directory = sys_get_temp_dir().'/kit-state-'.bin2hex(random_bytes(8)), 0700);
        mkdir($this->root = $this->directory.'/repo');
        mkdir($this->root.'/app');
        $this->home = $this->directory.'/home';
        file_put_contents($this->root.'/composer.json', '{"type":"project","require":{"php":"^8.3"}}');
        $registration = (new Projects($this->home))->register($this->root, ['actions']);
        (new StateStore(dirname($registration['config'])))->update('default', static fn (array $settings): array => [...$settings, 'analysis' => ['engine' => 'php']]);
    }

    protected function tearDown(): void
    {
        RepositoryContext::activate(null);
        (new Filesystem)->deleteDirectory($this->directory);
    }

    public function test_context_detects_live_config_drift_and_home_inside_repo(): void
    {
        $context = $this->context();
        $original = $context->fingerprint();
        file_put_contents($context->configSource, '{"analysis":{"engine":"php"},"enabled":["services"]}');
        self::assertNotSame($original, $this->context()->fingerprint());
        $this->expectException(RuntimeException::class);
        $context->fingerprint();
    }

    public function test_separate_repositories_and_tasks_have_independent_beginnings(): void
    {
        $context = $this->context();
        Host::create($this->home);
        RepositoryContext::activate($context);
        $tasks = new TaskControl($context);
        $first = $tasks->begin('first');
        self::assertSame('started', $first['status']);
        self::assertSame('missing', $tasks->status('second')['status']);
        self::assertSame('first', $tasks->begin('first')['task']);
        $other = $this->directory.'/other';
        mkdir($other);
        file_put_contents($other.'/composer.json', '{"type":"project","require":{"php":"^8.3"}}');
        $registration = (new Projects($this->home))->register($other, ['actions']);
        (new StateStore(dirname($registration['config'])))->update('default', static fn (array $settings): array => [...$settings, 'analysis' => ['engine' => 'php']]);
        $otherContext = new RepositoryContext(realpath($other), $this->home);
        RepositoryContext::activate($otherContext);
        self::assertSame('missing', (new TaskControl($otherContext))->status('first')['status']);
        self::assertNotSame($context->storage, $otherContext->storage);
    }

    public function test_task_uses_verified_audit_fingerprint_and_preserves_its_beginning(): void
    {
        $context = $this->context();
        RepositoryContext::activate($context);
        file_put_contents($this->root.'/app/Example.php', '<?php namespace App; class Example {}');
        $initial = SourceFingerprint::current($this->root, $context->settings);
        $audit = StandaloneAudit::run($context);
        self::assertSame($initial, $audit->sourceFingerprint);
        $tasks = new TaskControl($context);
        $begin = $tasks->begin('freshness');
        self::assertSame('started', $begin['status']);
        self::assertSame($initial, $begin['begin_sources']);
        file_put_contents($this->root.'/app/Example.php', '<?php namespace App; class Changed {}');
        $current = SourceFingerprint::current($this->root, $context->settings);
        self::assertNotSame($initial, $current);
        $checked = $tasks->check('freshness');
        self::assertSame('pass', $checked['status']);
        self::assertSame($initial, $checked['begin_sources']);
        self::assertSame($current, $checked['checked_sources']);
        self::assertSame($initial, $tasks->begin('freshness')['begin_sources']);
    }

    public function test_same_stat_change_during_audit_cannot_produce_verified_fingerprint(): void
    {
        $context = $this->context();
        RepositoryContext::activate($context);
        $path = $this->root.'/app/Example.php';
        file_put_contents($path, '<?php namespace App; class Example {}');
        $mtime = filemtime($path);
        $audit = new ApplicationAudit(new Filesystem, $context->rootPath, onParse: static function () use ($path, $mtime): void {
            file_put_contents($path, '<?php namespace App; class Changed {}');
            touch($path, $mtime);
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Inputs changed during audit');
        $audit->run(enabled: [], changedOnly: false, useBaseline: false);
    }

    public function test_task_without_active_repository_cannot_claim_verified_inputs(): void
    {
        mkdir($this->root.'/config');
        file_put_contents($this->root.'/config/architectures.php', '<?php return ["analysis"=>["engine"=>"php"],"enabled"=>["actions"]];');
        $context = $this->context();
        RepositoryContext::activate(null);
        $begin = (new TaskControl($context))->begin('inactive');
        self::assertSame('blocked', $begin['status']);
        self::assertFalse($begin['ok']);
        self::assertStringContainsString('freshness was not verified', $begin['reason']);
        self::assertArrayNotHasKey('begin_sources', $begin);
    }

    public function test_session_cannot_replace_unverified_task_beginning(): void
    {
        $context = $this->context();
        Host::create($this->home);
        RepositoryContext::activate($context);
        $control = new TaskControl($context);
        $control->begin('first');
        $control->begin('second');
        SessionBinding::bind($context, 'codex', 'session', 'first');
        try {
            SessionBinding::bind($context, 'codex', 'session', 'second');
            self::fail('An unfinished beginning must not be replaced.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Previous task', $error->getMessage());
        }
        $control->check('first');
        self::assertSame('second', SessionBinding::bind($context, 'codex', 'session', 'second')['task']);
    }

    public function test_unregistered_hooks_are_no_op_and_source_limit_is_never_pass(): void
    {
        $outside = $this->directory.'/outside';
        mkdir($outside);
        file_put_contents($outside.'/composer.json', '{}');
        self::assertSame([], (new AgentHooks($this->home))->handle('codex', ['cwd' => $outside, 'hook_event_name' => 'Stop', 'session_id' => 'x']));
        $context = $this->context();
        Host::create($this->home);
        RepositoryContext::activate($context);
        file_put_contents($this->root.'/app/Large.php', str_repeat(' ', 8000001));
        $status = (new TaskControl($context))->begin('limited');
        self::assertSame('blocked', $status['status']);
        self::assertFalse($status['ok']);
        unlink($this->root.'/app/Large.php');
        self::assertSame('blocked', (new TaskControl($context))->begin('limited')['status']);
    }

    public function test_symlink_home_and_state_files_are_rejected(): void
    {
        symlink($this->root, $this->directory.'/link');
        try {
            new RepositoryContext(realpath($this->root), $this->directory.'/link/state');
            self::fail('Home inside target must be refused before writing.');
        } catch (RuntimeException) {
            self::assertDirectoryDoesNotExist($this->root.'/state');
        }
        $store = new StateStore($this->home.'/safe');
        symlink($this->root.'/composer.json', $store->directory.'/record.json');
        $this->expectException(RuntimeException::class);
        $store->update('record', fn () => ['changed' => true]);
    }

    public function test_concurrent_updates_are_serialized_and_publish_valid_json(): void
    {
        $script = $this->directory.'/increment.php';
        file_put_contents($script, '<?php require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).'; $store=new \\GracjanKubicki\\ArchitectureKit\\Standalone\\StateStore($argv[1]); for($i=0;$i<20;$i++){$store->update("counter",static fn(array $old):array=>["count"=>($old["count"]??0)+1]);}');
        $processes = [];
        for ($i = 0; $i < 4; $i++) {
            $process = new Process([PHP_BINARY, $script, $this->home.'/concurrent']);
            $process->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
        }
        self::assertSame(80, (new StateStore($this->home.'/concurrent'))->read('counter')['count']);
    }

    public function test_in_flight_audits_do_not_hold_task_locks_or_overwrite_an_explicit_skip(): void
    {
        $context = $this->context();
        Host::create($this->home);
        RepositoryContext::activate($context);
        $control = new TaskControl($context);
        $program = <<<'PHP'
require $_SERVER['argv'][1];
$context = new GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext($_SERVER['argv'][2], $_SERVER['argv'][3]);
GracjanKubicki\ArchitectureKit\Standalone\Host::create($_SERVER['argv'][3]);
GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext::activate($context);
$marker = $_SERVER['argv'][4];
spl_autoload_register(static function (string $class) use ($marker): void {
    if ($class === 'GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit') {
        file_put_contents($marker, 'audit-starting');
        usleep(1000000);
    }
}, true, true);
$control = new GracjanKubicki\ArchitectureKit\Standalone\TaskControl($context);
$mode = $_SERVER['argv'][5];
echo json_encode($control->$mode('concurrent-'.$mode), JSON_THROW_ON_ERROR);
PHP;
        foreach (['begin', 'check'] as $mode) {
            $task = 'concurrent-'.$mode;
            if ($mode === 'check') {
                self::assertSame('started', $control->begin($task)['status']);
            }
            $marker = $this->directory.'/audit-'.$mode;
            $process = new Process([PHP_BINARY, '-r', $program, '--', dirname(__DIR__, 3).'/vendor/autoload.php', $context->rootPath, $this->home, $marker, $mode]);
            $process->setTimeout(10);
            $process->start();
            try {
                $deadline = microtime(true) + 5;
                while (! is_file($marker) && $process->isRunning() && microtime(true) < $deadline) {
                    usleep(1000);
                }
                self::assertFileExists($marker, $process->getErrorOutput());
                if ($mode === 'begin') {
                    $reserved = $control->status($task);
                    self::assertSame('blocked', $reserved['status']);
                    self::assertArrayNotHasKey('begin_sources', $reserved);
                    self::assertSame($reserved, $control->begin($task));
                }
                $skipped = $control->skip($task, true);
                // The audit is still paused when the short publication lock completes.
                self::assertTrue($process->isRunning());
                self::assertSame('skipped', $skipped['status']);
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
                self::assertSame('skipped', json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status']);
                self::assertSame($skipped, $control->status($task));
            } finally {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }
    }

    public function test_concurrent_check_rejects_stale_publication_without_refreshing_the_beginning(): void
    {
        $context = $this->context();
        Host::create($this->home);
        RepositoryContext::activate($context);
        $control = new TaskControl($context);
        $begin = $control->begin('concurrent-check');
        self::assertSame('started', $begin['status']);
        $marker = $this->directory.'/check-ready';
        $release = $this->directory.'/check-release';
        $program = <<<'PHP'
require $_SERVER['argv'][1];
$context = new GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext($_SERVER['argv'][2], $_SERVER['argv'][3]);
GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext::activate($context);
$marker = $_SERVER['argv'][4];
$release = $_SERVER['argv'][5];
spl_autoload_register(static function (string $class) use ($marker, $release): void {
    if ($class === 'GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit') {
        file_put_contents($marker, 'ready');
        $deadline = microtime(true) + 5;
        while (! is_file($release) && microtime(true) < $deadline) {
            usleep(1000);
        }
        if (! is_file($release)) {
            throw new RuntimeException('Concurrent check was not released.');
        }
    }
}, true, true);
echo json_encode((new GracjanKubicki\ArchitectureKit\Standalone\TaskControl($context))->check('concurrent-check'), JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-r', $program, '--', dirname(__DIR__, 3).'/vendor/autoload.php', $context->rootPath, $this->home, $marker, $release]);
        $process->setTimeout(10);
        $process->start();
        try {
            $deadline = microtime(true) + 5;
            while (! is_file($marker) && $process->isRunning() && microtime(true) < $deadline) {
                usleep(1000);
            }
            self::assertFileExists($marker, $process->getErrorOutput());
            self::assertSame('pass', $control->check('concurrent-check')['status']);
            file_put_contents($release, 'continue');
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            $stale = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('blocked', $stale['status']);
            self::assertFalse($stale['ok']);
            self::assertStringContainsString('Task state changed', $stale['reason']);
            self::assertSame($begin['begin_sources'], $stale['begin_sources']);
            self::assertSame($begin['begin_errors'], $stale['begin_errors']);
            self::assertSame($stale, $control->status('concurrent-check'));
            self::assertSame('pass', $control->check('concurrent-check')['status']);
            self::assertSame($begin['begin_sources'], $control->status('concurrent-check')['begin_sources']);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    public function test_redirected_external_subdirectory_cannot_write_into_the_target(): void
    {
        $context = $this->context();
        symlink($this->root, $context->storage.'/sessions');
        $this->expectException(RuntimeException::class);
        new StateStore($context->storage.'/sessions/nested', $context->home);
    }

    public function test_generated_configuration_corruption_is_not_accepted(): void
    {
        $context = $this->context();
        file_put_contents($context->configPath, '<?php return ["enabled" => ["services"]];');
        $this->expectException(RuntimeException::class);
        $this->context();
    }

    public function test_running_context_rejects_a_changed_tool_before_reusing_its_cache_identity(): void
    {
        $copy = $this->directory.'/tool/src';
        $files = new Filesystem;
        foreach (['Standalone/RepositoryContext.php', 'Audit/ProjectGraph/Cache/PackageFingerprint.php'] as $relative) {
            $files->ensureDirectoryExists(dirname($copy.'/'.$relative));
            $files->copy(dirname(__DIR__, 3).'/src/'.$relative, $copy.'/'.$relative);
        }
        $program = <<<'PHP'
$loader = require $_SERVER['argv'][1];
$loader->addPsr4('GracjanKubicki\\ArchitectureKit\\', $_SERVER['argv'][2], true);
$context = new GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext($_SERVER['argv'][3], $_SERVER['argv'][4]);
$original = $context->fingerprint();
file_put_contents($_SERVER['argv'][2].'/Standalone/RepositoryContext.php', "\n// changed tool source\n", FILE_APPEND);
try {
    $context->fingerprint();
    echo json_encode(['rejected'=>false]);
} catch (RuntimeException $error) {
    echo json_encode(['rejected'=>true, 'reason'=>$error->getMessage()]);
}
PHP;
        $process = new Process([PHP_BINARY, '-r', $program, '--', dirname(__DIR__, 3).'/vendor/autoload.php', $copy, realpath($this->root), $this->home]);
        $process->setTimeout(10);
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($result['rejected']);
        self::assertStringContainsString('Tool sources changed during this process', $result['reason']);
    }

    private function context(): RepositoryContext
    {
        return new RepositoryContext(realpath($this->root), $this->home);
    }
}
