<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature\Standalone;

use GracjanKubicki\ArchitectureKit\Standalone\AgentHooks;
use GracjanKubicki\ArchitectureKit\Standalone\Host;
use GracjanKubicki\ArchitectureKit\Standalone\Projects;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\SessionBinding;
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
        file_put_contents($this->root.'/composer.json', '{}');
        (new Projects($this->home))->register($this->root, ['actions']);
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
        file_put_contents($context->configSource, '{"enabled":["services"]}');
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
        file_put_contents($other.'/composer.json', '{}');
        (new Projects($this->home))->register($other, ['actions']);
        $otherContext = new RepositoryContext(realpath($other), $this->home);
        RepositoryContext::activate($otherContext);
        self::assertSame('missing', (new TaskControl($otherContext))->status('first')['status']);
        self::assertNotSame($context->storage, $otherContext->storage);
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

    private function context(): RepositoryContext
    {
        return new RepositoryContext(realpath($this->root), $this->home);
    }
}
