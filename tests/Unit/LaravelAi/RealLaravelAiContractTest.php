<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\LaravelAi;

use ArrayAccess;
use Composer\InstalledVersions;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use GracjanKubicki\ArchitectureKit\LaravelAi\LaravelAiCompatibility;
use GracjanKubicki\ArchitectureKit\LaravelAi\LaravelAiProfile;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use Illuminate\Filesystem\Filesystem;
use Laravel\Ai\Responses\StructuredAgentResponse;
use PHPUnit\Framework\TestCase;

final class RealLaravelAiContractTest extends TestCase
{
    public function test_installed_real_package_satisfies_the_selected_profile_contract(): void
    {
        if (! InstalledVersions::isInstalled('laravel/ai')) {
            $this->markTestSkipped('The real Laravel AI contract runs in the dedicated 0.8/0.9 CI matrix.');
        }

        $root = dirname(__DIR__, 3);
        $files = new Filesystem;
        $result = (new LaravelAiCompatibility(
            new ProjectPackageInventory($files, $root),
            $files,
            $root,
        ))->resolve();

        $this->assertTrue($result->supported(), $result->message.' '.$result->remediation);
        $this->assertNotNull($result->profile);
        $this->assertTrue(class_exists(StructuredAgentResponse::class));
        $this->assertTrue(method_exists(StructuredAgentResponse::class, 'toArray'));
        $this->assertTrue(is_subclass_of(StructuredAgentResponse::class, ArrayAccess::class));

        if ($result->profile === LaravelAiProfile::V09) {
            $this->assertSourceContains($root.'/vendor/laravel/ai/src', 'withProviderOptions');
        }

        $target = sys_get_temp_dir().'/architecture-kit-real-ai-'.uniqid('', true);
        $files->ensureDirectoryExists($target);

        try {
            $skill = (new ArchitectureResources($root, $target, $files, laravelAi: $result))
                ->skills([Architecture::LaravelAi])['laravel-ai']->contents;

            $this->assertStringContainsString('Profile: `'.$result->profile->key().'`', $skill);
            $this->assertStringContainsString('response->toArray()', $skill);
            $this->assertStringNotContainsString('structuredOutput()', $skill);
        } finally {
            $files->deleteDirectory($target);
        }
    }

    private function assertSourceContains(string $path, string $needle): void
    {
        $files = new Filesystem;

        foreach ($files->allFiles($path) as $file) {
            if ($file->getExtension() === 'php' && str_contains($files->get($file->getPathname()), $needle)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail("Real laravel/ai source does not contain {$needle}.");
    }
}
