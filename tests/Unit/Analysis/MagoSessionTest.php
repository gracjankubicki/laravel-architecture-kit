<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\EngineDescriptor;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
        $reference = \WeakReference::create($engine);
        unset($engine);
        self::assertNull($reference->get(), 'The process callback must not retain its owning engine.');
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
