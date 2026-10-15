<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone;

use GracjanKubicki\ArchitectureKit\Standalone\SourceFingerprint;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class SourceFingerprintTest extends TestCase
{
    public function test_generated_tool_caches_are_not_sources_but_explicit_helpers_and_scopes_are(): void
    {
        $files = new Filesystem;
        foreach (['app', '.cache/phpstan', '.rector-cache', '.phpunit.cache', 'coverage', 'storage/framework/views'] as $directory) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$directory);
        }
        $files->put($this->tempPath.'/app/User.php', '<?php class User {}');
        $before = SourceFingerprint::current($this->tempPath);
        $files->put($this->tempPath.'/.cache/phpstan/resultCache.php', str_repeat('x', 8_000_001));
        $files->put($this->tempPath.'/.rector-cache/generated.php', '<?php return "cache";');
        $files->put($this->tempPath.'/storage/framework/views/generated.php', str_repeat('x', 8_000_001));
        $files->put($this->tempPath.'/.phpunit.cache/generated.php', str_repeat('x', 8_000_001));
        $files->put($this->tempPath.'/coverage/generated.php', str_repeat('x', 8_000_001));
        self::assertSame($before, SourceFingerprint::current($this->tempPath));
        $files->put($this->tempPath.'/.rector-cache/helper.php', '<?php function helper() { return 1; }');
        $files->put($this->tempPath.'/composer.json', json_encode(['autoload' => ['files' => ['.rector-cache/helper.php']]], JSON_THROW_ON_ERROR));
        $withHelper = SourceFingerprint::current($this->tempPath);
        $files->put($this->tempPath.'/.rector-cache/helper.php', '<?php function helper() { return 2; }');
        self::assertNotSame($withHelper, SourceFingerprint::current($this->tempPath));
        $withScope = SourceFingerprint::current($this->tempPath, ['audit' => ['paths' => ['app', '.rector-cache']]]);
        $files->put($this->tempPath.'/.rector-cache/generated.php', '<?php return "edited";');
        self::assertNotSame($withScope, SourceFingerprint::current($this->tempPath, ['audit' => ['paths' => ['app', '.rector-cache']]]));
    }

    public function test_explicit_sources_inside_generated_view_storage_remain_tracked(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/storage/framework/views');
        $files->put($this->tempPath.'/storage/framework/views/helper.php', '<?php function helper() { return 1; }');
        $files->put($this->tempPath.'/composer.json', '{"autoload":{"files":["storage/framework/views/helper.php"]}}');
        $before = SourceFingerprint::current($this->tempPath);
        $files->put($this->tempPath.'/storage/framework/views/helper.php', '<?php function helper() { return 2; }');
        self::assertNotSame($before, SourceFingerprint::current($this->tempPath));
        $before = SourceFingerprint::current($this->tempPath, ['graph' => ['paths' => ['storage/framework/views']]]);
        $files->put($this->tempPath.'/storage/framework/views/other.php', '<?php class Other {}');
        self::assertNotSame($before, SourceFingerprint::current($this->tempPath, ['graph' => ['paths' => ['storage/framework/views']]]));
    }

    public function test_same_stat_edits_new_helpers_and_deletions_change_the_identity(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $path = $this->tempPath.'/app/Example.php';
        $files->put($path, '<?php class First {}');
        $time = filemtime($path);
        $before = SourceFingerprint::current($this->tempPath);
        $files->put($path, '<?php class Other {}');
        touch($path, $time);
        self::assertNotSame($before, SourceFingerprint::current($this->tempPath));
        $before = SourceFingerprint::current($this->tempPath);
        $files->put($this->tempPath.'/helper.php', '<?php function custom() {}');
        self::assertNotSame($before, SourceFingerprint::current($this->tempPath));
        unlink($this->tempPath.'/helper.php');
        self::assertSame($before, SourceFingerprint::current($this->tempPath));
    }

    public function test_malformed_helper_scope_is_unverified_instead_of_ignored(): void
    {
        (new Filesystem)->put($this->tempPath.'/composer.json', '{"autoload":{"files":"helper.php"}}');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task Composer helper scope is invalid.');
        SourceFingerprint::current($this->tempPath);
    }

    public function test_malformed_audit_scope_is_unverified_instead_of_ignored(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid task source scope.');
        SourceFingerprint::current($this->tempPath, ['audit' => ['paths' => 'app']]);
    }
}
