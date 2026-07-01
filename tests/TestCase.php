<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase as Orchestra;
use Taqie\ArchitectureKit\ArchitectureKitServiceProvider;

abstract class TestCase extends Orchestra
{
    protected string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir().'/architecture-kit-'.uniqid('', true);
        (new Filesystem())->ensureDirectoryExists($this->tempPath);
        $this->app->setBasePath($this->tempPath);
        (new Filesystem())->ensureDirectoryExists($this->tempPath.'/config');
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath)) {
            (new Filesystem())->deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ArchitectureKitServiceProvider::class,
        ];
    }
}
