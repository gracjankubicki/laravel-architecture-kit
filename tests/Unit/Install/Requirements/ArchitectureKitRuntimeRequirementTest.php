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
}
