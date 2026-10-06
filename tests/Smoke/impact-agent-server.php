<?php

declare(strict_types=1);
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

// Testbench is the host; the fixture remains source-only input to package tools.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$fixture = $argv[1] ?? '';
if (! is_dir($fixture) || ! is_file($fixture.'/composer.json')) {
    throw new InvalidArgumentException('Supply the controlled evaluation fixture directory.');
}

$host = new class('testEvaluationHost') extends TestCase
{
    public function serve(string $fixture): void
    {
        $this->setUp();
        try {
            $this->app->setBasePath($fixture);
            Artisan::call('mcp:start', ['handle' => 'architecture-kit']);
        } finally {
            // This standalone process has no PHPUnit runner configuration to flush.
            (new Filesystem)->deleteDirectory($this->tempPath);
        }
    }
};
$host->serve($fixture);
