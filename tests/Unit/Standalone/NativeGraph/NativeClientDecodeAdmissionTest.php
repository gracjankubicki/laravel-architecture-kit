<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\NativeGraph;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Malicious transport responses only; real runtime integration has separate tests. */
final class NativeClientDecodeAdmissionTest extends TestCase
{
    #[DataProvider('shapes')]
    public function test_predecode_admission_rejects_dense_containers_and_keeps_scalar_escaping_bounded(string $shape, bool $refuse): void
    {
        $directory = realpath(sys_get_temp_dir()).'/kit-r04-decode-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $helper = <<<'HELPER'
<?php
function sendFrame(array $value): void { $body = json_encode($value, JSON_THROW_ON_ERROR); fwrite(STDOUT, pack('N', strlen($body)).$body); fflush(STDOUT); }
sendFrame(['protocol'=>'graph-store-v1','capabilities'=>['graph-store-v1','basic-graph-v1'],'max_frame_bytes'=>16842752,'session'=>'fixture']);
$header=fread(STDIN,4); $size=unpack('N', $header)[1]; $body=''; while(strlen($body)<$size){$body.=fread(STDIN,$size-strlen($body));}
$request=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
$value=match($argv[1]){'array'=>array_fill(0,70000,0),'object'=>array_fill_keys(array_map(static fn($i)=>'k'.$i,range(0,24999)),0),default=>['text'=>str_repeat('[]{},:\\"',20000)]};
sendFrame(['protocol'=>'graph-store-v1','request_id'=>$request['request_id'],'ok'=>true,'result'=>$value]);
fread(STDIN,1);
HELPER;
        file_put_contents($directory.'/helper.php', $helper);
        file_put_contents($directory.'/runtime', "#!/bin/sh\nexec ".escapeshellarg(PHP_BINARY).' '.escapeshellarg($directory.'/helper.php').' '.escapeshellarg($shape)."\n");
        chmod($directory.'/runtime', 0700);
        $autoload = var_export(dirname(__DIR__, 4).'/vendor/autoload.php', true);
        $probe = '<?php require '.$autoload.'; '.$this->probe();
        file_put_contents($directory.'/probe.php', $probe);
        try {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=32M', $directory.'/probe.php', $directory.'/runtime'], timeout: 10);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $artifacts = getenv('ARCHITECTURE_KIT_NATIVE_TEST_ARTIFACTS') ?: dirname(__DIR__, 4).'/build/graph-performance-rust/R04/transport-proofs';
            if (! is_dir($artifacts)) {
                mkdir($artifacts, 0700, true);
            }
            file_put_contents($artifacts.'/decode-'.basename($directory).'-'.$shape.'.json', json_encode(['command' => $process->getCommandLine(), 'exit' => $process->getExitCode(), 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput(), 'shape' => $shape, 'probe_sha256' => hash('sha256', $probe), 'helper_sha256' => hash('sha256', $helper)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            self::assertLessThan(33554432, $result['peak']);
            if ($refuse) {
                self::assertSame('native_graph_decode_budget', $result['reason']);
                self::assertFalse($result['healthy']);
                self::assertSame('native_graph_closed', $result['after_close']);
            } else {
                self::assertNull($result['reason']);
                self::assertTrue($result['healthy']);
                self::assertSame(hash('sha256', str_repeat('[]{},:\\"', 20000)), $result['sha256']);
            }
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public static function shapes(): array
    {
        return [['array', true], ['object', true], ['escaped-string', false]];
    }

    private function probe(): string
    {
        return <<<'PROBE'
$client=new \GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeGraphClient($argv[1],268435456,536870912);
$result=['reason'=>null,'after_close'=>null];
try {$answer=$client->request(['op'=>'status']); $result['sha256']=hash('sha256',$answer['text']);}
catch(Throwable $e){$result['reason']=$e->getMessage();try{$client->request(['op'=>'status']);}catch(Throwable $next){$result['after_close']=$next->getMessage();}}
$result['healthy']=$client->healthy();$result['peak']=memory_get_peak_usage(true);$client->close();echo json_encode($result,JSON_THROW_ON_ERROR);
PROBE;
    }
}
