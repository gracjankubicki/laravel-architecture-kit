<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Composer;

use GracjanKubicki\ArchitectureKit\Composer\ProjectPackageInventory;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class ProjectPackageInventoryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/architecture-kit-composer-'.uniqid('', true);
        (new Filesystem)->ensureDirectoryExists($this->path.'/vendor/composer');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->path);

        parent::tearDown();
    }

    public function test_it_reads_root_installed_and_locked_package_state(): void
    {
        $files = new Filesystem;
        $files->put($this->path.'/composer.json', json_encode([
            'require' => ['laravel/ai' => '^0.9'],
        ], JSON_THROW_ON_ERROR));
        $files->put($this->path.'/vendor/composer/installed.php', <<<'PHP'
<?php

return ['versions' => ['laravel/ai' => ['pretty_version' => 'v0.9.0', 'version' => '0.9.0.0']]];
PHP);
        $files->put($this->path.'/composer.lock', json_encode([
            'packages' => [['name' => 'laravel/ai', 'version' => 'v0.9.0']],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));

        $package = (new ProjectPackageInventory($files, $this->path))->package('laravel/ai');

        $this->assertSame('require', $package->section);
        $this->assertSame('^0.9', $package->declaredConstraint);
        $this->assertSame('0.9.0', $package->installedVersion);
        $this->assertSame('0.9.0', $package->lockedVersion);
    }

    public function test_it_reports_a_dev_only_root_dependency(): void
    {
        (new Filesystem)->put($this->path.'/composer.json', json_encode([
            'require-dev' => ['laravel/ai' => '^0.8'],
        ], JSON_THROW_ON_ERROR));

        $package = (new ProjectPackageInventory(new Filesystem, $this->path))->package('laravel/ai');

        $this->assertSame('require-dev', $package->section);
        $this->assertSame('^0.8', $package->declaredConstraint);
        $this->assertNull($package->installedVersion);
    }
}
