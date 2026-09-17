<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Inertia;

use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use GracjanKubicki\ArchitectureKit\Inertia\InertiaCompatibility;
use GracjanKubicki\ArchitectureKit\Inertia\InertiaCompatibilityStatus;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InertiaCompatibilityTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/architecture-kit-inertia-'.uniqid('', true);
        (new Filesystem)->ensureDirectoryExists($this->path);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->path);

        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function supportedConstraints(): array
    {
        return [
            'caret' => ['^3.0', '3.0.0'],
            'minor caret' => ['^3.2', '3.2.1'],
            'bounded interval' => ['>=3.0 <4.0', '3.1.0'],
        ];
    }

    #[DataProvider('supportedConstraints')]
    public function test_it_accepts_fully_supported_inertia_3_constraints(string $constraint, string $version): void
    {
        $this->writeProject($constraint, $version, $version);

        $result = $this->resolver()->resolve();

        $this->assertTrue($result->supported());
        $this->assertSame('inertia@3', $result->profile);
        $this->assertSame($version, $result->installedVersion);
    }

    /** @return array<string, array{string}> */
    public static function unsupportedConstraints(): array
    {
        return [
            'legacy major' => ['^2.0'],
            'future major' => ['^3.0 || ^4.0'],
            'unbounded range' => ['>=3.0'],
            'wildcard' => ['*'],
            'development branch' => ['dev-main'],
        ];
    }

    #[DataProvider('unsupportedConstraints')]
    public function test_it_rejects_constraints_that_allow_unsupported_versions(string $constraint): void
    {
        $this->writeProject($constraint, '3.0.0', '3.0.0');

        $result = $this->resolver()->resolve();

        $this->assertSame(InertiaCompatibilityStatus::UnsupportedConstraint, $result->status);
        $this->assertFalse($result->supported());
    }

    public function test_it_requires_a_direct_runtime_dependency(): void
    {
        $this->writeProject('^3.0', '3.0.0', '3.0.0', 'require-dev');

        $result = $this->resolver()->resolve();

        $this->assertSame(InertiaCompatibilityStatus::RuntimeDependencyInRequireDev, $result->status);
        $this->assertStringContainsString('composer require inertiajs/inertia-laravel', $result->remediation);
    }

    public function test_it_rejects_missing_installed_metadata(): void
    {
        $this->writeProject('^3.0', null, '3.0.0');

        $result = $this->resolver()->resolve();

        $this->assertSame(InertiaCompatibilityStatus::NotInstalled, $result->status);
    }

    public function test_it_rejects_installed_and_locked_version_drift(): void
    {
        $this->writeProject('^3.0', '3.1.0', '3.0.0');

        $result = $this->resolver()->resolve();

        $this->assertSame(InertiaCompatibilityStatus::StaleLock, $result->status);
    }

    public function test_it_reports_invalid_installed_version_metadata(): void
    {
        $this->writeProject('^3.0', 'not-a-version', 'not-a-version');

        $result = $this->resolver()->resolve();

        $this->assertSame(InertiaCompatibilityStatus::InvalidVersion, $result->status);
        $this->assertStringContainsString('Invalid inertiajs/inertia-laravel', $result->message);
        $this->assertStringContainsString('composer install', $result->remediation);
    }

    public function test_it_rejects_a_runtime_dependency_locked_only_for_development(): void
    {
        $this->writeProject('^3.0', '3.0.0', '3.0.0');
        (new Filesystem)->put($this->path.'/composer.lock', json_encode([
            'packages' => [],
            'packages-dev' => [['name' => 'inertiajs/inertia-laravel', 'version' => '3.0.0']],
        ], JSON_THROW_ON_ERROR));

        $result = $this->resolver()->resolve();

        $this->assertSame(InertiaCompatibilityStatus::StaleLock, $result->status);
        $this->assertStringContainsString('packages-dev', $result->message);
    }

    public function test_it_rejects_a_runtime_dependency_missing_from_an_existing_lockfile(): void
    {
        $this->writeProject('^3.0', '3.0.0', '3.0.0');
        (new Filesystem)->put($this->path.'/composer.lock', json_encode([
            'packages' => [],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));

        $result = $this->resolver()->resolve();

        $this->assertSame(InertiaCompatibilityStatus::StaleLock, $result->status);
        $this->assertStringContainsString('missing from composer.lock', $result->message);
    }

    public function test_it_uses_installed_metadata_when_no_lockfile_exists(): void
    {
        $this->writeProject('^3.0', '3.0.0', '3.0.0');
        (new Filesystem)->delete($this->path.'/composer.lock');

        $result = $this->resolver()->resolve();

        $this->assertTrue($result->supported());
        $this->assertNull($result->lockedVersion);
    }

    private function resolver(): InertiaCompatibility
    {
        $files = new Filesystem;

        return new InertiaCompatibility(
            new ProjectPackageInventory($files, $this->path),
        );
    }

    private function writeProject(string $constraint, ?string $installed, string $locked, string $section = 'require'): void
    {
        $files = new Filesystem;
        $files->put($this->path.'/composer.json', json_encode([
            $section => ['inertiajs/inertia-laravel' => $constraint],
        ], JSON_THROW_ON_ERROR));
        $files->ensureDirectoryExists($this->path.'/vendor/composer');
        $installedEntry = $installed === null
            ? '[]'
            : "['inertiajs/inertia-laravel' => ['pretty_version' => '{$installed}']]";
        $files->put($this->path.'/vendor/composer/installed.php', "<?php\nreturn ['versions' => {$installedEntry}];\n");
        $files->put($this->path.'/composer.lock', json_encode([
            'packages' => [['name' => 'inertiajs/inertia-laravel', 'version' => $locked]],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));
    }
}
