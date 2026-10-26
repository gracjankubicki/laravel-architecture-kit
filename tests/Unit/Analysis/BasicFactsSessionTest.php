<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisSession;
use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsOptions;
use GracjanKubicki\ArchitectureKit\Analysis\BasicFileFacts;
use GracjanKubicki\ArchitectureKit\Analysis\BasicSource;
use GracjanKubicki\ArchitectureKit\Analysis\EngineDescriptor;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Protocol fault injection only; the real extraction tests are separate. */
final class BasicFactsSessionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/kit-basic-session-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
    }

    public function test_missing_capability_never_launches_an_analysis_or_php_fallback(): void
    {
        $backend = new MagoAnalysisEngine($this->binary('', capability: false));
        try {
            $backend->analyzeBasic([$this->source('app/Example.php')], new BasicFactsOptions);
            self::fail('Old syntax capability is not a Basic map.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString(BasicFileFacts::PRODUCT, $error->getMessage());
            self::assertFileDoesNotExist($this->directory.'/requests');
        }
    }

    public function test_wrong_second_result_rejects_the_whole_batch_and_poison_session_without_retry(): void
    {
        foreach (['$raw["path"] = "other.php";', '$raw["source_hash"] = "wrong";', '$raw["php_version"] = "8.1";',
            '$raw["extractor"] = 2;', '$raw["configuration_fingerprint"] = "other";', '$raw["mapping_fingerprint"] = "other";',
            'unset($raw["entry"]["test_invocations"]);', '$raw["entry"]["catalog"]["elements"][0]["parent"] = [\'$identity\' => 0];',
            '$raw["entry"]["catalog"]["elements"][0]["offset"] = 999;', '$raw["identities"][0][3] = 999;'] as $mutation) {
            $binary = $this->binary('if ($ordinal === 1) { '.$mutation.' }');
            $backend = new MagoAnalysisEngine($binary);
            $stages = [];
            $session = new AnalysisSession($backend, '8.3', stageObserver: static function ($stage) use (&$stages): void {
                $stages[] = $stage;
            });
            try {
                $session->basic([$this->source('app/A.php'), $this->source('app/B.php')], new BasicFactsOptions(catalog: true));
                self::fail('Corrupt second contribution must not partially succeed.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $stages);
            }
            try {
                $session->basic([$this->source('app/C.php')], new BasicFactsOptions(catalog: true));
                self::fail('Failed session cannot retry.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('no longer valid', $error->getMessage());
            }
            self::assertCount(1, file($this->directory.'/requests'));
            unset($session, $backend);
            unlink($this->directory.'/requests');
        }
    }

    public function test_chunked_batch_responses_preserve_every_source_and_keep_one_process(): void
    {
        $backend = new MagoAnalysisEngine($this->binary('$raw["entry"]["symbols"] = [[str_repeat($q["path"], 100000),1,"","class",false,true]];', chunked: true));
        foreach ([['app/A.php', 'app/B.php'], ['app/C.php', 'app/D.php']] as $paths) {
            $facts = $backend->analyzeBasic(array_map($this->source(...), $paths), new BasicFactsOptions);
            foreach ($facts as $i => $fact) {
                self::assertSame($paths[$i], $fact->source->path);
                self::assertSame(str_repeat($paths[$i], 100000), $fact->entry->symbols[0]->name);
            }
        }
        $requests = file($this->directory.'/requests', FILE_IGNORE_NEW_LINES);
        self::assertCount(2, $requests);
        self::assertCount(1, array_unique($requests));
    }

    public function test_truncated_or_malformed_response_cannot_become_an_empty_success(): void
    {
        foreach (['echo "{"; exit;', 'echo "{}\n";', 'echo "invalid\n";', '$response["results"] = []; echo json_encode($response)."\n";'] as $response) {
            $backend = new MagoAnalysisEngine($this->binary('', response: $response));
            try {
                $backend->analyzeBasic([$this->source('app/Example.php')], new BasicFactsOptions);
                self::fail('Incomplete Basic response must fail.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_32_mib_source_limit_and_64_mib_response_limit_remain_enforced(): void
    {
        $backend = new MagoAnalysisEngine($this->binary(''));
        $tooLarge = BasicSource::plain(new SourceInput('app/Large.php', '<?php /*'.str_repeat("\t", 17 * 1024 * 1024).'*/', '8.3'));
        try {
            $backend->analyzeBasic([$tooLarge], new BasicFactsOptions);
            self::fail('A single source cannot bypass 32 MiB through batching.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('32 MiB', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->directory.'/requests');
        unset($backend, $tooLarge);
        $backend = new MagoAnalysisEngine($this->binary('', response: 'for ($i=0;$i<65;$i++) { fwrite(STDOUT,str_repeat("x",1048576)); fflush(STDOUT); }'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('64 MiB');
        $backend->analyzeBasic([$this->source('app/Example.php')], new BasicFactsOptions);
    }

    public function test_timeout_does_not_restart_the_native_session(): void
    {
        $backend = new MagoAnalysisEngine($this->binary('', response: 'usleep(2000000);'), 0.5);
        try {
            $backend->analyzeBasic([$this->source('app/Example.php')], new BasicFactsOptions);
            self::fail('Timed out Basic extraction must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('timed out', $error->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('session failed');
        $backend->analyzeBasic([$this->source('app/Again.php')], new BasicFactsOptions);
    }

    private function source(string $path): BasicSource
    {
        return BasicSource::plain(new SourceInput($path, '<?php', '8.3'));
    }

    private function binary(string $mutation, bool $capability = true, bool $chunked = false, ?string $response = null): string
    {
        $metadata = json_encode(['engine' => 'mago', 'version' => MagoAnalysisEngine::VERSION,
            'mago_version' => MagoAnalysisEngine::MAGO_VERSION, 'schema' => EngineDescriptor::SCHEMA,
            'capabilities' => ['syntax-facts', 'syntax-tree-v1', ...($capability ? [BasicFileFacts::PRODUCT] : [])]], JSON_THROW_ON_ERROR);
        $script = "<?php\nif ((\$argv[1] ?? '') === '--version') { echo ".var_export($metadata."\n", true)."; exit; }\n";
        $script .= 'while (($line=fgets(STDIN))!==false) { file_put_contents('.var_export($this->directory.'/requests', true).',getmypid()."\n",FILE_APPEND); $request=json_decode($line,true,flags:JSON_THROW_ON_ERROR); $results=[]; foreach (($request["requests"]??[$request]) as $ordinal=>$q) {';
        $script .= <<<'PHP'
            $raw=array_intersect_key($q,array_flip(['schema','product','extractor','path','source_hash','php_version','engine_fingerprint','configuration_fingerprint','mapping_fingerprint']));
            $raw['raw_schema']=1;
            $raw['identities']=[['element','file',$q['path'],0]];
            $catalog=['v'=>3,'elements'=>[['id'=>['$identity'=>0],'name'=>$q['path'],'kind'=>'file','line'=>1,'end_line'=>1,'offset'=>0,'parent'=>null,'roles'=>[],'metadata'=>[]]],'relations'=>[],'diagnostics'=>[],'descriptor_receivers'=>[]];
            $raw['entry']=['symbols'=>[],'edges'=>[],'test_invocations'=>[],'impact'=>null,'catalog'=>$q['options']['catalog']?$catalog:null,'syntax_error'=>null,'syntax_error_line'=>1];
            $raw['diagnostics']=[];
            $raw['trace']=['product'=>'basic-file-facts-v1','syntax_transport'=>false,'ir_built'=>true];
            PHP;
        $script .= $mutation.' $results[]=$raw; } $response=["schema"=>1,"product"=>"basic-file-facts-batch-v1","results"=>$results]; ';
        $script .= $response ?? ($chunked ? 'foreach(str_split(json_encode($response)."\n",8192)as $chunk){fwrite(STDOUT,$chunk);fwrite(STDERR,"progress");}' : 'echo json_encode($response)."\n";');
        $script .= ' fflush(STDOUT); }';
        $id = bin2hex(random_bytes(4));
        $path = $this->directory.'/engine-'.$id;
        file_put_contents($path.'.php', $script);
        file_put_contents($path, "#!/bin/sh\nexec ".escapeshellarg(PHP_BINARY).' '.escapeshellarg($path.'.php').' "$@"'."\n");
        chmod($path, 0700);

        return $path;
    }
}
