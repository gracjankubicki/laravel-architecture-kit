<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Resources\UpgradeGuideResources;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class UpgradePlanCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->writePackageState('^0.8', '0.8.1', '0.8.1');
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->write([Architecture::LaravelAi]);
        $this->writeGeneratedUpgradeSkills();
    }

    public function test_agent_output_reports_the_full_route_and_one_active_step_without_writing(): void
    {
        $files = new Filesystem;
        $before = $this->snapshot($files);

        $exit = Artisan::call('architecture-kit:upgrade-plan', [
            'package' => 'laravel/ai',
            '--to' => '0.10',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertSame('upgrade-plan', $payload['cmd']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('0.8.1', $payload['state']['locked']);
        $this->assertSame(['ready', 'pending'], array_column($payload['route'], 'status'));
        $this->assertSame('architecture-kit-upgrade-laravel-ai-0-8-to-0-9', $payload['active']['skill']);
        $this->assertSame($before, $this->snapshot($files));
    }

    public function test_human_output_uses_the_same_plan(): void
    {
        $this->artisan('architecture-kit:upgrade-plan laravel/ai --to=0.10')
            ->expectsOutputToContain('Architecture Kit Upgrade Plan')
            ->expectsOutputToContain('Declared:   ^0.8')
            ->expectsOutputToContain('READY   0.8 -> 0.9')
            ->expectsOutputToContain('PENDING 0.9 -> 0.10')
            ->expectsOutputToContain('No files were changed.')
            ->assertExitCode(0);
    }

    public function test_a_controlled_blocker_uses_the_published_plan_shape_and_fails(): void
    {
        $this->writePackageState('^0.8', '0.8.1', '0.8.2');

        $exit = Artisan::call('architecture-kit:upgrade-plan', [
            'package' => 'laravel/ai',
            '--to' => '0.10',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertFalse($payload['ok']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('0.8.1', $payload['state']['locked']);
        $this->assertSame('0.8.2', $payload['state']['installed']);
        $this->assertStringContainsString('do not match', $payload['message']);
    }

    public function test_schema_is_available_without_command_arguments(): void
    {
        $exit = Artisan::call('architecture-kit:upgrade-plan', ['--schema' => true]);
        $schema = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('Architecture Kit upgrade plan agent output', $schema['title']);
        $this->assertSame('upgrade-plan', $schema['oneOf'][0]['properties']['cmd']['const']);
        $this->assertSame('upgrade-plan', $schema['oneOf'][1]['properties']['cmd']['const']);
    }

    public function test_missing_arguments_return_a_schema_compatible_command_error(): void
    {
        $exit = Artisan::call('architecture-kit:upgrade-plan', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertFalse($payload['ok']);
        $this->assertSame('upgrade-plan', $payload['cmd']);
        $this->assertSame('E_COMMAND_FAILED', $payload['m']);
    }

    private function writePackageState(string $constraint, string $locked, string $installed): void
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
                ['name' => 'gracjankubicki/laravel-architecture-kit', 'version' => '0.2.3'],
                ['name' => 'laravel/ai', 'version' => $locked],
            ],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));
        $files->ensureDirectoryExists($this->tempPath.'/vendor/composer');
        $files->put(
            $this->tempPath.'/vendor/composer/installed.php',
            "<?php\nreturn ['versions' => ['laravel/ai' => ['pretty_version' => '{$installed}']]];\n",
        );
    }

    private function writeGeneratedUpgradeSkills(): void
    {
        $files = new Filesystem;
        $resources = new UpgradeGuideResources(dirname(__DIR__, 2), $this->tempPath, $files);

        foreach ($resources->skills([Architecture::LaravelAi]) as $skill) {
            $files->ensureDirectoryExists(dirname($skill->path));
            $files->put($skill->path, $skill->contents);
        }
    }

    /** @return array<string, string> */
    private function snapshot(Filesystem $files): array
    {
        $snapshot = [];

        foreach ($files->allFiles($this->tempPath) as $file) {
            $path = str_replace($this->tempPath.'/', '', $file->getPathname());
            $snapshot[$path] = hash_file('sha256', $file->getPathname());
        }

        ksort($snapshot);

        return $snapshot;
    }
}
