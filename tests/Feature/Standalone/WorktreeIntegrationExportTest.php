<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature\Standalone;

use GracjanKubicki\ArchitectureKit\Standalone\AgentHooks;
use GracjanKubicki\ArchitectureKit\Standalone\GraphProtocol;
use GracjanKubicki\ArchitectureKit\Standalone\Projects;
use GracjanKubicki\ArchitectureKit\Tests\Support\WorktreeMcpClient;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class WorktreeIntegrationExportTest extends TestCase
{
    private string $home;

    /** @var array<string, mixed> */
    private array $lab;

    protected function setUp(): void
    {
        $path = getenv('ARCHITECTURE_KIT_WORKTREE_MANIFEST');
        if (! is_string($path) || ! is_file($path)) {
            self::markTestSkipped('OPEN: explicitly prepared real Git manifest required.');
        }
        $this->lab = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->home = realpath(sys_get_temp_dir()).'/kit-export space-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (isset($this->home)) {
            foreach (glob($this->home.'/repositories/*/runtime/*/coordinator.json') ?: [] as $path) {
                $record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                try {
                    $socket = GraphProtocol::socket(dirname($path), $record['endpoint']);
                    GraphProtocol::send($socket, [...$record, 'action' => 'shutdown', 'request_id' => bin2hex(random_bytes(16))]);
                    fclose($socket);
                } catch (\Throwable) {
                }
            }
            (new Filesystem)->deleteDirectory($this->home);
        }
    }

    public function test_family_export_has_no_hooks_permissions_or_activation_and_preserves_proxy(): void
    {
        $binary = dirname(__DIR__, 3).'/bin/architecture-kit';
        $root = $this->lab['roots']['main']['crm-backend'];
        foreach (['codex', 'claude'] as $client) {
            $export = (new AgentHooks($this->home, profile: 'personal', runtimeRoutes: true))->exportFamily($client, $binary, $root);
            self::assertFalse($export['active']);
            self::assertSame([], $export['hooks']);
            self::assertSame('family', $export['mode']);
            self::assertSame($binary, $export['mcp']['args'][0]);
            self::assertContains('--family', $export['mcp']['args']);
            self::assertNotContains('--runtime-routes', $export['mcp']['args']);
            self::assertNotContains('--enforce', $export['mcp']['args']);
            self::assertNotContains('--allow-write', $export['mcp']['args']);
            self::assertNotContains('--allow-execution', $export['mcp']['args']);
            self::assertFileDoesNotExist($export['directory'].'/hooks.json');
            self::assertFalse((new AgentHooks($this->home))->familyStatus($client, $root)['active']);
        }
    }

    public function test_cli_family_export_starts_real_unbound_product(): void
    {
        $binary = dirname(__DIR__, 3).'/bin/architecture-kit';
        $root = $this->lab['roots']['main']['crm-backend'];
        $process = new Process([PHP_BINARY, $binary, '--family', $root, '--tool-home', $this->home, 'integration', 'export', 'codex']);
        $process->mustRun();
        $export = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($export['ok']);
        self::assertFalse($export['active']);
        $client = new WorktreeMcpClient(array_slice($export['mcp']['args'], 1));
        try {
            $client->initialize();
            self::assertSame('unbound', $client->tool('repository-context', ['action' => 'status'])['result']['structuredContent']['status']);
        } finally {
            self::assertSame(0, $client->close());
        }
    }

    public function test_foreign_clone_does_not_bind_to_the_original_family(): void
    {
        $clone = $this->lab['foreign_clone'] ?? null;
        if (! is_string($clone)) {
            self::markTestSkipped('OPEN: main must explicitly prepare the foreign clone fixture.');
        }
        $main = $this->lab['roots']['main']['crm-backend'];
        $client = new WorktreeMcpClient(['--family', $main, '--tool-home', $this->home, 'mcp']);
        try {
            $client->initialize();
            $result = $client->tool('repository-context', ['action' => 'bind', 'workspace' => $clone])['result']['structuredContent'];
            self::assertFalse($result['ok']);
            self::assertNull($result['code_root']);
            self::assertStringContainsString('found 0', $result['reason']);
        } finally {
            self::assertSame(0, $client->close());
        }
    }

    public function test_family_export_keeps_a_composer_autoload_proxy_with_spaces(): void
    {
        mkdir($this->home, 0700, true);
        $proxy = $this->home.'/composer proxy.php';
        $base = dirname(__DIR__, 3);
        file_put_contents($proxy, '<?php $_composer_autoload_path = '.var_export($base.'/vendor/autoload.php', true).'; require '.var_export($base.'/bin/architecture-kit', true).';');
        $process = new Process([PHP_BINARY, $proxy, '--tool-home', $this->home, '--family', $this->lab['roots']['main']['crm-backend'], 'integration', 'export', 'codex']);
        $process->mustRun();
        $export = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($proxy, $export['mcp']['args'][0]);
        $initialize = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'proxy-contract', 'version' => '1']]], JSON_THROW_ON_ERROR)."\n";
        $started = new Process([$export['mcp']['command'], ...$export['mcp']['args']]);
        $started->setInput($initialize)->mustRun();
        self::assertSame(1, json_decode(trim($started->getOutput()), true, flags: JSON_THROW_ON_ERROR)['id']);
    }

    public function test_metadata_allowlist_rejects_payload_and_proxy_preserves_real_stdio(): void
    {
        $script = dirname(__DIR__, 3).'/tests/Support/worktree-client-metadata.py';
        $check = new Process(['python3', '-c', 'import importlib.util,sys; s=importlib.util.spec_from_file_location("metadata",sys.argv[1]);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);m.checked_event({"event":"request","prompt":"forbidden"})', $script]);
        $check->run();
        self::assertNotSame(0, $check->getExitCode());
        self::assertStringContainsString('Metadata allowlist violation', $check->getErrorOutput());
        $request = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'real-proxy-test', 'version' => '1']]], JSON_THROW_ON_ERROR)."\n";
        $proxy = new Process(['python3', $script, '--log-dir', $this->home.'/metadata', '--manifest', getenv('ARCHITECTURE_KIT_WORKTREE_MANIFEST'), '--', PHP_BINARY, dirname(__DIR__, 3).'/bin/architecture-kit', '--family', $this->lab['roots']['main']['crm-backend'], '--tool-home', $this->home, 'mcp']);
        $proxy->setInput($request)->mustRun();
        self::assertSame(1, json_decode(trim($proxy->getOutput()), true, flags: JSON_THROW_ON_ERROR)['id']);
        foreach (glob($this->home.'/metadata/*.jsonl') as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                self::assertArrayNotHasKey('prompt', $event);
                self::assertArrayNotHasKey('arguments', $event);
                self::assertArrayNotHasKey('result', $event);
            }
        }
    }

    public function test_metadata_proxy_records_witnesses_from_real_graph_search_without_source_payload(): void
    {
        $main = $this->lab['roots']['main']['crm-backend'];
        $a = $this->lab['roots']['a']['crm-backend'];
        $settings = (new Projects($this->home))->register($main, ['actions'])['config'];
        file_put_contents($settings, '{"enabled":["actions"],"analysis":{"engine":"php"},"audit":{"missing_test":"off"}}');
        $requests = [
            ['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'graph-projection-test', 'version' => '1']]],
            ['id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'repository-context', 'arguments' => ['action' => 'bind', 'workspace' => $a]]],
            ['id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'architecture-search', 'arguments' => ['query' => 'onlyA']]],
            ['id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'architecture-search', 'arguments' => ['query' => 'onlyMain']]],
        ];
        $input = '';
        foreach ($requests as $request) {
            $input .= json_encode(['jsonrpc' => '2.0', ...$request], JSON_THROW_ON_ERROR)."\n";
        }
        $base = dirname(__DIR__, 3);
        $proxy = new Process(['python3', $base.'/tests/Support/worktree-client-metadata.py', '--log-dir', $this->home.'/metadata',
            '--manifest', getenv('ARCHITECTURE_KIT_WORKTREE_MANIFEST'), '--', PHP_BINARY, $base.'/bin/architecture-kit',
            '--family', $main, '--tool-home', $this->home, 'mcp']);
        $proxy->setInput($input)->mustRun();
        $responses = [];
        foreach (explode("\n", trim($proxy->getOutput())) as $line) {
            $response = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $responses[$response['id']] = $response;
        }
        self::assertTrue($responses[2]['result']['structuredContent']['ok']);
        $candidates = $responses[3]['result']['structuredContent']['result']['candidates'];
        self::assertNotEmpty($candidates);
        self::assertStringContainsString('onlyA', json_encode($candidates, JSON_THROW_ON_ERROR));
        self::assertEmpty($responses[4]['result']['structuredContent']['result']['candidates']);
        $searchEvents = [];
        foreach (glob($this->home.'/metadata/*.jsonl') as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                foreach (['arguments', 'result', 'prompt', 'candidates', 'source', 'name', 'id', 'selector'] as $forbidden) {
                    self::assertArrayNotHasKey($forbidden, $event);
                }
                if ($event['event'] === 'response' && ($event['tool'] ?? null) === 'architecture-search') {
                    $searchEvents[] = $event;
                }
            }
        }
        self::assertCount(2, $searchEvents);
        self::assertSame('a.crm-backend', $searchEvents[0]['root']);
        self::assertSame(['onlyA'], $searchEvents[0]['witnesses']);
        self::assertGreaterThan(0, $searchEvents[0]['candidate_count']);
        self::assertSame([], $searchEvents[1]['witnesses']);
        self::assertSame(0, $searchEvents[1]['candidate_count']);
    }
}
