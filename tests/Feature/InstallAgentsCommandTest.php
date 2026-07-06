<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Config\ArchitectureConfig;
use Taqie\ArchitectureKit\Guard\ArchitectureGuard;
use Taqie\ArchitectureKit\Resources\ArchitectureResources;
use Taqie\ArchitectureKit\Tests\TestCase;

class InstallAgentsCommandTest extends TestCase
{
    public function test_it_installs_codex_and_claude_mcp_and_hooks(): void
    {
        $this->writeCurrentResources();

        $this->artisan('architecture-kit:install-agents --codex --claude --mcp --hooks')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $files = new Filesystem;

        $codexMcp = $files->get($this->tempPath.'/.codex/config.toml');
        $claudeMcp = $files->get($this->tempPath.'/.mcp.json');
        $codexHooks = $files->get($this->tempPath.'/.codex/hooks.json');
        $claudeHooks = $files->get($this->tempPath.'/.claude/settings.json');
        $state = $files->get($this->tempPath.'/.architecture-kit/install.json');
        $guard = $files->get($this->tempPath.'/.architecture-kit/hooks/guard.sh');
        $makefile = $files->get($this->tempPath.'/Makefile');

        $this->assertStringContainsString('[mcp_servers.'.$this->mcpServerKey().']', $codexMcp);
        $this->assertStringContainsString('command = "make"', $codexMcp);
        $this->assertStringContainsString('args = ["mcp-architecture-kit"]', $codexMcp);
        $this->assertStringContainsString('"'.$this->mcpServerKey().'"', $claudeMcp);
        $this->assertStringContainsString('"command": "make"', $claudeMcp);
        $this->assertStringContainsString('"mcp-architecture-kit"', $claudeMcp);
        $this->assertStringContainsString('mcp-architecture-kit:', $makefile);
        $this->assertStringContainsString("'php' 'artisan' 'architecture-kit:mcp'", $makefile);
        $this->assertStringContainsString("RUNNER=('php')", $guard);
        $this->assertStringContainsString('architecture-kit: runtime unavailable', $guard);
        $this->assertStringContainsString('.architecture-kit/hooks/guard.sh', $codexHooks);
        $this->assertStringNotContainsString('_architectureKit', $codexHooks);
        $this->assertStringContainsString('.architecture-kit/hooks/guard.sh claude', $claudeHooks);
        $this->assertStringNotContainsString('_architectureKit', $claudeHooks);
        $this->assertStringContainsString('"codex"', $state);
        $this->assertStringContainsString('"claude_code"', $state);
        $this->assertSame('755', substr(sprintf('%o', fileperms($this->tempPath.'/.architecture-kit/hooks/guard.sh')), -3));
    }

    public function test_it_uses_runtime_config_for_mcp_and_hooks(): void
    {
        $this->writeCurrentResources(runtime: [
            'driver' => 'sail',
            'service' => 'api',
            'php' => 'php',
            'command' => null,
        ]);

        $this->artisan('architecture-kit:install-agents --codex --mcp --hooks')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $files = new Filesystem;
        $codexMcp = $files->get($this->tempPath.'/.codex/config.toml');
        $makefile = $files->get($this->tempPath.'/Makefile');

        $this->assertStringContainsString('[mcp_servers.'.$this->mcpServerKey().']', $codexMcp);
        $this->assertStringContainsString('command = "make"', $codexMcp);
        $this->assertStringContainsString('args = ["mcp-architecture-kit"]', $codexMcp);
        $this->assertStringContainsString("'docker' 'compose' 'exec' '-T' 'api' 'php' 'artisan' 'architecture-kit:mcp'", $makefile);
        $this->assertStringContainsString("RUNNER=('docker' 'compose' 'exec' '-T' 'api' 'php')", $files->get($this->tempPath.'/.architecture-kit/hooks/guard.sh'));
    }

