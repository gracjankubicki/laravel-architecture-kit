<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests;

use GracjanKubicki\ArchitectureKit\ArchitectureKitServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir().'/architecture-kit-'.uniqid('', true);
        (new Filesystem)->ensureDirectoryExists($this->tempPath);
        $this->app->setBasePath($this->tempPath);
        (new Filesystem)->ensureDirectoryExists($this->tempPath.'/config');
        (new Filesystem)->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'gracjankubicki/laravel-architecture-kit' => '^0.2',
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath)) {
            if (is_link($this->tempPath.'/vendor')) {
                unlink($this->tempPath.'/vendor');
            }
            (new Filesystem)->deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    /** Supply actual routes to command fixtures that exercise route-aware checks. */
    protected function withRoutes(string $source): void
    {
        $files = new Filesystem;
        foreach (['bootstrap/cache', 'routes', 'storage/framework/views', 'storage/logs'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        symlink(dirname(__DIR__).'/vendor', $this->tempPath.'/vendor');
        $files->put($this->tempPath.'/routes/web.php', $source);
        $files->put($this->tempPath.'/bootstrap/app.php', <<<'PHP'
<?php
return Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: dirname(__DIR__).'/routes/web.php')
    ->withExceptions()
    ->create();
PHP);
    }

    protected function writeInertiaFixture(
        string $constraint = '^3.0',
        ?string $installedVersion = '3.0.0',
        string $section = 'require',
        ?string $lockedVersion = null,
    ): void {
        $files = new Filesystem;
        $composer = json_decode($files->get($this->tempPath.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $composer['require'] ??= [];
        $composer['require-dev'] ??= [];
        unset($composer['require']['inertiajs/inertia-laravel'], $composer['require-dev']['inertiajs/inertia-laravel']);
        $composer[$section]['inertiajs/inertia-laravel'] = $constraint;
        $files->put($this->tempPath.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $lockedVersion ??= $installedVersion;
        $lockSection = $section === 'require-dev' ? 'packages-dev' : 'packages';
        $lock = [
            'packages' => [[
                'name' => 'gracjankubicki/laravel-architecture-kit',
                'version' => '0.2.0',
            ]],
            'packages-dev' => [],
        ];

        if ($lockedVersion !== null) {
            $lock[$lockSection][] = ['name' => 'inertiajs/inertia-laravel', 'version' => $lockedVersion];
        }

        $files->put($this->tempPath.'/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
        $files->ensureDirectoryExists($this->tempPath.'/vendor/composer');
        $installed = $installedVersion === null
            ? '[]'
            : "['inertiajs/inertia-laravel' => ['pretty_version' => '{$installedVersion}']]";
        $files->put($this->tempPath.'/vendor/composer/installed.php', "<?php\nreturn ['versions' => {$installed}];\n");
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            McpServiceProvider::class,
            ArchitectureKitServiceProvider::class,
        ];
    }
}
