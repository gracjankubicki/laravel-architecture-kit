<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

class GuidelinesCommandTest extends TestCase
{
    public function test_it_lists_known_architectures_for_agents_and_humans(): void
    {
        $this->writeConfig([Architecture::Actions]);

        $exitCode = Artisan::call('architecture-kit:guidelines', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('guidelines', $payload['cmd']);
        $this->assertSame('actions', $payload['arch'][2]['slug']);
        $this->assertTrue($payload['arch'][2]['enabled']);
        $this->assertStringContainsString('Actions are final application use cases', $payload['arch'][2]['sum']);
        $this->assertFalse($payload['arch'][11]['enabled']);
        $this->assertSame(['guidelines {slug} --agent'], $payload['next']);

        $this->artisan('architecture-kit:guidelines')
            ->expectsOutputToContain('actions')
            ->expectsOutputToContain('Actions')
            ->assertExitCode(0);
    }

    public function test_it_returns_one_expanded_guideline_for_agents(): void
    {
        $this->writeConfig([Architecture::Actions]);

        $exitCode = Artisan::call('architecture-kit:guidelines', [
            'architecture' => 'actions',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('guidelines', $payload['cmd']);
        $this->assertSame('actions', $payload['slug']);
        $this->assertTrue($payload['enabled']);
        $this->assertSame('architecture-kit-actions', $payload['skill']);
        $this->assertStringContainsString('## Actions', $payload['md']);
        $this->assertStringContainsString('Good example:', $payload['md']);
    }

    public function test_it_expands_known_disabled_architecture(): void
    {
        $this->writeConfig([Architecture::Actions]);

        $exitCode = Artisan::call('architecture-kit:guidelines', [
            'architecture' => 'saloon',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('saloon', $payload['slug']);
        $this->assertFalse($payload['enabled']);
        $this->assertStringContainsString('## Saloon', $payload['md']);
    }

    public function test_it_reports_unknown_architecture(): void
    {
        $this->writeConfig([Architecture::Actions]);

        $exitCode = Artisan::call('architecture-kit:guidelines', [
            'architecture' => 'acitons',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('E_UNKNOWN_ARCHITECTURE', $payload['m']);
        $this->assertContains('actions', $payload['known']);
    }

    public function test_it_exposes_guidelines_schema(): void
    {
        $exitCode = Artisan::call('architecture-kit:guidelines', ['--schema' => true]);
        $schema = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('Architecture Kit guidelines agent output', $schema['title']);
        $this->assertCount(4, $schema['oneOf']);
    }

    public function test_it_expands_the_same_resolved_laravel_ai_profile_as_project_state(): void
    {
        $this->writeLaravelAiFixture('^0.9', '0.9.0');
        $this->writeConfig([Architecture::LaravelAi]);

        $exit = Artisan::call('architecture-kit:guidelines', [
            'architecture' => 'laravel-ai',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('laravel-ai@0.9', $payload['laravel_ai']['profile']);
        $this->assertStringContainsString('Compatibility profile: `laravel-ai@0.9`', $payload['md']);
        $this->assertStringContainsString('Installed version: `0.9.0`', $payload['md']);
    }

    public function test_it_expands_the_laravel_ai_010_profile_for_agents(): void
    {
        $this->writeLaravelAiFixture('^0.10', '0.10.1');
        $this->writeConfig([Architecture::LaravelAi]);

        $exit = Artisan::call('architecture-kit:guidelines', [
            'architecture' => 'laravel-ai',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('laravel-ai@0.10', $payload['laravel_ai']['profile']);
        $this->assertStringContainsString('human-in-the-loop approval', $payload['md']);
        $this->assertStringContainsString('approval_state', $payload['md']);
    }

    public function test_it_fails_when_config_is_missing(): void
    {
        (new Filesystem)->delete($this->tempPath.'/config/architectures.php');

        $exitCode = Artisan::call('architecture-kit:guidelines', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('E_COMMAND_FAILED', $payload['m']);
        $this->assertStringContainsString('config/architectures.php does not exist', $payload['msg']);
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    private function writeConfig(array $enabled): void
    {
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->write($enabled);
    }

    private function writeLaravelAiFixture(string $constraint, string $version): void
    {
        $files = new Filesystem;
        $files->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'gracjankubicki/laravel-architecture-kit' => '^0.2',
                'laravel/ai' => $constraint,
            ],
        ], JSON_THROW_ON_ERROR));
        $files->put($this->tempPath.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'gracjankubicki/laravel-architecture-kit', 'version' => '0.2.0'],
                ['name' => 'laravel/ai', 'version' => $version],
            ],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));
        $files->ensureDirectoryExists($this->tempPath.'/vendor/composer');
        $files->put($this->tempPath.'/vendor/composer/installed.php', "<?php\nreturn ['versions' => ['laravel/ai' => ['pretty_version' => '{$version}']]];\n");
        $files->ensureDirectoryExists($this->tempPath.'/vendor/laravel/ai/src/Responses');
        $files->put($this->tempPath.'/vendor/laravel/ai/src/Responses/StructuredAgentResponse.php', '<?php class StructuredAgentResponse implements ArrayAccess { public function toArray(): array {} }');
        $files->ensureDirectoryExists($this->tempPath.'/vendor/laravel/ai/src/Concerns');
        $files->put($this->tempPath.'/vendor/laravel/ai/src/Concerns/ProviderOptions.php', '<?php trait ProviderOptions { public function withProviderOptions(array $options): static {} }');
        $files->ensureDirectoryExists($this->tempPath.'/vendor/laravel/ai/src/Approvals');
        $files->put($this->tempPath.'/vendor/laravel/ai/src/Approvals/Decisions.php', '<?php class Decisions {}');
        $files->ensureDirectoryExists($this->tempPath.'/vendor/laravel/ai/src/Contracts');
        $files->put($this->tempPath.'/vendor/laravel/ai/src/Contracts/ConversationStore.php', '<?php interface ConversationStore { public function storeApprovalResults(): void; }');
    }
}
