<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use GracjanKubicki\ArchitectureKit\Context\GraphSource;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;

final class GraphFreshnessTest extends TestCase
{
    private function fixture(): Filesystem
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->ensureDirectoryExists($this->tempPath.'/vendor/composer');
        $files->put($this->tempPath.'/app/A.php', '<?php namespace App; class A {}');
        $files->put($this->tempPath.'/config/architectures.php', '<?php return ["audit" => ["cache" => true], "graph" => ["exclude" => ["config/*", "composer.*", "vendor/*"]]];');

        return $files;
    }

    /** @return iterable<string, array{string}> */
    public static function metadata(): iterable
    {
        foreach (['config/architectures.php', 'composer.json', 'composer.lock', 'vendor/composer/installed.json'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('metadata')]
    public function test_metadata_add_edit_remove_invalidates_independently_of_source_exclusion(string $path): void
    {
        $files = $this->fixture();
        if ($path === 'composer.lock' || $path === 'vendor/composer/installed.json') {
            $files->delete($this->tempPath.'/'.$path);
        }
        $initial = (new GraphSource($files, $this->tempPath))->load();
        $this->assertSame('success', $initial['cache']['write']['status']);
        $hit = (new GraphSource($files, $this->tempPath, static function (): void {
            throw new \RuntimeException('unexpected parse');
        }))->load();
        $this->assertSame('hit', $hit['cache']['read']['status']);
        $previousIdentity = SourceManifest::identity($this->tempPath, AnalysisMode::Basic);
        if ($path === 'config/architectures.php') {
            $contents = (string) $files->get($this->tempPath.'/'.$path);
            $files->put($this->tempPath.'/'.$path, str_replace('"vendor/*"', '"vendor/*", "app/Z.php"', $contents));
        } elseif ($path === 'composer.json') {
            $composer = json_decode($files->get($this->tempPath.'/'.$path), true, 32, JSON_THROW_ON_ERROR);
            $composer['require']['php'] = '^8.4';
            $files->put($this->tempPath.'/'.$path, json_encode($composer));
        } else {
            $files->put($this->tempPath.'/'.$path, '{"packages":[]}');
        }
        $previousSnapshot = $initial['snapshot'];
        foreach (['added-or-edited', 'edited', 'deleted'] as $stage) {
            if ($stage === 'edited') {
                $files->append($this->tempPath.'/'.$path, "\n");
            } elseif ($stage === 'deleted') {
                $files->delete($this->tempPath.'/'.$path);
            }
            $identity = SourceManifest::identity($this->tempPath, AnalysisMode::Basic);
            $this->assertNotSame($previousIdentity, $identity, $path.':'.$stage);
            $parses = [];
            $result = (new GraphSource($files, $this->tempPath, static function (string $path) use (&$parses): void {
                $parses[] = $path;
            }))->load();
            $this->assertNotSame($previousSnapshot, $result['snapshot'], $path.':'.$stage);
            if ($result['cache']['read']['status'] === 'hit') {
                $this->assertSame($initial['snapshot'], $result['snapshot']);
                $this->assertSame([], $parses);
            } else {
                $this->assertNotEmpty($parses);
            }
            $previousSnapshot = $result['snapshot'];
            $this->assertFalse($result['changed']);
            $previousIdentity = $identity;
        }
    }

    public function test_new_modules_root_and_target_php_have_distinct_identity(): void
    {
        $files = $this->fixture();
        $first = SourceManifest::identity($this->tempPath, AnalysisMode::Basic);
        $files->ensureDirectoryExists($this->tempPath.'/Modules');
        $this->assertNotSame($first, SourceManifest::identity($this->tempPath, AnalysisMode::Basic));
        $second = SourceManifest::identity($this->tempPath, AnalysisMode::Basic);
        AnalysisRuntime::activate(AnalysisRuntime::session()->withPhpVersion('8.4'));
        $this->assertNotSame($second, SourceManifest::identity($this->tempPath, AnalysisMode::Basic));
    }

    public function test_change_during_build_is_not_published_current_and_sources_remain_pinned(): void
    {
        $files = $this->fixture();
        $mutated = false;
        $result = (new GraphSource($files, $this->tempPath, function (string $path) use ($files, &$mutated): void {
            if ($path === 'app/A.php' && ! $mutated) {
                $mutated = true;
                $files->put($this->tempPath.'/app/B.php', '<?php namespace App; class B {}');
            }
        }))->load();
        $this->assertTrue($mutated);
        $this->assertTrue($result['changed']);
        $this->assertSame('changed_inputs', $result['cache']['write']['reason']);
        $this->assertFalse($result['analysis']['checks_complete']);
        $this->assertNotNull(($result['index']->sourceContents)('app/A.php'));
        $this->assertNull(($result['index']->sourceContents)('app/B.php'));
        $absolute = $this->tempPath.'/app/A.php';
        unlink($absolute);
        symlink(__FILE__, $absolute);
        $this->assertNull(($result['index']->sourceContents)('app/A.php'));
        unlink($absolute);
    }

    public function test_disabled_and_failed_write_preserve_ram_without_claiming_persistence(): void
    {
        $files = $this->fixture();
        $broken = new class extends Filesystem
        {
            public function put($path, $contents, $lock = false)
            {
                return false;
            }
        };
        $result = (new GraphSource($broken, $this->tempPath))->load();
        $this->assertFalse($result['changed']);
        $this->assertSame('error', $result['cache']['write']['status']);
        $this->assertSame('write_io', $result['cache']['write']['reason']);
        $this->assertNotEmpty($result['index']->namedTypes('App\\A'));
        $this->assertNotSame('hit', (new GraphSource($files, $this->tempPath))->load()['cache']['read']['status']);
        $files->put($this->tempPath.'/config/architectures.php', '<?php return ["audit" => ["cache" => false]];');
        $disabled = (new GraphSource($files, $this->tempPath))->load();
        $this->assertSame(['status' => 'skip', 'reason' => 'disabled'], $disabled['cache']['read']);
        $this->assertSame(['status' => 'skip', 'reason' => 'disabled'], $disabled['cache']['write']);
        $this->assertNotEmpty($disabled['index']->namedTypes('App\\A'));
    }

    public function test_transient_source_bytes_are_rejected_even_when_disk_hashes_never_change(): void
    {
        $this->fixture();
        $path = $this->tempPath.'/app/A.php';
        $before = SourceManifest::signature($path);
        $seam = new class($path) extends Filesystem
        {
            public function __construct(private readonly string $target) {}

            public function get($path, $lock = false)
            {
                return $path === $this->target ? '<?php namespace App; class Transient {}' : parent::get($path, $lock);
            }
        };
        $result = (new GraphSource($seam, $this->tempPath))->load();
        $this->assertSame($before, SourceManifest::signature($path));
        $this->assertTrue($result['changed']);
        $this->assertSame('changed_inputs', $result['cache']['write']['reason']);
        $this->assertSame([], $result['index']->namedTypes('App\\Transient'));
    }

    public function test_empty_scope_directory_changes_refresh_missing_root_diagnostics(): void
    {
        $files = $this->fixture();
        $first = (new GraphSource($files, $this->tempPath))->load();
        $files->ensureDirectoryExists($this->tempPath.'/routes');
        $second = (new GraphSource($files, $this->tempPath))->load();
        $this->assertNotSame($first['snapshot'], $second['snapshot']);
        $this->assertNotSame($first['index']->diagnostics, $second['index']->diagnostics);
        $this->assertNotSame('hit', $second['cache']['read']['status']);
        $hit = (new GraphSource($files, $this->tempPath))->load();
        $this->assertSame('hit', $hit['cache']['read']['status']);
        $this->assertSame($second['index']->exportState(), $hit['index']->exportState());
    }

    public function test_change_during_restore_returns_stale_generation_without_composing_another_index(): void
    {
        $files = $this->fixture();
        (new GraphSource($files, $this->tempPath))->load();
        $seam = new class($this->tempPath) extends Filesystem
        {
            private int $appScans = 0;

            public function __construct(private readonly string $root) {}

            public function allFiles($directory, $hidden = false)
            {
                if ($directory === $this->root.'/app' && ++$this->appScans === 2) {
                    file_put_contents($this->root.'/app/B.php', '<?php namespace App; class B {}');
                }

                return parent::allFiles($directory, $hidden);
            }
        };
        $result = (new GraphSource($seam, $this->tempPath, static function (): void {
            throw new \RuntimeException('A stale restored generation must not build in the same process.');
        }))->load();
        $this->assertTrue($result['changed']);
        $this->assertSame('hit', $result['cache']['read']['status']);
        $this->assertSame('changed_inputs', $result['cache']['read']['reason']);
        $this->assertSame([], $result['index']->namedTypes('App\\B'));
        $this->assertNull(($result['index']->sourceContents)('app/B.php'));
    }

    public function test_transient_configuration_bytes_are_rejected_even_when_configuration_is_excluded(): void
    {
        $this->fixture();
        $path = $this->tempPath.'/config/architectures.php';
        $before = hash_file('sha256', $path);
        $seam = new class($path) extends Filesystem
        {
            public function __construct(private readonly string $target) {}

            public function get($path, $lock = false)
            {
                return $path === $this->target ? '<?php return ["audit" => ["cache" => true], "graph" => ["exclude" => ["config/*"]]];' : parent::get($path, $lock);
            }
        };
        $result = (new GraphSource($seam, $this->tempPath))->load();
        $this->assertSame($before, hash_file('sha256', $path));
        $this->assertTrue($result['changed']);
        $this->assertSame('changed_inputs', $result['cache']['write']['reason']);
    }
}
