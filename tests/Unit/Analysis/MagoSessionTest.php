<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\EngineDescriptor;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Tests\Support\MagoReadLoopProbe;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2).'/Fixtures/Analysis/mago-read-loop-probe.php';

final class MagoSessionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/kit-engine-session-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
    }

    public function test_several_snapshots_use_one_process_and_validate_their_own_identity(): void
    {
        $binary = $this->binary('$response = $request; unset($response["source"]); $response["facts"] = []; $response["diagnostics"] = []; $response["syntax"] = []; echo json_encode($response)."\n";');
        $engine = new MagoAnalysisEngine($binary);
        self::assertTrue($engine->analyze(new SourceInput('a.php', '<?php', '8.3'))->complete());
        self::assertTrue($engine->analyze(new SourceInput('b.php', '<?php ', '8.4'))->complete());
        $requests = file($this->directory.'/requests', FILE_IGNORE_NEW_LINES);
        self::assertCount(2, $requests);
        self::assertCount(1, array_unique($requests));
    }

    public function test_wrong_snapshot_response_poison_session_instead_of_retrying(): void
    {
        $binary = $this->binary('echo json_encode(["schema"=>1,"path"=>"other.php","source_hash"=>"wrong","php_version"=>"8.3","facts"=>[],"diagnostics"=>[],"syntax"=>[]])."\n";');
        $engine = new MagoAnalysisEngine($binary);
        $source = new SourceInput('a.php', '<?php', '8.3');
        try {
            $engine->analyze($source);
            self::fail('Wrong snapshot must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('invalid analysis response', $error->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('session failed');
        $engine->analyze($source);
    }

    public function test_large_chunked_responses_and_stderr_are_not_lost_between_requests(): void
    {
        $binary = $this->binary(<<<'PHP'
            $response = $request;
            unset($response['source']);
            $response['facts'] = [['kind'=>'class', 'name'=>str_repeat($request['path'], 300000), 'start'=>0, 'end'=>0, 'owner'=>null]];
            $response['diagnostics'] = [];
            $response['syntax'] = [];
            foreach (str_split(json_encode($response)."\n", 8192) as $chunk) {
                fwrite(STDOUT, $chunk);
                fwrite(STDERR, 'progress');
            }
            PHP);
        $engine = new MagoAnalysisEngine($binary);
        foreach (['first.php', 'second.php', 'third.php'] as $path) {
            $document = $engine->analyze(new SourceInput($path, '<?php', '8.3'));
            self::assertSame(str_repeat($path, 300000), $document->facts[0]['name']);
        }
        self::assertCount(1, array_unique(file($this->directory.'/requests', FILE_IGNORE_NEW_LINES)));
    }

    public function test_session_releases_its_child_process_without_waiting_for_cycle_collection(): void
    {
        $binary = $this->binary('$response=$request; unset($response["source"]); $response["facts"]=[]; $response["diagnostics"]=[]; $response["syntax"]=[]; echo json_encode($response)."\n";');
        $engine = new MagoAnalysisEngine($binary);
        $engine->analyze(new SourceInput('a.php', '<?php', '8.3'));
        $pid = (int) trim(file_get_contents($this->directory.'/requests'));
        $reference = \WeakReference::create($engine);
        unset($engine);
        self::assertNull($reference->get(), 'The process callback must not retain its owning engine.');
        if (function_exists('posix_kill')) {
            self::assertFalse(posix_kill($pid, 0), 'The owned child must be stopped and reaped.');
        }
    }

    public function test_invalid_tree_poisons_session_even_when_envelope_is_correct(): void
    {
        $binary = $this->binary('$response = $request; unset($response["source"]); $response["facts"]=[]; $response["diagnostics"]=[]; $response["syntax"]=[["kind"=>"invented-node","start"=>0,"end"=>0,"fields"=>[],"attributes"=>[],"comments"=>[]]]; echo json_encode($response)."\n";');
        $engine = new MagoAnalysisEngine($binary);
        $source = new SourceInput('a.php', '<?php', '8.3');
        try {
            $engine->analyze($source);
            self::fail('Unknown tree kind must fail.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Unknown portable syntax kind', $error->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('session failed');
        $engine->analyze($source);
    }

    public function test_timeout_is_an_explicit_incomplete_result_and_cannot_restart_process(): void
    {
        $binary = $this->binary('usleep(2000000);');
        $engine = new MagoAnalysisEngine($binary, 0.5);
        $source = new SourceInput('a.php', '<?php', '8.3');
        try {
            $engine->analyze($source);
            self::fail('Unresponsive engine must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('timed out', $error->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('session failed');
        $engine->analyze($source);
    }

    public function test_replacing_binary_during_session_cannot_mix_implementations(): void
    {
        $binary = $this->binary('$response=$request; unset($response["source"]); $response["facts"]=[]; $response["diagnostics"]=[]; $response["syntax"]=[]; echo json_encode($response)."\n";');
        $engine = new MagoAnalysisEngine($binary);
        self::assertTrue($engine->analyze(new SourceInput('a.php', '<?php', '8.3'))->complete());
        copy($binary, $this->directory.'/replacement');
        file_put_contents($this->directory.'/replacement', "\n// Changed executable\n", FILE_APPEND);
        chmod($this->directory.'/replacement', 0700);
        rename($this->directory.'/replacement', $binary);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('binary changed during this analysis session');
        $engine->analyze(new SourceInput('b.php', '<?php', '8.3'));
    }

    public function test_partial_read_progress_is_drained_without_sleeping_between_chunks(): void
    {
        $acknowledgement = var_export($this->directory.'/ack-', true);
        $handler = <<<'PHP'
            $response = $request;
            unset($response['source']);
            $response['facts'] = [['kind'=>'class', 'name'=>str_repeat($request['path'], 60000), 'start'=>0, 'end'=>0, 'owner'=>null]];
            $response['diagnostics'] = [];
            $response['syntax'] = [];
            $wire = json_encode($response)."\n";
            fwrite(STDOUT, substr($wire, 0, 8192));
            fflush(STDOUT);
            while (!is_file(ACK_PREFIX.$request['path']) || !is_file(ACK_PREFIX.$request['path'].'.idle')) { usleep(1000); }
            foreach (str_split(substr($wire, 8192), 8192) as $chunk) { fwrite(STDOUT, $chunk); }
            PHP;
        $binary = $this->binary(str_replace('ACK_PREFIX', $acknowledgement, $handler));
        $engine = new MagoAnalysisEngine($binary);
        foreach (['first.php', 'second.php', 'third.php'] as $path) {
            MagoReadLoopProbe::begin($this->directory.'/ack-'.$path);
            try {
                $document = $engine->analyze(new SourceInput($path, '<?php', '8.3'));
                self::assertSame(str_repeat($path, 60000), $document->facts[0]['name']);
                self::assertGreaterThan(0, MagoReadLoopProbe::$progressChecks, 'The child cannot finish before a partial read is observed.');
                self::assertSame(0, MagoReadLoopProbe::$sleepWithProgress, 'A successful partial read must be followed by another drain, not a sleep.');
                self::assertNotEmpty(MagoReadLoopProbe::$idleSleepDurations);
                self::assertSame([1000], array_values(array_unique(MagoReadLoopProbe::$idleSleepDurations)));
            } finally {
                MagoReadLoopProbe::$active = false;
            }
        }
        self::assertCount(1, array_unique(file($this->directory.'/requests', FILE_IGNORE_NEW_LINES)));
    }

    public function test_stdout_and_stderr_share_the_same_response_budget_and_poison_the_session(): void
    {
        foreach ([65, 40] as $stderrMiB) {
            $binary = $this->binary('for ($i=0;$i<65;$i++) { fwrite($i < '.$stderrMiB.' ? STDERR : STDOUT, str_repeat("x", 1048576)); }');
            $engine = new MagoAnalysisEngine($binary);
            $source = new SourceInput('limit.php', '<?php', '8.3');
            try {
                $engine->analyze($source);
                self::fail('Both output streams must count towards 64 MiB.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('64 MiB', $error->getMessage());
            }
            try {
                $engine->analyze($source);
                self::fail('Overflow must poison the session without retry or fallback.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('session failed', $error->getMessage());
            }
            self::assertCount(1, file($this->directory.'/requests'));
            $pid = (int) trim(file_get_contents($this->directory.'/requests'));
            if (function_exists('posix_kill')) {
                self::assertFalse(posix_kill($pid, 0), 'Overflow must stop the owned child before returning.');
            }
            unset($engine);
            $this->addToAssertionCount(1); // Destruction must not rethrow a late output-limit error.
            unlink($this->directory.'/requests');
        }
    }

    public function test_extra_complete_frame_is_rejected_and_cannot_become_the_next_snapshot(): void
    {
        $binary = $this->binary('$response=$request; unset($response["source"]); $response["facts"]=[]; $response["diagnostics"]=[]; $response["syntax"]=[]; $wire=json_encode($response)."\n"; echo $wire.$wire;');
        $engine = new MagoAnalysisEngine($binary);
        $source = new SourceInput('extra.php', '<?php', '8.3');
        try {
            $engine->analyze($source);
            self::fail('More than one frame is not a valid response.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('extra protocol output', $error->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('session failed');
        $engine->analyze($source);
    }

    public function test_continuous_partial_progress_does_not_extend_the_request_deadline(): void
    {
        $binary = $this->binary('while (true) { fwrite(STDOUT, str_repeat(" ", 4096)); fflush(STDOUT); usleep(1000); }');
        $engine = new MagoAnalysisEngine($binary, 0.5);
        $source = new SourceInput('partial.php', '<?php', '8.3');
        try {
            $engine->analyze($source);
            self::fail('Partial progress must not reset the request deadline.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('timed out', $error->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('session failed');
        $engine->analyze($source);
    }

    private function binary(string $handler): string
    {
        $metadata = json_encode(['engine' => 'mago', 'version' => MagoAnalysisEngine::VERSION,
            'mago_version' => MagoAnalysisEngine::MAGO_VERSION, 'schema' => EngineDescriptor::SCHEMA,
            'capabilities' => ['syntax-facts', 'syntax-tree-v1']], JSON_THROW_ON_ERROR);
        $path = $this->directory.'/engine';
        $script = "<?php\nif ((\$argv[1] ?? '') === '--version') { echo ".var_export($metadata."\n", true)."; exit; }\n";
        $script .= 'while (($line = fgets(STDIN)) !== false) { file_put_contents('.var_export($this->directory.'/requests', true).', getmypid()."\n", FILE_APPEND); $request=json_decode($line,true,flags:JSON_THROW_ON_ERROR); '.$handler.' fflush(STDOUT); }';
        file_put_contents($path.'.php', $script);
        file_put_contents($path, "#!/bin/sh\nexec ".escapeshellarg(PHP_BINARY).' '.escapeshellarg($path.'.php').' "$@"'."\n");
        chmod($path, 0700);

        return $path;
    }
}
