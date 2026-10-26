<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature\Standalone;

use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Standalone\AnalysisConsent;
use GracjanKubicki\ArchitectureKit\Standalone\Host;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryRequestScope;
use GracjanKubicki\ArchitectureKit\Standalone\StandaloneServer;
use GracjanKubicki\ArchitectureKit\Standalone\TaskControl;
use GracjanKubicki\ArchitectureKit\Tests\Support\WorktreeMcpClient;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorktreeRefreshTest extends TestCase
{
    private string $temp;

    private string $root;

    private string $home;

    protected function setUp(): void
    {
        $this->temp = realpath(sys_get_temp_dir()).'/worktree-refresh-'.bin2hex(random_bytes(8));
        $this->root = $this->temp.'/project';
        $this->home = $this->temp.'/tool';
        mkdir($this->root.'/.architecture-kit', 0700, true);
        mkdir($this->root.'/app', 0700, true);
        file_put_contents($this->root.'/composer.json', '{"require":{"php":"^8.3"},"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        file_put_contents($this->root.'/app/Witness.php', '<?php namespace App; final class Witness {}');
        $this->settings(['actions']);
    }

    protected function tearDown(): void
    {
        RepositoryContext::activate(null);
        (new Filesystem)->deleteDirectory($this->temp);
    }

    /** @param list<string> $enabled */
    private function settings(array $enabled): void
    {
        file_put_contents($this->root.'/.architecture-kit/settings.json', json_encode(['enabled' => $enabled,
            'analysis' => ['engine' => 'php'], 'audit' => ['paths' => ['app']]], JSON_THROW_ON_ERROR));
    }

    public function test_snapshot_refreshes_retained_config_host_facade_without_replacing_container_or_sdk_request(): void
    {
        $app = Host::create($this->home);
        $context = new RepositoryContext($this->root, $this->home);
        Host::refresh($context);
        $retained = new ArchitectureConfig($context->configPath, new Filesystem);
        self::assertSame(['actions'], $retained->projectSettings()['enabled']);
        self::assertSame(['actions'], Config::get('architectures.enabled'));
        $request = new Request(['sentinel' => 'original']);
        $app->instance('mcp.request', $request);
        $this->settings(['services']);
        $scope = RepositoryRequestScope::begin();
        self::assertSame($app, app());
        self::assertSame($request, app(Request::class));
        self::assertSame(['services'], config('architectures.enabled'));
        self::assertSame(['services'], Config::get('architectures.enabled'));
        self::assertSame(['services'], $retained->projectSettings()['enabled']);
        self::assertSame($this->root, $scope->context->rootPath);
        self::assertEquals((new ArchitectureConfig($scope->context->configPath))->auditScope(), $retained->auditScope());
        $scope->assertCurrent();
    }

    public function test_drift_after_snapshot_is_rejected_for_configuration_and_same_stat_code_changes(): void
    {
        Host::create($this->home);
        RepositoryContext::activate(new RepositoryContext($this->root, $this->home));
        $scope = RepositoryRequestScope::begin();
        $this->settings(['services']);
        try {
            $scope->assertCurrent();
            self::fail('Configuration drift must not pass.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('configuration changed', $error->getMessage());
        }
        $next = RepositoryRequestScope::begin();
        $stamp = filemtime($this->root.'/app/Witness.php');
        file_put_contents($this->root.'/app/Witness.php', '<?php namespace App; final class Changed {}');
        touch($this->root.'/app/Witness.php', $stamp);
        $this->expectExceptionMessage('stale_snapshot');
        $next->assertCurrent();
    }

    public function test_live_fixed_process_refreshes_json_php_and_recovers_from_invalid_selection(): void
    {
        $client = new WorktreeMcpClient(['--project', $this->root, '--tool-home', $this->home, 'mcp']);
        try {
            $client->initialize();
            $first = $client->tool('enabled-architectures');
            self::assertSame('actions', $first['result']['structuredContent']['architectures'][0]['value']);
            $this->settings(['services']);
            $second = $client->tool('enabled-architectures');
            self::assertSame('services', $second['result']['structuredContent']['architectures'][0]['value']);
            file_put_contents($this->root.'/.architecture-kit/settings.json', '{invalid');
            $invalid = $client->tool('enabled-architectures');
            self::assertTrue($invalid['result']['isError']);
            self::assertFalse($invalid['result']['structuredContent']['ok']);
            unlink($this->root.'/.architecture-kit/settings.json');
            mkdir($this->root.'/config');
            file_put_contents($this->root.'/config/architectures.php', "<?php return ['enabled' => ['actions'], 'analysis' => ['engine' => 'php']];");
            self::assertSame('actions', $client->tool('enabled-architectures')['result']['structuredContent']['architectures'][0]['value']);
        } finally {
            self::assertSame(0, $client->close());
        }
    }

    public function test_sdk_boundary_discards_a_pass_when_rules_change_during_the_handler(): void
    {
        Host::create($this->home);
        Host::refresh(new RepositoryContext($this->root, $this->home));
        $transport = new class extends FakeTransporter
        {
            public array $messages = [];

            public function send(string $message, ?string $sessionId = null): void
            {
                $this->messages[] = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            }
        };
        $server = new StandaloneServer($transport);
        (new \ReflectionProperty($server, 'tools'))->setValue($server, [WorktreeDriftProbe::class]);
        WorktreeDriftProbe::$configuration = $this->root.'/.architecture-kit/settings.json';
        $server->start();
        $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 407, 'method' => 'tools/call',
            'params' => ['name' => 'worktree-drift-probe', 'arguments' => (object) []]], JSON_THROW_ON_ERROR));
        $response = $transport->messages[0];
        self::assertSame(407, $response['id']);
        self::assertTrue($response['result']['isError']);
        self::assertFalse($response['result']['structuredContent']['ok']);
        self::assertStringContainsString('stale_snapshot', $response['result']['structuredContent']['reason']);
        self::assertStringNotContainsString('"status":"pass"', json_encode($response));
    }

    public function test_live_refresh_rejects_unsearchable_directory_instead_of_using_lower_priority_php(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Directory search permissions require a non-root user.');
        }
        mkdir($this->root.'/config');
        file_put_contents($this->root.'/config/architectures.php', "<?php return ['enabled' => ['services'], 'analysis' => ['engine' => 'php']];");
        $client = new WorktreeMcpClient(['--project', $this->root, '--tool-home', $this->home, 'mcp']);
        try {
            $client->initialize();
            self::assertSame('actions', $client->tool('enabled-architectures')['result']['structuredContent']['architectures'][0]['value']);
            chmod($this->root.'/.architecture-kit', 0000);
            clearstatcache();
            $denied = $client->tool('enabled-architectures');
            self::assertTrue($denied['result']['isError']);
            self::assertFalse($denied['result']['structuredContent']['ok']);
            self::assertStringContainsString('not searchable', $denied['result']['structuredContent']['reason']);
            chmod($this->root.'/.architecture-kit', 0700);
            clearstatcache();
            self::assertSame('actions', $client->tool('enabled-architectures')['result']['structuredContent']['architectures'][0]['value']);
        } finally {
            chmod($this->root.'/.architecture-kit', 0700);
            clearstatcache();
            self::assertSame(0, $client->close());
        }
    }

    public function test_rule_refresh_preserves_beginning_and_consent_but_comparison_cannot_pass(): void
    {
        Host::create($this->home);
        $context = new RepositoryContext($this->root, $this->home);
        Host::refresh($context);
        $begin = (new TaskControl($context))->begin('tiny-refresh');
        self::assertSame('started', $begin['status'], json_encode($begin));
        $grant = (new AnalysisConsent($context))->grantDeep('tiny-refresh');
        $this->settings(['services']);
        $fresh = RepositoryRequestScope::begin()->context;
        $control = new TaskControl($fresh);
        $status = $control->status('tiny-refresh');
        self::assertSame('stale', $status['status']);
        self::assertFalse($status['control']['blocking']);
        self::assertFalse($control->check('tiny-refresh')['ok']);
        $again = $control->begin('tiny-refresh');
        foreach (['fingerprint', 'begin_sources', 'begin_errors', 'created_at'] as $key) {
            self::assertSame($begin[$key], $again[$key]);
        }
        self::assertSame($grant, (new AnalysisConsent($fresh))->grantDeep('tiny-refresh'));
    }
}

#[Name('worktree-drift-probe')]
final class WorktreeDriftProbe extends Tool
{
    public static string $configuration;

    public function handle(): ResponseFactory
    {
        file_put_contents(self::$configuration, '{"enabled":["services"],"analysis":{"engine":"php"}}');

        return Response::structured(['ok' => true, 'status' => 'pass']);
    }
}
