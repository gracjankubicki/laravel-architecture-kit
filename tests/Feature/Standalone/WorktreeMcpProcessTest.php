<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature\Standalone;

use GracjanKubicki\ArchitectureKit\Standalone\AnalysisConsent;
use GracjanKubicki\ArchitectureKit\Standalone\GraphProtocol;
use GracjanKubicki\ArchitectureKit\Standalone\Projects;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Tests\Support\WorktreeMcpClient;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class WorktreeMcpProcessTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $lab;

    private WorktreeMcpClient $client;

    protected function setUp(): void
    {
        $manifest = getenv('ARCHITECTURE_KIT_WORKTREE_MANIFEST');
        if (! is_string($manifest) || ! is_file($manifest)) {
            self::markTestSkipped('OPEN: supply the explicitly prepared real Git manifest. Tests never create worktrees.');
        }
        $this->lab = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $this->client = new WorktreeMcpClient(['--family', $this->lab['roots']['main']['crm-backend'],
            '--tool-home', $this->lab['home'], 'mcp'], sys_get_temp_dir());
    }

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            $directory = getenv('ARCHITECTURE_KIT_WORKTREE_TRANSCRIPTS');
            if (is_string($directory) && is_dir($directory)) {
                file_put_contents($directory.'/'.$this->name().'.json', json_encode($this->client->transcript, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
            self::assertSame(0, $this->client->close());
        }
    }

    public function test_unbound_inventory_and_every_tool_and_resource_are_guarded_before_arguments_or_effects(): void
    {
        $initialize = $this->client->initialize();
        $instructions = $initialize['result']['instructions'];
        self::assertStringContainsString('repository-context', $instructions);
        self::assertStringNotContainsString('MUST', $instructions);
        self::assertStringNotContainsString('Before edits use task-control', $instructions);
        self::assertSame('unbound', $this->client->tool('repository-context', ['action' => 'status'])['result']['structuredContent']['status']);
        $tools = $this->client->request('tools/list')['result']['tools'];
        self::assertGreaterThan(20, count($tools));
        foreach ($tools as $tool) {
            if ($tool['name'] === 'repository-context') {
                continue;
            }
            $response = $this->client->tool($tool['name']);
            self::assertSame('context_required', $response['result']['structuredContent']['status'], $tool['name']);
            self::assertTrue($response['result']['isError']);
        }
        foreach ($this->client->request('resources/list')['result']['resources'] as $resource) {
            $response = $this->client->request('resources/read', ['uri' => $resource['uri']]);
            self::assertStringContainsString('context_required', $response['error']['message']);
        }
        self::assertSame('unbound', $this->client->tool('repository-context', ['action' => 'status'])['result']['structuredContent']['status']);
    }

    public function test_bind_workspace_with_two_families_is_idempotent_conflicts_and_preserves_first_root(): void
    {
        $this->client->initialize();
        foreach (['', 'relative/path', '/nonexistent-worktree-architecture-kit', $this->lab['root']] as $invalid) {
            $result = $this->client->tool('repository-context', ['action' => 'bind', 'workspace' => $invalid])['result']['structuredContent'];
            self::assertFalse($result['ok']);
            self::assertNull($result['code_root']);
        }
        $a = $this->lab['roots']['a']['crm-backend'];
        $bound = $this->client->tool('repository-context', ['action' => 'bind', 'workspace' => dirname($a)])['result']['structuredContent'];
        self::assertTrue($bound['ok']);
        self::assertSame($a, $bound['code_root']);
        self::assertSame($this->lab['roots']['main']['crm-backend'], $bound['configuration_owner_root']);
        self::assertTrue($bound['inherited']);
        self::assertSame('advisory', $bound['permissions']['policy']);
        foreach (['execute', 'write', 'runtime_routes'] as $permission) {
            self::assertFalse($bound['permissions'][$permission]);
        }
        $again = $this->client->tool('repository-context', ['action' => 'bind', 'workspace' => $a.'/app'])['result']['structuredContent'];
        self::assertSame($bound['instance_id'], $again['instance_id']);
        self::assertSame($bound['fingerprint'], $again['fingerprint']);
        $conflict = $this->client->tool('repository-context', ['action' => 'bind', 'workspace' => $this->lab['roots']['main']['crm-backend']])['result']['structuredContent'];
        self::assertFalse($conflict['ok']);
        self::assertStringContainsString('context_conflict', $conflict['reason']);
        self::assertSame($a, $conflict['code_root']);
        $architectures = $this->client->tool('enabled-architectures');
        self::assertFalse($architectures['result']['isError']);
        self::assertNotEmpty($architectures['result']['structuredContent']['architectures']);
        $engine = $this->client->request('resources/read', ['uri' => 'architecture-kit://analysis-engine']);
        self::assertArrayHasKey('contents', $engine['result']);
        $guideline = $this->client->request('resources/read', ['uri' => 'architecture-kit://guideline']);
        self::assertStringNotContainsString('first Architecture Kit MCP call MUST be enabled-architectures', $guideline['result']['contents'][0]['text']);
        self::assertStringContainsString('Task beginning/check and audits are optional', $guideline['result']['contents'][0]['text']);
        $rules = $this->client->tool('file-rules', ['path' => 'app/Witness.php']);
        self::assertFalse($rules['result']['isError']);
        self::assertSame($a, $this->client->tool('repository-context', ['action' => 'status'])['result']['structuredContent']['code_root']);
    }

    public function test_named_parent_refresh_local_precedence_invalid_missing_and_recovery_in_one_live_family_process(): void
    {
        // The prepared Git family is reused, all mutable settings stay in a private tool home.
        $home = realpath(sys_get_temp_dir()).'/family-refresh-'.bin2hex(random_bytes(8));
        $profile = 'refresh';
        $parent = (new Projects($home))->register($this->lab['roots']['main']['crm-backend'], ['actions'], $profile)['config'];
        $write = static function (string $path, array $enabled): void {
            file_put_contents($path, json_encode(['enabled' => $enabled, 'analysis' => ['engine' => 'php'],
                'control' => ['policy' => 'enforce'], 'audit' => ['incomplete_analysis' => 'block']], JSON_THROW_ON_ERROR));
        };
        $write($parent, ['actions']);
        $client = new WorktreeMcpClient(['--family', $this->lab['roots']['main']['crm-backend'], '--tool-home', $home, '--profile', $profile, 'mcp']);
        try {
            $client->initialize();
            $a = $this->lab['roots']['a']['crm-backend'];
            $first = $client->tool('repository-context', ['action' => 'bind', 'workspace' => $a])['result']['structuredContent'];
            self::assertTrue($first['ok']);
            self::assertSame('actions', $client->tool('enabled-architectures')['result']['structuredContent']['architectures'][0]['value']);
            $write($parent, ['services']);
            self::assertSame('services', $client->tool('enabled-architectures')['result']['structuredContent']['architectures'][0]['value']);
            $changed = $client->tool('repository-context', ['action' => 'status'])['result']['structuredContent'];
            self::assertNotSame($first['fingerprint'], $changed['fingerprint']);
            self::assertSame($first['instance_id'], $changed['instance_id']);
            $local = (new Projects($home))->register($a, ['data-objects'], $profile)['config'];
            $write($local, ['data-objects']);
            self::assertSame('data-objects', $client->tool('enabled-architectures')['result']['structuredContent']['architectures'][0]['value']);
            $localStatus = $client->tool('repository-context', ['action' => 'status'])['result']['structuredContent'];
            self::assertFalse($localStatus['inherited']);
            self::assertSame('advisory', $localStatus['permissions']['policy']);
            file_put_contents($local, '{invalid');
            self::assertTrue($client->tool('enabled-architectures')['result']['isError']);
            self::assertSame('unverified', $client->tool('repository-context', ['action' => 'status'])['result']['structuredContent']['status']);
            unlink($local);
            self::assertSame('services', $client->tool('enabled-architectures')['result']['structuredContent']['architectures'][0]['value']);
            unlink($parent);
            self::assertTrue($client->tool('enabled-architectures')['result']['isError']);
            $write($parent, ['actions']);
            self::assertSame('actions', $client->tool('enabled-architectures')['result']['structuredContent']['architectures'][0]['value']);
        } finally {
            $directory = getenv('ARCHITECTURE_KIT_WORKTREE_TRANSCRIPTS');
            if (is_string($directory) && is_dir($directory)) {
                file_put_contents($directory.'/named-refresh.json', json_encode($client->transcript, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
            self::assertSame(0, $client->close());
            (new Filesystem)->deleteDirectory($home);
        }
    }

    public function test_two_live_processes_keep_witnesses_tasks_and_grants_separate_and_survive_peer_disconnect(): void
    {
        $home = realpath(sys_get_temp_dir()).'/family-isolation-'.bin2hex(random_bytes(8));
        $main = $this->lab['roots']['main']['crm-backend'];
        $a = $this->lab['roots']['a']['crm-backend'];
        $path = (new Projects($home))->register($main, ['actions'], 'isolation')['config'];
        file_put_contents($path, '{"enabled":["actions"],"analysis":{"engine":"php"},"audit":{"paths":["app"],"missing_test":"off"}}');
        $clients = [];
        $transcripts = [];
        try {
            foreach (['a' => $a, 'main' => $main] as $name => $root) {
                $clients[$name] = new WorktreeMcpClient(['--family', $main, '--tool-home', $home, '--profile', 'isolation', 'mcp']);
                $clients[$name]->initialize();
                self::assertTrue($clients[$name]->tool('repository-context', ['action' => 'bind', 'workspace' => $root])['result']['structuredContent']['ok']);
            }
            foreach (['a' => 'onlyA', 'main' => 'onlyMain'] as $name => $witness) {
                $found = $clients[$name]->tool('architecture-search', ['query' => $witness])['result']['structuredContent'];
                self::assertNotEmpty($found['result']['candidates'], json_encode($found));
                self::assertStringContainsString($witness, json_encode($found['result']['candidates']));
            }
            $absent = $clients['a']->tool('architecture-search', ['query' => 'onlyMain'])['result']['structuredContent'];
            self::assertEmpty($absent['result']['candidates']);
            $begin = $clients['a']->tool('task-control', ['action' => 'begin', 'task' => 'same-task'])['result']['structuredContent'];
            self::assertSame('started', $begin['status'], json_encode($begin));
            self::assertSame('missing', $clients['main']->tool('task-control', ['action' => 'status', 'task' => 'same-task'])['result']['structuredContent']['status']);
            self::assertTrue($clients['a']->tool('analysis-consent', ['task' => 'same-task', 'confirm' => true])['result']['structuredContent']['ok']);
            $aContext = new RepositoryContext($a, $home, 'isolation', advisoryOnly: true);
            $mainContext = new RepositoryContext($main, $home, 'isolation', advisoryOnly: true);
            self::assertTrue((new AnalysisConsent($aContext))->hasDeep('same-task'));
            self::assertFalse((new AnalysisConsent($mainContext))->hasDeep('same-task'));
            self::assertFalse((new AnalysisConsent($aContext))->hasDeep('other-task'));
            $grant = $home.'/repositories/'.$aContext->id.'/analysis-consent/'.hash('sha256', $aContext->id.':same-task').'.json';
            unlink($grant);
            $denied = $clients['a']->tool('architecture-search', ['query' => 'Witness', 'analysis_mode' => 'deep', 'task' => 'same-task'])['result']['structuredContent'];
            self::assertStringContainsString('consent', json_encode($denied));
            self::assertNotSame('current', $denied['freshness'] ?? 'unverified');
            $transcripts['a'] = $clients['a']->transcript;
            self::assertSame(0, $clients['a']->close());
            unset($clients['a']);
            $survivor = $clients['main']->tool('architecture-search', ['query' => 'onlyMain'])['result']['structuredContent'];
            self::assertNotEmpty($survivor['result']['candidates']);
        } finally {
            foreach ($clients as $name => $client) {
                $transcripts[$name] = $client->transcript;
                self::assertSame(0, $client->close());
            }
            $directory = getenv('ARCHITECTURE_KIT_WORKTREE_TRANSCRIPTS');
            if (is_string($directory) && is_dir($directory)) {
                file_put_contents($directory.'/two-processes.json', json_encode($transcripts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
            // Only coordinator records from this test-owned private home authorize shutdown.
            foreach (glob($home.'/repositories/*/runtime/*/coordinator.json') ?: [] as $recordPath) {
                $record = json_decode(file_get_contents($recordPath), true, flags: JSON_THROW_ON_ERROR);
                try {
                    $socket = GraphProtocol::socket(dirname($recordPath), $record['endpoint']);
                    GraphProtocol::send($socket, [...$record, 'action' => 'shutdown', 'request_id' => bin2hex(random_bytes(16))]);
                    fclose($socket);
                } catch (\Throwable) {
                }
            }
            (new Filesystem)->deleteDirectory($home);
        }
    }

    public function test_family_and_project_flags_are_rejected_together(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/bin/architecture-kit', '--family', $this->lab['roots']['main']['crm-backend'],
            '--project', $this->lab['roots']['a']['crm-backend'], '--tool-home', $this->lab['home'], 'mcp']);
        $process->run();
        self::assertStringContainsString('cannot be combined', $process->getErrorOutput());
        self::assertSame('', $process->getOutput());
    }
}
