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
        $this->assertCount(3, $schema['oneOf']);
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
}
