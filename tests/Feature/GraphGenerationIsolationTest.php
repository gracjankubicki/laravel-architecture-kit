<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use GracjanKubicki\ArchitectureKit\Context\GraphSource;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class GraphGenerationIsolationTest extends TestCase
{
    private function fixture(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->ensureDirectoryExists($this->tempPath.'/Modules');
        $files->ensureDirectoryExists($this->tempPath.'/tests');
        $files->put($this->tempPath.'/app/Worker.php', '<?php namespace App; interface Worker { public function perform(); }');
        $files->put($this->tempPath.'/tests/WorkerTest.php', '<?php namespace Tests; class WorkerTest extends \\PHPUnit\\Framework\\TestCase implements \\App\\Worker { public function perform() {} public function test_worker() { $worker = $this; $worker->perform(); } }');
        $files->put($this->tempPath.'/config/architectures.php', '<?php return ["audit" => ["cache" => true]];');
        $files->put($this->tempPath.'/app/Child.php', '<?php namespace App; class Child extends Base {} function run(Ghost $g, UnresolvedOther $n) { $g->perform(); $n->missing(); $dynamic->unresolvedControl(); }');
        $files->put($this->tempPath.'/app/Base.php', '<?php namespace App; class Base extends \\Illuminate\\Database\\Eloquent\\Model {}');
    }

    /** @return array<string, mixed> */
    private function worker(string $action, string $name): array
    {
        $output = $this->tempPath.'/'.$name.'.json';
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', dirname(__DIR__).'/Fixtures/Graph/generation-worker.php', $this->tempPath, $action, $output]);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());

        return json_decode((string) file_get_contents($output), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $actual
     * @param array<string, mixed> $oracle */
    private function equivalent(array $actual, array $oracle): void
    {
        $this->assertNotSame($actual['pid'], $oracle['pid']);
        foreach (['state', 'answers', 'contents', 'analysis', 'changed'] as $field) {
            $this->assertSame($oracle[$field], $actual[$field], $field);
        }
    }

    public function test_restore_and_edit_add_delete_move_have_independent_process_oracles(): void
    {
        $this->fixture();
        $first = $this->worker('build', 'first');
        $this->assertSame('success', $first['cache']['write']['status']);
        $this->assertNotEmpty(array_filter($first['answers']['App\\Worker:impact']['records'], static fn (array $row): bool => $row['test_candidate']));
        $this->assertTrue($first['answers']['page-1']['truncated']);
        $restore = $this->worker('restore', 'restore');
        $this->assertSame('hit', $restore['cache']['read']['status']);
        $this->assertSame([], $restore['parses']);
        $this->assertSame([], $restore['stages']);
        $this->assertSame($first['snapshot'], $restore['snapshot']);
        $this->equivalent($restore, $this->worker('oracle', 'oracle-first'));
        $callerHash = hash_file('sha256', $this->tempPath.'/app/Child.php');
        foreach (['edit', 'add', 'duplicate', 'remove-duplicate', 'move', 'delete'] as $operation) {
            match ($operation) {
                'edit' => file_put_contents($this->tempPath.'/app/Base.php', '<?php namespace App; class Base {}'),
                'add' => file_put_contents($this->tempPath.'/app/Ghost.php', '<?php namespace App; class Ghost { function perform() {} }'),
                'duplicate' => file_put_contents($this->tempPath.'/app/Duplicate.php', '<?php namespace App; class Ghost { function perform() {} }'),
                'remove-duplicate' => unlink($this->tempPath.'/app/Duplicate.php'),
                'move' => rename($this->tempPath.'/app/Ghost.php', $this->tempPath.'/Modules/Moved.php'),
                'delete' => unlink($this->tempPath.'/Modules/Moved.php'),
            };
            $updated = $this->worker('build', 'updated-'.$operation);
            $this->assertSame('success', $updated['cache']['write']['status'], $operation);
            $this->equivalent($updated, $this->worker('oracle', 'oracle-'.$operation));
            $this->equivalent($this->worker('restore', 'restore-'.$operation), $updated);
            $this->assertNotContains('app/Child.php', $updated['parses'], $operation);
            $this->assertSame($callerHash, hash_file('sha256', $this->tempPath.'/app/Child.php'));
        }
    }

    public function test_same_stat_change_is_detected_and_callback_remains_pinned(): void
    {
        $this->fixture();
        $first = (new GraphSource(new Filesystem, $this->tempPath))->load();
        $path = $this->tempPath.'/app/Base.php';
        $old = (string) file_get_contents($path);
        $signature = SourceManifest::signature($path);
        $mtime = filemtime($path);
        $new = str_replace('Base', 'Else', $old);
        file_put_contents($path, $new);
        touch($path, $mtime);
        $this->assertSame(strlen($old), strlen($new));
        $this->assertNotSame($signature, SourceManifest::signature($path));
        $this->assertNull(($first['index']->sourceContents)('app/Base.php'));
        $this->assertNull(($first['index']->sourceContents)('../composer.json'));
        $next = $this->worker('build', 'same-stat');
        $this->assertNotSame($first['snapshot'], $next['snapshot']);
        $this->equivalent($next, $this->worker('oracle', 'same-stat-oracle'));
        $this->equivalent($this->worker('restore', 'same-stat-restore'), $next);
    }
}
