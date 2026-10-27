<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\Capture;

use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureStore;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\OutputBudget;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use GracjanKubicki\ArchitectureKit\Tests\Support\EngineFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class NativeCaptureOutputBudgetTest extends TestCase
{
    private string $base;

    private string $repo;

    private string $evidence;

    protected function setUp(): void
    {
        $this->repo = dirname(__DIR__, 4);
        $this->base = realpath(sys_get_temp_dir()).'/kit-r01-r2-'.bin2hex(random_bytes(8));
        mkdir($this->base, 0700);
        $this->evidence = $this->repo.'/build/graph-performance-rust/R01/r2/fixtures/'.basename($this->base);
        mkdir($this->evidence, 0700, true);
        CaptureStore::writePrivate($this->evidence.'/provenance.json', TypedValue::json(['private_root' => $this->base]));
    }

    /** @param list<string> $argv */
    private function recordCli(string $label, Process $process, array $argv, string $output): void
    {
        CaptureStore::writePrivate($this->evidence.'/'.$label.'.stdout', $process->getOutput());
        CaptureStore::writePrivate($this->evidence.'/'.$label.'.stderr', $process->getErrorOutput());
        CaptureStore::writePrivate($this->evidence.'/'.$label.'.admission.json', file_get_contents($this->base.'/admission.json'));
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($output, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($output) + 1)] = $file->getSize();
            }
        }
        CaptureStore::writePrivate($this->evidence.'/'.$label.'.command.json', TypedValue::json([
            'command' => [PHP_BINARY, '-d', 'memory_limit=1G', ...$argv], 'cwd' => getcwd(), 'exit' => $process->getExitCode(),
            'output' => $output, 'files' => $files, 'total_output_bytes' => array_sum($files),
            'source_manifest_sha256' => hash_file('sha256', $this->base.'/source.json')]));
    }

    public function test_shared_ceiling_counts_metadata_and_log_bytes_at_minus_one_exact_and_plus_one(): void
    {
        foreach ([-1, 0, 1] as $delta) {
            $directory = $this->base.'/boundary-'.$delta;
            mkdir($directory, 0700);
            CaptureStore::writePrivate($directory.'/metadata.json', str_repeat('m', 10));
            $budget = new OutputBudget($directory, 64, reserve: 0);
            try {
                try {
                    CaptureStore::writePrivate($directory.'//stages.ndjson', str_repeat('e', 54 + $delta));
                    $this->assertLessThanOrEqual(0, $delta);
                    $this->assertSame(64 + $delta, $budget->used());
                } catch (\RuntimeException $error) {
                    $this->assertSame(1, $delta);
                    $this->assertSame('capture_output_limit', $error->getMessage());
                    $this->assertSame(10, $budget->used());
                }
                $this->assertLessThanOrEqual(64, $budget->used());
            } finally {
                $budget->close();
            }
        }
    }

    public function test_finalization_reserve_is_inside_ceiling_and_cannot_be_used_for_regular_events(): void
    {
        $budget = new OutputBudget($this->base, 64, reserve: 16);
        try {
            CaptureStore::writePrivate($this->base.'/stages.ndjson', str_repeat('e', 48));
            try {
                CaptureStore::writePrivate($this->base.'/rss.ndjson', 'x');
                $this->fail('regular event consumed the reserve');
            } catch (\RuntimeException $error) {
                $this->assertSame('capture_output_limit', $error->getMessage());
            }
            CaptureStore::writePrivate($this->base.'/report.json', str_repeat('r', 16), finalization: true);
            $this->assertSame(64, $budget->used());
            $this->expectException(\RuntimeException::class);
            CaptureStore::writePrivate($this->base.'/extra-report', 'x', finalization: true);
        } finally {
            $budget->close();
        }
    }

    public function test_stalled_writer_lock_has_a_bounded_failure_without_payload_write(): void
    {
        $directory = $this->base.'/stalled';
        mkdir($directory, 0700);
        $budget = new OutputBudget($directory, 64, reserve: 0);
        $ready = $this->base.'/holder.ready';
        $code = '$lock=fopen($argv[1], "r+b");flock($lock, LOCK_EX);file_put_contents($argv[2], "ready");usleep(5000000);';
        $holder = new Process([PHP_BINARY, '-r', $code, $directory.'/.quota.lock', $ready], timeout: 10);
        $holder->start();
        try {
            $deadline = hrtime(true) + 2000000000;
            while (! is_file($ready)) {
                $this->assertLessThan($deadline, hrtime(true));
                usleep(1000);
            }
            $start = hrtime(true);
            try {
                CaptureStore::writePrivate($directory.'/events', 'x');
                $this->fail('stalled interprocess lock accepted');
            } catch (\RuntimeException $error) {
                $this->assertSame('capture_output_lock_timeout', $error->getMessage());
                $this->assertLessThan(3000000000, hrtime(true) - $start);
                $this->assertSame(0, $budget->used());
            }
        } finally {
            $holder->stop(0.2);
            $budget->close();
        }
    }

    public function test_two_real_writers_cannot_both_reserve_the_same_last_bytes(): void
    {
        foreach ([-1, 0, 1] as $delta) {
            $directory = $this->base.'/writers-'.$delta;
            mkdir($directory, 0700);
            CaptureStore::writePrivate($directory.'/metadata.json', str_repeat('m', 10));
            $barrier = $this->base.'/barrier-'.$delta;
            $a = new Process([PHP_BINARY, $this->repo.'/tests/Support/native-capture-budget-writer.php', $directory, '64', '27', 'main', $barrier], timeout: 10);
            $b = new Process([PHP_BINARY, $this->repo.'/tests/Support/native-capture-budget-writer.php', $directory, '64', (string) (27 + $delta), 'sampler', $barrier], timeout: 10);
            $a->start();
            $b->start();
            $deadline = hrtime(true) + 5000000000;
            while (! is_file($barrier.'.main.ready') || ! is_file($barrier.'.sampler.ready')) {
                $this->assertLessThan($deadline, hrtime(true), 'writer barrier timed out');
                usleep(1000);
            }
            file_put_contents($barrier.'.go', 'go');
            $a->wait();
            $b->wait();
            $this->assertSame(0, $a->getExitCode(), $a->getErrorOutput());
            $this->assertSame(0, $b->getExitCode(), $b->getErrorOutput());
            $outputs = [$a->getOutput(), $b->getOutput()];
            $budget = new OutputBudget($directory, 64, reserve: 0);
            try {
                $this->assertLessThanOrEqual(64, $budget->used());
                if ($delta <= 0) {
                    $this->assertSame(["written\n", "written\n"], $outputs);
                    $this->assertSame(64 + $delta, $budget->used());
                } else {
                    $this->assertContains("capture_output_limit\n", $outputs);
                    $this->assertContains("written\n", $outputs);
                }
            } finally {
                $budget->close();
            }
        }
    }

    /** @return array{list<string>, array<string, mixed>, string} */
    private function fixture(): array
    {
        $root = $this->base.'/repo';
        $home = $this->base.'/home';
        mkdir($root.'/app', 0700, true);
        mkdir($root.'/.architecture-kit', 0700);
        file_put_contents($root.'/.git', 'synthetic-root');
        file_put_contents($root.'/composer.json', '{"name":"fixture/quota","type":"project","require":{"php":"^8.3"},"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        file_put_contents($root.'/.architecture-kit/settings.json', TypedValue::json(['enabled' => ['actions'], 'analysis' => ['engine' => EngineFixture::selected()]]));
        for ($i = 0; $i < 9; $i++) {
            file_put_contents($root.'/app/Fixture'.$i.'.php', '<?php namespace App; class Fixture'.$i.' { public function run(): int { return 42; } }');
        }
        if (EngineFixture::selected() === 'mago') {
            EngineFixture::install($home);
        }
        $manifest = $this->base.'/source.json';
        $prepare = new Process([PHP_BINARY, $this->repo.'/tests/Support/native-capture-plan.php', $root, $home, $manifest]);
        $prepare->mustRun();
        $output = $this->base.'/output';
        mkdir($output, 0700);
        $argv = [$this->repo.'/bin/architecture-kit', '--project', $root, '--tool-home', $home, 'capture-basic',
            '--admission-file', $this->base.'/admission.json', '--source-manifest', $manifest, '--output', $output, '--once'];
        $policy = ['command' => $argv, 'cwd' => getcwd(), 'source_manifest' => $manifest,
            'source_manifest_sha256' => hash_file('sha256', $manifest), 'php_memory_limit_bytes' => 1073741824,
            'max_wall_seconds' => 15, 'max_output_bytes' => 33554432, 'min_free_disk_bytes' => 1, 'private_output' => $output,
            'process_limits' => ['max_processes' => 6, 'max_tree_rss_bytes' => 1073741824]];

        return [$argv, $policy, $output];
    }

    public function test_real_cli_rejects_4096_bytes_and_work_quota_exhaustion_without_overshoot_or_signal_death(): void
    {
        [$argv, $policy, $output] = $this->fixture();
        foreach ([1, 4096, 16384] as $limit) {
            $runOutput = $output.'-'.$limit;
            mkdir($runOutput, 0700);
            $runArgv = $argv;
            $runArgv[array_search($output, $runArgv, true)] = $runOutput;
            $runPolicy = $policy;
            $runPolicy['command'] = $runArgv;
            $runPolicy['private_output'] = $runOutput;
            $runPolicy['max_output_bytes'] = $limit;
            file_put_contents($this->base.'/admission.json', TypedValue::json($runPolicy));
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', ...$runArgv], timeout: 20);
            $process->run();
            $this->recordCli('quota-'.$limit, $process, $runArgv, $runOutput);
            $this->assertSame(2, $process->getExitCode(), $process->getErrorOutput());
            $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('incomplete', $report['status']);
            $this->assertSame($limit <= 4096 ? 'capture_output_minimum' : 'capture_output_limit', $report['reason']);
            if ($limit === 1) {
                $this->assertSame('not_written', $report['report_status']);
                $this->assertFileDoesNotExist($runOutput.'/report.json');
            } else {
                $this->assertFileExists($runOutput.'/report.json');
            }
            $this->assertFileDoesNotExist($runOutput.'/dataset/complete');
            $budget = new OutputBudget($runOutput, $limit);
            try {
                $this->assertLessThanOrEqual($limit, $budget->used());
            } finally {
                $budget->close();
            }
        }
    }

    public function test_real_cli_minimum_admission_minus_one_exact_and_plus_one(): void
    {
        [$argv, $policy, $output] = $this->fixture();
        foreach ([-1, 0, 1] as $delta) {
            $runOutput = $output.'-minimum-'.$delta;
            mkdir($runOutput, 0700);
            $runArgv = $argv;
            $runArgv[array_search($output, $runArgv, true)] = $runOutput;
            $runPolicy = $policy;
            $runPolicy['command'] = $runArgv;
            $runPolicy['private_output'] = $runOutput;
            $minimum = 10000;
            do {
                $previous = $minimum;
                $runPolicy['max_output_bytes'] = $minimum + $delta;
                $minimum = OutputBudget::minimumBytes(strlen(TypedValue::json($runPolicy)));
            } while ($minimum !== $previous);
            $limit = $minimum + $delta;
            file_put_contents($this->base.'/admission.json', TypedValue::json($runPolicy));
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', ...$runArgv], timeout: 20);
            $process->run();
            $this->recordCli('minimum-'.$delta, $process, $runArgv, $runOutput);
            $this->assertSame(2, $process->getExitCode(), $process->getErrorOutput());
            $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($delta < 0 ? 'capture_output_minimum' : 'capture_output_limit', $report['reason']);
            $this->assertSame($delta >= 0, is_file($runOutput.'/once.json'));
            $this->assertFileDoesNotExist($runOutput.'/dataset/complete');
            $budget = new OutputBudget($runOutput, $limit);
            try {
                $this->assertLessThanOrEqual($limit, $budget->used());
            } finally {
                $budget->close();
            }
        }
    }

    /** @return array<string, array{bool}> */
    public static function finalReportCases(): array
    {
        return ['writable report' => [false], 'unwritable report' => [true]];
    }

    #[DataProvider('finalReportCases')]
    public function test_real_cli_ignores_late_usr1_until_its_stopped_observer_is_reaped(bool $unwritableReport): void
    {
        [$argv, $policy, $output] = $this->fixture();
        file_put_contents($this->base.'/admission.json', TypedValue::json($policy));
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', ...$argv], timeout: 20);
        $process->start();
        $observer = null;
        try {
            $deadline = hrtime(true) + 10000000000;
            while ($observer === null) {
                $this->assertTrue($process->isRunning(), $process->getOutput().$process->getErrorOutput());
                if (is_file($output.'/rss.ndjson')) {
                    $stream = fopen($output.'/rss.ndjson', 'rb');
                    $line = fgets($stream);
                    fclose($stream);
                    $sample = $line === false ? null : json_decode($line, true);
                    foreach ($sample['observer_processes'] ?? [] as $row) {
                        if ($row['ppid'] === $process->getPid()) {
                            $observer = $row['pid'];
                            $gate = fopen($output.'/.quota.lock', 'r+b');
                            while (! flock($gate, LOCK_EX | LOCK_NB)) {
                                $this->assertLessThan($deadline, hrtime(true));
                                usleep(1000);
                            }
                            try {
                                $this->assertTrue(posix_kill($observer, SIGSTOP));
                                do {
                                    $state = new Process(['ps', '-p', (string) $observer, '-o', 'stat='], timeout: 1);
                                    $state->mustRun();
                                    $this->assertLessThan($deadline, hrtime(true));
                                } while (! str_contains($state->getOutput(), 'T'));
                            } finally {
                                flock($gate, LOCK_UN);
                                fclose($gate);
                            }
                            break;
                        }
                    }
                }
                $this->assertLessThan($deadline, hrtime(true));
                usleep(1000);
            }
            while (! is_file($output.'/observer.stop')) {
                $this->assertTrue($process->isRunning(), $process->getOutput().$process->getErrorOutput());
                $this->assertLessThan($deadline, hrtime(true));
                usleep(1000);
            }
            if ($unwritableReport) {
                mkdir($output.'/report.json', 0700);
            }
            // The observer is stopped, so cleanup cannot already have reaped it.
            $control = ['root_pid' => $process->getPid(), 'observer_pid' => $observer, 'stop_marker_seen' => true, 'first_late_signal_ns' => hrtime(true)];
            $this->assertTrue(posix_kill($process->getPid(), SIGUSR1));
            usleep(10000);
            $this->assertTrue(posix_kill($process->getPid(), SIGUSR1));
            $this->assertTrue(posix_kill($observer, SIGCONT));
            $observer = null;
            $process->wait();
            CaptureStore::writePrivate($this->evidence.'/signal-control.json', TypedValue::json($control));
            $this->recordCli($unwritableReport ? 'late-signal-report-failure' : 'late-signal', $process, $argv, $output);
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($unwritableReport ? 2 : 0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertSame(! $unwritableReport, $result['ok']);
            if ($unwritableReport) {
                $this->assertSame('incomplete', $result['status']);
                $this->assertSame('not_written', $result['report_status']);
                $this->assertNull($result['complete_wall_ns']);
                $this->assertGreaterThan(0, $result['observed_total_wall_ns']);
                $this->assertFileDoesNotExist($output.'/dataset/complete');
            } else {
                $this->assertGreaterThan(0, $result['complete_wall_ns']);
                $this->assertFileExists($output.'/report.json');
            }
        } finally {
            if ($observer !== null) {
                posix_kill($observer, SIGCONT);
            }
            if ($process->isRunning()) {
                $process->stop(0.2);
            }
        }
    }
}
