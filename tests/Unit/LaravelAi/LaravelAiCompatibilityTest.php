<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\LaravelAi;

use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use GracjanKubicki\ArchitectureKit\LaravelAi\LaravelAiCompatibility;
use GracjanKubicki\ArchitectureKit\LaravelAi\LaravelAiCompatibilityStatus;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LaravelAiCompatibilityTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/architecture-kit-ai-'.uniqid('', true);
        (new Filesystem)->ensureDirectoryExists($this->path);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->path);

        parent::tearDown();
    }

    /** @return array<string, array{string, string, string}> */
    public static function supportedConstraints(): array
    {
        return [
            '0.8 caret' => ['^0.8', '0.8.1', 'laravel-ai@0.8'],
            '0.9 caret' => ['^0.9', '0.9.0', 'laravel-ai@0.9'],
            'supported union' => ['^0.8 || ^0.9', '0.9.0', 'laravel-ai@0.9'],
            'supported interval' => ['>=0.8 <0.10', '0.8.0', 'laravel-ai@0.8'],
        ];
    }

    #[DataProvider('supportedConstraints')]
    public function test_it_selects_one_profile_for_fully_supported_constraints(string $constraint, string $version, string $profile): void
    {
        $this->writeProject($constraint, $version, $version);

        $result = $this->resolver()->resolve();

        $this->assertTrue($result->supported());
        $this->assertSame($profile, $result->profile?->key());
        $this->assertSame($version, $result->installedVersion);
    }

    /** @return array<string, array{string}> */
    public static function unsupportedConstraints(): array
    {
        return [
            'future minor' => ['^0.9 || ^0.10'],
            'future major' => ['>=0.8 <2.0'],
            'wildcard' => ['*'],
            'development branch' => ['dev-main'],
        ];
    }

    #[DataProvider('unsupportedConstraints')]
    public function test_it_rejects_constraints_that_allow_unsupported_versions(string $constraint): void
    {
        $this->writeProject($constraint, '0.9.0', '0.9.0');

        $result = $this->resolver()->resolve();

        $this->assertSame(LaravelAiCompatibilityStatus::UnsupportedConstraint, $result->status);
        $this->assertFalse($result->supported());
    }

    public function test_it_rejects_dev_only_dependency_placement(): void
    {
        $this->writeProject('^0.9', '0.9.0', '0.9.0', 'require-dev');

        $result = $this->resolver()->resolve();

        $this->assertSame(LaravelAiCompatibilityStatus::RuntimeDependencyInRequireDev, $result->status);
        $this->assertStringContainsString('composer require laravel/ai', $result->remediation);
    }

    public function test_it_rejects_stale_lock_state(): void
    {
        $this->writeProject('>=0.8 <0.10', '0.9.0', '0.8.1');

        $result = $this->resolver()->resolve();

        $this->assertSame(LaravelAiCompatibilityStatus::StaleLock, $result->status);
    }

    public function test_it_rejects_missing_referenced_capability(): void
    {
        $this->writeProject('^0.9', '0.9.0', '0.9.0', withCapabilities: false);

        $result = $this->resolver()->resolve();

        $this->assertSame(LaravelAiCompatibilityStatus::MissingCapability, $result->status);
        $this->assertNotEmpty($result->missingCapabilities);
    }

    private function resolver(): LaravelAiCompatibility
    {
        $files = new Filesystem;

        return new LaravelAiCompatibility(
            new ProjectPackageInventory($files, $this->path),
            $files,
            $this->path,
        );
    }

    private function writeProject(
        string $constraint,
        string $installed,
        string $locked,
        string $section = 'require',
        bool $withCapabilities = true,
    ): void {
        $files = new Filesystem;
        $files->put($this->path.'/composer.json', json_encode([
            $section => ['laravel/ai' => $constraint],
        ], JSON_THROW_ON_ERROR));
        $files->ensureDirectoryExists($this->path.'/vendor/composer');
        $files->put($this->path.'/vendor/composer/installed.php', "<?php\nreturn ['versions' => ['laravel/ai' => ['pretty_version' => '{$installed}']]];\n");
        $files->put($this->path.'/composer.lock', json_encode([
            'packages' => [['name' => 'laravel/ai', 'version' => $locked]],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));

        if (! $withCapabilities) {
            return;
        }

        $files->ensureDirectoryExists($this->path.'/vendor/laravel/ai/src/Responses');
        $files->put(
            $this->path.'/vendor/laravel/ai/src/Responses/StructuredAgentResponse.php',
            '<?php class StructuredAgentResponse implements ArrayAccess { public function toArray(): array {} }',
        );
        $files->ensureDirectoryExists($this->path.'/vendor/laravel/ai/src/Concerns');
        $files->put(
            $this->path.'/vendor/laravel/ai/src/Concerns/Promptable.php',
            '<?php trait Promptable { public function withProviderOptions(array $options): static {} }',
        );
    }
}
