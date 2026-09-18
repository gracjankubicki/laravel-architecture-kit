<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Fortify;

use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use GracjanKubicki\ArchitectureKit\Fortify\FortifyCompatibility;
use GracjanKubicki\ArchitectureKit\Fortify\FortifyCompatibilityStatus;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FortifyCompatibilityTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/architecture-kit-fortify-'.uniqid('', true);
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
            'caret' => ['^1.0', '1.0.0'],
            'minor caret' => ['^1.20', '1.20.1'],
            'bounded interval' => ['>=1.0 <2.0', '1.31.0'],
        ];
    }

    #[DataProvider('supportedConstraints')]
    public function test_it_accepts_fully_supported_fortify_1_constraints(string $constraint, string $version): void
    {
        $this->writeProject($constraint, $version, $version);

        $result = $this->resolver()->resolve();

        $this->assertTrue($result->supported());
        $this->assertSame('fortify@1', $result->profile);
        $this->assertSame($version, $result->installedVersion);
    }

    /** @return array<string, array{string}> */
    public static function unsupportedConstraints(): array
    {
        return [
            'future major' => ['^2.0'],
            'mixed majors' => ['^1.0 || ^2.0'],
            'unbounded range' => ['>=1.0'],
            'wildcard' => ['*'],
            'development branch' => ['dev-main'],
        ];
    }

    #[DataProvider('unsupportedConstraints')]
    public function test_it_rejects_constraints_that_allow_unsupported_versions(string $constraint): void
    {
        $this->writeProject($constraint, '1.0.0', '1.0.0');

        $result = $this->resolver()->resolve();

        $this->assertSame(FortifyCompatibilityStatus::UnsupportedConstraint, $result->status);
        $this->assertFalse($result->supported());
    }

    public function test_it_requires_a_direct_runtime_dependency(): void
    {
        $this->writeProject('^1.0', '1.0.0', '1.0.0', 'require-dev');

        $result = $this->resolver()->resolve();

        $this->assertSame(FortifyCompatibilityStatus::RuntimeDependencyInRequireDev, $result->status);
        $this->assertStringContainsString('composer require laravel/fortify', $result->remediation);
    }

    public function test_it_reports_a_missing_root_dependency(): void
    {
        (new Filesystem)->put($this->path.'/composer.json', '{"require":{}}');

        $result = $this->resolver()->resolve();

        $this->assertSame(FortifyCompatibilityStatus::Missing, $result->status);
        $this->assertStringContainsString('not declared directly', $result->message);
    }

    public function test_it_rejects_missing_installed_metadata(): void
    {
        $this->writeProject('^1.0', null, '1.0.0');

        $result = $this->resolver()->resolve();

        $this->assertSame(FortifyCompatibilityStatus::NotInstalled, $result->status);
    }

    public function test_it_rejects_installed_and_locked_version_drift(): void
    {
        $this->writeProject('^1.0', '1.31.0', '1.30.0');

        $result = $this->resolver()->resolve();

        $this->assertSame(FortifyCompatibilityStatus::StaleLock, $result->status);
    }

    public function test_it_reports_invalid_installed_version_metadata(): void
    {
        $this->writeProject('^1.0', 'not-a-version', 'not-a-version');

        $result = $this->resolver()->resolve();

        $this->assertSame(FortifyCompatibilityStatus::InvalidVersion, $result->status);
        $this->assertStringContainsString('Invalid laravel/fortify', $result->message);
    }

    public function test_it_rejects_a_runtime_dependency_locked_only_for_development(): void
    {
        $this->writeProject('^1.0', '1.0.0', '1.0.0');
        (new Filesystem)->put($this->path.'/composer.lock', json_encode([
            'packages' => [],
            'packages-dev' => [['name' => 'laravel/fortify', 'version' => '1.0.0']],
        ], JSON_THROW_ON_ERROR));

        $result = $this->resolver()->resolve();

        $this->assertSame(FortifyCompatibilityStatus::StaleLock, $result->status);
        $this->assertStringContainsString('packages-dev', $result->message);
    }

    public function test_it_rejects_a_runtime_dependency_missing_from_an_existing_lockfile(): void
    {
        $this->writeProject('^1.0', '1.0.0', '1.0.0');
        (new Filesystem)->put($this->path.'/composer.lock', json_encode([
            'packages' => [],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));

        $result = $this->resolver()->resolve();

        $this->assertSame(FortifyCompatibilityStatus::StaleLock, $result->status);
        $this->assertStringContainsString('missing from composer.lock', $result->message);
    }

    public function test_it_uses_installed_metadata_when_no_lockfile_exists(): void
    {
        $this->writeProject('^1.0', '1.0.0', '1.0.0');
        (new Filesystem)->delete($this->path.'/composer.lock');

        $result = $this->resolver()->resolve();

        $this->assertTrue($result->supported());
        $this->assertNull($result->lockedVersion);
    }

    private function resolver(): FortifyCompatibility
    {
        return new FortifyCompatibility(
            new ProjectPackageInventory(new Filesystem, $this->path),
        );
    }

    private function writeProject(string $constraint, ?string $installed, string $locked, string $section = 'require'): void
    {
        $files = new Filesystem;
        $files->put($this->path.'/composer.json', json_encode([
            $section => ['laravel/fortify' => $constraint],
        ], JSON_THROW_ON_ERROR));
        $files->ensureDirectoryExists($this->path.'/vendor/composer');
        $installedEntry = $installed === null
            ? '[]'
            : "['laravel/fortify' => ['pretty_version' => '{$installed}']]";
        $files->put($this->path.'/vendor/composer/installed.php', "<?php\nreturn ['versions' => {$installedEntry}];\n");
        $files->put($this->path.'/composer.lock', json_encode([
            'packages' => [['name' => 'laravel/fortify', 'version' => $locked]],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));
    }
}
