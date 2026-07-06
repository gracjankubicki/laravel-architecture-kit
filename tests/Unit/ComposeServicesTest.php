<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Taqie\ArchitectureKit\Install\ComposeServices;

class ComposeServicesTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir().'/architecture-kit-compose-'.uniqid('', true);
        (new Filesystem)->ensureDirectoryExists($this->tempPath);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempPath);

        parent::tearDown();
    }

    public function test_it_reads_compose_services(): void
    {
        $files = new Filesystem;
        $files->put($this->tempPath.'/compose.yaml', <<<'YAML'
services:
  queue:
    image: php
  app:
    image: php
YAML);

        $this->assertSame(['app', 'queue'], (new ComposeServices($files, $this->tempPath))->services());
    }

    public function test_it_reads_services_from_compose_override_files(): void
    {
        $files = new Filesystem;
        $files->put($this->tempPath.'/compose.yaml', <<<'YAML'
name: architecture-kit
services:
  redis:
    image: redis
YAML);
        $files->put($this->tempPath.'/docker-compose.override.yml', <<<'YAML'
services:
  api:
    build: .
  queue:
    build: .
YAML);

        $this->assertSame(['api', 'queue', 'redis'], (new ComposeServices($files, $this->tempPath))->services());
    }

    public function test_it_returns_null_when_compose_is_missing_or_invalid(): void
    {
        $files = new Filesystem;

        $this->assertNull((new ComposeServices($files, $this->tempPath))->services());

        $files->put($this->tempPath.'/compose.yaml', "services:\n  app: [");

        $this->assertNull((new ComposeServices($files, $this->tempPath))->services());
    }

    public function test_it_reads_sail_service_from_env(): void
    {
        $files = new Filesystem;
        $files->put($this->tempPath.'/.env', "APP_NAME=Laravel\nAPP_SERVICE=api\n");

        $this->assertSame('api', (new ComposeServices($files, $this->tempPath))->sailService());
    }
}
