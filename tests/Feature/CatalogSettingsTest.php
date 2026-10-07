<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogSettings;
use GracjanKubicki\ArchitectureKit\Context\GraphSource;
use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

final class CatalogSettingsTest extends TestCase
{
    public function test_settings_and_graph_reuse_one_parse_of_configuration(): void
    {
        $this->write('config/architectures.php', '<?php return ["audit" => ["paths" => ["legacy"]], "graph" => ["paths" => ["src"]]];');
        $this->write('app/Action.php', '<?php namespace App; class Action { public function run() {} }');
        $calls = [];
        $source = new GraphSource(new Filesystem, $this->tempPath, static function (string $path) use (&$calls): void {
            $calls[$path] = ($calls[$path] ?? 0) + 1;
        });
        $cold = $source->load();
        ksort($calls);
        $this->assertSame(['app/Action.php' => 1, 'config/architectures.php' => 1], $calls);
        $this->assertFalse($cold['changed']);
        $this->assertContains('config/architectures.php', array_column($cold['index']->elements, 'path'));
        $calls = [];
        $warm = $source->load();
        // Settings still determine the scope/cache on each request; graph reuse
        // avoids parsing any other unchanged source or reparsing configuration.
        $this->assertSame(['config/architectures.php' => 1], $calls);
        $this->assertSame($cold['snapshot'], $warm['snapshot']);
        $this->assertEquals($cold['index'], $warm['index']);
    }

    public function test_same_stat_change_between_settings_and_graph_reading_is_explicitly_stale(): void
    {
        $contents = '<?php return ["graph" => ["paths" => ["src"]]];';
        $this->write('config/architectures.php', $contents);
        $absolute = $this->tempPath.'/config/architectures.php';
        $mtime = filemtime($absolute);
        $files = new class($absolute, $contents, $mtime) extends Filesystem
        {
            private int $reads = 0;

            public function __construct(private string $source, private string $original, private int $mtime) {}

            public function get($path, $lock = false)
            {
                if ($path === $this->source && ++$this->reads === 2) {
                    file_put_contents($path, str_replace('"src"', '"lib"', $this->original));
                    touch($path, $this->mtime);
                    clearstatcache(true, $path);
                }

                return parent::get($path, $lock);
            }
        };
        $calls = [];
        $result = (new GraphSource($files, $this->tempPath, static function (string $path) use (&$calls): void {
            $calls[] = $path;
        }))->load();
        $this->assertTrue($result['changed']);
        $this->assertContains('changed_inputs', array_column($result['index']->diagnostics, 'code'));
        $this->assertSame(['config/architectures.php'], $calls);
    }

    private function write(string $path, string $contents): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $contents);
        clearstatcache();
    }

    public function test_source_graph_scope_is_separate_and_configuration_is_never_executed(): void
    {
        $this->write('config/architectures.php', '<?php return ["audit" => ["paths" => ["legacy"], "exclude" => ["app/Legacy.php"]], "graph" => ["paths" => ["src/Billing"], "exclude" => ["app/Hidden.php"]], "unused" => function () { throw new \\RuntimeException("never execute"); }];');
        $settings = CatalogSettings::load(new Filesystem, $this->tempPath);
        $this->assertSame([...CatalogSettings::ROOTS, 'src/Billing'], $settings->scope->directories);
        $this->assertSame(['app/Hidden.php'], $settings->exclude);
        $this->assertSame(['app', 'legacy'], $settings->discovery->scope->directories);
        $this->assertSame(['app/Legacy.php'], $settings->discovery->exclude);
        $this->assertSame($settings->discovery->scope->directories, DiscoverySettings::load(new Filesystem, $this->tempPath)->scope->directories);
        $this->assertContains('missing_root', array_column($settings->diagnostics, 'code'));
        $this->assertTrue($settings->fresh($this->tempPath));
    }

    public function test_json_package_metadata_is_safe_and_changes_fingerprint_and_freshness(): void
    {
        $this->write('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"v4.4.5"}]}');
        $this->write('vendor/composer/installed.php', '<?php throw new \\RuntimeException("must never require installed.php");');
        $this->write('vendor/composer/installed.json', '{"packages":[{"name":"livewire/livewire","version":"v4.4.5"}]}');
        $settings = CatalogSettings::load(new Filesystem, $this->tempPath);
        $this->assertSame(['livewire/livewire' => '4.4.5'], $settings->packages);
        $this->write('composer.lock', '{"packages":[{"name":"livewire/livewire","version":"v4.4.50"}]}');
        $changed = CatalogSettings::load(new Filesystem, $this->tempPath);
        $this->assertNotSame($settings->fingerprint, $changed->fingerprint);
        $this->assertFalse($settings->fresh($this->tempPath));
        $this->assertContains('package_version_mismatch', array_column($changed->diagnostics, 'code'));
    }

    public function test_traversal_in_graph_scope_is_rejected(): void
    {
        $this->write('config/architectures.php', '<?php return ["graph" => ["paths" => ["../elsewhere"]]];');
        $this->expectException(InvalidArgumentException::class);
        CatalogSettings::load(new Filesystem, $this->tempPath);
    }
}
