<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Guard\ArchitectureGuard;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

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

        $this->assertStringContainsString('[mcp_servers.'.$this->mcpServerKey().']', $codexMcp);
        $this->assertStringContainsString('command = "php"', $codexMcp);
        $this->assertStringContainsString('args = ["artisan", "architecture-kit:mcp"]', $codexMcp);
        $this->assertStringContainsString('"'.$this->mcpServerKey().'"', $claudeMcp);
        $this->assertStringContainsString('"command": "php"', $claudeMcp);
        $this->assertStringContainsString('"architecture-kit:mcp"', $claudeMcp);
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

        $this->assertStringContainsString('[mcp_servers.'.$this->mcpServerKey().']', $codexMcp);
        $this->assertStringContainsString('command = "docker"', $codexMcp);
        $this->assertStringContainsString('"architecture-kit:mcp"', $codexMcp);
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

    public function test_it_preserves_developer_owned_legacy_architecture_kit_mcp_server_key(): void
    {
        $this->writeCurrentResources(runtime: [
            'driver' => 'docker',
            'service' => 'app',
            'php' => 'php',
            'command' => null,
        ]);

        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.codex');
        $codexMcp = <<<'TOML'
[mcp_servers.architecture-kit]
command = "docker"
args = ["compose", "exec", "-T", "app", "php", "artisan", "architecture-kit:mcp"]
required = true

[mcp_servers.architecture-kit.env]
OLD = "1"
TOML;
        $claudeMcp = json_encode([
            'mcpServers' => [
                'architecture-kit' => [
                    'command' => 'docker',
                    'args' => ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'architecture-kit:mcp'],
                ],
            ],
        ], JSON_PRETTY_PRINT);
        $files->put($this->tempPath.'/.codex/config.toml', $codexMcp);
        $files->put($this->tempPath.'/.mcp.json', $claudeMcp);

        $this->artisan('architecture-kit:install-agents --codex --claude --mcp')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $this->assertSame($codexMcp, $files->get($this->tempPath.'/.codex/config.toml'));
        $this->assertSame($claudeMcp, $files->get($this->tempPath.'/.mcp.json'));
    }

    public function test_reinstall_preserves_developer_owned_mcp_configuration_byte_for_byte(): void
    {
        $this->writeCurrentResources();

        $this->artisan('architecture-kit:install-agents --codex --claude --mcp')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $files = new Filesystem;
        $codexPath = $this->tempPath.'/.codex/config.toml';
        $claudePath = $this->tempPath.'/.mcp.json';
        $codexMcp = str_replace(
            'required = true',
            "required = false\ncustom = \"developer-owned\"",
            $files->get($codexPath),
        );
        $claudeMcp = str_replace(
            '"command": "php"',
            '"command": "./bin/custom-mcp"',
            $files->get($claudePath),
        );
        $files->put($codexPath, $codexMcp);
        $files->put($claudePath, $claudeMcp);

        $this->artisan('architecture-kit:install-agents --codex --claude --mcp')
            ->expectsOutputToContain('No agent integration changes needed.')
            ->assertExitCode(0);

        $this->assertSame($codexMcp, $files->get($codexPath));
        $this->assertSame($claudeMcp, $files->get($claudePath));
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
        $this->assertStringContainsString('architecture-kit:mcp', $files->get($this->tempPath.'/.codex/config.toml'));
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
            ->expectsOutputToContain('missing  .codex/config.toml')
            ->assertExitCode(1);
    }

    public function test_doctor_reports_every_missing_managed_agent_artifact(): void
    {
        $this->writeCurrentResources();

        $this->artisan('architecture-kit:install-agents --codex --claude --mcp --hooks')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $files = new Filesystem;

        foreach ([
            '.codex/config.toml',
            '.mcp.json',
            '.architecture-kit/hooks/guard.sh',
            '.architecture-kit/hooks/README.md',
            '.codex/hooks.json',
            '.claude/settings.json',
        ] as $path) {
            $files->delete($this->tempPath.'/'.$path);
        }

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('missing  .codex/config.toml')
            ->expectsOutputToContain('missing  .mcp.json')
            ->expectsOutputToContain('missing  .architecture-kit/hooks/guard.sh')
            ->expectsOutputToContain('missing  .architecture-kit/hooks/README.md')
            ->expectsOutputToContain('missing  .codex/hooks.json')
            ->expectsOutputToContain('missing  .claude/settings.json')
            ->assertExitCode(1);
    }

    public function test_doctor_accepts_developer_owned_configuration_and_reports_invalid_json(): void
    {
        $this->writeCurrentResources();

        $this->artisan('architecture-kit:install-agents --codex --mcp --hooks')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $files = new Filesystem;
        $files->replace(
            $this->tempPath.'/.codex/config.toml',
            str_replace('required = true', 'required = false', $files->get($this->tempPath.'/.codex/config.toml')),
        );
        $files->put($this->tempPath.'/.codex/hooks.json', '{broken');

        $codexMcp = $files->get($this->tempPath.'/.codex/config.toml');
        $codexHooks = $files->get($this->tempPath.'/.codex/hooks.json');

        $this->artisan('architecture-kit:doctor')
            ->doesntExpectOutputToContain('outdated .codex/config.toml')
            ->expectsOutputToContain('blocked  .codex/hooks.json')
            ->assertExitCode(1);

        $this->assertSame($codexMcp, $files->get($this->tempPath.'/.codex/config.toml'));
        $this->assertSame($codexHooks, $files->get($this->tempPath.'/.codex/hooks.json'));
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
