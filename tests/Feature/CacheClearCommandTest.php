<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class CacheClearCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->writeFile('app/Actions/SendInvoice.php', "<?php\n\nnamespace App\\Actions;\n\nfinal class SendInvoice\n{\n}\n");
    }

    public function test_it_removes_the_stored_graph(): void
    {
        $this->buildGraph();

        $this->assertNotSame([], $this->storedFiles());

        $exitCode = Artisan::call('architecture-kit:cache-clear', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame(1, $payload['removed']);
        $this->assertSame([], $this->storedFiles());
    }

    public function test_clearing_a_cache_that_was_never_built_succeeds(): void
    {
        // The command exists to recover from a stale entry, so it has to be safe to run
        // when there is nothing there. Failing would make it useless in a script.
        $exitCode = Artisan::call('architecture-kit:cache-clear', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $payload['removed']);
    }

    public function test_it_clears_the_directory_the_project_configured(): void
    {
        $this->writeConfig("'cache' => 'storage/graphs',");
        $this->buildGraph('storage/graphs');

        Artisan::call('architecture-kit:cache-clear', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $payload['removed']);
        $this->assertStringEndsWith('/storage/graphs', $payload['directory']);
        $this->assertSame([], $this->storedFiles('storage/graphs'));
    }

    public function test_it_still_clears_after_the_project_turned_the_cache_off(): void
    {
        // Turning the cache off does not delete what it already wrote, and the entry is
        // the thing a project wants gone when it suspects the cache of being stale.
        $this->buildGraph();
        $this->writeConfig("'cache' => false,");

        Artisan::call('architecture-kit:cache-clear', ['--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $payload['removed']);
        $this->assertSame([], $this->storedFiles());
    }

    public function test_an_explicit_path_clears_a_location_the_configuration_no_longer_names(): void
    {
        // Moving or disabling the cache takes the old directory out of the configuration,
        // which is exactly the moment the old entry needs removing.
        $this->buildGraph('storage/graphs');
        $this->writeConfig("'cache' => false,");

        Artisan::call('architecture-kit:cache-clear', ['--path' => 'storage/graphs', '--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $payload['removed']);
        $this->assertSame([], $this->storedFiles('storage/graphs'));
    }

    public function test_a_project_that_turned_the_cache_off_stores_nothing(): void
    {
        $this->writeConfig("'cache' => false,");

        $this->assertNull($this->config()->graphCache());
    }

    public function test_the_default_configuration_leaves_the_cache_on(): void
    {
        $this->writeConfig('');

        $this->assertNotNull($this->config()->graphCache());
    }

    public function test_a_configuration_that_is_neither_a_path_nor_a_switch_is_refused(): void
    {
        $this->writeConfig("'cache' => ['nonsense'],");

        $this->expectExceptionMessage('audit.cache must be false to disable it, or a directory path');

        $this->config()->graphCache();
    }

    private function buildGraph(string $directory = ProjectGraphCache::DIRECTORY): void
    {
        (new ProjectGraphLoader(
            new Filesystem,
            $this->tempPath,
            cache: new ProjectGraphCache(new Filesystem, $this->tempPath, $directory),
        ))->load();
    }

    private function config(): ArchitectureConfig
    {
        return new ArchitectureConfig($this->tempPath.'/config/architectures.php');
    }

    /** @return array<int, string> */
    private function storedFiles(string $directory = ProjectGraphCache::DIRECTORY): array
    {
        return (new Filesystem)->glob($this->tempPath.'/'.$directory.'/*.cache') ?: [];
    }

    private function writeConfig(string $auditLine): void
    {
        $this->writeFile('config/architectures.php', <<<PHP
<?php

use GracjanKubicki\\ArchitectureKit\\Architecture;

return [
    'enabled' => [Architecture::Actions],
    'audit' => [
        {$auditLine}
    ],
];

PHP);
    }

    private function writeFile(string $path, string $contents): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $contents);
    }
}
