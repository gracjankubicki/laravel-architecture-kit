<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Taqie\ArchitectureKit\Architecture;
use Taqie\ArchitectureKit\Support\ArchitectureConfig;
use Taqie\ArchitectureKit\Support\ArchitectureGuard;
use Taqie\ArchitectureKit\Support\ArchitectureResources;
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

        $this->assertStringContainsString('[mcp_servers.architecture-kit]', $codexMcp);
        $this->assertStringContainsString('args = ["artisan", "architecture-kit:mcp"]', $codexMcp);
        $this->assertStringContainsString('"architecture-kit:mcp"', $claudeMcp);
        $this->assertStringContainsString('.architecture-kit/hooks/guard.sh', $codexHooks);
        $this->assertStringNotContainsString('_architectureKit', $codexHooks);
        $this->assertStringContainsString('.architecture-kit/hooks/guard.sh claude', $claudeHooks);
        $this->assertStringNotContainsString('_architectureKit', $claudeHooks);
        $this->assertStringContainsString('"codex"', $state);
        $this->assertStringContainsString('"claude_code"', $state);
        $this->assertSame('755', substr(sprintf('%o', fileperms($this->tempPath.'/.architecture-kit/hooks/guard.sh')), -3));
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
        $this->assertStringContainsString('[mcp_servers.architecture-kit]', $files->get($this->tempPath.'/.codex/config.toml'));
        $this->assertStringContainsString('vendor/bin/phpunit', $files->get($this->tempPath.'/.codex/hooks.json'));
    }

    public function test_it_uses_configured_mcp_and_hook_commands(): void
    {
        config()->set('architectures.agents.mcp', [
            'command' => 'docker',
            'args' => ['compose', 'exec', '-T', 'api', 'php', 'artisan', 'architecture-kit:mcp'],
            'cwd' => '/repo',
        ]);
        config()->set('architectures.agents.hooks', [
            'guard_command' => ['docker', 'compose', 'run', '--rm', 'api', 'artisan', 'architecture-kit:guard'],
            'commands' => [
                'codex' => 'sh "/repo/backend/.architecture-kit/hooks/guard.sh" codex',
                'claude' => '/repo/backend/.architecture-kit/hooks/guard.sh claude',
            ],
        ]);

        $this->artisan('architecture-kit:install-agents --codex --claude --mcp --hooks')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $files = new Filesystem;

        $this->assertStringContainsString('command = "docker"', $files->get($this->tempPath.'/.codex/config.toml'));
        $this->assertStringContainsString('cwd = "/repo"', $files->get($this->tempPath.'/.codex/config.toml'));
        $this->assertStringContainsString('"command": "docker"', $files->get($this->tempPath.'/.mcp.json'));
        $this->assertStringContainsString('"cwd": "/repo"', $files->get($this->tempPath.'/.mcp.json'));
        $this->assertStringContainsString("'docker' 'compose' 'run' '--rm' 'api' 'artisan' 'architecture-kit:guard'", $files->get($this->tempPath.'/.architecture-kit/hooks/guard.sh'));
        $this->assertStringContainsString('sh \\"/repo/backend/.architecture-kit/hooks/guard.sh\\" codex', $files->get($this->tempPath.'/.codex/hooks.json'));
        $this->assertStringContainsString('/repo/backend/.architecture-kit/hooks/guard.sh claude', $files->get($this->tempPath.'/.claude/settings.json'));
    }

    public function test_it_blocks_invalid_agent_config_before_writing(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.codex');
        $files->put($this->tempPath.'/.codex/hooks.json', '{broken');
        $files->put($this->tempPath.'/.codex/config.toml', "[mcp_servers.architecture-kit]\ncommand = \"node\"\n");

        $this->artisan('architecture-kit:install-agents --codex --mcp --hooks')
            ->expectsOutputToContain('blocked  .codex/config.toml')
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
            ->expectsOutputToContain('current  .codex/config.toml')
            ->expectsOutputToContain('current  .codex/hooks.json')
            ->assertExitCode(0);

        (new Filesystem)->delete($this->tempPath.'/.codex/config.toml');

        $this->artisan('architecture-kit:doctor')
            ->expectsOutputToContain('missing  .codex/config.toml')
            ->assertExitCode(1);
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
            '.codex/config.toml',
            collect($result['agents']['checks'])->firstWhere('path', '.codex/config.toml')['path'] ?? null,
        );

        $this->artisan('architecture-kit:guard --json')
            ->expectsOutputToContain('"agents"')
            ->assertExitCode(0);
    }

    private function writeCurrentResources(): void
    {
        $files = new Filesystem;
        $config = new ArchitectureConfig($this->tempPath.'/config/architectures.php', $files);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath, $files);
        $enabled = [Architecture::Actions];

        $config->write($enabled);

        foreach (array_merge([$resources->guideline($enabled)], array_values($resources->skills($enabled))) as $file) {
            $files->ensureDirectoryExists(dirname($file->path));
            $files->put($file->path, $file->contents);
        }
    }
}