    public function test_it_preserves_unrelated_mcp_servers_and_hooks(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.codex');
        $files->put($this->tempPath.'/.codex/config.toml', "[mcp_servers.other]\ncommand = \"node\"\n");
        $files->put($this->tempPath.'/.codex/hooks.json', json_encode([
            'hooks' => [
                'Stop' => [
                    [
                        'hooks' => [
                            [
                                'type' => 'command',
                                'command' => 'vendor/bin/phpunit',
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT));

        $this->artisan('architecture-kit:install-agents --codex --mcp --hooks')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $this->assertStringContainsString('[mcp_servers.other]', $files->get($this->tempPath.'/.codex/config.toml'));
        $this->assertStringContainsString('[mcp_servers.'.$this->mcpServerKey().']', $files->get($this->tempPath.'/.codex/config.toml'));
        $this->assertStringContainsString('vendor/bin/phpunit', $files->get($this->tempPath.'/.codex/hooks.json'));
    }

    public function test_it_replaces_legacy_architecture_kit_mcp_server_key(): void
    {
        $this->writeCurrentResources(runtime: [
            'driver' => 'docker',
            'service' => 'app',
            'php' => 'php',
            'command' => null,
        ]);

        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.codex');
        $files->put($this->tempPath.'/.codex/config.toml', <<<'TOML'
[mcp_servers.architecture-kit]
command = "docker"
args = ["compose", "exec", "-T", "app", "php", "artisan", "architecture-kit:mcp"]
required = true

[mcp_servers.architecture-kit.env]
OLD = "1"
TOML);
        $files->put($this->tempPath.'/.mcp.json', json_encode([
            'mcpServers' => [
                'architecture-kit' => [
                    'command' => 'docker',
                    'args' => ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'architecture-kit:mcp'],
                ],
            ],
        ], JSON_PRETTY_PRINT));

        $this->artisan('architecture-kit:install-agents --codex --claude --mcp')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $codexMcp = $files->get($this->tempPath.'/.codex/config.toml');
        $claudeMcp = json_decode($files->get($this->tempPath.'/.mcp.json'), true);

        $this->assertStringNotContainsString('[mcp_servers.architecture-kit]'."\n", $codexMcp);
        $this->assertStringNotContainsString('[mcp_servers.architecture-kit.env]'."\n", $codexMcp);
        $this->assertStringContainsString('[mcp_servers.'.$this->mcpServerKey().']', $codexMcp);
        $this->assertArrayNotHasKey('architecture-kit', $claudeMcp['mcpServers']);
        $this->assertSame('make', $claudeMcp['mcpServers'][$this->mcpServerKey()]['command']);
    }

    public function test_it_preserves_existing_makefile_mcp_target(): void
    {
        $this->writeCurrentResources();

        $files = new Filesystem;
        $makefile = "mcp-architecture-kit:\n\t./bin/custom-mcp\n";
        $files->put($this->tempPath.'/Makefile', $makefile);

        $this->artisan('architecture-kit:install-agents --codex --mcp')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $this->assertSame($makefile, $files->get($this->tempPath.'/Makefile'));
        $this->assertStringContainsString('args = ["mcp-architecture-kit"]', $files->get($this->tempPath.'/.codex/config.toml'));
    }

    public function test_it_blocks_invalid_agent_config_before_writing(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.codex');
        $files->put($this->tempPath.'/.codex/hooks.json', '{broken');
        $files->put($this->tempPath.'/.codex/config.toml', "[mcp_servers.architecture-kit]\ncommand = \"node\"\n");

        $this->artisan('architecture-kit:install-agents --codex --mcp --hooks')
            ->expectsOutputToContain('blocked  .codex/hooks.json')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->tempPath.'/.architecture-kit/install.json');
    }

    public function test_doctor_reports_agent_state(): void
    {
        $this->writeCurrentResources();

        $this->artisan('architecture-kit:install-agents --codex --mcp --hooks')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('Agents:')
            ->expectsOutputToContain('current  .architecture-kit/install.json')
            ->expectsOutputToContain('current  artisan architecture-kit:mcp')
            ->expectsOutputToContain('current  mcp:architecture-kit')
            ->assertExitCode(0);

        (new Filesystem)->delete($this->tempPath.'/.codex/config.toml');

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('current  .architecture-kit/install.json')
            ->assertExitCode(0);
    }

    public function test_guard_json_includes_agent_checks(): void
    {
        $this->writeCurrentResources();

        $this->artisan('architecture-kit:install-agents --codex --mcp --hooks')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $result = (new ArchitectureGuard(new Filesystem, dirname(__DIR__, 2), $this->tempPath))
            ->run(changedOnly: false, baseRef: null, strict: false)
            ->toArray();

        $this->assertSame(
            '.architecture-kit/install.json',
            collect($result['agents']['checks'])->firstWhere('path', '.architecture-kit/install.json')['path'] ?? null,
        );

        $this->artisan('architecture-kit:guard --json')
            ->expectsOutputToContain('"agents"')
            ->assertExitCode(0);
    }

    /**
     * @param  array<string, mixed>|null  $runtime
     */
    private function writeCurrentResources(?array $runtime = null): void
    {
        $files = new Filesystem;
        $config = new ArchitectureConfig($this->tempPath.'/config/architectures.php', $files);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath, $files);
        $enabled = [Architecture::Actions];

        $config->write($enabled, $runtime);

        foreach (array_merge([$resources->guideline($enabled)], array_values($resources->skills($enabled))) as $file) {
            $files->ensureDirectoryExists(dirname($file->path));
            $files->put($file->path, $file->contents);
        }
    }

    private function mcpServerKey(): string
    {
        $project = strtolower(basename($this->tempPath));
        $project = preg_replace('/[^a-z0-9]+/', '-', $project) ?: 'project';
        $project = trim($project, '-');

        return 'architecture-kit-'.($project === '' ? 'project' : $project);
    }
}
