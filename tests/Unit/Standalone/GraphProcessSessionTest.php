<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone;

use GracjanKubicki\ArchitectureKit\Standalone\GraphRuntime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GraphProcessSessionTest extends TestCase
{
    public function test_detaching_twice_preserves_the_same_isolated_session(): void
    {
        $process = $this->probe('\\'.GraphRuntime::class.'::detachProcess(); \\'.GraphRuntime::class.'::detachProcess(); echo json_encode([getmypid(), posix_getsid(0), posix_getpgrp()]);');
        $identity = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertGreaterThan(0, $identity[0]);
        self::assertSame([$identity[0], $identity[0], $identity[0]], $identity);
    }

    public function test_an_existing_group_leader_reports_detach_failure(): void
    {
        $process = $this->probe('posix_setpgid(0, 0); try { \\'.GraphRuntime::class.'::detachProcess(); exit(1); } catch (\\RuntimeException $error) { echo $error->getMessage(); }');
        self::assertStringStartsWith('runtime_session_detach_failed:', $process->getOutput());
    }

    public function test_missing_posix_is_reported_before_initializing_the_graph_child(): void
    {
        $home = sys_get_temp_dir().'/kit-session-unsupported-'.bin2hex(random_bytes(6));
        $process = new Process([PHP_BINARY, '-d', 'disable_functions=posix_setsid', dirname(__DIR__, 3).'/bin/architecture-kit',
            '--tool-home', $home, '__graph-runtime', 'coordinator', 'unused', 'unused']);
        $process->mustRun();
        $failure = json_decode($process->getErrorOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($failure['ok']);
        self::assertStringStartsWith('runtime_session_unavailable:', $failure['reason']);
        self::assertFalse($failure['control']['blocking']);
        self::assertDirectoryDoesNotExist($home);
    }

    private function probe(string $body): Process
    {
        $autoload = var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true);
        $process = new Process([PHP_BINARY, '-r', 'require '.$autoload.'; '.$body]);
        $process->mustRun();

        return $process;
    }
}
