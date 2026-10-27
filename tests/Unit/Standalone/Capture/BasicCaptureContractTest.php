<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\Capture;

use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureAdmission;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureStore;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use GracjanKubicki\ArchitectureKit\Tests\Support\EngineFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BasicCaptureContractTest extends TestCase
{
    private string $base;

    private string $oldLimit;

    private array $policy;

    protected function setUp(): void
    {
        $this->oldLimit = ini_get('memory_limit');
        ini_set('memory_limit', '1G');
        $this->base = dirname(__DIR__, 4).'/build/graph-performance-rust/R01/admission/'.bin2hex(random_bytes(6));
        mkdir($this->base.'/output', 0700, true);
        file_put_contents($this->base.'/source.json', '{}');
        $this->policy = ['command' => ['fixture'], 'cwd' => getcwd(), 'source_manifest' => $this->base.'/source.json',
            'source_manifest_sha256' => hash_file('sha256', $this->base.'/source.json'), 'php_memory_limit_bytes' => 1073741824,
            'max_wall_seconds' => 60, 'max_output_bytes' => 33554432, 'min_free_disk_bytes' => 1,
            'private_output' => $this->base.'/output', 'process_limits' => ['max_processes' => 4, 'max_tree_rss_bytes' => 2147483648]];
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->oldLimit);
    }

    private function admit(array $policy, bool $once = true): CaptureAdmission
    {
        return new CaptureAdmission($policy, ['fixture'], $this->base.'/source.json', $this->base.'/output', $once);
    }

    public function test_every_admission_is_required_and_no_missing_budget_starts_parser(): void
    {
        foreach (array_keys($this->policy) as $key) {
            $policy = $this->policy;
            unset($policy[$key]);
            try {
                $this->admit($policy);
                $this->fail('missing '.$key.' accepted');
            } catch (\RuntimeException $error) {
                $this->assertStringStartsWith('capture_', $error->getMessage());
                $this->assertSame(['.', '..'], scandir($this->base.'/output'));
            }
        }
        foreach (['max_wall_seconds' => 0, 'max_output_bytes' => 0, 'min_free_disk_bytes' => PHP_INT_MAX, 'php_memory_limit_bytes' => 2147483648] as $key => $invalid) {
            $policy = $this->policy;
            $policy[$key] = $invalid;
            try {
                $this->admit($policy);
                $this->fail('invalid '.$key.' accepted');
            } catch (\RuntimeException $error) {
                $this->assertStringStartsWith('capture_', $error->getMessage());
            }
        }
        $this->expectException(\RuntimeException::class);
        $this->admit($this->policy, false);
    }

    public function test_exact_command_memory_once_permissions_and_output_budget(): void
    {
        $admission = $this->admit($this->policy);
        $admission->check(100);
        try {
            $admission->check(33554433);
            $this->fail('output budget not enforced');
        } catch (\RuntimeException $error) {
            $this->assertSame('capture_disk_limit', $error->getMessage());
        }
        ini_set('memory_limit', '2G');
        try {
            $this->admit($this->policy);
            $this->fail('wrong memory limit accepted');
        } catch (\RuntimeException $error) {
            $this->assertSame('capture_admission_identity', $error->getMessage());
        }
        ini_set('memory_limit', '1G');
        CaptureStore::writePrivate($this->base.'/output/once', 'already started');
        $this->expectException(\RuntimeException::class);
        $this->admit($this->policy);
    }

    public function test_elapsed_wall_budget_stops_without_changing_limits(): void
    {
        $policy = $this->policy;
        $policy['max_wall_seconds'] = 1;
        $admission = $this->admit($policy);
        usleep(1050000);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('capture_wall_limit');
        $admission->check(0);
    }

    public function test_symlink_and_insecure_directory_are_rejected(): void
    {
        mkdir($this->base.'/insecure', 0755);
        chmod($this->base.'/insecure', 0755);
        try {
            CaptureStore::privateDirectory($this->base.'/insecure');
            $this->fail('insecure directory accepted');
        } catch (\RuntimeException $error) {
            $this->assertSame('capture_output_permissions', $error->getMessage());
        }
        symlink($this->base.'/output', $this->base.'/linked');
        $this->expectException(\RuntimeException::class);
        CaptureStore::privateDirectory($this->base.'/linked');
    }

    public function test_actual_cli_small_capture_private_raw_and_publishable_aggregate_logs(): void
    {
        $repo = dirname(__DIR__, 4);
        $root = $this->base.'/repo';
        $home = realpath(sys_get_temp_dir()).'/kit-r01-'.basename($this->base);
        mkdir($home, 0700);
        $output = $home.'-capture';
        mkdir($output, 0700);
        file_put_contents($this->base.'/home-provenance.json', TypedValue::json(['home' => $home, 'capture' => $output, 'limited_capture' => $home.'-limited']));
        mkdir($root.'/app', 0700, true);
        mkdir($root.'/.architecture-kit', 0700, true);
        file_put_contents($root.'/.git', 'synthetic-root-marker');
        file_put_contents($root.'/composer.json', '{"name":"fixture/command","type":"project","require":{"php":"^8.3"},"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        file_put_contents($root.'/app/Secret.php', '<?php namespace App; class SYNTHETIC_CAPTURE_SECRET_42 {}');
        file_put_contents($root.'/.architecture-kit/settings.json', TypedValue::json(['enabled' => ['actions'], 'analysis' => ['engine' => EngineFixture::selected()]]));
        if (EngineFixture::selected() === 'mago') {
            EngineFixture::install($home);
        }
        $manifest = $this->base.'/command-source.json';
        $prepare = new Process([PHP_BINARY, $repo.'/tests/Support/native-capture-plan.php', $root, $home, $manifest], cwd: $repo);
        $prepare->run();
        $this->assertSame(0, $prepare->getExitCode(), $prepare->getErrorOutput());
        $argv = [$repo.'/bin/architecture-kit', '--project', $root, '--tool-home', $home, 'capture-basic',
            '--admission-file', $this->base.'/admission.json', '--source-manifest', $manifest, '--output', $output, '--once'];
        $policy = $this->policy;
        $policy['command'] = $argv;
        $policy['private_output'] = $output;
        $policy['source_manifest'] = $manifest;
        $policy['source_manifest_sha256'] = hash_file('sha256', $manifest);
        CaptureStore::writePrivate($this->base.'/admission.json', TypedValue::json($policy));
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', ...$argv], cwd: $repo, timeout: 20);
        $process->run();
        file_put_contents($this->base.'/command.stdout', $process->getOutput());
        file_put_contents($this->base.'/command.stderr', $process->getErrorOutput());
        file_put_contents($this->base.'/command.exit', (string) $process->getExitCode());
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('complete', $result['status']);
        $this->assertFileExists($output.'/dataset/complete');
        foreach (['stages.ndjson', 'rss.ndjson', 'report.json'] as $file) {
            $this->assertStringNotContainsString('SYNTHETIC_CAPTURE_SECRET_42', file_get_contents($output.'/'.$file));
        }
        $this->assertStringContainsString('SYNTHETIC_CAPTURE_SECRET_42', file_get_contents($output.'/dataset/segment-000000.ndjson'));
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertSame('capture_once_output_not_empty', json_decode($process->getOutput(), true)['reason']);
        // A separate experiment, with its own frozen command and output, exercises
        // the observer refusal before configuration or a source parser can run.
        $limitedOutput = $home.'-limited';
        mkdir($limitedOutput, 0700);
        $limitedArgv = $argv;
        $limitedArgv[array_search($output, $limitedArgv, true)] = $limitedOutput;
        $limitedArgv[array_search($this->base.'/admission.json', $limitedArgv, true)] = $this->base.'/limited-admission.json';
        $policy['command'] = $limitedArgv;
        $policy['private_output'] = $limitedOutput;
        $policy['process_limits']['max_tree_rss_bytes'] = 1;
        CaptureStore::writePrivate($this->base.'/limited-admission.json', TypedValue::json($policy));
        $limited = new Process([PHP_BINARY, '-d', 'memory_limit=1G', ...$limitedArgv], cwd: $repo, timeout: 20);
        $limited->run();
        file_put_contents($this->base.'/limited.stdout', $limited->getOutput());
        file_put_contents($this->base.'/limited.stderr', $limited->getErrorOutput());
        file_put_contents($this->base.'/limited.exit', (string) $limited->getExitCode());
        $this->assertSame(2, $limited->getExitCode(), $limited->getErrorOutput());
        $this->assertFileDoesNotExist($limitedOutput.'/dataset/complete');
        $this->assertStringContainsString('max_tree_rss_bytes', file_get_contents($limitedOutput.'/observer.failure.json'));

    }
}
