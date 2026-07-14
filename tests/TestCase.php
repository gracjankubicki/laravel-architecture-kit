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
            (new Filesystem)->deleteDirectory($this->tempPath);
        }

        parent::tearDown();
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
