<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Install\Requirements;

use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use GracjanKubicki\ArchitectureKit\Install\Requirements\ArchitectureKitRuntimeRequirement;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class ArchitectureKitRuntimeRequirementTest extends TestCase
{
    public function test_it_requires_architecture_kit_in_root_runtime_require(): void
    {
        $path = sys_get_temp_dir().'/architecture-kit-runtime-'.uniqid('', true);
        $files = new Filesystem;
        $files->ensureDirectoryExists($path);
        $files->put($path.'/composer.json', json_encode([
            'require-dev' => ['gracjankubicki/laravel-architecture-kit' => '^0.1'],
        ], JSON_THROW_ON_ERROR));

        $result = (new ArchitectureKitRuntimeRequirement(new ProjectPackageInventory($files, $path)))->check();

        $this->assertFalse($result->satisfied);
        $this->assertSame('require-dev', $result->section);
        $this->assertStringContainsString('composer remove --dev', $result->remediation);

        $files->deleteDirectory($path);
    }

    public function test_it_rejects_architecture_kit_locked_only_for_development(): void
    {
        $path = sys_get_temp_dir().'/architecture-kit-runtime-'.uniqid('', true);
        $files = new Filesystem;
        $files->ensureDirectoryExists($path);
        $files->put($path.'/composer.json', json_encode([
            'require' => ['gracjankubicki/laravel-architecture-kit' => '^0.2'],
        ], JSON_THROW_ON_ERROR));
        $files->put($path.'/composer.lock', json_encode([
            'packages' => [],
            'packages-dev' => [['name' => 'gracjankubicki/laravel-architecture-kit', 'version' => '0.2.0']],
        ], JSON_THROW_ON_ERROR));

        $result = (new ArchitectureKitRuntimeRequirement(new ProjectPackageInventory($files, $path)))->check();

        $this->assertFalse($result->satisfied);
        $this->assertSame('require', $result->section);
        $this->assertStringContainsString('packages-dev', $result->message);
        $this->assertStringContainsString('composer update gracjankubicki/laravel-architecture-kit', $result->remediation);

        $files->deleteDirectory($path);
    }

    public function test_it_rejects_architecture_kit_missing_from_an_existing_lockfile(): void
    {
        $path = sys_get_temp_dir().'/architecture-kit-runtime-'.uniqid('', true);
        $files = new Filesystem;
        $files->ensureDirectoryExists($path);
        $files->put($path.'/composer.json', json_encode([
            'require' => ['gracjankubicki/laravel-architecture-kit' => '^0.2'],
        ], JSON_THROW_ON_ERROR));
        $files->put($path.'/composer.lock', json_encode([
            'packages' => [],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));

        $result = (new ArchitectureKitRuntimeRequirement(new ProjectPackageInventory($files, $path)))->check();

        $this->assertFalse($result->satisfied);
        $this->assertStringContainsString('missing from composer.lock', $result->message);

        $files->deleteDirectory($path);
    }

    public function test_it_accepts_root_runtime_placement_when_no_lockfile_exists(): void
    {
        $path = sys_get_temp_dir().'/architecture-kit-runtime-'.uniqid('', true);
        $files = new Filesystem;
        $files->ensureDirectoryExists($path);
        $files->put($path.'/composer.json', json_encode([
            'require' => ['gracjankubicki/laravel-architecture-kit' => '^0.2'],
        ], JSON_THROW_ON_ERROR));

        $result = (new ArchitectureKitRuntimeRequirement(new ProjectPackageInventory($files, $path)))->check();

        $this->assertTrue($result->satisfied);
        $this->assertSame('require', $result->section);

        $files->deleteDirectory($path);
    }
}
