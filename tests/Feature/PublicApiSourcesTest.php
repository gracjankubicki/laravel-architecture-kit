<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Revision\RevisionSources;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;

final class PublicApiSourcesTest extends TestCase
{
    public function test_git_and_dirty_working_sources_are_frozen_without_checkout_or_execution(): void
    {
        $this->writeSource('src/Api.php', '<?php file_put_contents(__DIR__."/executed", "bad"); class Before {}');
        $this->writeSource('composer.json', '{"autoload":{"psr-4":{"Demo\\\\":"src/"}}}');
        $this->git('init', '-q');
        $this->commit();
        $before = $this->git('rev-parse', 'HEAD');
        $this->writeSource('src/Api.php', '<?php class After {}');
        $this->writeSource('src/New.php', '<?php class Added {}');
        $status = $this->git('status', '--porcelain');
        $reader = new RevisionSources($this->tempPath);
        $old = $reader->capture($before);
        $new = $reader->capture('working');
        $this->assertStringContainsString('class Before', $old->files['src/Api.php']);
        $this->assertStringContainsString('class After', $new->files['src/Api.php']);
        $this->assertArrayHasKey('src/New.php', $new->files);
        $this->assertNotSame($old->fingerprint, $new->fingerprint);
        $this->assertSame($before, $new->revision);
        $this->assertTrue($reader->isFresh($new));
        $this->assertSame($status, $this->git('status', '--porcelain'));
        $this->assertSame($before, $this->git('rev-parse', 'HEAD'));
        $this->assertFileDoesNotExist($this->tempPath.'/src/executed');
        $this->writeSource('src/Api.php', '<?php class Fresh {}');
        $this->assertFalse($reader->isFresh($new));
        $this->assertStringContainsString('class After', $new->files['src/Api.php']);
    }

    public function test_two_git_revisions_and_deleted_working_files_are_distinct(): void
    {
        $this->writeSource('src/One.php', '<?php class One {}');
        $this->git('init', '-q');
        $this->commit();
        $first = $this->git('rev-parse', 'HEAD');
        $this->writeSource('src/Two.php', '<?php class Two {}');
        $this->commit();
        $reader = new RevisionSources($this->tempPath);
        $old = $reader->capture($first);
        $new = $reader->capture('HEAD');
        $this->assertArrayNotHasKey('src/Two.php', $old->files);
        $this->assertArrayHasKey('src/Two.php', $new->files);
        $this->assertTrue($reader->isFresh($old));
        unlink($this->tempPath.'/src/One.php');
        $this->assertArrayNotHasKey('src/One.php', $reader->capture('working')->files);
    }

    public function test_package_subdirectory_keeps_paths_relative_in_both_states(): void
    {
        $this->writeSource('packages/demo/src/Api.php', '<?php class Api {}');
        $this->writeSource('outside.php', '<?php class Outside {}');
        $this->git('init', '-q');
        $this->commit();
        $reader = new RevisionSources($this->tempPath.'/packages/demo');
        foreach (['HEAD', 'working'] as $state) {
            $snapshot = $reader->capture($state);
            $this->assertSame(['src/Api.php'], array_keys($snapshot->files));
            $this->assertSame([], $snapshot->notices);
        }
    }

    public function test_links_environment_and_vendor_are_not_read(): void
    {
        $this->writeSource('src/Api.php', '<?php class Api {}');
        $this->writeSource('vendor/Secret.php', '<?php secret();');
        $this->writeSource('.env.php', '<?php secret();');
        symlink($this->tempPath.'/vendor/Secret.php', $this->tempPath.'/src/Link.php');
        symlink($this->tempPath.'/vendor', $this->tempPath.'/linked');
        $this->git('init', '-q');
        $this->commit();
        $reader = new RevisionSources($this->tempPath);
        foreach (['HEAD', 'working'] as $state) {
            $snapshot = $reader->capture($state);
            $this->assertSame(['composer.json', 'src/Api.php'], array_keys($snapshot->files));
            $this->assertContains('E_SOURCE_LINK', array_column($snapshot->notices, 'code'));
        }
    }

    public function test_limits_preserve_known_sources_and_explain_incompleteness(): void
    {
        unlink($this->tempPath.'/composer.json');
        $this->writeSource('src/A.php', '<?php class A {}');
        $this->writeSource('src/B.php', '<?php class B {}');
        $this->writeSource('src/C.php', str_repeat('x', 300));
        $this->git('init', '-q');
        $this->commit();
        foreach (['HEAD', 'working'] as $state) {
            $limited = (new RevisionSources($this->tempPath, maxFiles: 1))->capture($state);
            $this->assertSame(['src/A.php'], array_keys($limited->files));
            $this->assertSame('incomplete', $limited->identity()['status']);
            $this->assertContains('E_SOURCE_LIMIT', array_column($limited->notices, 'code'));
            $bytes = (new RevisionSources($this->tempPath, maxFileBytes: 100))->capture($state);
            $this->assertCount(2, $bytes->files);
            $this->assertContains('E_SOURCE_LIMIT', array_column($bytes->notices, 'code'));
        }
    }

    public function test_unknown_revision_is_explicit_without_mutations(): void
    {
        $this->writeSource('src/Api.php', '<?php class Api {}');
        $this->git('init', '-q');
        $this->commit();
        $this->expectException(RuntimeException::class);
        (new RevisionSources($this->tempPath))->capture('--help');
    }

    public function test_missing_git_checkout_is_explicit(): void
    {
        $this->expectException(RuntimeException::class);
        new RevisionSources($this->tempPath);
    }

    private function writeSource(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $source);
    }

    private function commit(): void
    {
        $this->git('add', '.');
        $this->git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'fixture');
    }

    private function git(string ...$args): string
    {
        $process = new Process(['git', '-C', $this->tempPath, ...$args]);
        $this->assertSame(0, $process->run(), $process->getErrorOutput());

        return trim($process->getOutput());
    }
}
