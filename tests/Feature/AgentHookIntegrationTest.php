<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Install\Hooks\CodexHookWriter;
use GracjanKubicki\ArchitectureKit\Install\Hooks\HookWriter;
use GracjanKubicki\ArchitectureKit\Install\RuntimeResolver;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class AgentHookIntegrationTest extends TestCase
{
    public function test_it_exposes_one_agent_installation_command(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('architecture-kit:install-agents')
            ->doesntExpectOutputToContain('architecture-kit:install-hooks')
            ->assertExitCode(0);
    }

    public function test_it_generates_codex_and_claude_hooks(): void
    {
        $this->artisan('architecture-kit:install-agents --codex --claude --hooks')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $files = new Filesystem;

        $guard = $files->get($this->tempPath.'/.architecture-kit/hooks/guard.sh');
        $codex = $files->get($this->tempPath.'/.codex/hooks.json');
        $claude = $files->get($this->tempPath.'/.claude/settings.json');

        $this->assertStringContainsString('Bootstrapped by Laravel Architecture Kit', $guard);
        $this->assertStringContainsString('Developer-owned after creation', $guard);
        $this->assertStringContainsString('architecture-kit:guard --changed --strict --json', $guard);
        $this->assertStringContainsString('"Stop"', $codex);
        $this->assertStringContainsString('.architecture-kit/hooks/guard.sh', $codex);
        $this->assertStringNotContainsString('_architectureKit', $codex);
        $this->assertStringContainsString('"Stop"', $claude);
        $this->assertStringContainsString('.architecture-kit/hooks/guard.sh claude', $claude);
        $this->assertStringNotContainsString('_architectureKit', $claude);
        $this->assertSame('755', substr(sprintf('%o', fileperms($this->tempPath.'/.architecture-kit/hooks/guard.sh')), -3));
    }

    public function test_codex_hook_command_runs_the_real_generated_guard_script(): void
    {
        $this->artisan('architecture-kit:install-agents --codex --hooks')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $process = Process::fromShellCommandline((new CodexHookWriter)->command(), $this->tempPath);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertStringNotContainsString('Syntax error', $output);
        $this->assertStringNotContainsString('Illegal option', $output);
        $this->assertStringContainsString('architecture-kit:', $output);
    }

    public function test_the_codex_command_lets_the_guard_shebang_choose_the_interpreter(): void
    {
        $this->artisan('architecture-kit:install-agents --codex --hooks')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        // A developer may replace the generated script with a POSIX one, so the hook
        // must not force an interpreter of its own.
        $files = new Filesystem;
        $guard = $this->tempPath.'/.architecture-kit/hooks/guard.sh';
        $files->put($guard, "#!/bin/sh\nprintf '%s\\n' posix-guard\n");
        $files->chmod($guard, 0755);

        $process = Process::fromShellCommandline((new CodexHookWriter)->command(), $this->tempPath);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("posix-guard\n", $process->getOutput());
    }

    public function test_the_generated_guard_script_is_rejected_by_a_posix_shell(): void
    {
        $this->artisan('architecture-kit:install-agents --codex --hooks')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $dash = (new ExecutableFinder)->find('dash');

        if ($dash === null) {
            $this->markTestSkipped('dash is not available, so the POSIX-shell incompatibility cannot be demonstrated here.');
        }

        // The generated script uses bash arrays and bash-only options, which is why the
        // hook must rely on its shebang instead of handing it to /bin/sh.
        $guard = $this->tempPath.'/.architecture-kit/hooks/guard.sh';

        $underDash = new Process([$dash, $guard, 'codex'], $this->tempPath);
        $underDash->run();

        $underBash = new Process(['bash', $guard, 'codex'], $this->tempPath);
        $underBash->run();

        $dashOutput = $underDash->getOutput().$underDash->getErrorOutput();
        $bashOutput = $underBash->getOutput().$underBash->getErrorOutput();

        $this->assertMatchesRegularExpression('/Illegal option|Syntax error|not found/', $dashOutput);
        $this->assertDoesNotMatchRegularExpression('/Illegal option|Syntax error/', $bashOutput);
        $this->assertStringContainsString('architecture-kit:', $bashOutput);
    }

    public function test_codex_hook_command_prefers_the_application_guard_in_a_monorepo(): void
    {
        $files = new Filesystem;
        $repoPath = $this->tempPath.'/repo';
        $appPath = $repoPath.'/app';
        $repoGuard = $repoPath.'/.architecture-kit/hooks/guard.sh';
        $appGuard = $appPath.'/.architecture-kit/hooks/guard.sh';

        $files->ensureDirectoryExists(dirname($repoGuard));
        $files->ensureDirectoryExists(dirname($appGuard));
        $files->put($repoGuard, "#!/usr/bin/env bash\nprintf '%s\n' repo-guard\n");
        $files->put($appGuard, "#!/usr/bin/env bash\nprintf '%s\n' app-guard\n");

        $process = Process::fromShellCommandline((new CodexHookWriter)->command(), $appPath);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("app-guard\n", $process->getOutput());
    }

    public function test_codex_hook_command_uses_the_current_directory_for_a_root_project(): void
    {
        $files = new Filesystem;
        $guard = $this->tempPath.'/.architecture-kit/hooks/guard.sh';

        $files->ensureDirectoryExists(dirname($guard));
        $files->put($guard, "#!/usr/bin/env bash\nprintf '%s\n' root-guard\n");

        $process = Process::fromShellCommandline((new CodexHookWriter)->command(), $this->tempPath);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("root-guard\n", $process->getOutput());
    }

    public function test_codex_hook_command_falls_back_to_the_git_root_when_the_current_directory_has_no_guard(): void
    {
        $files = new Filesystem;
        $repoPath = $this->tempPath.'/repo';
        $workingPath = $repoPath.'/tools';
        $guard = $repoPath.'/.architecture-kit/hooks/guard.sh';
        $fakeGit = $repoPath.'/bin/git';

        $files->ensureDirectoryExists(dirname($guard));
        $files->ensureDirectoryExists(dirname($fakeGit));
        $files->ensureDirectoryExists($workingPath);
        $files->put($guard, "#!/usr/bin/env bash\nprintf '%s\n' fallback-guard\n");
        $files->put($fakeGit, "#!/usr/bin/env sh\nprintf '%s\n' ".escapeshellarg($repoPath)."\n");
        $files->chmod($fakeGit, 0755);

        $process = Process::fromShellCommandline(
            (new CodexHookWriter)->command(),
            $workingPath,
            ['PATH' => dirname($fakeGit).':'.(getenv('PATH') ?: '')],
        );
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("fallback-guard\n", $process->getOutput());
    }

    public function test_it_is_idempotent_for_generated_hooks(): void
    {
        $this->artisan('architecture-kit:install-agents --codex --hooks')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $this->artisan('architecture-kit:install-agents --codex --hooks')
            ->expectsOutputToContain('No agent integration changes needed.')
            ->assertExitCode(0);
    }

    public function test_it_blocks_unmanaged_hook_config(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.codex');
        $files->put($this->tempPath.'/.codex/hooks.json', '{not json');

        $this->artisan('architecture-kit:install-agents --codex --hooks')
            ->expectsOutputToContain('blocked  .codex/hooks.json')
            ->assertExitCode(1);

        $this->assertSame('{not json', $files->get($this->tempPath.'/.codex/hooks.json'));
    }

    public function test_it_merges_existing_hook_config(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.codex');
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

        $this->artisan('architecture-kit:install-agents --codex --hooks')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $contents = $files->get($this->tempPath.'/.codex/hooks.json');

        $this->assertStringContainsString('vendor/bin/phpunit', $contents);
        $this->assertStringContainsString('.architecture-kit/hooks/guard.sh', $contents);
    }

    public function test_it_preserves_developer_owned_legacy_codex_hook_config(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/.codex');
        $contents = json_encode([
            'hooks' => [
                'Stop' => [
                    [
                        'hooks' => [
                            [
                                'type' => 'command',
                                'command' => 'sh "$(git rev-parse --show-toplevel)/.architecture-kit/hooks/guard.sh" codex',
                                'statusMessage' => 'Generated by Laravel Architecture Kit. Do not edit manually.',
                            ],
                        ],
                    ],
                ],
            ],
            '_architectureKit' => [
                'generated' => true,
            ],
        ], JSON_PRETTY_PRINT);
        $files->put($this->tempPath.'/.codex/hooks.json', $contents);

        $this->artisan('architecture-kit:install-agents --codex --hooks')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $this->assertSame($contents, $files->get($this->tempPath.'/.codex/hooks.json'));
    }

    public function test_reinstall_preserves_all_developer_owned_hook_files_byte_for_byte(): void
    {
        $this->artisan('architecture-kit:install-agents --codex --claude --hooks')
            ->expectsConfirmation('Continue?', 'yes')
            ->assertExitCode(0);

        $files = new Filesystem;
        $customizations = [
            '.codex/hooks.json' => str_replace('120', '45', $files->get($this->tempPath.'/.codex/hooks.json')),
            '.claude/settings.json' => str_replace('120', '60', $files->get($this->tempPath.'/.claude/settings.json')),
            '.architecture-kit/hooks/guard.sh' => "#!/usr/bin/env bash\n# developer-owned guard\nexit 0\n",
            '.architecture-kit/hooks/README.md' => "# Project guard\n\nDeveloper instructions.\n",
        ];

        foreach ($customizations as $path => $contents) {
            $files->put($this->tempPath.'/'.$path, $contents);
        }

        $this->artisan('architecture-kit:install-agents --codex --claude --hooks')
            ->expectsOutputToContain('No agent integration changes needed.')
            ->assertExitCode(0);

        foreach ($customizations as $path => $contents) {
            $this->assertSame($contents, $files->get($this->tempPath.'/'.$path));
        }
    }

    public function test_generated_hook_allows_a_valid_gate_and_fails_closed_when_runtime_is_unavailable(): void
    {
        $files = new Filesystem;
        $runner = $this->tempPath.'/fake-runtime.sh';
        $files->put($runner, <<<'SH'
#!/usr/bin/env sh
if [ "${ARCHITECTURE_KIT_FAKE_FAILURE:-0}" = "1" ]; then
    exit 9
fi

printf '%s\n' '{"ok":true}'
SH);
        $files->chmod($runner, 0755);

        (new HookWriter(
            $files,
            $this->tempPath,
            new RuntimeResolver(['driver' => 'custom', 'command' => [$runner]]),
        ))->write([]);

        $guard = $files->get($this->tempPath.'/.architecture-kit/hooks/guard.sh');
        $this->assertStringContainsString('case "$OUTPUT" in', $guard);
        $this->assertStringNotContainsString('| grep -q', $guard);

        $hook = new Process([$this->tempPath.'/.architecture-kit/hooks/guard.sh', 'codex'], $this->tempPath);
        $hook->run();

        $this->assertSame(0, $hook->getExitCode());

        $failedHook = new Process([$this->tempPath.'/.architecture-kit/hooks/guard.sh', 'codex'], $this->tempPath, [
            'ARCHITECTURE_KIT_FAKE_FAILURE' => '1',
        ]);
        $failedHook->run();

        $this->assertSame(9, $failedHook->getExitCode());
        $this->assertStringContainsString('runtime unavailable', $failedHook->getErrorOutput());

        $failedClaudeHook = new Process([$this->tempPath.'/.architecture-kit/hooks/guard.sh', 'claude'], $this->tempPath, [
            'ARCHITECTURE_KIT_FAKE_FAILURE' => '1',
        ]);
        $failedClaudeHook->run();

        $this->assertSame(2, $failedClaudeHook->getExitCode());
        $this->assertStringContainsString('runtime unavailable', $failedClaudeHook->getErrorOutput());
    }
}
